<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
$user = require_role($pdo, 'admin');

$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$errors = [];
$form = [
    'title' => '',
    'author' => '',
    'category_id' => 0,
    'description' => '',
    'total_copies' => 1,
    'available_copies' => 1,
    'listing_type' => 'rental',
    'price' => '',
    'rental_price' => '',
    'address' => '',
    'phone' => '',
    'cover_image' => null,
];

if ($bookId > 0) {
    $existingStatement = $pdo->prepare('SELECT * FROM books WHERE id = :id LIMIT 1');
    $existingStatement->execute(['id' => $bookId]);
    $existingBook = $existingStatement->fetch();
    if (!$existingBook) {
        set_flash('warning', 'Tahrirlanadigan kitob topilmadi.');
        redirect('admin/books.php');
    }
    foreach (array_keys($form) as $field) {
        if (array_key_exists($field, $existingBook)) {
            $form[$field] = $existingBook[$field] ?? '';
        }
    }
}

$categoryStatement = $pdo->prepare('SELECT id, name FROM categories ORDER BY name ASC');
$categoryStatement->execute();
$categories = $categoryStatement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedBookId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    $form['title'] = trim((string) ($_POST['title'] ?? ''));
    $form['author'] = trim((string) ($_POST['author'] ?? ''));
    $form['category_id'] = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT) ?: 0;
    $form['description'] = trim((string) ($_POST['description'] ?? ''));
    $form['total_copies'] = filter_input(INPUT_POST, 'total_copies', FILTER_VALIDATE_INT) ?: 0;
    $form['listing_type'] = trim((string) ($_POST['listing_type'] ?? 'rental'));
    $form['price'] = trim((string) ($_POST['price'] ?? ''));
    $form['rental_price'] = trim((string) ($_POST['rental_price'] ?? ''));
    $form['address'] = trim((string) ($_POST['address'] ?? ''));
    $form['phone'] = trim((string) ($_POST['phone'] ?? ''));

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) $errors[] = 'Xavfsizlik tokeni yaroqsiz. Sahifani yangilang.';
    if ($postedBookId !== $bookId) $errors[] = 'Kitob identifikatori mos emas. Sahifani yangilang.';
    if (mb_strlen($form['title']) < 2 || mb_strlen($form['title']) > 255) $errors[] = 'Kitob nomi 2–255 belgidan iborat bo‘lsin.';
    if (mb_strlen($form['author']) < 2 || mb_strlen($form['author']) > 180) $errors[] = 'Muallif nomi 2–180 belgidan iborat bo‘lsin.';
    if ($form['category_id'] < 1) $errors[] = 'Kategoriyani tanlang.';
    if (mb_strlen($form['description']) < 10) $errors[] = 'Tavsif kamida 10 belgidan iborat bo‘lsin.';
    if ($form['total_copies'] < 1) $errors[] = 'Jami nusxalar kamida 1 ta bo‘lsin.';
    if (!in_array($form['listing_type'], ['sale', 'rental', 'both'], true)) $errors[] = 'E’lon turini to‘g‘ri tanlang.';
    if ($form['price'] !== '' && (!is_numeric($form['price']) || (float) $form['price'] < 0)) $errors[] = 'Sotuv narxini to‘g‘ri kiriting.';
    if ($form['rental_price'] !== '' && (!is_numeric($form['rental_price']) || (float) $form['rental_price'] < 0)) $errors[] = 'Ijara narxini to‘g‘ri kiriting.';
    if (mb_strlen($form['address']) > 255) $errors[] = 'Manzil 255 belgidan oshmasin.';
    if (mb_strlen($form['phone']) > 30) $errors[] = 'Telefon 30 belgidan oshmasin.';

    $newCover = null;
    if ($errors === []) {
        $newCover = library_feature_save_cover($_FILES['cover_image'] ?? null, $errors);
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $oldCover = null;
            $activeLoans = 0;
            $activeHolds = 0;

            if ($bookId > 0) {
                $currentStatement = $pdo->prepare(
                    "SELECT b.cover_image,
                            (SELECT COUNT(*) FROM borrow_transactions bt
                             WHERE bt.book_id=b.id AND bt.status IN ('borrowed','overdue') AND bt.return_date IS NULL) AS active_loans,
                            (SELECT COUNT(*) FROM reservations r
                             WHERE r.book_id=b.id AND r.status IN ('approved','ready')) AS active_holds
                     FROM books b WHERE b.id=:id FOR UPDATE"
                );
                $currentStatement->execute(['id' => $bookId]);
                $currentBook = $currentStatement->fetch();
                if (!$currentBook) throw new RuntimeException('Tahrirlanadigan kitob topilmadi.');
                $oldCover = $currentBook['cover_image'];
                $activeLoans = (int) $currentBook['active_loans'];
                $activeHolds = (int) $currentBook['active_holds'];
            }

            $committedCopies = $activeLoans + $activeHolds;
            if ((int) $form['total_copies'] < $committedCopies) {
                throw new RuntimeException('Jami nusxalar faol berish va tasdiqlangan bandlardan kam bo‘la olmaydi: ' . $committedCopies . '.');
            }
            $availableCopies = (int) $form['total_copies'] - $activeLoans;

            $params = [
                'title' => $form['title'],
                'author' => $form['author'],
                'category_id' => $form['category_id'],
                'description' => $form['description'],
                'cover_image' => $newCover ?? $oldCover,
                'total_copies' => $form['total_copies'],
                'available_copies' => $availableCopies,
                'listing_type' => $form['listing_type'],
                'price' => in_array($form['listing_type'], ['sale', 'both'], true) && $form['price'] !== '' ? (float) $form['price'] : null,
                'rental_price' => in_array($form['listing_type'], ['rental', 'both'], true) && $form['rental_price'] !== '' ? (float) $form['rental_price'] : null,
                'address' => $form['address'] !== '' ? $form['address'] : null,
                'phone' => $form['phone'] !== '' ? $form['phone'] : null,
            ];

            if ($bookId > 0) {
                $params['id'] = $bookId;
                $statement = $pdo->prepare(
                    'UPDATE books SET title=:title, author=:author, category_id=:category_id, description=:description,
                     cover_image=:cover_image, total_copies=:total_copies, available_copies=:available_copies,
                     listing_type=:listing_type, price=:price, rental_price=:rental_price, address=:address, phone=:phone
                     WHERE id=:id'
                );
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO books (user_id,title,author,category_id,description,cover_image,total_copies,available_copies,listing_type,price,rental_price,address,phone,is_active)
                     VALUES (NULL,:title,:author,:category_id,:description,:cover_image,:total_copies,:available_copies,:listing_type,:price,:rental_price,:address,:phone,1)'
                );
            }
            $statement->execute($params);
            $pdo->commit();

            if ($newCover !== null && is_string($oldCover) && $oldCover !== '' && $oldCover !== $newCover) {
                library_feature_delete_cover($oldCover);
            }
            set_flash('success', $bookId > 0 ? 'Kitob ma’lumotlari yangilandi.' : 'Yangi kitob katalogga qo‘shildi.');
            redirect('admin/books.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($newCover !== null) library_feature_delete_cover($newCover);
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Kitobni saqlab bo‘lmadi. Ma’lumotlarni tekshirib qayta urinib ko‘ring.';
        }
    }
}
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $bookId > 0 ? 'Kitobni tahrirlash' : 'Kitob qo‘shish' ?> — <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-layout">
    <aside class="admin-sidebar">
        <a class="admin-brand" href="<?= e(APP_URL) ?>/admin/index.php"><span><i class="fa-solid fa-book-open"></i></span><div><strong>Kutubxona</strong><small>Super Admin paneli</small></div></a>
        <?= library_feature_admin_nav('add-book') ?>
    </aside>
    <main class="admin-main">
        <header class="admin-topbar">
            <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menyuni ochish"><i class="fa-solid fa-bars"></i></button>
            <div><p class="admin-eyebrow">Katalog boshqaruvi</p><h1><?= $bookId > 0 ? 'Kitobni tahrirlash' : 'Yangi kitob qo‘shish' ?></h1></div>
            <a class="btn btn-outline-light" href="<?= e(APP_URL) ?>/admin/books.php"><i class="fa-solid fa-arrow-left me-2"></i>Kitoblar</a>
        </header>

        <?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="row g-4" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $bookId ?>">
            <div class="col-xl-8">
                <section class="admin-panel admin-form">
                    <div class="panel-heading"><div><p class="admin-eyebrow">Asosiy ma’lumotlar</p><h2 class="h4">Kitob tafsilotlari</h2></div><span class="panel-icon"><i class="fa-solid fa-pen-to-square"></i></span></div>
                    <div class="row g-3">
                        <div class="col-md-7"><label class="form-label" for="title">Kitob nomi</label><input class="form-control" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="255" required></div>
                        <div class="col-md-5"><label class="form-label" for="author">Muallif</label><input class="form-control" id="author" name="author" value="<?= e($form['author']) ?>" maxlength="180" required></div>
                        <div class="col-md-6"><label class="form-label" for="category_id">Kategoriya</label><select class="form-select" id="category_id" name="category_id" required><option value="">Tanlang</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) $form['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label" for="listing_type">E’lon turi</label><select class="form-select" id="listing_type" name="listing_type" required><option value="rental" <?= $form['listing_type'] === 'rental' ? 'selected' : '' ?>>Ijara</option><option value="sale" <?= $form['listing_type'] === 'sale' ? 'selected' : '' ?>>Sotuv</option><option value="both" <?= $form['listing_type'] === 'both' ? 'selected' : '' ?>>Sotuv va ijara</option></select></div>
                        <div class="col-12"><label class="form-label" for="description">Tavsif</label><textarea class="form-control" id="description" name="description" rows="6" required><?= e($form['description']) ?></textarea></div>
                        <div class="col-sm-6"><label class="form-label" for="total_copies">Jami nusxalar</label><input class="form-control" type="number" id="total_copies" name="total_copies" min="1" value="<?= (int) $form['total_copies'] ?>" required></div>
                        <div class="col-sm-6"><label class="form-label" for="available_copies">Hozir javonda</label><input class="form-control" type="number" id="available_copies" value="<?= (int) $form['available_copies'] ?>" readonly aria-describedby="available_help"><div class="form-text" id="available_help">Faol berishlar asosida tizim avtomatik hisoblaydi.</div></div>
                        <div class="col-sm-6"><label class="form-label" for="price">Sotuv narxi (so‘m)</label><input class="form-control" type="number" id="price" name="price" min="0" step="0.01" value="<?= e($form['price']) ?>"></div>
                        <div class="col-sm-6"><label class="form-label" for="rental_price">Ijara narxi (so‘m)</label><input class="form-control" type="number" id="rental_price" name="rental_price" min="0" step="0.01" value="<?= e($form['rental_price']) ?>"></div>
                        <div class="col-md-7"><label class="form-label" for="address">Manzil <span class="optional-label">ixtiyoriy</span></label><input class="form-control" id="address" name="address" maxlength="255" value="<?= e($form['address']) ?>"></div>
                        <div class="col-md-5"><label class="form-label" for="phone">Telefon <span class="optional-label">ixtiyoriy</span></label><input class="form-control" type="tel" id="phone" name="phone" maxlength="30" value="<?= e($form['phone']) ?>"></div>
                        <div class="col-12 d-flex flex-wrap gap-2 pt-2"><button class="btn btn-primary btn-lg" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Saqlash</button><a class="btn btn-outline-light btn-lg" href="<?= e(APP_URL) ?>/admin/books.php">Bekor qilish</a></div>
                    </div>
                </section>
            </div>
            <div class="col-xl-4">
                <section class="admin-panel preview-panel admin-form">
                    <div class="panel-heading"><div><p class="admin-eyebrow">Muqova</p><h2 class="h4">Rasm yuklash</h2></div></div>
                    <div class="cover-preview"><img data-image-preview src="<?= e(book_cover_url($form['cover_image'])) ?>" alt="Kitob muqovasi ko‘rinishi"></div>
                    <label class="form-label" for="cover_image">Yangi muqova <span class="optional-label">ixtiyoriy</span></label>
                    <input class="form-control" type="file" id="cover_image" name="cover_image" accept="image/jpeg,image/png,image/webp" data-image-input>
                    <div class="upload-rules mt-3"><strong>Talablar</strong><ul><li>JPG, PNG yoki WEBP</li><li>Eng ko‘pi 5 MB</li><li>Vertikal 3:4 nisbat tavsiya etiladi</li></ul></div>
                </section>
            </div>
        </form>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
</body>
</html>
