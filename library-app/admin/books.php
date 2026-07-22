<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
$user = require_role($pdo, 'admin');
library_feature_expire_reservations($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) $errors[] = 'Xavfsizlik tokeni yaroqsiz.';
    if ($bookId < 1 || !in_array($action, ['archive', 'restore', 'delete'], true)) $errors[] = 'Amal noto‘g‘ri.';

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $check = $pdo->prepare("SELECT b.cover_image,
                (SELECT COUNT(*) FROM borrow_transactions bt WHERE bt.book_id=b.id) +
                (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id) +
                (SELECT COUNT(*) FROM reviews rv WHERE rv.book_id=b.id) AS reference_count,
                (SELECT COUNT(*) FROM borrow_transactions bt WHERE bt.book_id=b.id AND bt.status IN ('borrowed','overdue') AND bt.return_date IS NULL) +
                (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status IN ('pending','approved','ready')) AS active_count
                FROM books b WHERE b.id=:id FOR UPDATE");
            $check->execute(['id' => $bookId]);
            $book = $check->fetch();
            if (!$book) throw new RuntimeException('Kitob topilmadi.');

            if ($action === 'delete') {
                if ((int) $book['reference_count'] > 0) throw new RuntimeException('Tarixga bog‘langan kitob o‘chirilmaydi; uni arxivlang.');
                $mutation = $pdo->prepare('DELETE FROM books WHERE id=:id');
                $message = 'Kitob butunlay o‘chirildi.';
            } elseif ($action === 'archive') {
                if ((int) $book['active_count'] > 0) throw new RuntimeException('Faol berish yoki band qilish mavjud; avval ularni yakunlang.');
                $mutation = $pdo->prepare('UPDATE books SET is_active=0 WHERE id=:id');
                $message = 'Tarix saqlangan holda kitob arxivlandi.';
            } else {
                $mutation = $pdo->prepare('UPDATE books SET is_active=1 WHERE id=:id');
                $message = 'Kitob katalogga qaytarildi.';
            }
            $mutation->execute(['id' => $bookId]);
            if ($mutation->rowCount() !== 1) throw new RuntimeException('Amal bajarilmadi.');
            $pdo->commit();
            if ($action === 'delete') library_feature_delete_cover($book['cover_image']);
            set_flash('success', $message);
            redirect('admin/books.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Amal bajarilmadi.';
        }
    }
}

$statement = $pdo->prepare("SELECT b.*, c.name category_name, CONCAT_WS(' ', u.first_name, u.last_name) owner_name,
    (SELECT COUNT(*) FROM borrow_transactions bt WHERE bt.book_id=b.id) loan_count,
    (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id) reservation_count,
    (SELECT COUNT(*) FROM reviews rv WHERE rv.book_id=b.id) review_count,
    (SELECT COUNT(*) FROM borrow_transactions bt WHERE bt.book_id=b.id AND bt.status IN ('borrowed','overdue') AND bt.return_date IS NULL) active_loan_count,
    (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status IN ('pending','approved','ready')) active_reservation_count
    FROM books b INNER JOIN categories c ON c.id=b.category_id LEFT JOIN users u ON u.id=b.user_id
    ORDER BY b.is_active DESC, b.updated_at DESC, b.title");
$statement->execute();
$books = library_feature_enrich_books($pdo, $statement->fetchAll());
$flash = get_flash();
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kitoblar — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-layout">
    <aside class="admin-sidebar"><a class="admin-brand" href="<?= e(APP_URL) ?>/admin/index.php"><span><i class="fa-solid fa-book-open"></i></span><div><strong>Kutubxona</strong><small>Boshqaruv paneli</small></div></a><?= library_feature_admin_nav('books') ?></aside>
    <main class="admin-main">
        <header class="admin-topbar"><button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menyuni ochish"><i class="fa-solid fa-bars"></i></button><div><p class="admin-eyebrow">Global inventar</p><h1>Barcha kitoblar</h1></div><a class="btn btn-primary" href="<?= e(APP_URL) ?>/admin/add-book.php"><i class="fa-solid fa-plus me-2"></i>Yangi kitob</a></header>
        <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"><?= e($flash['message']) ?><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button></div><?php endif; ?>
        <?php if ($errors): ?><div class="alert alert-danger alert-dismissible fade show"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button></div><?php endif; ?>
        <section class="admin-panel">
            <div class="table-responsive"><table class="table admin-table align-middle"><thead><tr><th>Kitob</th><th>Egasi / e’lon</th><th>Narx</th><th>Nusxalar</th><th>Tarix</th><th>Holat</th><th>Amal</th></tr></thead><tbody>
            <?php if ($books === []): ?><tr><td colspan="7" class="text-center py-5 text-secondary">Kitoblar yo‘q.</td></tr><?php endif; ?>
            <?php foreach ($books as $book): ?><?php $references = (int) $book['loan_count'] + (int) $book['reservation_count'] + (int) $book['review_count']; $active = (int) $book['active_loan_count'] + (int) $book['active_reservation_count']; ?>
                <tr>
                    <td><div class="book-cell"><img src="<?= e(book_cover_url($book['cover_image'])) ?>" alt=""><div><strong><?= e($book['title']) ?></strong><small><?= e($book['author']) ?> · <?= e($book['category_name']) ?></small></div></div></td>
                    <td><strong><?= e($book['owner_name'] ?: 'Maktab kutubxonasi') ?></strong><small class="d-block"><?= e(library_feature_listing_label($book['listing_type'])) ?></small></td>
                    <td><?php if (in_array($book['listing_type'], ['sale','both'], true)): ?><span class="d-block"><?= e(money_uzs($book['price'])) ?></span><?php endif; ?><?php if (in_array($book['listing_type'], ['rental','both'], true)): ?><small>Ijara: <?= e(money_uzs($book['rental_price'])) ?></small><?php endif; ?></td>
                    <td><span class="stock-count <?= (int) $book['free_copies'] > 0 ? 'in-stock' : 'out-stock' ?>"><?= (int) $book['free_copies'] ?>/<?= (int) $book['total_copies'] ?> erkin</span><small class="d-block"><?= (int) $book['available_copies'] ?> javonda</small></td>
                    <td><?= (int) $book['loan_count'] ?> berish · <?= (int) $book['reservation_count'] ?> band · <?= (int) $book['review_count'] ?> fikr</td>
                    <td><span class="status-pill <?= (int) $book['is_active'] ? 'status-returned' : 'status-overdue' ?>"><?= (int) $book['is_active'] ? 'Faol' : 'Arxiv' ?></span></td>
                    <td><div class="d-flex flex-wrap gap-1"><a class="btn btn-sm btn-outline-light" href="<?= e(APP_URL) ?>/admin/add-book.php?id=<?= (int) $book['id'] ?>" aria-label="Tahrirlash"><i class="fa-solid fa-pen"></i></a>
                    <?php if (!(int) $book['is_active']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $book['id'] ?>"><input type="hidden" name="action" value="restore"><button class="btn btn-sm btn-outline-success" type="submit" data-confirm-action="Kitobni katalogga qaytarasizmi?"><i class="fa-solid fa-rotate-left"></i></button></form>
                    <?php elseif ($active > 0): ?><span class="btn btn-sm btn-outline-light disabled" title="Faol majburiyat mavjud"><i class="fa-solid fa-lock"></i></span>
                    <?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $book['id'] ?>"><input type="hidden" name="action" value="<?= $references > 0 ? 'archive' : 'delete' ?>"><button class="btn btn-sm btn-outline-danger" type="submit" data-confirm-action="<?= $references > 0 ? 'Kitobni arxivlaysizmi?' : 'Kitobni butunlay o‘chirasizmi?' ?>"><i class="fa-solid <?= $references > 0 ? 'fa-box-archive' : 'fa-trash' ?>"></i></button></form><?php endif; ?></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
</body>
</html>
