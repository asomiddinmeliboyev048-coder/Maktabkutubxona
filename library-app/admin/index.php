<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
$user = require_any_role($pdo, ['librarian', 'admin']);
if ($user['role'] === 'librarian') {
    redirect('vendor/index.php');
}
redirect('admin/reservations.php');
