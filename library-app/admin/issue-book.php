<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
$user = require_role($pdo, 'admin');
$isAdmin = true;
$ownerClause = $isAdmin ? '' : ' AND b.user_id = :user_id';
$ownerParams = $isAdmin ? [] : ['user_id' => $user['id']];

sync_overdue_transactions($pdo, $isAdmin ? null : (int) $user['id']);
library_feature_expire_reservations($pdo, $isAdmin ? null : (int) $user['id']);

$errors = [];
$formData = [
    'student_id' => 0,
    'book_id' => 0,
    'borrow_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+14 days')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['student_id'] = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT) ?: 0;
    $formData['book_id'] = filter_input(INPUT_POST, 'book_id', FILTER_VALIDATE_INT) ?: 0;
    $formData['borrow_date'] = sanitize_input($_POST['borrow_date'] ?? '');
    $formData['due_date'] = sanitize_input($_POST['due_date'] ?? '');

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Xavfsizlik tokeni yaroqsiz. Sahifani yangilang va qayta urinib ko‘ring.';
    }
    if ($formData['student_id'] < 1) {
        $errors[] = 'O‘quvchini tanlang.';
    }
    if ($formData['book_id'] < 1) {
        $errors[] = 'Kitobni tanlang.';
    }

    $borrowDate = DateTimeImmutable::createFromFormat('!Y-m-d', $formData['borrow_date']);
    $dueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $formData['due_date']);
    $borrowIsValid = $borrowDate && $borrowDate->format('Y-m-d') === $formData['borrow_date'];
    $dueIsValid = $dueDate && $dueDate->format('Y-m-d') === $formData['due_date'];

    if (!$borrowIsValid || !$dueIsValid) {
        $errors[] = 'Berilgan va qaytarish sanalarini to‘g‘ri kiriting.';
    } elseif ($dueDate < $borrowDate) {
        $errors[] = 'Qaytarish muddati kitob berilgan sanadan oldin bo‘lishi mumkin emas.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            $studentStatement = $pdo->prepare(
                "SELECT s.id, s.full_name FROM students s
                 WHERE s.id = :student_id AND s.is_active = 1
                   AND (:is_admin = 1 OR EXISTS (
                       SELECT 1 FROM reservations linked_reservation
                       INNER JOIN books owned_book ON owned_book.id = linked_reservation.book_id
                       WHERE linked_reservation.student_id = s.id AND owned_book.user_id = :user_id
                   ))
                 FOR UPDATE"
            );
            $studentStatement->execute(['student_id' => $formData['student_id'], 'is_admin' => $isAdmin ? 1 : 0, 'user_id' => $user['id']]);
            $student = $studentStatement->fetch();
            if (!$student) {
                throw new RuntimeException('Tanlangan o‘quvchi topilmadi.');
            }

            $bookStatement = $pdo->prepare(
                "SELECT b.id, b.title, b.available_copies,
                        (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status IN ('approved','ready')) AS held_copies
                 FROM books b WHERE b.id = :book_id AND (:is_admin = 1 OR b.user_id = :user_id) AND b.is_active = 1 AND b.listing_type IN ('rental','both') FOR UPDATE"
            );
            $bookStatement->execute(['book_id' => $formData['book_id'], 'is_admin' => $isAdmin ? 1 : 0, 'user_id' => $user['id']]);
            $book = $bookStatement->fetch();
            if (!$book) {
                throw new RuntimeException('Tanlangan kitob topilmadi.');
            }
            if ((int) $book['available_copies'] - (int) $book['held_copies'] < 1) {
                throw new RuntimeException('Erkin nusxa qolmagan; javondagi nusxalar tasdiqlangan band qilishlar uchun ajratilgan.');
            }

            $reservationStatement = $pdo->prepare(
                "SELECT id, status FROM reservations
                 WHERE book_id = :book_id AND student_id = :student_id
                   AND status IN ('pending', 'approved', 'ready')
                 LIMIT 1 FOR UPDATE"
            );
            $reservationStatement->execute([
                'book_id' => $formData['book_id'],
                'student_id' => $formData['student_id'],
            ]);
            if ($reservationStatement->fetch()) {
                throw new RuntimeException('Bu o‘quvchining shu kitob uchun faol so‘rovi bor. Uni “Band qilishlar” sahifasidagi jarayon orqali bering.');
            }

            $activeLoanStatement = $pdo->prepare(
                "SELECT id FROM borrow_transactions
                 WHERE book_id = :book_id AND student_id = :student_id
                   AND status IN ('borrowed', 'overdue') AND return_date IS NULL
                 LIMIT 1 FOR UPDATE"
            );
            $activeLoanStatement->execute([
                'book_id' => $formData['book_id'],
                'student_id' => $formData['student_id'],
            ]);
            if ($activeLoanStatement->fetchColumn()) {
                throw new RuntimeException('Bu o‘quvchida ushbu kitobning faol nusxasi allaqachon mavjud.');
            }

            $transactionStatement = $pdo->prepare(
                "INSERT INTO borrow_transactions (book_id, student_id, borrow_date, due_date, status)
                 VALUES (:book_id, :student_id, :borrow_date, :due_date, 'borrowed')"
            );
            $transactionStatement->execute([
                'book_id' => $formData['book_id'],
                'student_id' => $formData['student_id'],
                'borrow_date' => $formData['borrow_date'],
                'due_date' => $formData['due_date'],
            ]);

            $stockStatement = $pdo->prepare(
                'UPDATE books SET available_copies = available_copies - 1 WHERE id = :book_id AND (:is_admin = 1 OR user_id = :user_id) AND available_copies > 0'
            );
            $stockStatement->execute(['book_id' => $formData['book_id'], 'is_admin' => $isAdmin ? 1 : 0, 'user_id' => $user['id']]);
            if ($stockStatement->rowCount() !== 1) {
                throw new RuntimeException('Kitob qoldig‘ini yangilab bo‘lmadi.');
            }

            $pdo->commit();
            set_flash('success', '“' . htmlspecialchars_decode($book['title'], ENT_QUOTES) . '” kitobi ' . htmlspecialchars_decode($student['full_name'], ENT_QUOTES) . 'ga muvaffaqiyatli berildi.');
            redirect('admin/issue-book.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Kitobni berishda tizim xatosi yuz berdi.';
        }
    }
}

$studentStatement = $pdo->prepare(
    "SELECT s.id, s.full_name, s.class_name, s.student_code
     FROM students s
     WHERE s.is_active = 1
       AND (:is_admin = 1 OR EXISTS (
           SELECT 1 FROM reservations r
           INNER JOIN books owned_book ON owned_book.id = r.book_id
           WHERE r.student_id = s.id
             AND r.status IN ('pending','approved','ready')
             AND owned_book.user_id = :user_id
       ))
     ORDER BY s.full_name ASC"
);
$studentStatement->execute(['is_admin' => $isAdmin ? 1 : 0, 'user_id' => $user['id']]);
$students = $studentStatement->fetchAll();

$availableBookStatement = $pdo->prepare(
    "SELECT b.id, b.title, b.author, b.available_copies, b.total_copies,
            b.available_copies - (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status IN ('approved','ready')) AS free_copies
     FROM books b
     WHERE (:is_admin = 1 OR b.user_id=:user_id) AND b.is_active=1 AND b.listing_type IN ('rental','both') AND b.available_copies > (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status IN ('approved','ready'))
     ORDER BY b.title ASC"
);
$availableBookStatement->execute(['is_admin' => $isAdmin ? 1 : 0, 'user_id' => $user['id']]);
$availableBooks = $availableBookStatement->fetchAll();

$activeStatement = $pdo->prepare(
    "SELECT bt.id, bt.borrow_date, bt.due_date, bt.status, b.title, s.full_name, s.class_name
     FROM borrow_transactions bt
     INNER JOIN books b ON b.id = bt.book_id
     INNER JOIN students s ON s.id = bt.student_id
     WHERE bt.status IN ('borrowed', 'overdue') AND (:is_admin = 1 OR b.user_id=:user_id)
     ORDER BY bt.due_date ASC
     LIMIT 10"
);
$activeStatement->execute(['is_admin' => $isAdmin ? 1 : 0, 'user_id' => $user['id']]);
$activeLoans = $activeStatement->fetchAll();
$flash = get_flash();
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kitob berish — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-layout">
    <aside class="admin-sidebar">
        <a class="admin-brand" href="<?= e(APP_URL) ?>/admin/index.php"><span><i class="fa-solid fa-book-open"></i></span><div><strong>Kutubxona</strong><small>Boshqaruv paneli</small></div></a>
        <?= library_feature_admin_nav('issue') ?>
    </aside>
    <main class="admin-main">
        <header class="admin-topbar"><button class="sidebar-toggle" type="button" data-sidebar-toggle><i class="fa-solid fa-bars"></i></button><div><p class="admin-eyebrow">Biriktirish operatsiyasi</p><h1>Kitob berish</h1></div><a class="btn btn-outline-light" href="<?= e(APP_URL) ?>/admin/index.php"><i class="fa-solid fa-arrow-left me-2"></i>Dashboard</a></header>

        <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"><?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <div class="row g-4">
            <div class="col-xl-5">
                <section class="admin-panel">
                    <div class="panel-heading"><div><p class="admin-eyebrow">Yangi operatsiya</p><h2 class="h4">O‘quvchiga kitob biriktirish</h2></div><span class="panel-icon"><i class="fa-solid fa-hand-holding-heart"></i></span></div>
                    <?php if ($students === [] || $availableBooks === []): ?>
                        <div class="alert alert-warning">Kitob berish uchun kamida bitta o‘quvchi va mavjud kitob bo‘lishi kerak.</div>
                    <?php endif; ?>
                    <form method="post" action="<?= e(APP_URL) ?>/admin/issue-book.php" class="admin-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <div class="mb-4"><label class="form-label" for="student_id">O‘quvchi</label><select class="form-select form-select-lg" id="student_id" name="student_id" required><option value="">O‘quvchini tanlang</option><?php foreach ($students as $student): ?><option value="<?= (int) $student['id'] ?>" <?= (int) $formData['student_id'] === (int) $student['id'] ? 'selected' : '' ?>><?= e($student['full_name']) ?> — <?= e($student['class_name']) ?> (<?= e($student['student_code']) ?>)</option><?php endforeach; ?></select></div>
                        <div class="mb-4"><label class="form-label" for="book_id">Mavjud kitob</label><select class="form-select form-select-lg" id="book_id" name="book_id" required><option value="">Kitobni tanlang</option><?php foreach ($availableBooks as $book): ?><option value="<?= (int) $book['id'] ?>" <?= (int) $formData['book_id'] === (int) $book['id'] ? 'selected' : '' ?>><?= e($book['title']) ?> — <?= e($book['author']) ?> (<?= (int) $book['free_copies'] ?>/<?= (int) $book['total_copies'] ?> erkin)</option><?php endforeach; ?></select></div>
                        <div class="row g-3 mb-4"><div class="col-sm-6"><label class="form-label" for="borrow_date">Berilgan sana</label><input type="date" class="form-control form-control-lg" id="borrow_date" name="borrow_date" value="<?= e($formData['borrow_date']) ?>" required></div><div class="col-sm-6"><label class="form-label" for="due_date">Qaytarish muddati</label><input type="date" class="form-control form-control-lg" id="due_date" name="due_date" value="<?= e($formData['due_date']) ?>" required></div></div>
                        <button class="btn btn-success btn-lg w-100" type="submit" <?= $students === [] || $availableBooks === [] ? 'disabled' : '' ?>><i class="fa-solid fa-arrow-up-right-from-square me-2"></i>Kitobni berish</button>
                    </form>
                </section>
            </div>
            <div class="col-xl-7">
                <section class="admin-panel">
                    <div class="panel-heading"><div><p class="admin-eyebrow">Nazorat</p><h2 class="h4">Faol biriktirishlar</h2></div><span class="dark-badge"><?= count($activeLoans) ?> ta</span></div>
                    <div class="table-responsive"><table class="table admin-table align-middle"><thead><tr><th>O‘quvchi</th><th>Kitob</th><th>Muddat</th><th>Holat</th></tr></thead><tbody>
                    <?php if ($activeLoans === []): ?><tr><td colspan="4" class="text-center py-5 text-secondary">Faol biriktirishlar yo‘q.</td></tr><?php endif; ?>
                    <?php foreach ($activeLoans as $loan): ?><tr><td><strong><?= e($loan['full_name']) ?></strong><small class="d-block"><?= e($loan['class_name']) ?></small></td><td><?= e($loan['title']) ?></td><td><?= date('d.m.Y', strtotime($loan['due_date'])) ?></td><td><span class="status-pill status-<?= e($loan['status']) ?>"><?= $loan['status'] === 'overdue' ? 'Muddati o‘tgan' : 'Berilgan' ?></span></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </section>
            </div>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
</body>
</html>
