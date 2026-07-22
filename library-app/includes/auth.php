<?php
declare(strict_types=1);

/** Authentication, authorization and session helpers. */

/** @var array{loaded: bool, id: ?int, user: ?array} */
$GLOBALS['current_user_cache'] = ['loaded' => false, 'id' => null, 'user' => null];

function invalidate_current_user_cache(): void
{
    $GLOBALS['current_user_cache'] = ['loaded' => false, 'id' => null, 'user' => null];
}

function current_user(PDO $pdo): ?array
{
    $sessionId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$sessionId || $sessionId < 1) {
        invalidate_current_user_cache();
        return null;
    }

    $cache = $GLOBALS['current_user_cache'];
    if ($cache['loaded'] && $cache['id'] === (int) $sessionId) {
        return $cache['user'];
    }

    $statement = $pdo->prepare(
        'SELECT id, first_name, last_name, class_name, email, phone, role, is_active
         FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $sessionId]);
    $user = $statement->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        unset($_SESSION['user_id']);
        invalidate_current_user_cache();
        return null;
    }

    $GLOBALS['current_user_cache'] = ['loaded' => true, 'id' => (int) $sessionId, 'user' => $user];
    return $user;
}

function login_user(array $user): void
{
    $userId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId || $userId < 1) {
        throw new InvalidArgumentException('Foydalanuvchi identifikatori yaroqsiz.');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $userId;
    unset($_SESSION['csrf_token']);
    invalidate_current_user_cache();
    csrf_token();
}

function logout_user(): void
{
    unset($_SESSION['user_id'], $_SESSION['csrf_token']);
    invalidate_current_user_cache();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        csrf_token();
    }
}

function require_auth(PDO $pdo): array
{
    $user = current_user($pdo);
    if ($user === null) {
        set_flash('warning', 'Davom etish uchun tizimga kiring.');
        redirect('login.php');
    }
    return $user;
}

function require_any_role(PDO $pdo, array $roles): array
{
    $user = require_auth($pdo);
    if (!in_array((string) ($user['role'] ?? ''), $roles, true)) {
        http_response_code(403);
        exit('Bu sahifaga kirish uchun ruxsatingiz yo‘q.');
    }
    return $user;
}

function require_role(PDO $pdo, string $role): array
{
    return require_any_role($pdo, [$role]);
}

function role_dashboard_path(string $role): string
{
    return match ($role) {
        'admin' => 'admin/index.php',
        'librarian' => 'vendor/index.php',
        default => 'index.php',
    };
}

function user_is_owner(array $book, array $user): bool
{
    return isset($book['user_id']) && (int) $book['user_id'] === (int) $user['id'];
}

function public_auth_navigation(PDO $pdo): string
{
    $user = current_user($pdo);
    if ($user === null) {
        return '<div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="' . e(APP_URL . '/login.php') . '">Kirish</a><a class="btn btn-primary" href="' . e(APP_URL . '/register.php') . '">Ro‘yxatdan o‘tish</a></div>';
    }

    $dashboard = role_dashboard_path((string) $user['role']);
    return '<div class="d-flex flex-wrap align-items-center gap-2"><span class="small text-secondary">' . e($user['first_name'] . ' ' . $user['last_name']) . '</span><a class="btn btn-outline-primary" href="' . e(APP_URL . '/' . $dashboard) . '">Kabinet</a><form method="post" action="' . e(APP_URL . '/logout.php') . '"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><button class="btn btn-outline-danger" type="submit">Chiqish</button></form></div>';
}
