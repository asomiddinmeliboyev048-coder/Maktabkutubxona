<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

/**
 * Yuklangan muqovani tekshiradi va uploads/covers papkasiga saqlaydi.
 *
 * Muvaffaqiyatli bo‘lsa yangi fayl nomini,
 * rasm tanlanmagan bo‘lsa null qaytaradi.
 */
function save_uploaded_cover($file, array &$errors, bool $required = false)
{
    if (!is_array($file)) {
        if ($required) {
            $errors[] = 'Muqova rasmini tanlang.';
        }

        return null;
    }

    $uploadError = isset($file['error'])
        ? (int) $file['error']
        : UPLOAD_ERR_NO_FILE;

    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            $errors[] = 'Muqova rasmini tanlang.';
        }

        return null;
    }

    $uploadErrorMessages = [
        UPLOAD_ERR_INI_SIZE =>
            'Rasm server ruxsat bergan hajmdan katta.',
        UPLOAD_ERR_FORM_SIZE =>
            'Rasm formadagi maksimal hajmdan katta.',
        UPLOAD_ERR_PARTIAL =>
            'Rasm to‘liq yuklanmadi. Qayta urinib ko‘ring.',
        UPLOAD_ERR_NO_TMP_DIR =>
            'Serverning vaqtinchalik papkasi topilmadi.',
        UPLOAD_ERR_CANT_WRITE =>
            'Rasmni diskka yozib bo‘lmadi.',
        UPLOAD_ERR_EXTENSION =>
            'PHP kengaytmasi rasm yuklanishini to‘xtatdi.',
    ];

    if ($uploadError !== UPLOAD_ERR_OK) {
        $errors[] = isset($uploadErrorMessages[$uploadError])
            ? $uploadErrorMessages[$uploadError]
            : 'Rasm yuklashda noma’lum xatolik yuz berdi.';

        return null;
    }

    $temporaryPath = isset($file['tmp_name'])
        ? (string) $file['tmp_name']
        : '';

    $fileSize = isset($file['size'])
        ? (int) $file['size']
        : 0;

    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        $errors[] =
            'Yuklangan rasm vaqtinchalik papkada topilmadi.';

        return null;
    }

    if ($fileSize < 1) {
        $errors[] = 'Tanlangan rasm bo‘sh.';

        return null;
    }

    if ($fileSize > MAX_COVER_SIZE) {
        $errors[] = 'Rasm hajmi 5 MB dan oshmasligi kerak.';

        return null;
    }

    $imageInformation = @getimagesize($temporaryPath);

    if (
        $imageInformation === false ||
        !isset($imageInformation['mime'])
    ) {
        $errors[] =
            'Tanlangan fayl haqiqiy rasm emas yoki buzilgan.';

        return null;
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $mimeType = strtolower((string) $imageInformation['mime']);

    if (!isset($allowedMimeTypes[$mimeType])) {
        $errors[] =
            'Faqat JPG, JPEG, PNG yoki WEBP rasmi qabul qilinadi.';

        return null;
    }

    $uploadDirectory =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        'covers';

    if (!is_dir($uploadDirectory)) {
        if (
            !mkdir($uploadDirectory, 0775, true) &&
            !is_dir($uploadDirectory)
        ) {
            $errors[] =
                'uploads/covers papkasini yaratib bo‘lmadi.';

            return null;
        }
    }

    if (!is_writable($uploadDirectory)) {
        $errors[] =
            'uploads/covers papkasiga rasm yozishga ruxsat yo‘q.';

        return null;
    }

    try {
        $randomPart = bin2hex(random_bytes(12));
    } catch (Exception $exception) {
        $randomPart =
            str_replace('.', '', uniqid('', true));
    }

    $extension = $allowedMimeTypes[$mimeType];

    $newFilename =
        date('Ymd_His') .
        '_' .
        $randomPart .
        '.' .
        $extension;

    $destinationPath =
        $uploadDirectory .
        DIRECTORY_SEPARATOR .
        $newFilename;

    if (
        !move_uploaded_file(
            $temporaryPath,
            $destinationPath
        )
    ) {
        $errors[] =
            'Rasmni uploads/covers papkasiga ko‘chirib bo‘lmadi.';

        return null;
    }

    if (
        !is_file($destinationPath) ||
        filesize($destinationPath) < 1
    ) {
        if (is_file($destinationPath)) {
            @unlink($destinationPath);
        }

        $errors[] =
            'Rasm diskka to‘liq saqlanmadi.';

        return null;
    }

    return $newFilename;
}

/**
 * Saqlangan muqova faylini o‘chiradi.
 */
function delete_saved_cover($filename): void
{
    if (!is_string($filename) || trim($filename) === '') {
        return;
    }

    $safeFilename = basename(trim($filename));

    if ($safeFilename === 'default.jpg') {
        return;
    }

    $path =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        'covers' .
        DIRECTORY_SEPARATOR .
        $safeFilename;

    if (is_file($path)) {
        @unlink($path);
    }
}

$categoryStatement = $pdo->prepare(
    'SELECT id, name
     FROM categories
     ORDER BY name ASC'
);
$categoryStatement->execute();
$categories = $categoryStatement->fetchAll();

$errors = [];

$formData = [
    'title' => '',
    'author' => '',
    'category_id' => 0,
    'description' => '',
    'total_copies' => 1,
];

$selectedCoverBookId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) &&
        is_string($_POST['action'])
        ? $_POST['action']
        : 'add_book';

    $postedToken = isset($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : null;

    if (!verify_csrf_token($postedToken)) {
        $errors[] =
            'Xavfsizlik tokeni yaroqsiz. Sahifani yangilang.';
    }

    /*
     * Oldin qo‘shilgan kitobning muqovasini yangilash.
     */
    if ($action === 'update_cover') {
        $selectedCoverBookId = filter_input(
            INPUT_POST,
            'book_id',
            FILTER_VALIDATE_INT
        );

        if (
            $selectedCoverBookId === false ||
            $selectedCoverBookId === null ||
            $selectedCoverBookId < 1
        ) {
            $selectedCoverBookId = 0;
            $errors[] = 'Muqovasi yangilanadigan kitobni tanlang.';
        }

        $selectedBook = null;

        if ($selectedCoverBookId > 0) {
            $bookCheckStatement = $pdo->prepare(
                'SELECT id, title, cover_image
                 FROM books
                 WHERE id = :book_id'
            );

            $bookCheckStatement->execute([
                'book_id' => $selectedCoverBookId,
            ]);

            $selectedBook = $bookCheckStatement->fetch();

            if (!$selectedBook) {
                $errors[] = 'Tanlangan kitob topilmadi.';
            }
        }

        $newCoverFilename = null;

        if ($errors === []) {
            $newCoverFilename = save_uploaded_cover(
                isset($_FILES['cover_image'])
                    ? $_FILES['cover_image']
                    : null,
                $errors,
                true
            );
        }

        if (
            $errors === [] &&
            $selectedBook &&
            $newCoverFilename !== null
        ) {
            try {
                $updateStatement = $pdo->prepare(
                    'UPDATE books
                     SET cover_image = :cover_image
                     WHERE id = :book_id'
                );

                $updateStatement->execute([
                    'cover_image' => $newCoverFilename,
                    'book_id' => $selectedCoverBookId,
                ]);

                if ($updateStatement->rowCount() < 1) {
                    throw new RuntimeException(
                        'Bazadagi muqova ma’lumotini yangilab bo‘lmadi.'
                    );
                }

                $oldCoverFilename =
                    isset($selectedBook['cover_image'])
                        ? $selectedBook['cover_image']
                        : null;

                if (
                    is_string($oldCoverFilename) &&
                    $oldCoverFilename !== $newCoverFilename
                ) {
                    delete_saved_cover($oldCoverFilename);
                }

                set_flash(
                    'success',
                    '“' .
                    htmlspecialchars_decode(
                        $selectedBook['title'],
                        ENT_QUOTES
                    ) .
                    '” kitobining muqovasi yangilandi.'
                );

                redirect(
                    'book-details.php?id=' .
                    $selectedCoverBookId
                );
            } catch (Throwable $exception) {
                delete_saved_cover($newCoverFilename);

                $errors[] =
                    $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'Muqovani yangilashda tizim xatosi yuz berdi.';
            }
        }
    } else {
        /*
         * Yangi kitob qo‘shish.
         */
        $formData['title'] = sanitize_input(
            isset($_POST['title'])
                ? $_POST['title']
                : ''
        );

        $formData['author'] = sanitize_input(
            isset($_POST['author'])
                ? $_POST['author']
                : ''
        );

        $formData['category_id'] = filter_input(
            INPUT_POST,
            'category_id',
            FILTER_VALIDATE_INT
        );

        $formData['description'] = sanitize_input(
            isset($_POST['description'])
                ? $_POST['description']
                : ''
        );

        $formData['total_copies'] = filter_input(
            INPUT_POST,
            'total_copies',
            FILTER_VALIDATE_INT
        );

        if (
            $formData['category_id'] === false ||
            $formData['category_id'] === null
        ) {
            $formData['category_id'] = 0;
        }

        if (
            $formData['total_copies'] === false ||
            $formData['total_copies'] === null
        ) {
            $formData['total_copies'] = 0;
        }

        $plainTitle = htmlspecialchars_decode(
            $formData['title'],
            ENT_QUOTES
        );

        $plainAuthor = htmlspecialchars_decode(
            $formData['author'],
            ENT_QUOTES
        );

        $plainDescription = htmlspecialchars_decode(
            $formData['description'],
            ENT_QUOTES
        );

        $titleLength = mb_strlen(
            $plainTitle,
            'UTF-8'
        );

        $authorLength = mb_strlen(
            $plainAuthor,
            'UTF-8'
        );

        $descriptionLength = mb_strlen(
            $plainDescription,
            'UTF-8'
        );

        if ($titleLength < 2 || $titleLength > 255) {
            $errors[] =
                'Kitob nomi 2 dan 255 tagacha belgidan iborat bo‘lsin.';
        }

        if ($authorLength < 2 || $authorLength > 180) {
            $errors[] =
                'Muallif nomi 2 dan 180 tagacha belgidan iborat bo‘lsin.';
        }

        if (
            $descriptionLength < 10 ||
            $descriptionLength > 5000
        ) {
            $errors[] =
                'Tavsif 10 dan 5000 tagacha belgidan iborat bo‘lsin.';
        }

        if (
            $formData['total_copies'] < 1 ||
            $formData['total_copies'] > 10000
        ) {
            $errors[] =
                'Nusxalar soni 1 dan 10000 gacha bo‘lsin.';
        }

        if ($formData['category_id'] > 0) {
            $categoryCheckStatement = $pdo->prepare(
                'SELECT id
                 FROM categories
                 WHERE id = :category_id'
            );

            $categoryCheckStatement->execute([
                'category_id' =>
                    $formData['category_id'],
            ]);

            if (!$categoryCheckStatement->fetch()) {
                $errors[] =
                    'Tanlangan kategoriya mavjud emas.';
            }
        } else {
            $errors[] = 'Kategoriyani tanlang.';
        }

        $uploadedFilename = null;

        if ($errors === []) {
            $uploadedFilename = save_uploaded_cover(
                isset($_FILES['cover_image'])
                    ? $_FILES['cover_image']
                    : null,
                $errors,
                false
            );
        }

        if ($errors === []) {
            try {
                $insertStatement = $pdo->prepare(
                    'INSERT INTO books (
                        title,
                        author,
                        category_id,
                        description,
                        cover_image,
                        total_copies,
                        available_copies
                    ) VALUES (
                        :title,
                        :author,
                        :category_id,
                        :description,
                        :cover_image,
                        :total_copies,
                        :available_copies
                    )'
                );

                $insertStatement->execute([
                    'title' => $formData['title'],
                    'author' => $formData['author'],
                    'category_id' =>
                        $formData['category_id'],
                    'description' =>
                        $formData['description'],
                    'cover_image' =>
                        $uploadedFilename,
                    'total_copies' =>
                        $formData['total_copies'],
                    'available_copies' =>
                        $formData['total_copies'],
                ]);

                set_flash(
                    'success',
                    '“' .
                    $plainTitle .
                    '” kitobi va muqovasi muvaffaqiyatli saqlandi.'
                );

                redirect('admin/index.php');
            } catch (Throwable $exception) {
                if ($uploadedFilename !== null) {
                    delete_saved_cover(
                        $uploadedFilename
                    );
                }

                $errors[] =
                    'Kitobni bazaga saqlashda xatolik yuz berdi.';
            }
        }
    }
}

$booksStatement = $pdo->prepare(
    'SELECT id, title, author, cover_image
     FROM books
     ORDER BY title ASC'
);
$booksStatement->execute();
$allBooks = $booksStatement->fetchAll();

$flash = get_flash();
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Kitob va muqova qo‘shish — <?= e(APP_NAME) ?>
    </title>

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <link
        rel="stylesheet"
        href="<?= e(APP_URL) ?>/assets/css/admin.css"
    >
</head>

<body class="admin-body">
<div class="admin-layout">
    <aside class="admin-sidebar">
        <a
            class="admin-brand"
            href="<?= e(APP_URL) ?>/admin/index.php"
        >
            <span>
                <i class="fa-solid fa-book-open"></i>
            </span>

            <div>
                <strong>Kutubxona</strong>
                <small>Boshqaruv paneli</small>
            </div>
        </a>

        <nav class="admin-nav">
            <a href="<?= e(APP_URL) ?>/admin/index.php">
                <i class="fa-solid fa-chart-pie"></i>
                Dashboard
            </a>

            <a
                class="active"
                href="<?= e(APP_URL) ?>/admin/add-book.php"
            >
                <i class="fa-solid fa-square-plus"></i>
                Kitob va muqova
            </a>

            <a href="<?= e(APP_URL) ?>/admin/issue-book.php">
                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                Kitob berish
            </a>

            <a href="<?= e(APP_URL) ?>/admin/return-book.php">
                <i class="fa-solid fa-rotate-left"></i>
                Kitobni qaytarish
            </a>

            <div class="nav-divider"></div>

            <a href="<?= e(APP_URL) ?>/index.php">
                <i class="fa-solid fa-globe"></i>
                Ochiq katalog
            </a>
        </nav>
    </aside>

    <main class="admin-main">
        <header class="admin-topbar">
            <button
                class="sidebar-toggle"
                type="button"
                data-sidebar-toggle
            >
                <i class="fa-solid fa-bars"></i>
            </button>

            <div>
                <p class="admin-eyebrow">
                    Inventarni boshqarish
                </p>

                <h1>Kitob va muqova</h1>
            </div>

            <a
                class="btn btn-outline-light"
                href="<?= e(APP_URL) ?>/admin/index.php"
            >
                <i class="fa-solid fa-arrow-left me-2"></i>
                Dashboard
            </a>
        </header>

        <?php if ($flash): ?>
            <div
                class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"
            >
                <?= e($flash['message']) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>
            </div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger">
                <strong>
                    Quyidagi xatolarni tuzating:
                </strong>

                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-xl-8">
                <section class="admin-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="admin-eyebrow">
                                Yangi kitob
                            </p>

                            <h2 class="h4">
                                Kitob va muqova qo‘shish
                            </h2>
                        </div>

                        <span class="panel-icon">
                            <i class="fa-solid fa-book-circle-plus"></i>
                        </span>
                    </div>

                    <form
                        method="post"
                        enctype="multipart/form-data"
                        action="<?= e(APP_URL) ?>/admin/add-book.php"
                        class="admin-form"
                    >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e(csrf_token()) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="add_book"
                        >

                        <div class="row g-4">
                            <div class="col-md-7">
                                <label
                                    class="form-label"
                                    for="title"
                                >
                                    Kitob nomi
                                </label>

                                <input
                                    class="form-control form-control-lg"
                                    id="title"
                                    name="title"
                                    value="<?= e($formData['title']) ?>"
                                    maxlength="255"
                                    required
                                >
                            </div>

                            <div class="col-md-5">
                                <label
                                    class="form-label"
                                    for="author"
                                >
                                    Muallif
                                </label>

                                <input
                                    class="form-control form-control-lg"
                                    id="author"
                                    name="author"
                                    value="<?= e($formData['author']) ?>"
                                    maxlength="180"
                                    required
                                >
                            </div>

                            <div class="col-md-7">
                                <label
                                    class="form-label"
                                    for="category_id"
                                >
                                    Kategoriya
                                </label>

                                <select
                                    class="form-select form-select-lg"
                                    id="category_id"
                                    name="category_id"
                                    required
                                >
                                    <option value="">
                                        Tanlang
                                    </option>

                                    <?php foreach ($categories as $category): ?>
                                        <option
                                            value="<?= (int) $category['id'] ?>"
                                            <?= (int) $formData['category_id'] === (int) $category['id'] ? 'selected' : '' ?>
                                        >
                                            <?= e($category['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-5">
                                <label
                                    class="form-label"
                                    for="total_copies"
                                >
                                    Nusxalar soni
                                </label>

                                <input
                                    type="number"
                                    class="form-control form-control-lg"
                                    id="total_copies"
                                    name="total_copies"
                                    min="1"
                                    max="10000"
                                    value="<?= (int) $formData['total_copies'] ?>"
                                    required
                                >
                            </div>

                            <div class="col-12">
                                <label
                                    class="form-label"
                                    for="description"
                                >
                                    Kitob tavsifi
                                </label>

                                <textarea
                                    class="form-control"
                                    id="description"
                                    name="description"
                                    rows="6"
                                    minlength="10"
                                    maxlength="5000"
                                    required
                                ><?= e($formData['description']) ?></textarea>
                            </div>

                            <div class="col-12">
                                <label
                                    class="form-label"
                                    for="cover_image"
                                >
                                    Muqova rasmi
                                </label>

                                <input
                                    type="file"
                                    class="form-control form-control-lg"
                                    id="cover_image"
                                    name="cover_image"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                    data-image-input
                                >

                                <div class="form-text">
                                    JPG, JPEG, PNG yoki WEBP.
                                    Maksimal hajm: 5 MB.
                                </div>
                            </div>

                            <div class="col-12">
                                <button
                                    class="btn btn-primary btn-lg px-5"
                                    type="submit"
                                >
                                    <i class="fa-solid fa-floppy-disk me-2"></i>
                                    Kitob va muqovani saqlash
                                </button>
                            </div>
                        </div>
                    </form>
                </section>
            </div>

            <div class="col-xl-4">
                <section class="admin-panel preview-panel">
                    <p class="admin-eyebrow">
                        Jonli ko‘rinish
                    </p>

                    <h2 class="h4 mb-4">
                        Tanlangan muqova
                    </h2>

                    <div class="cover-preview">
                        <img
                            src="<?= e(book_cover_url(null)) ?>"
                            alt="Muqova ko‘rinishi"
                            data-image-preview
                        >
                    </div>

                    <div class="alert alert-info mt-4 mb-0">
                        Rasm tanlaganingizdan keyin shu yerda
                        ko‘rinadi va saqlash tugmasi orqali
                        serverga yuboriladi.
                    </div>
                </section>
            </div>
        </div>

        <section class="admin-panel mt-4">
            <div class="panel-heading">
                <div>
                    <p class="admin-eyebrow">
                        Mavjud kitob
                    </p>

                    <h2 class="h4">
                        Eski kitob muqovasini yangilash
                    </h2>
                </div>

                <span class="panel-icon">
                    <i class="fa-solid fa-images"></i>
                </span>
            </div>

            <form
                method="post"
                enctype="multipart/form-data"
                action="<?= e(APP_URL) ?>/admin/add-book.php"
                class="admin-form"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="update_cover"
                >

                <div class="row g-4 align-items-end">
                    <div class="col-lg-7">
                        <label
                            class="form-label"
                            for="cover_book_id"
                        >
                            Kitobni tanlang
                        </label>

                        <select
                            class="form-select form-select-lg"
                            id="cover_book_id"
                            name="book_id"
                            required
                        >
                            <option value="">
                                Kitobni tanlang
                            </option>

                            <?php foreach ($allBooks as $existingBook): ?>
                                <option
                                    value="<?= (int) $existingBook['id'] ?>"
                                    <?= $selectedCoverBookId === (int) $existingBook['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($existingBook['title']) ?>
                                    —
                                    <?= e($existingBook['author']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-5">
                        <label
                            class="form-label"
                            for="replacement_cover"
                        >
                            Yangi muqova rasmi
                        </label>

                        <input
                            type="file"
                            class="form-control form-control-lg"
                            id="replacement_cover"
                            name="cover_image"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            required
                        >
                    </div>

                    <div class="col-12">
                        <button
                            class="btn btn-success btn-lg"
                            type="submit"
                        >
                            <i class="fa-solid fa-image me-2"></i>
                            Muqovani saqlash va yangilash
                        </button>
                    </div>
                </div>
            </form>
        </section>
    </main>
</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
</body>
</html>
