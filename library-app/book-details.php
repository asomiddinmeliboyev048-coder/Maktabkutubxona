<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

sync_overdue_transactions($pdo);
library_feature_expire_reservations($pdo);

$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($bookId < 1) {
    set_flash('warning', 'Kitob topilmadi.');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $className = trim((string) ($_POST['class_name'] ?? ''));
    $contact = trim((string) ($_POST['contact'] ?? ''));
    $pickupValue = trim((string) ($_POST['pickup_date'] ?? ''));
    $pickupDate = library_feature_valid_date($pickupValue);
    $today = new DateTimeImmutable('today');
    $latest = $today->modify('+60 days');
    $errors = [];

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Xavfsizlik tokeni yaroqsiz. Sahifani yangilang.';
    }
    if (mb_strlen($firstName) < 2 || mb_strlen($firstName) > 80) {
        $errors[] = 'Ism 2–80 belgidan iborat bo‘lsin.';
    }
    if (mb_strlen($lastName) < 2 || mb_strlen($lastName) > 80) {
        $errors[] = 'Familiya 2–80 belgidan iborat bo‘lsin.';
    }
    if (mb_strlen($className) < 1 || mb_strlen($className) > 50) {
        $errors[] = 'Sinfni to‘g‘ri kiriting.';
    }
    if (mb_strlen($contact) < 7 || mb_strlen($contact) > 100) {
        $errors[] = 'Bog‘lanish uchun telefon yoki e-mail kiriting.';
    } elseif (str_contains($contact, '@') && filter_var($contact, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'E-mail manzilini to‘g‘ri kiriting.';
    }
    if (!$pickupDate || $pickupDate < $today || $pickupDate > $latest) {
        $errors[] = 'Olib ketish sanasi bugundan 60 kun ichida bo‘lsin.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $bookLock = $pdo->prepare('SELECT id, title, total_copies, available_copies, is_active FROM books WHERE id = :id FOR UPDATE');
            $bookLock->execute(['id' => $bookId]);
            $lockedBook = $bookLock->fetch();
            if (!$lockedBook || (int) $lockedBook['is_active'] !== 1 || (int) $lockedBook['total_copies'] < 1) {
                throw new RuntimeException('Bu kitobni hozir band qilib bo‘lmaydi.');
            }
            $requestAvailability = library_feature_enrich_books($pdo, [$lockedBook])[0];
            $earliestPickup = $requestAvailability['earliest_pickup_date'];
            if ((int) $requestAvailability['free_copies'] < 1 && $earliestPickup === null) {
                throw new RuntimeException('Barcha nusxalar band, yangi olib ketish sanasi hozircha aniq emas. Keyinroq urinib ko‘ring.');
            }
            if (is_string($earliestPickup) && $pickupValue < $earliestPickup) {
                throw new RuntimeException('Tanlangan sana juda erta. Eng erta kutilayotgan sana: ' . library_feature_date($earliestPickup) . '.');
            }

            $studentStatement = $pdo->prepare(
                'SELECT id, is_active FROM students
                 WHERE LOWER(first_name) = LOWER(:first_name)
                   AND LOWER(last_name) = LOWER(:last_name)
                   AND LOWER(phone) = LOWER(:contact)
                 ORDER BY is_active DESC, id ASC LIMIT 1 FOR UPDATE'
            );
            $studentStatement->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'contact' => $contact,
            ]);
            $student = $studentStatement->fetch();
            $studentId = (int) ($student['id'] ?? 0);
            $fullName = $firstName . ' ' . $lastName;

            if ($student && (int) $student['is_active'] !== 1) {
                throw new RuntimeException('Bu o‘quvchi yozuvi arxivlangan. Kutubxonachi bilan bog‘laning.');
            }

            if ($studentId < 1) {
                $studentCode = 'WEB-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
                $insertStudent = $pdo->prepare(
                    'INSERT INTO students (first_name, last_name, full_name, class_name, phone, student_code, is_active)
                     VALUES (:first_name, :last_name, :full_name, :class_name, :phone, :student_code, 1)'
                );
                $insertStudent->execute([
                    'first_name' => $firstName, 'last_name' => $lastName,
                    'full_name' => $fullName, 'class_name' => $className,
                    'phone' => $contact, 'student_code' => $studentCode,
                ]);
                $studentId = (int) $pdo->lastInsertId();
            } else {
                $updateStudent = $pdo->prepare(
                    'UPDATE students
                     SET full_name = :full_name, class_name = :class_name, phone = :contact
                     WHERE id = :id'
                );
                $updateStudent->execute([
                    'full_name' => $fullName,
                    'class_name' => $className,
                    'contact' => $contact,
                    'id' => $studentId,
                ]);
            }

            $duplicate = $pdo->prepare(
                "SELECT id FROM reservations
                 WHERE book_id = :book_id AND student_id = :student_id
                   AND status IN ('pending', 'approved', 'ready')
                 LIMIT 1 FOR UPDATE"
            );
            $duplicate->execute(['book_id' => $bookId, 'student_id' => $studentId]);
            if ($duplicate->fetchColumn()) {
                throw new RuntimeException('Sizda bu kitob uchun faol so‘rov allaqachon mavjud.');
            }

            $insert = $pdo->prepare(
                "INSERT INTO reservations
                 (book_id, student_id, request_date, pickup_date, status, expires_at)
                 VALUES (:book_id, :student_id, CURDATE(), :pickup_date, 'pending', :expires_at)"
            );
            $insert->execute([
                'book_id' => $bookId,
                'student_id' => $studentId,
                'pickup_date' => $pickupValue,
                'expires_at' => $pickupValue . ' 23:59:59',
            ]);
            $pdo->commit();
            set_flash('success', 'So‘rovingiz yuborildi. Kutubxonachi tasdiqlagach, kitobni belgilangan sanada olishingiz mumkin.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = 'So‘rovni saqlab bo‘lmadi. Qayta urinib ko‘ring.';
            if ($exception instanceof RuntimeException) {
                $message = $exception->getMessage();
            } elseif ($exception instanceof PDOException && $exception->getCode() === '23000') {
                $message = 'Sizda bu kitob uchun faol so‘rov allaqachon mavjud.';
            }
            set_flash('danger', $message);
        }
    } else {
        set_flash('danger', implode(' ', $errors));
    }
    redirect('book-details.php?id=' . $bookId);
}

$bookStatement = $pdo->prepare(
    'SELECT b.*, c.name AS category_name,
            COALESCE(AVG(r.rating), 0) AS average_rating, COUNT(DISTINCT r.id) AS review_count
     FROM books b
     INNER JOIN categories c ON c.id = b.category_id
     LEFT JOIN reviews r ON r.book_id = b.id
     WHERE b.id = :id AND b.is_active = 1
     GROUP BY b.id, c.name'
);
$bookStatement->execute(['id' => $bookId]);
$book = $bookStatement->fetch();
if (!$book) {
    set_flash('warning', 'Kitob topilmadi yoki katalogdan olib tashlangan.');
    redirect('index.php');
}
$book = library_feature_enrich_books($pdo, [$book])[0];

$loanStatement = $pdo->prepare(
    "SELECT bt.borrow_date, bt.due_date, bt.status
     FROM borrow_transactions bt
     WHERE bt.book_id = :book_id
       AND bt.status IN ('borrowed', 'overdue') AND bt.return_date IS NULL
     ORDER BY bt.due_date ASC, bt.id ASC"
);
$loanStatement->execute(['book_id' => $bookId]);
$currentLoans = $loanStatement->fetchAll();
$reviews = library_feature_review_rows($pdo, $bookId);
$flash = get_flash();
$status = (string) $book['availability_status'];
$todayValue = date('Y-m-d');
$latestPickupValue = date('Y-m-d', strtotime('+60 days'));
$minimumPickupValue = is_string($book['earliest_pickup_date'])
    && $book['earliest_pickup_date'] > $todayValue
    ? $book['earliest_pickup_date']
    : $todayValue;
$canReserve = $minimumPickupValue <= $latestPickupValue
    && ($status === 'available' || is_string($book['earliest_pickup_date']));
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($book['title']) ?> kitobi mavjudligi va band qilish">
    <title><?= e($book['title']) ?> — <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/features.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top border-bottom"><div class="container py-2"><a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="<?= e(APP_URL) ?>/index.php"><span class="brand-icon"><i class="fa-solid fa-book-open"></i></span><span><?= e(APP_NAME) ?></span></a><a class="btn btn-outline-primary rounded-pill px-4" href="<?= e(APP_URL) ?>/index.php"><i class="fa-solid fa-arrow-left me-2"></i>Katalog</a></div></nav>

<main>
    <?php if ($flash): ?><div class="container pt-4"><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert"><?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Yopish"></button></div></div><?php endif; ?>
    <section class="details-hero py-5"><div class="container"><div class="row align-items-center g-5">
        <div class="col-md-4 col-lg-3"><div class="details-cover-frame"><img class="details-cover" src="<?= e(book_cover_url($book['cover_image'])) ?>" alt="<?= e($book['title']) ?> muqovasi"></div></div>
        <div class="col-md-8 col-lg-9"><span class="section-kicker"><?= e($book['category_name']) ?></span><h1 class="display-5 fw-bold mt-2 mb-2"><?= e($book['title']) ?></h1><p class="lead text-secondary mb-3"><i class="fa-regular fa-user me-2"></i><?= e($book['author']) ?></p><div class="rating-large d-flex align-items-center gap-2 mb-4"><?= render_stars((float) $book['average_rating']) ?><span class="text-secondary"><?= number_format((float) $book['average_rating'], 1) ?> · <?= (int) $book['review_count'] ?> fikr</span></div>
            <div class="availability-summary"><div class="availability-box status-<?= e($status) ?>"><i class="fa-solid <?= $status === 'available' ? 'fa-circle-check' : ($status === 'busy' ? 'fa-clock' : 'fa-circle-xmark') ?>"></i><div><strong><?= e(library_feature_status_label($status)) ?></strong><span><?= (int) $book['free_copies'] ?> ta erkin nusxa</span></div></div><div class="stock-metric"><strong><?= (int) $book['total_copies'] ?></strong><span>Jami</span></div><div class="stock-metric"><strong><?= (int) $book['available_copies'] ?></strong><span>Javonda</span></div><div class="stock-metric"><strong><?= (int) $book['held_copies'] ?></strong><span>Band</span></div></div>
            <p class="pickup-note mt-3 mb-0"><i class="fa-solid fa-calendar-day me-2"></i>Eng erta olib ketish: <strong><?= e(library_feature_date($book['earliest_pickup_date'], 'Hozircha aniq emas')) ?></strong></p>
        </div>
    </div></div></section>

    <div class="container py-5"><div class="row g-4">
        <div class="col-lg-7"><section class="content-card mb-4"><p class="section-kicker">Kitob haqida</p><h2 class="h4 fw-bold">Tavsif</h2><p class="description-text mb-0"><?= nl2br(e($book['description'])) ?></p></section>
            <section class="content-card mb-4" aria-labelledby="loansHeading"><div class="d-flex justify-content-between align-items-center gap-2 mb-3"><div><p class="section-kicker mb-1">Ayni paytda</p><h2 id="loansHeading" class="h4 fw-bold mb-0">O‘quvchilardagi nusxalar</h2></div><span class="review-count-badge"><?= count($currentLoans) ?> ta</span></div>
                <?php if ($currentLoans === []): ?><p class="text-secondary mb-0">Bu kitobning barcha berilgan nusxalari qaytarilgan.</p><?php else: ?><div class="loan-list"><?php foreach ($currentLoans as $index => $loan): ?><article class="loan-row"><span class="loan-icon"><i class="fa-solid fa-book-reader"></i></span><div><strong>Nusxa #<?= $index + 1 ?></strong><small>Berilgan: <?= e(library_feature_date($loan['borrow_date'])) ?> · Qaytarish muddati: <?= e(library_feature_date($loan['due_date'])) ?></small></div><span class="loan-state <?= $loan['status'] === 'overdue' ? 'overdue' : '' ?>"><?= $loan['status'] === 'overdue' ? 'Muddati o‘tgan' : 'O‘quvchida' ?></span></article><?php endforeach; ?></div><?php endif; ?>
            </section>
            <section class="content-card"><div class="d-flex justify-content-between align-items-center mb-3"><div><p class="section-kicker mb-1">Kitobxonlar</p><h2 class="h4 fw-bold mb-0">Fikrlar</h2></div><span class="review-count-badge"><?= count($reviews) ?> ta</span></div><?php if ($reviews === []): ?><p class="text-secondary mb-0">Hozircha fikr qoldirilmagan.</p><?php else: ?><div class="review-list"><?php foreach ($reviews as $review): ?><article class="review-item"><span class="review-avatar"><i class="fa-solid fa-user"></i></span><div><div class="mb-2"><?= render_stars((float) $review['rating']) ?></div><p class="mb-1"><?= nl2br(e($review['comment'])) ?></p><small class="text-secondary">Anonim kitobxon · <?= e(library_feature_date($review['created_at'])) ?></small></div></article><?php endforeach; ?></div><?php endif; ?></section>
        </div>
        <div class="col-lg-5"><section class="content-card reservation-card sticky-lg-top" aria-labelledby="reserveHeading"><span class="reservation-icon"><i class="fa-solid fa-calendar-check"></i></span><p class="section-kicker mt-3">Onlayn so‘rov</p><h2 id="reserveHeading" class="h3 fw-bold">Kitobni band qiling</h2><p class="text-secondary">Ma’lumotlaringiz faqat kutubxonachi bilan bog‘lanish va so‘rovni boshqarish uchun ishlatiladi.</p><?php if (!$canReserve): ?><div class="alert alert-warning">Keyingi bo‘shash sanasi hozircha aniq emas yoki 60 kundan keyin. So‘rov yuborish uchun keyinroq tekshiring.</div><?php endif; ?><form method="post" action="<?= e(APP_URL) ?>/book-details.php?id=<?= $bookId ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="row g-3"><div class="col-sm-6"><label class="form-label fw-semibold" for="first_name">Ism</label><input class="form-control" id="first_name" name="first_name" maxlength="80" autocomplete="given-name" required></div><div class="col-sm-6"><label class="form-label fw-semibold" for="last_name">Familiya</label><input class="form-control" id="last_name" name="last_name" maxlength="80" autocomplete="family-name" required></div><div class="col-sm-5"><label class="form-label fw-semibold" for="class_name">Sinf</label><input class="form-control" id="class_name" name="class_name" maxlength="50" placeholder="9-A" required></div><div class="col-sm-7"><label class="form-label fw-semibold" for="contact">Telefon yoki e-mail</label><input class="form-control" id="contact" name="contact" maxlength="100" autocomplete="tel" required></div><div class="col-12"><label class="form-label fw-semibold" for="pickup_date">Olib ketish sanasi</label><input class="form-control" type="date" id="pickup_date" name="pickup_date" min="<?= e($minimumPickupValue) ?>" max="<?= e($latestPickupValue) ?>" value="<?= e($minimumPickupValue) ?>" required><div class="form-text">Kutubxonachi so‘rovni tasdiqlaydi; “tayyor” holati nusxani olib ketish mumkinligini bildiradi.</div></div><div class="col-12 d-grid"><button class="btn btn-primary btn-lg" type="submit" <?= $canReserve ? '' : 'disabled' ?>><i class="fa-solid fa-paper-plane me-2"></i>So‘rov yuborish</button></div></div></form></section></div>
    </div></div>
</main>

<footer class="public-footer interactive-footer mt-5" data-interactive-footer><div class="footer-orbit" aria-hidden="true"><i class="fa-solid fa-book"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-graduation-cap"></i></div><div class="container py-5 position-relative"><div class="row g-4 align-items-center"><div class="col-lg-7"><span class="footer-mark"><i class="fa-solid fa-book-open"></i></span><h2 class="h3 text-white mt-3">Har bir sahifa — yangi imkoniyat</h2><p class="mb-0">Izlang, mavjudligini tekshiring va navbatdagi kitobingizni oldindan band qiling.</p></div><div class="col-lg-5 text-lg-end"><a class="btn btn-light rounded-pill px-4" href="<?= e(APP_URL) ?>/index.php">Katalogga qaytish <i class="fa-solid fa-arrow-right ms-2"></i></a></div></div><div class="footer-bottom mt-4 pt-4"><span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></span><span><i class="fa-solid fa-shield-heart me-1"></i> O‘quvchilar uchun xavfsiz makon</span></div></div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
</body></html>
