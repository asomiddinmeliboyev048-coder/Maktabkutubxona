<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

const APP_NAME = 'Maktab Kutubxonasi';

/*
 * Loyiha manzili:
 * C:\xampp\htdocs\library-app\library-app
 */
const APP_URL = '/library-app/library-app';

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'school_library';
const DB_USER = 'root';
const DB_PASS = '';

const MAX_COVER_SIZE = 5242880;

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST .
        ';port=' . DB_PORT .
        ';dbname=' . DB_NAME .
        ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    http_response_code(500);

    exit(
        '<!doctype html>' .
        '<html lang="uz">' .
        '<head>' .
        '<meta charset="utf-8">' .
        '<meta name="viewport" content="width=device-width, initial-scale=1">' .
        '<title>Ulanish xatosi</title>' .
        '<style>' .
        'body{font-family:Arial,sans-serif;background:#f8fafc;padding:40px;color:#0f172a}' .
        '.box{max-width:720px;margin:auto;background:#fff;padding:28px;border-radius:16px;box-shadow:0 12px 35px #0f172a1a}' .
        'code{background:#fee2e2;padding:3px 7px;border-radius:5px}' .
        '</style>' .
        '</head>' .
        '<body>' .
        '<div class="box">' .
        '<h1>Ma’lumotlar bazasiga ulanib bo‘lmadi</h1>' .
        '<p><code>database.sql</code> faylini phpMyAdmin orqali import qiling.</p>' .
        '<p><code>config/db.php</code> ichidagi baza nomi va ulanish ma’lumotlarini tekshiring.</p>' .
        '</div>' .
        '</body>' .
        '</html>'
    );
}

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8',
        false
    );
}

function sanitize_input($data): string
{
    if (!is_string($data)) {
        return '';
    }

    return htmlspecialchars(
        trim($data),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8',
        false
    );
}

function csrf_token(): string
{
    if (
        !isset($_SESSION['csrf_token']) ||
        !is_string($_SESSION['csrf_token']) ||
        $_SESSION['csrf_token'] === ''
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    if (
        !isset($_SESSION['flash']) ||
        !is_array($_SESSION['flash'])
    ) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function redirect(string $path): void
{
    header('Location: ' . APP_URL . '/' . ltrim($path, '/'));
    exit;
}

/*
 * Standart muqova rasmni fayl yaratmasdan SVG sifatida qaytaradi.
 * Shu sabab default.jpg buzilgan bo‘lsa ham, kitob muqovasi ko‘rinadi.
 */
function default_book_cover_url(): string
{
    $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800" viewBox="0 0 600 800">
    <defs>
        <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="#2563eb"/>
            <stop offset="52%" stop-color="#4f46e5"/>
            <stop offset="100%" stop-color="#7c3aed"/>
        </linearGradient>
        <linearGradient id="book" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="#ffffff"/>
            <stop offset="100%" stop-color="#dbeafe"/>
        </linearGradient>
        <filter id="shadow">
            <feDropShadow dx="0" dy="18" stdDeviation="20" flood-color="#172554" flood-opacity=".32"/>
        </filter>
    </defs>

    <rect width="600" height="800" fill="url(#bg)"/>
    <circle cx="80" cy="110" r="150" fill="#ffffff" opacity=".06"/>
    <circle cx="555" cy="690" r="210" fill="#ffffff" opacity=".06"/>

    <rect
        x="52"
        y="55"
        width="496"
        height="690"
        rx="36"
        fill="none"
        stroke="#ffffff"
        stroke-width="2"
        opacity=".24"
    />

    <g filter="url(#shadow)">
        <path
            d="M145 240 Q225 205 300 270 V535 Q225 475 145 510 Z"
            fill="url(#book)"
        />
        <path
            d="M455 240 Q375 205 300 270 V535 Q375 475 455 510 Z"
            fill="#ffffff"
        />
        <path
            d="M300 270 V535"
            stroke="#93c5fd"
            stroke-width="7"
            stroke-linecap="round"
        />
        <path
            d="M170 285 Q230 265 274 300"
            fill="none"
            stroke="#bfdbfe"
            stroke-width="8"
            stroke-linecap="round"
        />
        <path
            d="M430 285 Q370 265 326 300"
            fill="none"
            stroke="#ddd6fe"
            stroke-width="8"
            stroke-linecap="round"
        />
        <path
            d="M170 330 Q230 310 274 345"
            fill="none"
            stroke="#bfdbfe"
            stroke-width="8"
            stroke-linecap="round"
        />
        <path
            d="M430 330 Q370 310 326 345"
            fill="none"
            stroke="#ddd6fe"
            stroke-width="8"
            stroke-linecap="round"
        />
    </g>

    <text
        x="300"
        y="625"
        text-anchor="middle"
        fill="#ffffff"
        font-family="Arial, sans-serif"
        font-size="30"
        font-weight="700"
        letter-spacing="2"
    >MAKTAB KUTUBXONASI</text>

    <text
        x="300"
        y="670"
        text-anchor="middle"
        fill="#dbeafe"
        font-family="Arial, sans-serif"
        font-size="21"
    >Bilim sari bir qadam</text>
</svg>
SVG;

    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
}

/*
 * Yuklangan muqova mavjud bo‘lsa URL’ini qaytaradi.
 * Fayl yo‘q, bo‘sh yoki noto‘g‘ri formatda bo‘lsa SVG muqova qaytaradi.
 */
function book_cover_url($filename): string
{
    if (!is_string($filename) || trim($filename) === '') {
        return default_book_cover_url();
    }

    $safeFilename = basename(trim($filename));
    $extension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));

    $allowedExtensions = [
        'jpg',
        'jpeg',
        'png',
        'webp',
    ];

    if (!in_array($extension, $allowedExtensions, true)) {
        return default_book_cover_url();
    }

    $uploadDirectory =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        'covers';

    $fullPath =
        $uploadDirectory .
        DIRECTORY_SEPARATOR .
        $safeFilename;

    if (
        !is_file($fullPath) ||
        !is_readable($fullPath) ||
        filesize($fullPath) < 1
    ) {
        return default_book_cover_url();
    }

    return APP_URL .
        '/uploads/covers/' .
        rawurlencode($safeFilename);
}

function render_stars(float $rating): string
{
    $rounded = (int) round($rating);

    $html =
        '<span class="rating-stars" aria-label="' .
        e(number_format($rating, 1)) .
        ' dan 5">';

    for ($index = 1; $index <= 5; $index++) {
        $class = $index <= $rounded
            ? 'fa-solid'
            : 'fa-regular';

        $html .=
            '<i class="' .
            $class .
            ' fa-star"></i>';
    }

    return $html . '</span>';
}

function sync_overdue_transactions(PDO $pdo): void
{
    $statement = $pdo->prepare(
        "UPDATE borrow_transactions
         SET status = 'overdue'
         WHERE status = 'borrowed'
           AND return_date IS NULL
           AND due_date < CURDATE()"
    );

    $statement->execute();
}


require_once dirname(__DIR__) . '/includes/feature_helpers.php';
