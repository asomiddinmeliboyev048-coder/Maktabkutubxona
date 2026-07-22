<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
if (($existing = current_user($pdo)) !== null) redirect(role_dashboard_path((string) $existing['role']));
$errors = [];
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) $errors[] = 'Xavfsizlik tokeni yaroqsiz.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail manzilini to‘g‘ri kiriting.';
    if ($errors === []) {
        $statement = $pdo->prepare('SELECT id,password_hash,role,is_active FROM users WHERE email=:email LIMIT 1');
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, (string) $user['password_hash'])) {
            $errors[] = 'E-mail yoki parol noto‘g‘ri.';
        } else {
            if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
                $rehash = $pdo->prepare('UPDATE users SET password_hash=:hash WHERE id=:id');
                $rehash->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
            }
            login_user($user);
            set_flash('success', 'Xush kelibsiz!');
            redirect(role_dashboard_path((string) $user['role']));
        }
    }
}
$flash=get_flash();
?>
<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kirish — <?= e(APP_NAME) ?></title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css"></head><body><main class="auth-shell"><section class="auth-card"><a class="navbar-brand d-flex align-items-center gap-2 fw-bold mb-4" href="<?= e(APP_URL) ?>/index.php"><span class="brand-icon">K</span><span><?= e(APP_NAME) ?></span></a><h1 class="h3 fw-bold">Tizimga kirish</h1><p class="text-secondary">Marketplace hisobingizdan foydalaning.</p><?php if($flash):?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif;?><?php if($errors):?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as$error):?><li><?= e($error) ?></li><?php endforeach;?></ul></div><?php endif;?><form method="post" class="vstack gap-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div><label class="form-label" for="email">E-mail</label><input class="form-control" type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus></div><div><label class="form-label" for="password">Parol</label><input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required></div><button class="btn btn-primary btn-lg" type="submit">Kirish</button></form><p class="mt-4 mb-0 text-center">Hisobingiz yo‘qmi? <a href="<?= e(APP_URL) ?>/register.php">Ro‘yxatdan o‘tish</a></p></section></main></body></html>
