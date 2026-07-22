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
    $user = require_role($pdo, 'student');
    $pickupValue = trim((string) ($_POST['pickup_date'] ?? ''));
    $pickupDate = library_feature_valid_date($pickupValue);
    $today = new DateTimeImmutable('today');
    $latest = $today->modify('+60 days');
    $errors = [];

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) $errors[] = 'Xavfsizlik tokeni yaroqsiz.';
    if (!$pickupDate || $pickupDate < $today || $pickupDate > $latest) $errors[] = 'Olib ketish sanasi bugundan 60 kun ichida bo‘lsin.';

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $bookLock = $pdo->prepare('SELECT id,title,total_copies,available_copies,is_active,listing_type FROM books WHERE id=:id FOR UPDATE');
            $bookLock->execute(['id' => $bookId]);
            $lockedBook = $bookLock->fetch();
            if (!$lockedBook || (int) $lockedBook['is_active'] !== 1 || (int) $lockedBook['total_copies'] < 1) throw new RuntimeException('Bu kitobni hozir band qilib bo‘lmaydi.');
            if ($lockedBook['listing_type'] === 'sale') throw new RuntimeException('Faqat sotuvdagi kitob kutubxona band qilish tizimi orqali berilmaydi.');

            $studentStatement = $pdo->prepare('SELECT id,is_active FROM students WHERE user_id=:user_id LIMIT 1 FOR UPDATE');
            $studentStatement->execute(['user_id' => $user['id']]);
            $student = $studentStatement->fetch();
            if (!$student || (int) $student['is_active'] !== 1) throw new RuntimeException('Hisobingizga faol o‘quvchi profili bog‘lanmagan.');

            $availability = library_feature_enrich_books($pdo, [$lockedBook])[0];
            $earliest = $availability['earliest_pickup_date'];
            if ((int) $availability['free_copies'] < 1 && $earliest === null) throw new RuntimeException('Barcha nusxalar band; bo‘shash sanasi hozircha aniq emas.');
            if (is_string($earliest) && $pickupValue < $earliest) throw new RuntimeException('Eng erta sana: ' . library_feature_date($earliest) . '.');

            $duplicate = $pdo->prepare("SELECT id FROM reservations WHERE book_id=:book_id AND student_id=:student_id AND status IN ('pending','approved','ready') LIMIT 1 FOR UPDATE");
            $duplicate->execute(['book_id' => $bookId, 'student_id' => $student['id']]);
            if ($duplicate->fetchColumn()) throw new RuntimeException('Sizda bu kitob uchun faol so‘rov mavjud.');

            $insert = $pdo->prepare("INSERT INTO reservations (book_id,student_id,request_date,pickup_date,status,expires_at) VALUES (:book_id,:student_id,CURDATE(),:pickup_date,'pending',:expires_at)");
            $insert->execute(['book_id' => $bookId, 'student_id' => $student['id'], 'pickup_date' => $pickupValue, 'expires_at' => $pickupValue . ' 23:59:59']);
            $pdo->commit();
            set_flash('success', 'Band qilish so‘rovingiz sotuvchiga yuborildi.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'So‘rovni saqlab bo‘lmadi.');
        }
    } else {
        set_flash('danger', implode(' ', $errors));
    }
    redirect('book-details.php?id=' . $bookId);
}

$statement = $pdo->prepare("SELECT b.*,c.name category_name,CONCAT_WS(' ',u.first_name,u.last_name) seller_name,COALESCE(AVG(r.rating),0) average_rating,COUNT(DISTINCT r.id) review_count FROM books b INNER JOIN categories c ON c.id=b.category_id LEFT JOIN users u ON u.id=b.user_id AND u.is_active=1 LEFT JOIN reviews r ON r.book_id=b.id WHERE b.id=:id AND b.is_active=1 GROUP BY b.id,c.name,u.first_name,u.last_name");
$statement->execute(['id' => $bookId]);
$book = $statement->fetch();
if (!$book) {
    set_flash('warning', 'Kitob topilmadi yoki katalogdan olingan.');
    redirect('index.php');
}
$book = library_feature_enrich_books($pdo, [$book])[0];

$loansStatement = $pdo->prepare("SELECT borrow_date,due_date,status FROM borrow_transactions WHERE book_id=:book_id AND status IN ('borrowed','overdue') AND return_date IS NULL ORDER BY due_date,id");
$loansStatement->execute(['book_id' => $bookId]);
$loans = $loansStatement->fetchAll();
$reviews = library_feature_review_rows($pdo, $bookId);
$flash = get_flash();
$user = current_user($pdo);
$todayValue = date('Y-m-d');
$latestValue = date('Y-m-d', strtotime('+60 days'));
$minimum = is_string($book['earliest_pickup_date']) && $book['earliest_pickup_date'] > $todayValue ? $book['earliest_pickup_date'] : $todayValue;
$canReserve = $book['listing_type'] !== 'sale' && $minimum <= $latestValue && ($book['availability_status'] === 'available' || is_string($book['earliest_pickup_date']));
$phone = (string) ($book['phone'] ?? '');
$whatsapp = whatsapp_phone($phone);
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($book['title']) ?> kitobi">
    <title><?= e($book['title']) ?> — <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/features.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top border-bottom">
    <div class="container py-2">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="<?= e(APP_URL) ?>/index.php">
            <span class="brand-icon"><i class="fa-solid fa-book-open"></i></span>
            <span><?= e(APP_NAME) ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavigation" aria-controls="mainNavigation" aria-expanded="false" aria-label="Menyuni ochish"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="mainNavigation">
            <div class="ms-auto mt-3 mt-lg-0 d-flex flex-wrap align-items-center gap-2">
                <a class="btn btn-outline-primary" href="<?= e(APP_URL) ?>/index.php"><i class="fa-solid fa-arrow-left me-2"></i>Katalog</a>
                <?= public_auth_navigation($pdo) ?>
            </div>
        </div>
    </div>
</nav>

<main>
    <?php if ($flash): ?>
        <div class="container pt-4">
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= e($flash['message']) ?>
                <button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button>
            </div>
        </div>
    <?php endif; ?>

    <section class="details-hero py-5">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-md-4 col-lg-3">
                    <div class="details-cover-frame"><img class="details-cover" src="<?= e(book_cover_url($book['cover_image'])) ?>" alt="<?= e($book['title']) ?> muqovasi"></div>
                </div>
                <div class="col-md-8 col-lg-9">
                    <span class="section-kicker"><?= e($book['category_name']) ?></span>
                    <h1 class="display-5 fw-bold mt-2"><?= e($book['title']) ?></h1>
                    <p class="lead text-secondary"><i class="fa-regular fa-user me-2"></i><?= e($book['author']) ?></p>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span class="listing-badge"><?= e(library_feature_listing_label($book['listing_type'])) ?></span>
                        <span class="result-count"><i class="fa-solid fa-store me-2"></i><?= e($book['seller_name'] ?: 'Maktab kutubxonasi') ?></span>
                        <?= render_stars((float) $book['average_rating']) ?>
                        <span class="rating-number"><?= number_format((float) $book['average_rating'], 1) ?> (<?= (int) $book['review_count'] ?>)</span>
                    </div>
                    <div class="marketplace-price-grid">
                        <?php if (in_array($book['listing_type'], ['sale', 'both'], true) && $book['price'] !== null): ?><div><small>Sotuv narxi</small><strong><?= e(money_uzs($book['price'])) ?></strong></div><?php endif; ?>
                        <?php if (in_array($book['listing_type'], ['rental', 'both'], true) && $book['rental_price'] !== null): ?><div><small>Ijara narxi</small><strong><?= e(money_uzs($book['rental_price'])) ?></strong></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="container py-5">
        <div class="row g-4 align-items-start">
            <div class="col-lg-7">
                <section class="content-card mb-4">
                    <p class="section-kicker">Kitob haqida</p>
                    <h2 class="h4 fw-bold">Tavsif</h2>
                    <p class="description-text"><?= nl2br(e($book['description'])) ?></p>
                    <hr>
                    <dl class="contact-list">
                        <div><dt>Sotuvchi</dt><dd><?= e($book['seller_name'] ?: 'Maktab kutubxonasi') ?></dd></div>
                        <div><dt>Manzil</dt><dd><?= e($book['address'] ?: 'Maktab kutubxonasi') ?></dd></div>
                        <div><dt>Telefon</dt><dd><?= e($phone ?: 'Ko‘rsatilmagan') ?></dd></div>
                    </dl>
                    <?php if ($phone !== ''): ?><div class="d-flex flex-wrap gap-2"><a class="btn btn-primary" href="tel:<?= e($phone) ?>"><i class="fa-solid fa-phone me-2"></i>Qo‘ng‘iroq</a><?php if ($whatsapp !== ''): ?><a class="btn btn-success" href="https://wa.me/<?= e($whatsapp) ?>" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-whatsapp me-2"></i>WhatsApp</a><?php endif; ?></div><?php endif; ?>
                </section>

                <section class="content-card mb-4">
                    <div class="availability-summary mb-4">
                        <div class="availability-box status-<?= e($book['availability_status']) ?> alert mb-0"><strong><?= e(library_feature_status_label($book['availability_status'])) ?></strong><span class="d-block"><?= (int) $book['free_copies'] ?> erkin / <?= (int) $book['total_copies'] ?> jami</span></div>
                        <div class="stock-metric"><strong><?= (int) $book['active_loan_count'] ?></strong><span>O‘quvchida</span></div>
                        <div class="stock-metric"><strong><?= (int) $book['held_copies'] ?></strong><span>Band</span></div>
                    </div>
                    <h2 class="h4 fw-bold">Faol berishlar</h2>
                    <div class="loan-list">
                        <?php if ($loans === []): ?><p class="text-secondary mb-0">Hozir faol berilgan nusxa yo‘q.</p><?php endif; ?>
                        <?php foreach ($loans as $loan): ?><div class="loan-row"><span class="loan-icon"><i class="fa-solid fa-book-open-reader"></i></span><div><strong>Qaytarish muddati: <?= e(library_feature_date($loan['due_date'])) ?></strong><small>Berilgan: <?= e(library_feature_date($loan['borrow_date'])) ?></small></div><span class="loan-state <?= $loan['status'] === 'overdue' ? 'overdue' : '' ?>"><?= $loan['status'] === 'overdue' ? 'Kechikkan' : 'Faol' ?></span></div><?php endforeach; ?>
                    </div>
                </section>

                <section class="content-card">
                    <h2 class="h4 fw-bold">O‘quvchilar fikri</h2>
                    <div class="review-list">
                        <?php if ($reviews === []): ?><p class="text-secondary mb-0">Hozircha fikr bildirilmagan.</p><?php endif; ?>
                        <?php foreach ($reviews as $review): ?><article class="review-item"><span class="review-avatar"><i class="fa-solid fa-user"></i></span><div><?= render_stars((float) $review['rating']) ?><p class="mb-0 mt-2"><?= nl2br(e($review['comment'])) ?></p><small class="text-secondary"><?= e(library_feature_date($review['created_at'])) ?></small></div></article><?php endforeach; ?>
                    </div>
                </section>
            </div>

            <div class="col-lg-5">
                <section class="content-card reservation-card sticky-lg-top">
                    <span class="reservation-icon"><i class="fa-solid fa-calendar-check"></i></span>
                    <h2 class="h3 fw-bold mt-3">Kitobni band qilish</h2>
                    <?php if ($book['listing_type'] === 'sale'): ?>
                        <div class="alert alert-info">Bu kitob faqat sotuvda. Sotuvchi bilan telefon yoki WhatsApp orqali bog‘laning.</div>
                    <?php elseif (!$user): ?>
                        <div class="alert alert-info">Band qilish uchun o‘quvchi hisobiga kiring.</div><a class="btn btn-primary w-100" href="<?= e(APP_URL) ?>/login.php">Kirish</a>
                    <?php elseif ($user['role'] !== 'student'): ?>
                        <div class="alert alert-warning">Bu hisobdan kitob band qilib bo‘lmaydi.</div>
                    <?php elseif (!$canReserve): ?>
                        <div class="alert alert-warning">Hozir band qilish imkoni yo‘q.</div>
                    <?php else: ?>
                        <p class="text-secondary">Profilingizdagi o‘quvchi ma’lumotlari xavfsiz ishlatiladi.</p>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label class="form-label" for="pickup_date">Olib ketish sanasi</label><input class="form-control mb-3" type="date" id="pickup_date" name="pickup_date" min="<?= e($minimum) ?>" max="<?= e($latestValue) ?>" value="<?= e($minimum) ?>" required><button class="btn btn-primary btn-lg w-100" type="submit">So‘rov yuborish</button></form>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>
</main>

<footer class="public-footer interactive-footer mt-5" data-interactive-footer>
    <div class="footer-orbit" aria-hidden="true"><i class="fa-solid fa-book"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-graduation-cap"></i></div>
    <div class="container py-5 position-relative">
        <div class="row g-4 align-items-center">
            <div class="col-lg-7"><span class="footer-mark"><i class="fa-solid fa-book-open"></i></span><h2 class="h3 text-white mt-3">Har bir sahifa — yangi imkoniyat</h2><p class="mb-0">Kitobni toping, holatini ko‘ring va o‘zingizga qulay kun uchun band qiling.</p></div>
            <div class="col-lg-5 text-lg-end"><a class="btn btn-light rounded-pill px-4" href="#mainNavigation">Yuqoriga <i class="fa-solid fa-arrow-up ms-2"></i></a></div>
        </div>
        <div class="footer-bottom mt-4 pt-4"><span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></span><span><i class="fa-solid fa-shield-heart me-1"></i> O‘quvchilar uchun xavfsiz makon</span></div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
</body>
</html>
