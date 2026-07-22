<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
$user = require_role($pdo, 'admin');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    $action = trim((string) ($_POST['action'] ?? ''));

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) $errors[] = 'Xavfsizlik tokeni yaroqsiz. Sahifani yangilang.';
    if ($bookId < 1) $errors[] = 'Kitob noto‘g‘ri tanlangan.';
    if (!in_array($action, ['archive', 'restore', 'delete'], true)) $errors[] = 'Amalga ruxsat berilmagan.';

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $bookStatement = $pdo->prepare(
                'SELECT b.id, b.title, b.cover_image,
                        (SELECT COUNT(*) FROM borrow_transactions bt WHERE bt.book_id=b.id) +
                        (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id) +
                        (SELECT COUNT(*) FROM reviews rv WHERE rv.book_id=b.id) AS reference_count,
                        (SELECT COUNT(*) FROM borrow_transactions active_bt
                         WHERE active_bt.book_id=b.id AND active_bt.status IN (\'borrowed\',\'overdue\') AND active_bt.return_date IS NULL) +
                        (SELECT COUNT(*) FROM reservations active_r
                         WHERE active_r.book_id=b.id AND active_r.status IN (\'pending\',\'approved\',\'ready\')) AS active_count
                 FROM books b WHERE b.id=:id FOR UPDATE'
            );
            $bookStatement->execute(['id' => $bookId]);
            $book = $bookStatement->fetch();
            if (!$book) throw new RuntimeException('Kitob topilmadi.');

            if ($action === 'delete') {
                if ((int) $book['reference_count'] > 0) {
                    throw new RuntimeException('Tarix, band qilish yoki fikrlarga bog‘langan kitobni o‘chirib bo‘lmaydi; uni arxivlang.');
                }
                $delete = $pdo->prepare('DELETE FROM books WHERE id=:id');
                $delete->execute(['id' => $bookId]);
                $message = 'Kitob butunlay o‘chirildi.';
            } else {
                if ($action === 'archive' && (int) $book['active_count'] > 0) {
                    throw new RuntimeException('Faol berish yoki band qilish mavjud; avval ularni yakunlang.');
                }
                $isActive = $action === 'restore' ? 1 : 0;
                $update = $pdo->prepare('UPDATE books SET is_active=:is_active WHERE id=:id');
                $update->execute(['is_active' => $isActive, 'id' => $bookId]);
                $message = $isActive ? 'Kitob katalogga qaytarildi.' : 'Kitob arxivlandi.';
            }

            $pdo->commit();
            if ($action === 'delete') library_feature_delete_cover($book['cover_image']);
            set_flash('success', $message);
            redirect('admin/books.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Amalni bajarib bo‘lmadi.';
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? 'active'));
if (!in_array($status, ['active', 'archived', 'all'], true)) $status = 'active';

$sql = "SELECT b.id,b.title,b.author,b.cover_image,b.total_copies,b.available_copies,b.listing_type,b.is_active,
               c.name AS category_name, CONCAT_WS(' ',u.first_name,u.last_name) AS owner_name,
               (SELECT COUNT(*) FROM borrow_transactions bt WHERE bt.book_id=b.id AND bt.status IN ('borrowed','overdue') AND bt.return_date IS NULL) AS active_loans,
               (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status IN ('pending','approved','ready')) AS active_reservations,
               (SELECT COUNT(*) FROM borrow_transactions bt2 WHERE bt2.book_id=b.id) +
               (SELECT COUNT(*) FROM reservations r2 WHERE r2.book_id=b.id) +
               (SELECT COUNT(*) FROM reviews rv WHERE rv.book_id=b.id) AS reference_count
        FROM books b
        INNER JOIN categories c ON c.id=b.category_id
        LEFT JOIN users u ON u.id=b.user_id
        WHERE 1=1";
$params = [];
if ($status === 'active') $sql .= ' AND b.is_active=1';
if ($status === 'archived') $sql .= ' AND b.is_active=0';
if ($search !== '') {
    $sql .= ' AND (b.title LIKE :title OR b.author LIKE :author OR c.name LIKE :category)';
    $like = '%' . $search . '%';
    $params = ['title' => $like, 'author' => $like, 'category' => $like];
}
$sql .= ' ORDER BY b.is_active DESC,b.created_at DESC,b.title ASC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$books = $statement->fetchAll();
$flash = get_flash();
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kitoblar — <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-layout">
    <aside class="admin-sidebar">
        <a class="admin-brand" href="<?= e(APP_URL) ?>/admin/index.php"><span><i class="fa-solid fa-book-open"></i></span><div><strong>Kutubxona</strong><small>Super Admin paneli</small></div></a>
        <?= library_feature_admin_nav('books') ?>
    </aside>
    <main class="admin-main">
        <header class="admin-topbar">
            <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menyuni ochish"><i class="fa-solid fa-bars"></i></button>
            <div><p class="admin-eyebrow">Katalog boshqaruvi</p><h1>Kitoblar</h1></div>
            <a class="btn btn-primary" href="<?= e(APP_URL) ?>/admin/add-book.php"><i class="fa-solid fa-plus me-2"></i>Kitob qo‘shish</a>
        </header>

        <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"><?= e($flash['message']) ?><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button></div><?php endif; ?>
        <?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <section class="admin-panel mb-4">
            <form class="row g-3 align-items-end admin-form" method="get">
                <div class="col-md-7"><label class="form-label" for="q">Kitob, muallif yoki kategoriya</label><input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Qidirish..."></div>
                <div class="col-md-3"><label class="form-label" for="status">Holat</label><select class="form-select" id="status" name="status"><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Faol</option><option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Arxiv</option><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Barchasi</option></select></div>
                <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-2"></i>Izlash</button></div>
            </form>
        </section>

        <section class="admin-panel">
            <div class="panel-heading"><div><p class="admin-eyebrow">Inventar</p><h2 class="h4">Katalog yozuvlari</h2></div><span class="dark-badge"><?= count($books) ?> ta</span></div>
            <div class="table-responsive">
                <table class="table admin-table align-middle">
                    <thead><tr><th>Kitob</th><th>Kategoriya / tur</th><th>Qoldiq</th><th>Jarayonlar</th><th>Egasi</th><th>Amal</th></tr></thead>
                    <tbody>
                    <?php if ($books === []): ?><tr><td colspan="6" class="empty-admin">Bu filtr bo‘yicha kitob topilmadi.</td></tr><?php endif; ?>
                    <?php foreach ($books as $book): ?>
                        <tr>
                            <td><div class="book-cell"><img src="<?= e(book_cover_url($book['cover_image'])) ?>" alt=""><div><strong><?= e($book['title']) ?></strong><small><?= e($book['author']) ?></small><?php if (!(int) $book['is_active']): ?><span class="reservation-status status-expired mt-1">Arxiv</span><?php endif; ?></div></div></td>
                            <td><strong><?= e($book['category_name']) ?></strong><small class="d-block"><?= e(library_feature_listing_label($book['listing_type'])) ?></small></td>
                            <td><span class="stock-count <?= (int) $book['available_copies'] > 0 ? 'in-stock' : 'out-stock' ?>"><?= (int) $book['available_copies'] ?>/<?= (int) $book['total_copies'] ?></span></td>
                            <td><small><?= (int) $book['active_loans'] ?> ta o‘quvchida</small><small class="d-block"><?= (int) $book['active_reservations'] ?> ta faol band</small></td>
                            <td><?= e($book['owner_name'] ?: 'Maktab kutubxonasi') ?></td>
                            <td><div class="d-flex flex-wrap gap-1">
                                <a class="btn btn-sm btn-outline-light" href="<?= e(APP_URL) ?>/admin/add-book.php?id=<?= (int) $book['id'] ?>" aria-label="Tahrirlash"><i class="fa-solid fa-pen"></i></a>
                                <?php if (!(int) $book['is_active']): ?>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $book['id'] ?>"><input type="hidden" name="action" value="restore"><button class="btn btn-sm btn-outline-success" type="submit" aria-label="Qayta faollashtirish"><i class="fa-solid fa-rotate-left"></i></button></form>
                                <?php elseif ((int) $book['active_loans'] + (int) $book['active_reservations'] === 0): ?>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $book['id'] ?>"><input type="hidden" name="action" value="archive"><button class="btn btn-sm btn-outline-light" type="submit" data-confirm-action="Kitobni arxivlaysizmi?" aria-label="Arxivlash"><i class="fa-solid fa-box-archive"></i></button></form>
                                <?php else: ?>
                                    <span class="btn btn-sm btn-outline-light disabled" title="Faol berish yoki band qilish mavjud" aria-label="Arxivlash bloklangan"><i class="fa-solid fa-lock"></i></span>
                                <?php endif; ?>
                                <?php if ((int) $book['reference_count'] === 0): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $book['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-outline-danger" type="submit" data-confirm-action="Kitobni butunlay o‘chirasizmi?" aria-label="O‘chirish"><i class="fa-solid fa-trash"></i></button></form><?php else: ?><span class="btn btn-sm btn-outline-light disabled" title="Tarixga bog‘langan"><i class="fa-solid fa-lock"></i></span><?php endif; ?>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
</body>
</html>
