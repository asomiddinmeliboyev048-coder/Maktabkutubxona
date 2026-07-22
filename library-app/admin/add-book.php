<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
$user = require_role($pdo, 'admin');
library_feature_expire_reservations($pdo);

$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$errors = [];
$form = [
    'user_id' => '', 'title' => '', 'author' => '', 'category_id' => 0,
    'description' => '', 'total_copies' => 1, 'listing_type' => 'rental',
    'price' => '', 'rental_price' => '', 'address' => '', 'phone' => '',
    'cover_image' => null,
];

$categoryStatement = $pdo->prepare('SELECT id, name FROM categories ORDER BY name');
$categoryStatement->execute();
$categories = $categoryStatement->fetchAll();
$ownerStatement = $pdo->prepare("SELECT id, first_name, last_name, email, is_active FROM users WHERE role = 'librarian' ORDER BY is_active DESC, first_name, last_name");
$ownerStatement->execute();
$owners = $ownerStatement->fetchAll();

if ($bookId > 0) {
    $statement = $pdo->prepare('SELECT * FROM books WHERE id = :id');
    $statement->execute(['id' => $bookId]);
    $found = $statement->fetch();
    if (!$found) {
        set_flash('danger', 'Kitob topilmadi.');
        redirect('admin/books.php');
    }
    $form = $found;
    $form['user_id'] = $found['user_id'] === null ? '' : (string) $found['user_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    foreach (['title', 'author', 'description', 'listing_type', 'price', 'rental_price', 'address', 'phone'] as $field) {
        $form[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $form['user_id'] = trim((string) ($_POST['user_id'] ?? ''));
    $form['category_id'] = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT) ?: 0;
    $form['total_copies'] = filter_input(INPUT_POST, 'total_copies', FILTER_VALIDATE_INT) ?: 0;

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) $errors[] = 'Xavfsizlik tokeni yaroqsiz.';
    if ($postedId !== $bookId) $errors[] = 'Kitob identifikatori mos emas.';
    if (mb_strlen($form['title']) < 2 || mb_strlen($form['title']) > 255) $errors[] = 'Kitob nomi 2–255 belgidan iborat bo‘lsin.';
    if (mb_strlen($form['author']) < 2 || mb_strlen($form['author']) > 180) $errors[] = 'Muallif 2–180 belgidan iborat bo‘lsin.';
    if (mb_strlen($form['description']) < 10 || mb_strlen($form['description']) > 5000) $errors[] = 'Tavsif 10–5000 belgidan iborat bo‘lsin.';
    if ($form['category_id'] < 1) $errors[] = 'Kategoriyani tanlang.';
    if ($form['total_copies'] < 1 || $form['total_copies'] > 10000) $errors[] = 'Nusxalar soni 1–10000 oralig‘ida bo‘lsin.';
    if (!in_array($form['listing_type'], ['sale', 'rental', 'both'], true)) $errors[] = 'E’lon turini tanlang.';

    $ownerId = $form['user_id'] === '' ? null : filter_var($form['user_id'], FILTER_VALIDATE_INT);
    if ($form['user_id'] !== '' && (!$ownerId || $ownerId < 1)) $errors[] = 'Egani to‘g‘ri tanlang.';
    $price = $form['price'] === '' ? null : filter_var($form['price'], FILTER_VALIDATE_FLOAT);
    $rentalPrice = $form['rental_price'] === '' ? null : filter_var($form['rental_price'], FILTER_VALIDATE_FLOAT);
    if (in_array($form['listing_type'], ['sale', 'both'], true) && ($price === null || $price === false || $price <= 0 || $price > 9999999999.99)) $errors[] = 'Sotuv narxini to‘g‘ri kiriting.';
    if (in_array($form['listing_type'], ['rental', 'both'], true) && $rentalPrice !== null && ($rentalPrice === false || $rentalPrice <= 0 || $rentalPrice > 9999999999.99)) $errors[] = 'Ijara narxini to‘g‘ri kiriting.';
    if (mb_strlen($form['address']) > 255) $errors[] = 'Manzil 255 belgidan oshmasin.';
    if ($form['phone'] !== '' && (mb_strlen($form['phone']) < 7 || mb_strlen($form['phone']) > 30)) $errors[] = 'Telefon 7–30 belgidan iborat bo‘lsin.';

    $newCover = null;
    if ($errors === []) $newCover = library_feature_save_cover($_FILES['cover_image'] ?? null, $errors);

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $categoryCheck = $pdo->prepare('SELECT id FROM categories WHERE id = :id');
            $categoryCheck->execute(['id' => $form['category_id']]);
            if (!$categoryCheck->fetchColumn()) throw new RuntimeException('Kategoriya topilmadi.');
            if ($ownerId !== null) {
                $ownerCheck = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'librarian'");
                $ownerCheck->execute(['id' => $ownerId]);
                if (!$ownerCheck->fetchColumn()) throw new RuntimeException('Tanlangan sotuvchi topilmadi.');
            }

            $params = [
                'user_id' => $ownerId, 'title' => $form['title'], 'author' => $form['author'],
                'category_id' => $form['category_id'], 'description' => $form['description'],
                'total' => $form['total_copies'], 'listing_type' => $form['listing_type'],
                'price' => in_array($form['listing_type'], ['sale', 'both'], true) ? number_format((float) $price, 2, '.', '') : null,
                'rental_price' => in_array($form['listing_type'], ['rental', 'both'], true) && $rentalPrice !== null ? number_format((float) $rentalPrice, 2, '.', '') : null,
                'address' => $form['address'] !== '' ? $form['address'] : null,
                'phone' => $form['phone'] !== '' ? $form['phone'] : null,
            ];
            $oldCover = null;

            if ($bookId > 0) {
                $currentStatement = $pdo->prepare("SELECT b.*,
                    (SELECT COUNT(*) FROM borrow_transactions bt WHERE bt.book_id=b.id AND bt.status IN ('borrowed','overdue') AND bt.return_date IS NULL) active_loans,
                    (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status IN ('approved','ready') AND (r.expires_at IS NULL OR r.expires_at>=NOW())) active_holds,
                    (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status IN ('pending','approved','ready') AND (r.expires_at IS NULL OR r.expires_at>=NOW())) active_reservations
                    FROM books b WHERE b.id=:id FOR UPDATE");
                $currentStatement->execute(['id' => $bookId]);
                $current = $currentStatement->fetch();
                if (!$current) throw new RuntimeException('Kitob topilmadi.');
                $committed = (int) $current['active_loans'] + (int) $current['active_holds'];
                if ((int) $form['total_copies'] < $committed) throw new RuntimeException('Nusxalar soni faol majburiyatlardan kam bo‘la olmaydi: ' . $committed . '.');
                $currentOwnerId = $current['user_id'] === null ? null : (int) $current['user_id'];
                $requestedOwnerId = $ownerId === null ? null : (int) $ownerId;
                if ($currentOwnerId !== $requestedOwnerId && ((int) $current['active_loans'] > 0 || (int) $current['active_reservations'] > 0)) {
                    throw new RuntimeException('Faol berish yoki band qilish mavjud kitobning egasini almashtirib bo‘lmaydi. Avval barcha faol jarayonlarni yakunlang.');
                }
                $oldCover = $current['cover_image'];
                $params['cover'] = $newCover ?: $oldCover;
                $params['available'] = (int) $form['total_copies'] - (int) $current['active_loans'];
                $params['id'] = $bookId;
                $update = $pdo->prepare('UPDATE books SET user_id=:user_id,title=:title,author=:author,category_id=:category_id,description=:description,cover_image=:cover,total_copies=:total,available_copies=:available,listing_type=:listing_type,price=:price,rental_price=:rental_price,address=:address,phone=:phone WHERE id=:id');
                $update->execute($params);
            } else {
                $params['cover'] = $newCover;
                $params['available'] = $form['total_copies'];
                $insert = $pdo->prepare('INSERT INTO books (user_id,title,author,category_id,description,cover_image,total_copies,available_copies,listing_type,price,rental_price,address,phone,is_active) VALUES (:user_id,:title,:author,:category_id,:description,:cover,:total,:available,:listing_type,:price,:rental_price,:address,:phone,1)');
                $insert->execute($params);
            }

            $pdo->commit();
            if ($newCover && $oldCover && $newCover !== $oldCover) library_feature_delete_cover($oldCover);
            set_flash('success', $bookId > 0 ? 'Kitob ma’lumotlari yangilandi.' : 'Yangi kitob qo‘shildi.');
            redirect('admin/books.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($newCover) library_feature_delete_cover($newCover);
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Kitobni saqlab bo‘lmadi.';
        }
    }
}
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $bookId ? 'Kitobni tahrirlash' : 'Yangi kitob' ?> — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-layout">
    <aside class="admin-sidebar"><a class="admin-brand" href="<?= e(APP_URL) ?>/admin/index.php"><span><i class="fa-solid fa-book-open"></i></span><div><strong>Kutubxona</strong><small>Boshqaruv paneli</small></div></a><?= library_feature_admin_nav($bookId ? 'books' : 'add-book') ?></aside>
    <main class="admin-main">
        <header class="admin-topbar"><button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menyuni ochish"><i class="fa-solid fa-bars"></i></button><div><p class="admin-eyebrow">Global inventar</p><h1><?= $bookId ? 'Kitobni tahrirlash' : 'Yangi kitob qo‘shish' ?></h1></div><a class="btn btn-outline-light" href="<?= e(APP_URL) ?>/admin/books.php">Ortga</a></header>
        <?php if ($errors): ?><div class="alert alert-danger alert-dismissible fade show"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button></div><?php endif; ?>
        <section class="admin-panel">
            <form class="admin-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $bookId ?>">
                <div class="row g-3">
                    <div class="col-md-7"><label class="form-label" for="title">Kitob nomi</label><input class="form-control" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="255" required></div>
                    <div class="col-md-5"><label class="form-label" for="author">Muallif</label><input class="form-control" id="author" name="author" value="<?= e($form['author']) ?>" maxlength="180" required></div>
                    <div class="col-md-6"><label class="form-label" for="category_id">Kategoriya</label><select class="form-select" id="category_id" name="category_id" required><option value="">Tanlang</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) $form['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label" for="user_id">Egasi <span class="optional-label">bo‘sh qolsa maktab inventari</span></label><select class="form-select" id="user_id" name="user_id"><option value="">Egasiz / maktab kutubxonasi</option><?php foreach ($owners as $owner): ?><option value="<?= (int) $owner['id'] ?>" <?= (string) $form['user_id'] === (string) $owner['id'] ? 'selected' : '' ?>><?= e($owner['first_name'] . ' ' . $owner['last_name'] . ' — ' . $owner['email'] . ((int) $owner['is_active'] ? '' : ' (faol emas)')) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label" for="total_copies">Nusxalar</label><input class="form-control" type="number" id="total_copies" name="total_copies" min="1" max="10000" value="<?= (int) $form['total_copies'] ?>" required></div>
                    <div class="col-md-3"><label class="form-label" for="listing_type">E’lon turi</label><select class="form-select" id="listing_type" name="listing_type" required><?php foreach (['sale' => 'Sotuv', 'rental' => 'Ijara', 'both' => 'Ikkalasi'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $form['listing_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label" for="price">Sotuv narxi</label><input class="form-control" type="number" step="0.01" min="0" id="price" name="price" value="<?= e($form['price']) ?>"></div>
                    <div class="col-md-3"><label class="form-label" for="rental_price">Ijara narxi</label><input class="form-control" type="number" step="0.01" min="0" id="rental_price" name="rental_price" value="<?= e($form['rental_price']) ?>"></div>
                    <div class="col-md-8"><label class="form-label" for="address">Manzil</label><input class="form-control" id="address" name="address" value="<?= e($form['address']) ?>" maxlength="255"></div>
                    <div class="col-md-4"><label class="form-label" for="phone">Telefon</label><input class="form-control" type="tel" id="phone" name="phone" value="<?= e($form['phone']) ?>" maxlength="30"></div>
                    <div class="col-12"><label class="form-label" for="description">Tavsif</label><textarea class="form-control" id="description" name="description" rows="5" minlength="10" maxlength="5000" required><?= e($form['description']) ?></textarea></div>
                    <div class="col-12"><label class="form-label" for="cover_image">Muqova rasmi</label><input class="form-control" type="file" id="cover_image" name="cover_image" accept="image/jpeg,image/png,image/webp" data-image-input><div class="form-text">JPG, PNG yoki WEBP; 5 MB gacha. Bo‘sh qoldirilsa mavjud muqova saqlanadi.</div></div>
                    <div class="col-12"><button class="btn btn-primary btn-lg" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Saqlash</button></div>
                </div>
            </form>
        </section>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
</body>
</html>
