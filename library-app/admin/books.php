<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_any_role($pdo, ['librarian', 'admin']);
$user = current_user($pdo);
if ($user['role'] === 'admin') {
    redirect('admin/reservations.php');
}
redirect('vendor/index.php');
