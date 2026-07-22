<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$searchInput = $_GET['q'] ?? '';
$search = is_string($searchInput)
    ? sanitize_input($searchInput)
    : '';

$categoryId = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$categoryId = $categoryId !== false && $categoryId !== null && $categoryId > 0 ? $categoryId : 0;

$categoryStatement = $pdo->prepare('SELECT id, name FROM categories ORDER BY name ASC');
$categoryStatement->execute();
$categories = $categoryStatement->fetchAll();

$sql = "SELECT
            b.id,
            b.title,
            b.author,
            b.description,
            b.cover_image,
            b.total_copies,
            b.available_copies,
            c.name AS category_name,
            COALESCE(AVG(r.rating), 0) AS average_rating,
            COUNT(r.id) AS review_count
        FROM books b
        INNER JOIN categories c ON c.id = b.category_id
        LEFT JOIN reviews r ON r.book_id = b.id
        WHERE 1 = 1";
$params = [];

if ($search !== '') {
    $sql .= ' AND (b.title LIKE :title_search OR b.author LIKE :author_search OR c.name LIKE :category_search)';
    $likeSearch = '%' . $search . '%';
    $params['title_search'] = $likeSearch;
    $params['author_search'] = $likeSearch;
    $params['category_search'] = $likeSearch;
}

if ($categoryId > 0) {
    $sql .= ' AND b.category_id = :category_id';
    $params['category_id'] = $categoryId;
}

$sql .= ' GROUP BY b.id, b.title, b.author, b.description, b.cover_image, b.total_copies, b.available_copies, c.name
          ORDER BY b.created_at DESC, b.title ASC';

$bookStatement = $pdo->prepare($sql);
$bookStatement->execute($params);
$books = $bookStatement->fetchAll();
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Maktab kutubxonasi elektron kitob katalogi">
    <title><?= e(APP_NAME) ?> — Kitoblar katalogi</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top border-bottom">
    <div class="container py-2">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="<?= e(APP_URL) ?>/index.php">
            <span class="brand-icon"><i class="fa-solid fa-book-open"></i></span>
            <span><?= e(APP_NAME) ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavigation" aria-controls="mainNavigation" aria-expanded="false" aria-label="Menyuni ochish">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNavigation">
            <div class="ms-auto mt-3 mt-lg-0">
                <a class="btn btn-outline-primary rounded-pill px-4" href="<?= e(APP_URL) ?>/admin/index.php">
                    <i class="fa-solid fa-user-shield me-2"></i>Kutubxonachi paneli
                </a>
            </div>
        </div>
    </div>
</nav>

<header class="catalog-hero">
    <div class="container position-relative">
        <div class="row align-items-center g-4">
            <div class="col-lg-7">
                <span class="hero-eyebrow"><i class="fa-solid fa-graduation-cap me-2"></i>Bilim sari bir qadam</span>
                <h1 class="display-4 fw-bold mt-3 mb-3">Sevimli kitobingizni toping</h1>
                <p class="lead text-secondary mb-0">Maktab kutubxonasidagi kitoblarni izlang, mavjudligini tekshiring va o‘quvchilar fikrlari bilan tanishing.</p>
            </div>
            <div class="col-lg-5 d-none d-lg-block text-center">
                <div class="hero-illustration"><i class="fa-solid fa-book-bookmark"></i></div>
            </div>
        </div>
    </div>
</header>

<main class="container py-5">
    <section class="search-panel mb-5" aria-label="Kitoblarni qidirish">
        <form method="get" action="<?= e(APP_URL) ?>/index.php" class="row g-3 align-items-end">
            <div class="col-lg-7">
                <label for="q" class="form-label fw-semibold">Kitob, muallif yoki kategoriya</label>
                <div class="input-group input-group-lg">
                    <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-secondary"></i></span>
                    <input type="search" class="form-control border-start-0" id="q" name="q" value="<?= e($search) ?>" placeholder="Masalan: O‘tkan kunlar">
                </div>
            </div>
            <div class="col-md-7 col-lg-3">
                <label for="category" class="form-label fw-semibold">Kategoriya</label>
                <select class="form-select form-select-lg" id="category" name="category">
                    <option value="">Barcha kategoriyalar</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>>
                            <?= e($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5 col-lg-2 d-grid">
                <button class="btn btn-primary btn-lg" type="submit"><i class="fa-solid fa-filter me-2"></i>Izlash</button>
            </div>
            <?php if ($search !== '' || $categoryId > 0): ?>
                <div class="col-12">
                    <a class="small text-decoration-none" href="<?= e(APP_URL) ?>/index.php"><i class="fa-solid fa-xmark me-1"></i>Filtrlarni tozalash</a>
                </div>
            <?php endif; ?>
        </form>
    </section>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <p class="section-kicker mb-1">Elektron katalog</p>
            <h2 class="h3 fw-bold mb-0">Kitoblar</h2>
        </div>
        <span class="result-count"><i class="fa-solid fa-layer-group me-2"></i><?= count($books) ?> ta natija</span>
    </div>

    <?php if ($books === []): ?>
        <div class="empty-state text-center">
            <i class="fa-regular fa-folder-open"></i>
            <h3 class="h4 mt-3">Kitob topilmadi</h3>
            <p class="text-secondary">Qidiruv so‘zini o‘zgartiring yoki filtrlarni tozalab qayta urinib ko‘ring.</p>
            <a class="btn btn-primary" href="<?= e(APP_URL) ?>/index.php">Barcha kitoblarni ko‘rish</a>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($books as $book): ?>
                <?php $isAvailable = (int) $book['available_copies'] > 0; ?>
                <div class="col-sm-6 col-lg-4 col-xl-3">
                    <article class="book-card h-100">
                        <div class="book-cover-wrap">
                            <img class="book-cover" src="<?= e(book_cover_url($book['cover_image'])) ?>" alt="<?= e($book['title']) ?> kitobi muqovasi" loading="lazy">
                            <span class="category-badge"><?= e($book['category_name']) ?></span>
                            <span class="availability-ribbon <?= $isAvailable ? 'available' : 'unavailable' ?>">
                                <?php if ($isAvailable): ?>
                                    Mavjud: <?= (int) $book['available_copies'] ?>/<?= (int) $book['total_copies'] ?>
                                <?php else: ?>
                                    Tugagan
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="book-card-body">
                            <p class="book-author mb-2"><i class="fa-regular fa-user me-1"></i><?= e($book['author']) ?></p>
                            <h3 class="book-title h5"><?= e($book['title']) ?></h3>
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <?= render_stars((float) $book['average_rating']) ?>
                                <span class="rating-number"><?= number_format((float) $book['average_rating'], 1) ?> (<?= (int) $book['review_count'] ?>)</span>
                            </div>
                            <p class="book-description"><?= e(mb_strimwidth(strip_tags(htmlspecialchars_decode($book['description'], ENT_QUOTES)), 0, 105, '…', 'UTF-8')) ?></p>
                            <a class="btn btn-outline-primary w-100 stretched-link" href="<?= e(APP_URL) ?>/book-details.php?id=<?= (int) $book['id'] ?>">
                                Batafsil <i class="fa-solid fa-arrow-right ms-2"></i>
                            </a>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<footer class="public-footer mt-5">
    <div class="container py-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
        <p class="mb-0">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Barcha huquqlar himoyalangan.</p>
        <p class="mb-0"><i class="fa-solid fa-heart text-danger me-1"></i> Kitobxon o‘quvchilar uchun</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
</body>
</html>
