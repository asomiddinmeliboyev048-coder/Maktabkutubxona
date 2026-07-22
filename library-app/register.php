<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

if (($existing = current_user($pdo)) !== null) {
    redirect(role_dashboard_path((string) $existing['role']));
}

$errors = [];
$form = ['first_name' => '', 'last_name' => '', 'class_name' => '', 'email' => '', 'phone' => '', 'role' => 'student'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $field) {
        $form[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) $errors[] = 'Xavfsizlik tokeni yaroqsiz.';
    if (mb_strlen($form['first_name']) < 2 || mb_strlen($form['first_name']) > 80) $errors[] = 'Ism 2–80 belgidan iborat bo‘lsin.';
    if (mb_strlen($form['last_name']) < 2 || mb_strlen($form['last_name']) > 80) $errors[] = 'Familiya 2–80 belgidan iborat bo‘lsin.';
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 190) $errors[] = 'E-mail manzilini to‘g‘ri kiriting.';
    if (mb_strlen($form['phone']) < 7 || mb_strlen($form['phone']) > 30) $errors[] = 'Telefon 7–30 belgidan iborat bo‘lsin.';
    if (!is_public_registration_role($form['role'])) $errors[] = 'Faqat o‘quvchi yoki kutubxonachi rolini tanlash mumkin.';
    if ($form['role'] === 'student' && ($form['class_name'] === '' || mb_strlen($form['class_name']) > 50)) $errors[] = 'O‘quvchi uchun sinf majburiy.';
    if (strlen($password) < 8 || strlen($password) > 72) $errors[] = 'Parol 8–72 belgidan iborat bo‘lsin.';
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) $errors[] = 'Parolda kamida bitta harf va bitta raqam bo‘lsin.';
    if ($password !== $confirmation) $errors[] = 'Parollar mos emas.';

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO users (first_name,last_name,class_name,email,phone,password_hash,role,is_active)
                 VALUES (:first_name,:last_name,:class_name,:email,:phone,:password_hash,:role,1)'
            );
            $insert->execute([
                'first_name' => $form['first_name'], 'last_name' => $form['last_name'],
                'class_name' => $form['role'] === 'student' ? $form['class_name'] : null,
                'email' => mb_strtolower($form['email']), 'phone' => $form['phone'],
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $form['role'],
            ]);
            $userId = (int) $pdo->lastInsertId();
            if ($form['role'] === 'student') {
                $student = $pdo->prepare(
                    'INSERT INTO students (user_id,first_name,last_name,full_name,class_name,phone,student_code,is_active)
                     VALUES (:user_id,:first_name,:last_name,:full_name,:class_name,:phone,:student_code,1)'
                );
                $student->execute([
                    'user_id' => $userId, 'first_name' => $form['first_name'], 'last_name' => $form['last_name'],
                    'full_name' => $form['first_name'] . ' ' . $form['last_name'], 'class_name' => $form['class_name'],
                    'phone' => $form['phone'], 'student_code' => 'WEB-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(5))),
                ]);
            }
            $pdo->commit();
            login_user(['id' => $userId]);
            set_flash('success', 'Hisobingiz muvaffaqiyatli yaratildi.');
            redirect(role_dashboard_path($form['role']));
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $exception->getCode() === '23000' ? 'Bu e-mail allaqachon ro‘yxatdan o‘tgan.' : 'Ro‘yxatdan o‘tib bo‘lmadi.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Ro‘yxatdan o‘tib bo‘lmadi.';
        }
    }
}
$flash = get_flash();
?>
<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ro‘yxatdan o‘tish — <?= e(APP_NAME) ?></title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/style.css"></head><body><main class="auth-shell"><section class="auth-card"><a class="navbar-brand d-flex align-items-center gap-2 fw-bold mb-4" href="<?= e(APP_URL) ?>/index.php"><span class="brand-icon">K</span><span><?= e(APP_NAME) ?></span></a><h1 class="h3 fw-bold">Hisob yaratish</h1><p class="text-secondary">O‘quvchi yoki kutubxonachi sifatida ro‘yxatdan o‘ting.</p><?php if($flash):?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif;?><?php if($errors):?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as$error):?><li><?= e($error) ?></li><?php endforeach;?></ul></div><?php endif;?><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="col-sm-6"><label class="form-label" for="first_name">Ism</label><input class="form-control" id="first_name" name="first_name" value="<?= e($form['first_name']) ?>" maxlength="80" required></div><div class="col-sm-6"><label class="form-label" for="last_name">Familiya</label><input class="form-control" id="last_name" name="last_name" value="<?= e($form['last_name']) ?>" maxlength="80" required></div><div class="col-sm-6"><label class="form-label" for="email">E-mail</label><input class="form-control" type="email" id="email" name="email" value="<?= e($form['email']) ?>" maxlength="190" autocomplete="email" required></div><div class="col-sm-6"><label class="form-label" for="phone">Telefon</label><input class="form-control" type="tel" id="phone" name="phone" value="<?= e($form['phone']) ?>" maxlength="30" autocomplete="tel" required></div><div class="col-sm-6"><label class="form-label" for="role">Rol</label><select class="form-select" id="role" name="role" required><option value="student" <?= $form['role']==='student'?'selected':'' ?>>Oddiy o‘quvchi / Kitobxon</option><option value="librarian" <?= $form['role']==='librarian'?'selected':'' ?>>Kutubxonachi / Sotuvchi</option></select></div><div class="col-sm-6"><label class="form-label" for="class_name">Sinf (o‘quvchi uchun)</label><input class="form-control" id="class_name" name="class_name" value="<?= e($form['class_name']) ?>" maxlength="50"></div><div class="col-sm-6"><label class="form-label" for="password">Parol</label><input class="form-control" type="password" id="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required></div><div class="col-sm-6"><label class="form-label" for="password_confirmation">Parolni takrorlang</label><input class="form-control" type="password" id="password_confirmation" name="password_confirmation" minlength="8" maxlength="72" autocomplete="new-password" required></div><div class="col-12 d-grid"><button class="btn btn-primary btn-lg" type="submit">Ro‘yxatdan o‘tish</button></div></form><p class="mt-4 mb-0 text-center">Hisobingiz bormi? <a href="<?= e(APP_URL) ?>/login.php">Kirish</a></p></section></main></body></html>
