<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
$user = require_role($pdo, 'admin');

sync_overdue_transactions($pdo);
library_feature_expire_reservations($pdo);

$statistics = [
    'books' => (int) $pdo->query('SELECT COUNT(*) FROM books WHERE is_active = 1')->fetchColumn(),
    'students' => (int) $pdo->query('SELECT COUNT(*) FROM students WHERE is_active = 1')->fetchColumn(),
    'active_loans' => (int) $pdo->query("SELECT COUNT(*) FROM borrow_transactions WHERE status IN ('borrowed', 'overdue') AND return_date IS NULL")->fetchColumn(),
    'pending_reservations' => (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn(),
];

$recentStatement = $pdo->prepare(
    "SELECT bt.borrow_date, bt.due_date, bt.status, b.title, s.full_name
     FROM borrow_transactions bt
     INNER JOIN books b ON b.id = bt.book_id
     INNER JOIN students s ON s.id = bt.student_id
     ORDER BY bt.created_at DESC, bt.id DESC
     LIMIT 8"
);
$recentStatement->execute();
$recentTransactions = $recentStatement->fetchAll();

$lowStockStatement = $pdo->prepare(
    'SELECT id, title, author, available_copies, total_copies
     FROM books
     WHERE is_active = 1 AND available_copies <= 1
     ORDER BY available_copies ASC, title ASC
     LIMIT 8'
);
$lowStockStatement->execute();
$lowStockBooks = $lowStockStatement->fetchAll();
$flash = get_flash();
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Maktab kutubxonasi Super Admin boshqaruv paneli">
    <title>Super Admin — <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-layout">
    <aside class="admin-sidebar">
        <a class="admin-brand" href="<?= e(APP_URL) ?>/admin/index.php">
            <span><i class="fa-solid fa-book-open"></i></span>
            <div><strong>Kutubxona</strong><small>Super Admin paneli</small></div>
        </a>
        <?= library_feature_admin_nav('dashboard') ?>
        <div class="sidebar-footer">
            <i class="fa-solid fa-shield-halved"></i>
            <span><strong><?= e($user['first_name'] . ' ' . $user['last_name']) ?></strong><small>Super Admin</small></span>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-topbar">
            <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menyuni ochish"><i class="fa-solid fa-bars"></i></button>
            <div>
                <p class="admin-eyebrow">Umumiy nazorat</p>
                <h1>Boshqaruv paneli</h1>
            </div>
            <form method="post" action="<?= e(APP_URL) ?>/logout.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="btn btn-outline-light" type="submit"><i class="fa-solid fa-right-from-bracket me-2"></i>Chiqish</button>
            </form>
        </header>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= e($flash['message']) ?>
                <button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button>
            </div>
        <?php endif; ?>

        <section class="row g-4 mb-4" aria-label="Kutubxona statistikasi">
            <div class="col-sm-6 col-xl-3"><article class="stat-card stat-blue"><span class="stat-icon"><i class="fa-solid fa-book"></i></span><div><p>Faol kitoblar</p><h2><?= $statistics['books'] ?></h2><small>Katalogdagi yozuvlar</small></div></article></div>
            <div class="col-sm-6 col-xl-3"><article class="stat-card stat-violet"><span class="stat-icon"><i class="fa-solid fa-users"></i></span><div><p>Faol o‘quvchilar</p><h2><?= $statistics['students'] ?></h2><small>Ro‘yxatdagi a’zolar</small></div></article></div>
            <div class="col-sm-6 col-xl-3"><article class="stat-card stat-green"><span class="stat-icon"><i class="fa-solid fa-book-open-reader"></i></span><div><p>Berilgan kitoblar</p><h2><?= $statistics['active_loans'] ?></h2><small>Faol operatsiyalar</small></div></article></div>
            <div class="col-sm-6 col-xl-3"><article class="stat-card stat-red"><span class="stat-icon"><i class="fa-solid fa-calendar-check"></i></span><div><p>Yangi so‘rovlar</p><h2><?= $statistics['pending_reservations'] ?></h2><small>Ko‘rib chiqilishi kerak</small></div></article></div>
        </section>

        <section class="quick-actions mb-4">
            <div><p class="admin-eyebrow">Tezkor amallar</p><h2 class="h5 mb-0">Kutubxonani boshqarish</h2></div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-primary" href="<?= e(APP_URL) ?>/admin/add-book.php"><i class="fa-solid fa-plus me-2"></i>Kitob qo‘shish</a>
                <a class="btn btn-success" href="<?= e(APP_URL) ?>/admin/issue-book.php"><i class="fa-solid fa-arrow-up-right-from-square me-2"></i>Kitob berish</a>
                <a class="btn btn-outline-light" href="<?= e(APP_URL) ?>/admin/reservations.php"><i class="fa-solid fa-calendar-check me-2"></i>Band qilishlar</a>
            </div>
        </section>

        <div class="row g-4">
            <div class="col-xl-7">
                <section class="admin-panel h-100">
                    <div class="panel-heading"><div><p class="admin-eyebrow">So‘nggi harakatlar</p><h2 class="h4">Kitob operatsiyalari</h2></div><a href="<?= e(APP_URL) ?>/admin/return-book.php">Barchasini ko‘rish</a></div>
                    <div class="table-responsive">
                        <table class="table admin-table align-middle">
                            <thead><tr><th>O‘quvchi</th><th>Kitob</th><th>Muddat</th><th>Holat</th></tr></thead>
                            <tbody>
                            <?php if ($recentTransactions === []): ?><tr><td colspan="4" class="empty-admin">Operatsiyalar hali yo‘q.</td></tr><?php endif; ?>
                            <?php foreach ($recentTransactions as $transaction): ?>
                                <tr>
                                    <td><strong><?= e($transaction['full_name']) ?></strong></td>
                                    <td><?= e($transaction['title']) ?></td>
                                    <td><?= e(library_feature_date($transaction['due_date'])) ?></td>
                                    <td><span class="status-pill status-<?= e($transaction['status']) ?>"><?= $transaction['status'] === 'returned' ? 'Qaytarilgan' : ($transaction['status'] === 'overdue' ? 'Muddati o‘tgan' : 'Berilgan') ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            <div class="col-xl-5">
                <section class="admin-panel h-100">
                    <div class="panel-heading"><div><p class="admin-eyebrow">Qoldiq nazorati</p><h2 class="h4">Kam qolgan kitoblar</h2></div><a href="<?= e(APP_URL) ?>/admin/books.php">Kitoblar</a></div>
                    <?php if ($lowStockBooks === []): ?><div class="empty-admin">Qoldig‘i kam kitob yo‘q.</div><?php endif; ?>
                    <div class="transaction-list">
                        <?php foreach ($lowStockBooks as $book): ?>
                            <a class="transaction-item" href="<?= e(APP_URL) ?>/admin/add-book.php?id=<?= (int) $book['id'] ?>">
                                <span class="transaction-icon <?= (int) $book['available_copies'] > 0 ? 'status-borrowed' : 'status-overdue' ?>"><i class="fa-solid fa-book"></i></span>
                                <span class="flex-grow-1"><strong><?= e($book['title']) ?></strong><small><?= e($book['author']) ?></small></span>
                                <span class="stock-count <?= (int) $book['available_copies'] > 0 ? 'in-stock' : 'out-stock' ?>"><?= (int) $book['available_copies'] ?>/<?= (int) $book['total_copies'] ?></span>
                            </a>
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
