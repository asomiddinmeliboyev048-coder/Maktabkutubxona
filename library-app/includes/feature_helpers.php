<?php
declare(strict_types=1);

/** Shared reservation, availability and display helpers. */

function library_feature_expire_reservations(PDO $pdo, ?int $ownerId = null): void
{
    if ($ownerId === null) {
        $statement = $pdo->prepare(
            "UPDATE reservations SET status = 'expired'
             WHERE status IN ('pending', 'approved', 'ready')
               AND expires_at IS NOT NULL AND expires_at < NOW()"
        );
        $statement->execute();
        return;
    }

    $statement = $pdo->prepare(
        "UPDATE reservations r INNER JOIN books b ON b.id = r.book_id
         SET r.status = 'expired'
         WHERE r.status IN ('pending', 'approved', 'ready')
           AND r.expires_at IS NOT NULL AND r.expires_at < NOW()
           AND b.user_id = :owner_id"
    );
    $statement->execute(['owner_id' => $ownerId]);
}

function library_feature_valid_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

function library_feature_date($value, string $fallback = '—'): string
{
    if (!is_string($value) || $value === '') {
        return $fallback;
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? $fallback : date('d.m.Y', $timestamp);
}

function library_feature_first_name(array $student): string
{
    if (!empty($student['first_name'])) {
        return (string) $student['first_name'];
    }
    $parts = preg_split('/\s+/u', trim((string) ($student['full_name'] ?? '')));
    return $parts && $parts[0] !== '' ? $parts[0] : 'O‘quvchi';
}

function library_feature_review_rows(PDO $pdo, int $bookId): array
{
    $statement = $pdo->prepare(
        'SELECT rating, comment, created_at
         FROM reviews
         WHERE book_id = :book_id
         ORDER BY created_at DESC, id DESC'
    );
    $statement->execute(['book_id' => $bookId]);
    return $statement->fetchAll();
}

/**
 * Adds held_copies, active_loan_count, free_copies, availability_status and
 * earliest_pickup_date to book rows. available_copies is physical on-shelf
 * stock; approved/ready reservations are subtracted to get public free stock.
 */
function library_feature_enrich_books(PDO $pdo, array $books): array
{
    if ($books === []) {
        return [];
    }

    $ids = [];
    foreach ($books as $book) {
        $ids[] = (int) $book['id'];
    }
    $ids = array_values(array_unique(array_filter($ids)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $holdStatement = $pdo->prepare(
        "SELECT book_id, expires_at
         FROM reservations
         WHERE status IN ('approved', 'ready')
           AND (expires_at IS NULL OR expires_at >= NOW())
           AND book_id IN ($placeholders)
         ORDER BY book_id, expires_at, id"
    );
    $holdStatement->execute($ids);
    $holds = [];
    $releaseDates = [];
    foreach ($holdStatement->fetchAll() as $row) {
        $holdBookId = (int) $row['book_id'];
        $holds[$holdBookId] = ($holds[$holdBookId] ?? 0) + 1;

        if (!empty($row['expires_at'])) {
            $expiry = new DateTimeImmutable((string) $row['expires_at']);
            $releaseDates[$holdBookId][] = $expiry->modify('+1 day')->format('Y-m-d');
        }
    }

    $loanStatement = $pdo->prepare(
        "SELECT book_id, due_date, status
         FROM borrow_transactions
         WHERE status IN ('borrowed', 'overdue')
           AND return_date IS NULL
           AND book_id IN ($placeholders)
         ORDER BY book_id, due_date, id"
    );
    $loanStatement->execute($ids);
    $loanCounts = [];
    foreach ($loanStatement->fetchAll() as $row) {
        $loanBookId = (int) $row['book_id'];
        $loanCounts[$loanBookId] = ($loanCounts[$loanBookId] ?? 0) + 1;

        // An overdue copy has no trustworthy future return date.
        if ($row['status'] === 'borrowed' && (string) $row['due_date'] >= date('Y-m-d')) {
            $releaseDates[$loanBookId][] = (string) $row['due_date'];
        }
    }

    foreach ($books as &$book) {
        $id = (int) $book['id'];
        $total = max(0, (int) $book['total_copies']);
        $available = min($total, max(0, (int) $book['available_copies']));
        $held = $holds[$id] ?? 0;
        $free = max(0, $available - $held);
        $dates = $releaseDates[$id] ?? [];
        sort($dates, SORT_STRING);

        $book['held_copies'] = $held;
        $book['active_loan_count'] = $loanCounts[$id] ?? 0;
        $book['free_copies'] = $free;
        $book['earliest_pickup_date'] = null;

        if ((int) ($book['is_active'] ?? 1) !== 1 || $total < 1) {
            $book['availability_status'] = 'unavailable';
        } elseif ($free > 0) {
            $book['availability_status'] = 'available';
            $book['earliest_pickup_date'] = date('Y-m-d');
        } elseif ($book['active_loan_count'] > 0 || $held > 0) {
            $book['availability_status'] = 'busy';
            $releasesNeeded = max(1, $held - $available + 1);
            if (isset($dates[$releasesNeeded - 1])) {
                $book['earliest_pickup_date'] = $dates[$releasesNeeded - 1];
            }
        } else {
            $book['availability_status'] = 'unavailable';
        }
    }
    unset($book);

    return $books;
}

function library_feature_status_label(string $status): string
{
    $labels = [
        'available' => 'Mavjud',
        'busy' => 'Band',
        'unavailable' => 'Mavjud emas',
    ];
    return $labels[$status] ?? 'Noma’lum';
}

function library_feature_reservation_label(string $status): string
{
    $labels = [
        'pending' => 'Kutilmoqda',
        'approved' => 'Tasdiqlangan',
        'ready' => 'Olib ketishga tayyor',
        'collected' => 'Olib ketilgan',
        'cancelled' => 'Bekor qilingan',
        'expired' => 'Muddati tugagan',
    ];
    return $labels[$status] ?? $status;
}

function library_feature_listing_label(string $type): string
{
    return ['sale' => 'Sotuv', 'rental' => 'Ijara', 'both' => 'Sotuv va ijara'][$type] ?? 'Kutubxona';
}

function money_uzs($amount): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return number_format((float) $amount, 2, '.', ' ') . ' so‘m';
}

function whatsapp_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function library_feature_admin_nav(string $active): string
{
    global $pdo;
    $role = isset($pdo) ? (string) ((current_user($pdo)['role'] ?? '')) : '';
    if ($role === 'admin') {
        $items = [
            'students' => ['admin/students.php', 'fa-users', 'O‘quvchilar'],
            'reservations' => ['admin/reservations.php', 'fa-calendar-check', 'Band qilishlar'],
            'issue' => ['admin/issue-book.php', 'fa-arrow-up-right-from-square', 'Kitob berish'],
            'return' => ['admin/return-book.php', 'fa-rotate-left', 'Kitobni qaytarish'],
        ];
    } else {
        $items = [
            'dashboard' => ['vendor/index.php', 'fa-chart-pie', 'Sotuvchi paneli'],
            'books' => ['vendor/index.php', 'fa-book', 'Mening kitoblarim'],
            'add-book' => ['vendor/book-form.php', 'fa-square-plus', 'E’lon qo‘shish'],
            'reservations' => ['admin/reservations.php', 'fa-calendar-check', 'Band qilishlar'],
            'issue' => ['admin/issue-book.php', 'fa-arrow-up-right-from-square', 'Kitob berish'],
            'return' => ['admin/return-book.php', 'fa-rotate-left', 'Kitobni qaytarish'],
        ];
    }
    $html = '<nav class="admin-nav" aria-label="Admin navigatsiyasi">';
    foreach ($items as $key => $item) {
        $html .= '<a' . ($active === $key ? ' class="active"' : '') . ' href="' . e(APP_URL . '/' . $item[0]) . '"><i class="fa-solid ' . e($item[1]) . '"></i>' . e($item[2]) . '</a>';
    }
    return $html . '<div class="nav-divider"></div><a href="' . e(APP_URL . '/index.php') . '"><i class="fa-solid fa-globe"></i>Ochiq katalog</a></nav>';
}


function library_feature_save_cover($file, array &$errors): ?string
{
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Muqova rasmini yuklashda xatolik yuz berdi.';
        return null;
    }
    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || $size < 1 || $size > MAX_COVER_SIZE) {
        $errors[] = 'Muqova haqiqiy va 5 MB dan kichik rasm bo‘lishi kerak.';
        return null;
    }
    $image = @getimagesize($temporaryPath);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = is_array($image) ? strtolower((string) ($image['mime'] ?? '')) : '';
    if (!isset($types[$mime])) {
        $errors[] = 'Faqat JPG, PNG yoki WEBP muqova qabul qilinadi.';
        return null;
    }
    $directory = dirname(__DIR__) . '/uploads/covers';
    if ((!is_dir($directory) && !mkdir($directory, 0775, true)) || !is_writable($directory)) {
        $errors[] = 'Muqova papkasiga yozib bo‘lmadi.';
        return null;
    }
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(12)) . '.' . $types[$mime];
    if (!move_uploaded_file($temporaryPath, $directory . '/' . $filename)) {
        $errors[] = 'Muqovani saqlab bo‘lmadi.';
        return null;
    }
    return $filename;
}

function library_feature_delete_cover($filename): void
{
    if (!is_string($filename) || $filename === '' || basename($filename) === 'default.jpg') {
        return;
    }
    $path = dirname(__DIR__) . '/uploads/covers/' . basename($filename);
    if (is_file($path)) {
        @unlink($path);
    }
}
