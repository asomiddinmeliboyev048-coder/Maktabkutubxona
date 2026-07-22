<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
$user = require_role($pdo, 'librarian');
$ownerId = (int) $user['id'];
sync_overdue_transactions($pdo, $ownerId);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionId = filter_input(INPUT_POST, 'transaction_id', FILTER_VALIDATE_INT) ?: 0;
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) $errors[] = 'Xavfsizlik tokeni yaroqsiz.';
    if ($transactionId < 1) $errors[] = 'Qaytariladigan yozuvni tanlang.';
    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare("SELECT bt.id,bt.book_id,bt.status,b.title,s.full_name FROM borrow_transactions bt INNER JOIN books b ON b.id=bt.book_id INNER JOIN students s ON s.id=bt.student_id WHERE bt.id=:id AND b.user_id=:owner_id FOR UPDATE");
            $statement->execute(['id' => $transactionId, 'owner_id' => $ownerId]);
            $loan = $statement->fetch();
            if (!$loan) throw new RuntimeException('Berish yozuvi topilmadi yoki sizga tegishli emas.');
            if (!in_array($loan['status'], ['borrowed', 'overdue'], true)) throw new RuntimeException('Bu kitob avval qaytarilgan.');
            $return = $pdo->prepare("UPDATE borrow_transactions bt INNER JOIN books b ON b.id=bt.book_id SET bt.return_date=CURDATE(),bt.status='returned' WHERE bt.id=:id AND bt.status IN ('borrowed','overdue') AND b.user_id=:owner_id");
            $return->execute(['id' => $transactionId, 'owner_id' => $ownerId]);
            if ($return->rowCount() !== 1) throw new RuntimeException('Qaytarish holatini yangilab bo‘lmadi.');
            $stock = $pdo->prepare('UPDATE books SET available_copies=LEAST(available_copies+1,total_copies) WHERE id=:book_id AND user_id=:owner_id');
            $stock->execute(['book_id' => $loan['book_id'], 'owner_id' => $ownerId]);
            if ($stock->rowCount() !== 1) throw new RuntimeException('Kitob qoldig‘ini yangilab bo‘lmadi.');
            $pdo->commit();
            set_flash('success', '“' . htmlspecialchars_decode((string) $loan['title'], ENT_QUOTES) . '” kitobi qaytarib olindi.');
            redirect('vendor/return-book.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Kitobni qaytarib bo‘lmadi.';
        }
    }
}

$statement = $pdo->prepare("SELECT bt.id,bt.borrow_date,bt.due_date,bt.status,DATEDIFF(CURDATE(),bt.due_date) overdue_days,b.title,b.author,b.cover_image,s.full_name,s.class_name,s.student_code FROM borrow_transactions bt INNER JOIN books b ON b.id=bt.book_id INNER JOIN students s ON s.id=bt.student_id WHERE b.user_id=:owner_id AND bt.status IN ('borrowed','overdue') AND bt.return_date IS NULL ORDER BY CASE WHEN bt.status='overdue' THEN 0 ELSE 1 END,bt.due_date");
$statement->execute(['owner_id' => $ownerId]);
$activeLoans = $statement->fetchAll();
$flash = get_flash();
?>
<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kitobni qaytarish — <?= e(APP_NAME) ?></title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css"></head><body class="admin-body"><div class="admin-layout"><aside class="admin-sidebar"><a class="admin-brand" href="<?= e(APP_URL) ?>/vendor/index.php"><span><i class="fa-solid fa-store"></i></span><div><strong>Marketplace</strong><small>Sotuvchi paneli</small></div></a><?= library_feature_vendor_nav('return') ?></aside><main class="admin-main"><header class="admin-topbar"><button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menyuni ochish"><i class="fa-solid fa-bars"></i></button><div><p class="admin-eyebrow">Mening kitoblarim</p><h1>Kitobni qaytarib olish</h1></div><span class="active-counter"><?= count($activeLoans) ?> ta</span></header>
<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"><?= e($flash['message']) ?><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button></div><?php endif; ?><?php if ($errors): ?><div class="alert alert-danger alert-dismissible fade show"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button></div><?php endif; ?>
<section class="admin-panel"><?php if ($activeLoans === []): ?><div class="empty-admin-large"><i class="fa-solid fa-circle-check"></i><h3>Barcha kitoblar joyida</h3><p>Faol berish mavjud emas.</p></div><?php else: ?><div class="return-grid"><?php foreach ($activeLoans as $loan): ?><article class="return-card <?= $loan['status'] === 'overdue' ? 'is-overdue' : '' ?>"><img src="<?= e(book_cover_url($loan['cover_image'])) ?>" alt="<?= e($loan['title']) ?> muqovasi"><div class="return-card-content"><span class="status-pill status-<?= e($loan['status']) ?>"><?= $loan['status'] === 'overdue' ? 'Muddati o‘tgan' : 'Berilgan' ?></span><h3><?= e($loan['title']) ?></h3><p class="book-meta"><?= e($loan['author']) ?></p><div class="student-block"><span><i class="fa-solid fa-user-graduate"></i></span><div><strong><?= e($loan['full_name']) ?></strong><small><?= e($loan['class_name']) ?> · <?= e($loan['student_code']) ?></small></div></div><div class="date-row"><span><small>Berilgan</small><strong><?= e(library_feature_date($loan['borrow_date'])) ?></strong></span><i class="fa-solid fa-arrow-right"></i><span><small>Muddat</small><strong><?= e(library_feature_date($loan['due_date'])) ?></strong></span></div><?php if ($loan['status'] === 'overdue'): ?><p class="overdue-note"><?= max(1, (int) $loan['overdue_days']) ?> kun kechikkan</p><?php endif; ?><form method="post" data-confirm-return="<?= e($loan['title']) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="transaction_id" value="<?= (int) $loan['id'] ?>"><button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-rotate-left me-2"></i>Qaytarib olindi</button></form></div></article><?php endforeach; ?></div><?php endif; ?></section>
</main></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(APP_URL) ?>/assets/js/app.js"></script></body></html>
