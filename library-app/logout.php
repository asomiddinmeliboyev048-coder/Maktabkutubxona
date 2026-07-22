<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Faqat POST so‘rovi qabul qilinadi.');
}
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Xavfsizlik tokeni yaroqsiz.');
}
logout_user();
header('Location: ' . APP_URL . '/login.php');
exit;
