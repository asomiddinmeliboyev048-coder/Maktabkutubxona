<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';

$errors = [];
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$form = ['first_name' => '', 'last_name' => '', 'class_name' => '', 'phone' => '', 'student_code' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Xavfsizlik tokeni yaroqsiz.';
    }

    if ($errors === [] && in_array($action, ['archive', 'restore', 'delete'], true)) {
        try {
            $reference = $pdo->prepare(
                'SELECT s.id,
                        (SELECT COUNT(*) FROM borrow_transactions WHERE student_id = s.id) +
                        (SELECT COUNT(*) FROM reservations WHERE student_id = s.id) AS reference_count
                 FROM students s
                 WHERE s.id = :id'
            );
            $reference->execute(['id' => $id]);
            $student = $reference->fetch();
            if (!$student) {
                throw new RuntimeException('O‘quvchi topilmadi.');
            }

            $referenced = (int) $student['reference_count'] > 0;
            if ($action !== 'restore' && $referenced) {
                throw new RuntimeException('Bu o‘quvchi berish yoki band qilish tarixiga bog‘langan; uni o‘chirib ham, arxivlab ham bo‘lmaydi.');
            }

            if ($action === 'delete') {
                $statement = $pdo->prepare('DELETE FROM students WHERE id = :id');
                $message = 'O‘quvchi yozuvi o‘chirildi.';
            } else {
                $isActive = $action === 'restore' ? 1 : 0;
                $statement = $pdo->prepare('UPDATE students SET is_active = :is_active WHERE id = :id');
                $statement->bindValue(':is_active', $isActive, PDO::PARAM_INT);
                $message = $isActive ? 'O‘quvchi qayta faollashtirildi.' : 'O‘quvchi arxivlandi.';
            }
            $statement->bindValue(':id', $id, PDO::PARAM_INT);
            $statement->execute();
            set_flash('success', $message);
            redirect('admin/students.php');
        } catch (Throwable $exception) {
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Amalni bajarib bo‘lmadi.';
        }
    }

    if ($action === 'save') {
        foreach (array_keys($form) as $field) {
            $form[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        if (mb_strlen($form['first_name']) < 2 || mb_strlen($form['first_name']) > 80) $errors[] = 'Ism 2–80 belgidan iborat bo‘lsin.';
        if (mb_strlen($form['last_name']) < 2 || mb_strlen($form['last_name']) > 80) $errors[] = 'Familiya 2–80 belgidan iborat bo‘lsin.';
        if ($form['class_name'] === '' || mb_strlen($form['class_name']) > 50) $errors[] = 'Sinfni kiriting.';
        if (mb_strlen($form['phone']) < 7 || mb_strlen($form['phone']) > 100) $errors[] = 'Kontakt 7–100 belgidan iborat bo‘lsin.';
        if ($form['student_code'] === '') $form['student_code'] = 'ST-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        if (mb_strlen($form['student_code']) > 50) $errors[] = 'O‘quvchi kodi 50 belgidan oshmasin.';

        if ($errors === []) {
            try {
                $params = [
                    'first_name' => $form['first_name'], 'last_name' => $form['last_name'],
                    'full_name' => $form['first_name'] . ' ' . $form['last_name'],
                    'class_name' => $form['class_name'], 'phone' => $form['phone'] !== '' ? $form['phone'] : null,
                    'student_code' => $form['student_code'],
                ];
                if ($id > 0) {
                    $params['id'] = $id;
                    $statement = $pdo->prepare('UPDATE students SET first_name=:first_name,last_name=:last_name,full_name=:full_name,class_name=:class_name,phone=:phone,student_code=:student_code,updated_at=NOW() WHERE id=:id');
                } else {
                    $statement = $pdo->prepare('INSERT INTO students (first_name,last_name,full_name,class_name,phone,student_code,is_active) VALUES (:first_name,:last_name,:full_name,:class_name,:phone,:student_code,1)');
                }
                $statement->execute($params);
                set_flash('success', $id > 0 ? 'O‘quvchi ma’lumotlari yangilandi.' : 'Yangi o‘quvchi qo‘shildi.');
                redirect('admin/students.php');
            } catch (PDOException $exception) {
                $errors[] = $exception->getCode() === '23000' ? 'Bu o‘quvchi kodi allaqachon ishlatilgan.' : 'Ma’lumotni saqlab bo‘lmadi.';
            }
        }
    }
}

if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $statement = $pdo->prepare('SELECT first_name,last_name,class_name,phone,student_code FROM students WHERE id=:id');
    $statement->execute(['id' => $editId]);
    $found = $statement->fetch();
    if ($found) $form = $found;
}
$statement = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM borrow_transactions bt WHERE bt.student_id=s.id) loan_count, (SELECT COUNT(*) FROM reservations r WHERE r.student_id=s.id) reservation_count FROM students s ORDER BY s.is_active DESC,s.first_name,s.last_name');
$statement->execute();
$students = $statement->fetchAll();
$flash = get_flash();
?>
<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>O‘quvchilar — <?= e(APP_NAME) ?></title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css"></head><body class="admin-body"><div class="admin-layout"><aside class="admin-sidebar"><a class="admin-brand" href="<?= e(APP_URL) ?>/admin/index.php"><span><i class="fa-solid fa-book-open"></i></span><div><strong>Kutubxona</strong><small>Boshqaruv paneli</small></div></a><?= library_feature_admin_nav('students') ?></aside><main class="admin-main"><header class="admin-topbar"><button class="sidebar-toggle" type="button" data-sidebar-toggle><i class="fa-solid fa-bars"></i></button><div><p class="admin-eyebrow">A’zolar</p><h1>O‘quvchilar</h1></div><span class="active-counter"><?= count($students) ?> ta yozuv</span></header>
<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?><?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="row g-4"><div class="col-xl-4"><section class="admin-panel"><div class="panel-heading"><div><p class="admin-eyebrow"><?= $editId ? 'Tahrirlash' : 'Yangi yozuv' ?></p><h2 class="h4"><?= $editId ? 'O‘quvchini yangilash' : 'O‘quvchi qo‘shish' ?></h2></div></div><form class="admin-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editId ?>"><div class="row g-3"><div class="col-sm-6"><label class="form-label" for="first_name">Ism</label><input class="form-control" id="first_name" name="first_name" value="<?= e($form['first_name']) ?>" required></div><div class="col-sm-6"><label class="form-label" for="last_name">Familiya</label><input class="form-control" id="last_name" name="last_name" value="<?= e($form['last_name']) ?>" required></div><div class="col-12"><label class="form-label" for="class_name">Sinf</label><input class="form-control" id="class_name" name="class_name" value="<?= e($form['class_name']) ?>" required></div><div class="col-12"><label class="form-label" for="phone">Kontakt</label><input class="form-control" id="phone" name="phone" value="<?= e($form['phone']) ?>" maxlength="100" required></div><div class="col-12"><label class="form-label" for="student_code">O‘quvchi kodi</label><input class="form-control" id="student_code" name="student_code" value="<?= e($form['student_code']) ?>" placeholder="Bo‘sh qolsa avtomatik"></div><div class="col-12 d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit">Saqlash</button><?php if ($editId): ?><a class="btn btn-outline-light" href="<?= e(APP_URL) ?>/admin/students.php">Bekor qilish</a><?php endif; ?></div></div></form></section></div>
<div class="col-xl-8"><section class="admin-panel"><div class="table-responsive"><table class="table admin-table align-middle"><thead><tr><th>O‘quvchi</th><th>Sinf / kontakt</th><th>Tarix</th><th>Holat</th><th>Amal</th></tr></thead><tbody><?php foreach ($students as $student): ?><tr><td><strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong><small class="d-block"><?= e($student['student_code']) ?></small></td><td><?= e($student['class_name']) ?><small class="d-block"><?= e($student['phone'] ?: 'Kontakt yo‘q') ?></small></td><td><?= (int)$student['loan_count'] ?> kitob · <?= (int)$student['reservation_count'] ?> band</td><td><span class="status-pill <?= (int)$student['is_active'] ? 'status-returned' : 'status-overdue' ?>"><?= (int)$student['is_active'] ? 'Faol' : 'Arxiv' ?></span></td><td><div class="d-flex gap-1"><a class="btn btn-sm btn-outline-light" href="?edit=<?= (int)$student['id'] ?>" aria-label="O‘quvchini tahrirlash"><i class="fa-solid fa-pen"></i></a><?php if (!(int)$student['is_active']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$student['id'] ?>"><input type="hidden" name="action" value="restore"><button class="btn btn-sm btn-outline-success" type="submit" data-confirm-action="O‘quvchini qayta faollashtirasizmi?" aria-label="Qayta faollashtirish"><i class="fa-solid fa-rotate-left"></i></button></form><?php elseif ((int)$student['loan_count'] + (int)$student['reservation_count'] > 0): ?><span class="btn btn-sm btn-outline-light disabled" title="Tarixga bog‘langan yozuv himoyalangan" aria-label="Tarixga bog‘langan yozuv"><i class="fa-solid fa-lock"></i></span><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$student['id'] ?>"><input type="hidden" name="action" value="archive"><button class="btn btn-sm btn-outline-light" type="submit" data-confirm-action="O‘quvchini arxivlaysizmi?" aria-label="Arxivlash"><i class="fa-solid fa-box-archive"></i></button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$student['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-outline-danger" type="submit" data-confirm-action="O‘quvchini butunlay o‘chirasizmi?" aria-label="Butunlay o‘chirish"><i class="fa-solid fa-trash"></i></button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section></div></div></main></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(APP_URL) ?>/assets/js/app.js"></script></body></html>
