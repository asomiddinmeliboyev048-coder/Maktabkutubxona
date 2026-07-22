<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
$user = require_role($pdo, 'librarian');
$ownerId = (int) $user['id'];
sync_overdue_transactions($pdo, $ownerId);
library_feature_expire_reservations($pdo, $ownerId);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reservationId = filter_input(INPUT_POST, 'reservation_id', FILTER_VALIDATE_INT) ?: 0;
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) $errors[] = 'Xavfsizlik tokeni yaroqsiz.';
    if ($reservationId < 1) $errors[] = 'Band qilish so‘rovini tanlang.';

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare("SELECT r.id,r.book_id,r.student_id,r.status,b.title,b.available_copies,s.full_name
                FROM reservations r INNER JOIN books b ON b.id=r.book_id INNER JOIN students s ON s.id=r.student_id
                WHERE r.id=:id AND r.status IN ('approved','ready') AND b.user_id=:owner_id AND b.is_active=1 AND s.is_active=1 FOR UPDATE");
            $statement->execute(['id' => $reservationId, 'owner_id' => $ownerId]);
            $reservation = $statement->fetch();
            if (!$reservation) throw new RuntimeException('Faol band qilish topilmadi yoki kitob sizga tegishli emas.');
            if ((int) $reservation['available_copies'] < 1) throw new RuntimeException('Kitob javonda yo‘q.');

            $activeLoan = $pdo->prepare("SELECT bt.id FROM borrow_transactions bt INNER JOIN books b ON b.id=bt.book_id
                WHERE bt.book_id=:book_id AND bt.student_id=:student_id AND bt.status IN ('borrowed','overdue') AND bt.return_date IS NULL AND b.user_id=:owner_id LIMIT 1 FOR UPDATE");
            $activeLoan->execute(['book_id' => $reservation['book_id'], 'student_id' => $reservation['student_id'], 'owner_id' => $ownerId]);
            if ($activeLoan->fetchColumn()) throw new RuntimeException('Bu o‘quvchida ushbu kitobning faol nusxasi bor.');

            $loan = $pdo->prepare("INSERT INTO borrow_transactions (book_id,student_id,borrow_date,due_date,status) VALUES (:book_id,:student_id,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 14 DAY),'borrowed')");
            $loan->execute(['book_id' => $reservation['book_id'], 'student_id' => $reservation['student_id']]);
            $loanId = (int) $pdo->lastInsertId();
            $stock = $pdo->prepare('UPDATE books SET available_copies=available_copies-1 WHERE id=:book_id AND user_id=:owner_id AND available_copies>0');
            $stock->execute(['book_id' => $reservation['book_id'], 'owner_id' => $ownerId]);
            if ($stock->rowCount() !== 1) throw new RuntimeException('Kitob qoldig‘ini yangilab bo‘lmadi.');
            $update = $pdo->prepare("UPDATE reservations r INNER JOIN books b ON b.id=r.book_id SET r.status='collected',r.collected_at=NOW(),r.borrow_transaction_id=:loan_id WHERE r.id=:id AND r.status IN ('approved','ready') AND b.user_id=:owner_id");
            $update->execute(['loan_id' => $loanId, 'id' => $reservationId, 'owner_id' => $ownerId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('Band qilish holatini yangilab bo‘lmadi.');
            $pdo->commit();
            set_flash('success', '“' . htmlspecialchars_decode((string) $reservation['title'], ENT_QUOTES) . '” kitobi ' . htmlspecialchars_decode((string) $reservation['full_name'], ENT_QUOTES) . 'ga berildi.');
            redirect('vendor/issue-book.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Kitobni berib bo‘lmadi.';
        }
    }
}

$statement = $pdo->prepare("SELECT r.id,r.pickup_date,r.status,b.title,b.author,b.available_copies,s.full_name,s.class_name,s.student_code
    FROM reservations r INNER JOIN books b ON b.id=r.book_id INNER JOIN students s ON s.id=r.student_id
    WHERE b.user_id=:owner_id AND b.is_active=1 AND s.is_active=1 AND r.status IN ('approved','ready')
    ORDER BY FIELD(r.status,'ready','approved'),r.pickup_date,r.id");
$statement->execute(['owner_id' => $ownerId]);
$reservations = $statement->fetchAll();
$activeStatement = $pdo->prepare("SELECT bt.id,bt.due_date,bt.status,b.title,s.full_name FROM borrow_transactions bt INNER JOIN books b ON b.id=bt.book_id INNER JOIN students s ON s.id=bt.student_id WHERE b.user_id=:owner_id AND bt.status IN ('borrowed','overdue') AND bt.return_date IS NULL ORDER BY bt.due_date LIMIT 10");
$activeStatement->execute(['owner_id' => $ownerId]);
$activeLoans = $activeStatement->fetchAll();
$flash = get_flash();
?>
<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kitob berish — <?= e(APP_NAME) ?></title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css"></head><body class="admin-body"><div class="admin-layout"><aside class="admin-sidebar"><a class="admin-brand" href="<?= e(APP_URL) ?>/vendor/index.php"><span><i class="fa-solid fa-store"></i></span><div><strong>Marketplace</strong><small>Sotuvchi paneli</small></div></a><?= library_feature_vendor_nav('issue') ?></aside><main class="admin-main"><header class="admin-topbar"><button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menyuni ochish"><i class="fa-solid fa-bars"></i></button><div><p class="admin-eyebrow">Faqat faol band qilishlar</p><h1>Kitob berish</h1></div><a class="btn btn-outline-light" href="<?= e(APP_URL) ?>/vendor/reservations.php">Band qilishlar</a></header>
<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"><?= e($flash['message']) ?><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button></div><?php endif; ?><?php if ($errors): ?><div class="alert alert-danger alert-dismissible fade show"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button></div><?php endif; ?>
<div class="row g-4"><div class="col-xl-6"><section class="admin-panel"><div class="panel-heading"><div><p class="admin-eyebrow">Tasdiqlangan o‘quvchilar</p><h2 class="h4">Band qilingan kitobni berish</h2></div></div><div class="alert alert-info">Maxfiylik uchun faqat sizning kitobingizga faol band qilishi mavjud o‘quvchilar ko‘rsatiladi.</div><form class="admin-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label class="form-label" for="reservation_id">Band qilish</label><select class="form-select form-select-lg mb-3" id="reservation_id" name="reservation_id" required><option value="">Tanlang</option><?php foreach ($reservations as $reservation): ?><option value="<?= (int) $reservation['id'] ?>"><?= e($reservation['full_name'] . ' — ' . $reservation['title'] . ' (' . library_feature_reservation_label($reservation['status']) . ')') ?></option><?php endforeach; ?></select><button class="btn btn-success btn-lg w-100" type="submit" <?= $reservations === [] ? 'disabled' : '' ?>><i class="fa-solid fa-arrow-up-right-from-square me-2"></i>14 kunga berish</button></form></section></div><div class="col-xl-6"><section class="admin-panel"><div class="panel-heading"><div><p class="admin-eyebrow">Nazorat</p><h2 class="h4">Faol berishlar</h2></div><span class="dark-badge"><?= count($activeLoans) ?> ta</span></div><div class="table-responsive"><table class="table admin-table"><thead><tr><th>O‘quvchi</th><th>Kitob</th><th>Muddat</th></tr></thead><tbody><?php if ($activeLoans === []): ?><tr><td colspan="3" class="text-center py-4 text-secondary">Faol berish yo‘q.</td></tr><?php endif; ?><?php foreach ($activeLoans as $loan): ?><tr><td><?= e($loan['full_name']) ?></td><td><?= e($loan['title']) ?></td><td><?= e(library_feature_date($loan['due_date'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section></div></div>
</main></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(APP_URL) ?>/assets/js/app.js"></script></body></html>
