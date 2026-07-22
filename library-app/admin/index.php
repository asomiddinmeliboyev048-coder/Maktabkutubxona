<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

sync_overdue_transactions($pdo);
library_feature_expire_reservations($pdo);

$statsStatement = $pdo->prepare(
    "SELECT
        (SELECT COALESCE(SUM(total_copies), 0) FROM books WHERE is_active=1) AS total_books,
        (SELECT COUNT(*) FROM books WHERE is_active=1) AS total_titles,
        (SELECT COUNT(*) FROM students WHERE is_active=1) AS total_students,
        (SELECT COUNT(*) FROM reservations WHERE status='pending') AS pending_reservations,
        (SELECT COUNT(*) FROM borrow_transactions WHERE status IN ('borrowed', 'overdue')) AS active_loans,
        (SELECT COUNT(*) FROM borrow_transactions WHERE status = 'overdue') AS overdue_loans"
);
$statsStatement->execute();
$stats = $statsStatement->fetch();

$recentStatement = $pdo->prepare(
    "SELECT
        bt.id,
        bt.borrow_date,
        bt.due_date,
        bt.return_date,
        bt.status,
        b.title,
        s.full_name,
        s.class_name
     FROM borrow_transactions bt
     INNER JOIN books b ON b.id = bt.book_id
     INNER JOIN students s ON s.id = bt.student_id
     ORDER BY bt.id DESC
     LIMIT 8"
);
$recentStatement->execute();
$recentTransactions = $recentStatement->fetchAll();

$inventoryStatement = $pdo->prepare(
    "SELECT b.id, b.title, b.author, b.cover_image, b.total_copies, b.available_copies, c.name AS category_name
     FROM books b
     INNER JOIN categories c ON c.id = b.category_id
     WHERE b.is_active=1
     ORDER BY b.created_at DESC, b.title ASC
     LIMIT 8"
);
$inventoryStatement->execute();
$inventory = library_feature_enrich_books($pdo, $inventoryStatement->fetchAll());
$flash = get_flash();
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-layout">
    <aside class="admin-sidebar">
        <a class="admin-brand" href="<?= e(APP_URL) ?>/admin/index.php">
            <span><i class="fa-solid fa-book-open"></i></span>
            <div><strong>Kutubxona</strong><small>Boshqaruv paneli</small></div>
        </a>
        <?= library_feature_admin_nav('dashboard') ?>
        <div class="sidebar-footer"><i class="fa-solid fa-shield-halved"></i><span>Xavfsiz PDO tizimi<small><?= date('Y') ?> versiya</small></span></div>
    </aside>

    <main class="admin-main">
        <header class="admin-topbar">
            <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menyuni ochish"><i class="fa-solid fa-bars"></i></button>
            <div><p class="admin-eyebrow">Umumiy ko‘rinish</p><h1>Dashboard</h1></div>
            <div class="admin-profile"><span><i class="fa-solid fa-user-shield"></i></span><div><strong>Kutubxonachi</strong><small>Administrator</small></div></div>
        </header>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= e($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Yopish"></button>
            </div>
        <?php endif; ?>

        <section class="row g-4 mb-4" aria-label="Kutubxona statistikasi">
            <div class="col-sm-6 col-xl-3">
                <article class="stat-card stat-blue"><div class="stat-icon"><i class="fa-solid fa-book"></i></div><div><p>Jami kitoblar</p><h2><?= (int) $stats['total_books'] ?></h2><small><?= (int) $stats['total_titles'] ?> ta nom</small></div></article>
            </div>
            <div class="col-sm-6 col-xl-3">
                <article class="stat-card stat-violet"><div class="stat-icon"><i class="fa-solid fa-users"></i></div><div><p>O‘quvchilar</p><h2><?= (int) $stats['total_students'] ?></h2><small>Ro‘yxatga olingan</small></div></article>
            </div>
            <div class="col-sm-6 col-xl-3">
                <article class="stat-card stat-green"><div class="stat-icon"><i class="fa-solid fa-book-open-reader"></i></div><div><p>O‘quvchilarda</p><h2><?= (int) $stats['active_loans'] ?></h2><small>Faol biriktirish</small></div></article>
            </div>
            <div class="col-sm-6 col-xl-3">
                <article class="stat-card stat-red"><div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div><div><p>Muddati o‘tgan</p><h2><?= (int) $stats['overdue_loans'] ?></h2><small>Qarzdor kitoblar</small></div></article>
            </div>
        </section>

        <section class="quick-actions mb-4">
            <div><p class="admin-eyebrow">Tezkor amallar</p><h2 class="h4 mb-0">Bugun nima qilamiz?</h2></div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-primary" href="<?= e(APP_URL) ?>/admin/add-book.php"><i class="fa-solid fa-plus me-2"></i>Yangi kitob</a>
                <a class="btn btn-outline-light" href="<?= e(APP_URL) ?>/admin/books.php"><i class="fa-solid fa-book me-2"></i>Kitoblar</a>
                <a class="btn btn-outline-light" href="<?= e(APP_URL) ?>/admin/students.php"><i class="fa-solid fa-users me-2"></i>O‘quvchilar</a>
                <a class="btn btn-outline-light" href="<?= e(APP_URL) ?>/admin/reservations.php"><i class="fa-solid fa-calendar-check me-2"></i>So‘rovlar (<?= (int) $stats['pending_reservations'] ?>)</a>
                <a class="btn btn-success" href="<?= e(APP_URL) ?>/admin/issue-book.php"><i class="fa-solid fa-arrow-right me-2"></i>Kitob berish</a>
                <a class="btn btn-outline-light" href="<?= e(APP_URL) ?>/admin/return-book.php"><i class="fa-solid fa-rotate-left me-2"></i>Qaytarib olish</a>
            </div>
        </section>

        <div class="row g-4">
            <div class="col-xl-7">
                <section class="admin-panel h-100">
                    <div class="panel-heading"><div><p class="admin-eyebrow">Inventar</p><h2 class="h4">So‘nggi kitoblar</h2></div><a href="<?= e(APP_URL) ?>/admin/add-book.php">Qo‘shish <i class="fa-solid fa-arrow-right ms-1"></i></a></div>
                    <div class="table-responsive">
                        <table class="table admin-table align-middle">
                            <thead><tr><th>Kitob</th><th>Kategoriya</th><th class="text-center">Mavjud</th></tr></thead>
                            <tbody>
                            <?php foreach ($inventory as $book): ?>
                                <tr>
                                    <td><div class="book-cell"><img src="<?= e(book_cover_url($book['cover_image'])) ?>" alt=""><div><strong><?= e($book['title']) ?></strong><small><?= e($book['author']) ?></small></div></div></td>
                                    <td><span class="dark-badge"><?= e($book['category_name']) ?></span></td>
                                    <td class="text-center"><span class="stock-count <?= (int) $book['free_copies'] > 0 ? 'in-stock' : 'out-stock' ?>"><?= (int) $book['free_copies'] ?>/<?= (int) $book['total_copies'] ?> erkin</span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            <div class="col-xl-5">
                <section class="admin-panel h-100">
                    <div class="panel-heading"><div><p class="admin-eyebrow">Faollik</p><h2 class="h4">So‘nggi operatsiyalar</h2></div></div>
                    <div class="transaction-list">
                        <?php if ($recentTransactions === []): ?>
                            <p class="empty-admin">Hozircha operatsiyalar yo‘q.</p>
                        <?php endif; ?>
                        <?php foreach ($recentTransactions as $transaction): ?>
                            <?php
                            $statusLabels = ['borrowed' => 'Berilgan', 'returned' => 'Qaytarilgan', 'overdue' => 'Muddati o‘tgan'];
                            $statusIcons = ['borrowed' => 'fa-arrow-up-right-from-square', 'returned' => 'fa-check', 'overdue' => 'fa-clock'];
                            ?>
                            <article class="transaction-item">
                                <span class="transaction-icon status-<?= e($transaction['status']) ?>"><i class="fa-solid <?= e($statusIcons[$transaction['status']]) ?>"></i></span>
                                <div class="flex-grow-1"><strong><?= e($transaction['title']) ?></strong><small><?= e($transaction['full_name']) ?> · <?= e($transaction['class_name']) ?></small></div>
                                <span class="status-text status-<?= e($transaction['status']) ?>"><?= e($statusLabels[$transaction['status']]) ?></span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
</body>
</html>
