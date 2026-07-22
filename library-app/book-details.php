<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/feature_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (function_exists('sync_overdue_transactions')) {
    sync_overdue_transactions();
}
library_feature_expire_reservations($pdo);

$baseUrl = library_feature_base_url();
$bookId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($bookId < 1) {
    set_flash('error', 'Book not found.');
    library_feature_redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    library_feature_require_csrf('book-details.php?id=' . $bookId);
    $studentCode = isset($_POST['student_code']) ? trim(sanitize_input($_POST['student_code'])) : '';
    $pickupDate = isset($_POST['pickup_date']) ? trim((string) $_POST['pickup_date']) : '';
    $today = new DateTime('today');
    $latest = new DateTime('+30 days');
    $pickup = DateTime::createFromFormat('!Y-m-d', $pickupDate);

    if ($studentCode === '' || $pickup === false || $pickup->format('Y-m-d') !== $pickupDate) {
        set_flash('error', 'Enter a valid student code and pickup date.');
        library_feature_redirect('book-details.php?id=' . $bookId);
    }
    if ($pickup < $today || $pickup > $latest) {
        set_flash('error', 'Pickup must be scheduled between today and 30 days from now.');
        library_feature_redirect('book-details.php?id=' . $bookId);
    }

    try {
        $pdo->beginTransaction();
        $bookLock = $pdo->prepare('SELECT id, is_active FROM books WHERE id = ? FOR UPDATE');
        $bookLock->execute(array($bookId));
        $bookRow = $bookLock->fetch(PDO::FETCH_ASSOC);
        if (!$bookRow || (int) $bookRow['is_active'] !== 1) {
            throw new RuntimeException('This book is no longer available for reservation.');
        }

        $studentStmt = $pdo->prepare('SELECT id FROM students WHERE student_code = ? ORDER BY id LIMIT 2 FOR UPDATE');
        $studentStmt->execute(array($studentCode));
        $studentMatches = $studentStmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($studentMatches) !== 1) {
            throw new RuntimeException('The student code could not be verified. Please ask library staff to check the account.');
        }
        $studentId = (int) $studentMatches[0];

        $duplicateStmt = $pdo->prepare(
            "SELECT id FROM reservations
             WHERE book_id = ? AND student_id = ? AND status IN ('pending', 'approved')
             LIMIT 1 FOR UPDATE"
        );
        $duplicateStmt->execute(array($bookId, (int) $studentId));
        if ($duplicateStmt->fetchColumn()) {
            throw new RuntimeException('An active reservation for this book already exists for that student code.');
        }

        $insertStmt = $pdo->prepare(
            "INSERT INTO reservations (book_id, student_id, request_date, pickup_date, status, expires_at, created_at, updated_at)
             VALUES (?, ?, CURDATE(), ?, 'pending', CONCAT(?, ' 23:59:59'), NOW(), NOW())"
        );
        $insertStmt->execute(array($bookId, (int) $studentId, $pickupDate, $pickupDate));
        $pdo->commit();
        set_flash('success', 'Reservation requested. Library staff will review it before pickup.');
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Reservation request failed: ' . $exception->getMessage());
        set_flash('error', 'The reservation could not be saved right now. Please try again or contact library staff.');
    } catch (Exception $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', $exception->getMessage());
    }
    library_feature_redirect('book-details.php?id=' . $bookId);
}

$bookStmt = $pdo->prepare(
    "SELECT b.*, c.name AS category_name,
            COALESCE(holds.held_copies, 0) AS held_copies,
            GREATEST(b.available_copies - COALESCE(holds.held_copies, 0), 0) AS free_copies,
            (SELECT MIN(bt.due_date)
               FROM borrow_transactions bt
              WHERE bt.book_id = b.id
                AND bt.status IN ('borrowed', 'overdue')
                AND bt.return_date IS NULL) AS nearest_return_date
     FROM books b
     LEFT JOIN categories c ON c.id = b.category_id
     LEFT JOIN (
         SELECT book_id, COUNT(*) AS held_copies
         FROM reservations
         WHERE status = 'approved'
         GROUP BY book_id
     ) holds ON holds.book_id = b.id
     WHERE b.id = ? AND b.is_active = 1"
);
$bookStmt->execute(array($bookId));
$book = $bookStmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    set_flash('error', 'Book not found or no longer in the public catalog.');
    library_feature_redirect('index.php');
}

$reviews = library_feature_review_rows($pdo, $bookId);
$successMessage = get_flash('success');
$errorMessage = get_flash('error');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($book['title']); ?> | Library</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo e($baseUrl); ?>/assets/css/features.css" rel="stylesheet">
</head>
<body class="library-page">
<nav class="navbar navbar-expand-lg navbar-dark library-navbar">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?php echo e($baseUrl); ?>/index.php"><i class="bi bi-book-half me-2"></i>Library</a>
        <a class="btn btn-sm btn-outline-light" href="<?php echo e($baseUrl); ?>/index.php"><i class="bi bi-arrow-left me-1"></i>Catalog</a>
    </div>
</nav>

<main class="container py-5">
    <?php if ($successMessage): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo e($successMessage); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($errorMessage): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo e($errorMessage); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>

    <article class="details-shell">
        <div class="row g-0">
            <div class="col-md-4 col-lg-3">
                <?php if (!empty($book['cover_image'])): ?>
                    <img class="book-cover h-100" src="<?php echo e(book_cover_url($book['cover_image'])); ?>" alt="Cover of <?php echo e($book['title']); ?>">
                <?php else: ?>
                    <div class="book-cover-placeholder h-100"><i class="bi bi-book"></i></div>
                <?php endif; ?>
            </div>
            <div class="col-md-8 col-lg-9 p-4 p-lg-5">
                <span class="badge text-bg-primary mb-3"><?php echo e($book['category_name'] ? $book['category_name'] : 'Uncategorized'); ?></span>
                <h1 class="display-6 fw-bold mb-2"><?php echo e($book['title']); ?></h1>
                <p class="lead text-secondary">by <?php echo e($book['author']); ?></p>
                <div class="d-flex flex-wrap gap-2 my-4">
                    <span class="stock-pill <?php echo (int) $book['free_copies'] > 0 ? 'available' : 'waiting'; ?>"><?php echo (int) $book['free_copies']; ?> free / <?php echo (int) $book['total_copies']; ?> total</span>
                    <?php if ((int) $book['held_copies'] > 0): ?><span class="stock-pill waiting"><?php echo (int) $book['held_copies']; ?> held for pickup</span><?php endif; ?>
                </div>
                <p class="text-secondary"><i class="bi bi-clock me-1"></i>Nearest expected return: <strong><?php echo e(library_feature_date($book['nearest_return_date'], 'No return pending')); ?></strong></p>
                <p class="mb-0"><?php echo nl2br(e($book['description'] ? $book['description'] : 'No description is available for this title.')); ?></p>
            </div>
        </div>
    </article>

    <div class="row g-4 mt-2">
        <section class="col-lg-5" aria-labelledby="reserveHeading">
            <div class="reservation-panel p-4 h-100">
                <h2 id="reserveHeading" class="h4 fw-bold"><i class="bi bi-calendar-check me-2"></i>Request a reservation</h2>
                <p class="text-secondary small">Use your assigned student code. The public page never displays student names, phone numbers, or reservation identities.</p>
                <form method="post" action="<?php echo e($baseUrl); ?>/book-details.php?id=<?php echo (int) $bookId; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="studentCode">Student code</label>
                        <input class="form-control" id="studentCode" type="text" name="student_code" maxlength="50" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="pickupDate">Preferred pickup date</label>
                        <input class="form-control" id="pickupDate" type="date" name="pickup_date" min="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-send me-1"></i>Send request</button>
                </form>
            </div>
        </section>

        <section class="col-lg-7" aria-labelledby="reviewsHeading">
            <div class="bg-white rounded-4 shadow-sm p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 id="reviewsHeading" class="h4 fw-bold mb-0">Reader reviews</h2>
                    <span class="badge text-bg-light"><?php echo count($reviews); ?> total</span>
                </div>
                <?php if (!$reviews): ?>
                    <p class="text-secondary mb-0">No reviews have been posted for this book yet.</p>
                <?php else: ?>
                    <div class="d-grid gap-3">
                        <?php foreach ($reviews as $review): ?>
                            <div class="review-card">
                                <?php if (isset($review['rating'])): ?><div class="text-warning mb-2" aria-label="Rating: <?php echo (int) $review['rating']; ?> out of 5"><?php echo render_stars((int) $review['rating']); ?></div><?php endif; ?>
                                <p class="mb-2"><?php echo nl2br(e(isset($review['review_body']) ? $review['review_body'] : 'Rating submitted.')); ?></p>
                                <div class="small text-secondary">Anonymous reader<?php echo isset($review['created_at']) ? ' &middot; ' . e(library_feature_date($review['created_at'], '')) : ''; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<footer class="library-footer py-5">
    <div class="container d-flex flex-column flex-md-row justify-content-between gap-3">
        <div><h2 class="h5 text-white"><i class="bi bi-book-half me-2"></i>Your Library</h2><p class="small mb-0">Reservations are confirmed only after staff approval.</p></div>
        <div><a class="footer-link me-3" href="<?php echo e($baseUrl); ?>/index.php">Catalog</a><a class="footer-link" href="<?php echo e($baseUrl); ?>/admin/index.php">Staff</a></div>
    </div>
</footer>
<button class="back-to-top" type="button" aria-label="Back to top"><i class="bi bi-arrow-up"></i></button>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var button = document.querySelector('.back-to-top');
    function updateButton() { button.classList.toggle('visible', window.scrollY > 360); }
    window.addEventListener('scroll', updateButton, { passive: true });
    button.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    updateButton();
}());
</script>
</body>
</html>