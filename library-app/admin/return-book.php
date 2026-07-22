<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

sync_overdue_transactions($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionId = filter_input(INPUT_POST, 'transaction_id', FILTER_VALIDATE_INT) ?: 0;

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Xavfsizlik tokeni yaroqsiz. Sahifani yangilang va qayta urinib ko‘ring.';
    }
    if ($transactionId < 1) {
        $errors[] = 'Qaytariladigan operatsiya noto‘g‘ri tanlangan.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            $transactionStatement = $pdo->prepare(
                "SELECT bt.id, bt.book_id, bt.status, b.title, s.full_name
                 FROM borrow_transactions bt
                 INNER JOIN books b ON b.id = bt.book_id
                 INNER JOIN students s ON s.id = bt.student_id
                 WHERE bt.id = :transaction_id
                 FOR UPDATE"
            );
            $transactionStatement->execute(['transaction_id' => $transactionId]);
            $transaction = $transactionStatement->fetch();

            if (!$transaction) {
                throw new RuntimeException('Operatsiya topilmadi.');
            }
            if ($transaction['status'] === 'returned') {
                throw new RuntimeException('Bu kitob avval qaytarib olingan.');
            }

            $returnStatement = $pdo->prepare(
                "UPDATE borrow_transactions
                 SET return_date = CURDATE(), status = 'returned'
                 WHERE id = :transaction_id AND status IN ('borrowed', 'overdue')"
            );
            $returnStatement->execute(['transaction_id' => $transactionId]);
            if ($returnStatement->rowCount() !== 1) {
                throw new RuntimeException('Qaytarish holatini yangilab bo‘lmadi.');
            }

            $stockStatement = $pdo->prepare(
                'UPDATE books SET available_copies = LEAST(available_copies + 1, total_copies) WHERE id = :book_id'
            );
            $stockStatement->execute(['book_id' => $transaction['book_id']]);
            if ($stockStatement->rowCount() !== 1) {
                throw new RuntimeException('Kitob qoldig‘ini yangilab bo‘lmadi.');
            }

            $pdo->commit();
            set_flash('success', '“' . htmlspecialchars_decode($transaction['title'], ENT_QUOTES) . '” kitobi ' . htmlspecialchars_decode($transaction['full_name'], ENT_QUOTES) . 'dan qaytarib olindi.');
            redirect('admin/return-book.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Kitobni qaytarishda tizim xatosi yuz berdi.';
        }
    }
}

$loanStatement = $pdo->prepare(
    "SELECT
        bt.id,
        bt.borrow_date,
        bt.due_date,
        bt.status,
        DATEDIFF(CURDATE(), bt.due_date) AS overdue_days,
        b.title,
        b.author,
        b.cover_image,
        s.full_name,
        s.class_name,
        s.student_code
     FROM borrow_transactions bt
     INNER JOIN books b ON b.id = bt.book_id
     INNER JOIN students s ON s.id = bt.student_id
     WHERE bt.status IN ('borrowed', 'overdue')
     ORDER BY CASE WHEN bt.status = 'overdue' THEN 0 ELSE 1 END, bt.due_date ASC"
);
$loanStatement->execute();
$activeLoans = $loanStatement->fetchAll();
$flash = get_flash();
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kitobni qaytarish — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-layout">
    <aside class="admin-sidebar">
        <a class="admin-brand" href="<?= e(APP_URL) ?>/admin/index.php"><span><i class="fa-solid fa-book-open"></i></span><div><strong>Kutubxona</strong><small>Boshqaruv paneli</small></div></a>
        <?= library_feature_admin_nav('return') ?>
    </aside>
    <main class="admin-main">
        <header class="admin-topbar"><button class="sidebar-toggle" type="button" data-sidebar-toggle><i class="fa-solid fa-bars"></i></button><div><p class="admin-eyebrow">Qabul qilish operatsiyasi</p><h1>Kitobni qaytarib olish</h1></div><span class="active-counter"><i class="fa-solid fa-book-open-reader"></i><?= count($activeLoans) ?> ta o‘quvchida</span></header>

        <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"><?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <section class="admin-panel">
            <div class="panel-heading"><div><p class="admin-eyebrow">Faol ro‘yxat</p><h2 class="h4">O‘quvchilardagi kitoblar</h2></div><div class="return-legend"><span><i class="legend-dot borrowed"></i>Muddatida</span><span><i class="legend-dot overdue"></i>Muddati o‘tgan</span></div></div>
            <?php if ($activeLoans === []): ?>
                <div class="empty-admin-large"><i class="fa-solid fa-circle-check"></i><h3>Barcha kitoblar joyida</h3><p>Hozirda o‘quvchilarga biriktirilgan kitob mavjud emas.</p><a class="btn btn-success" href="<?= e(APP_URL) ?>/admin/issue-book.php">Kitob berish</a></div>
            <?php else: ?>
                <div class="return-grid">
                    <?php foreach ($activeLoans as $loan): ?>
                        <article class="return-card <?= $loan['status'] === 'overdue' ? 'is-overdue' : '' ?>">
                            <img src="<?= e(book_cover_url($loan['cover_image'])) ?>" alt="<?= e($loan['title']) ?> muqovasi">
                            <div class="return-card-content">
                                <div class="d-flex justify-content-between gap-2"><span class="status-pill status-<?= e($loan['status']) ?>"><?= $loan['status'] === 'overdue' ? 'Muddati o‘tgan' : 'Berilgan' ?></span><small>#<?= (int) $loan['id'] ?></small></div>
                                <h3><?= e($loan['title']) ?></h3><p class="book-meta"><?= e($loan['author']) ?></p>
                                <div class="student-block"><span><i class="fa-solid fa-user-graduate"></i></span><div><strong><?= e($loan['full_name']) ?></strong><small><?= e($loan['class_name']) ?> · <?= e($loan['student_code']) ?></small></div></div>
                                <div class="date-row"><span><small>Berilgan</small><strong><?= date('d.m.Y', strtotime($loan['borrow_date'])) ?></strong></span><i class="fa-solid fa-arrow-right"></i><span><small>Muddat</small><strong><?= date('d.m.Y', strtotime($loan['due_date'])) ?></strong></span></div>
                                <?php if ($loan['status'] === 'overdue'): ?><p class="overdue-note"><i class="fa-solid fa-triangle-exclamation me-2"></i><?= max(1, (int) $loan['overdue_days']) ?> kun kechikkan</p><?php endif; ?>
                                <form method="post" action="<?= e(APP_URL) ?>/admin/return-book.php" data-confirm-return="<?= e($loan['title']) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="transaction_id" value="<?= (int) $loan['id'] ?>">
                                    <button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-rotate-left me-2"></i>Qaytarib olindi</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
</body>
</html>
