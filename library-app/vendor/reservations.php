<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
$user = require_role($pdo, 'librarian');
$ownerId = (int) $user['id'];
sync_overdue_transactions($pdo, $ownerId);
library_feature_expire_reservations($pdo, $ownerId);
$errors = [];
$allowedTransitions = ['pending' => ['approved', 'cancelled'], 'approved' => ['ready', 'cancelled'], 'ready' => ['collected', 'cancelled']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reservationId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    $targetStatus = trim((string) ($_POST['status'] ?? ''));
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) $errors[] = 'Xavfsizlik tokeni yaroqsiz.';
    if ($reservationId < 1) $errors[] = 'So‘rov tanlanmagan.';

    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare("SELECT r.*, b.title, b.available_copies, b.is_active book_is_active, s.full_name, s.is_active student_is_active
                FROM reservations r INNER JOIN books b ON b.id=r.book_id INNER JOIN students s ON s.id=r.student_id
                WHERE r.id=:id AND b.user_id=:owner_id FOR UPDATE");
            $statement->execute(['id' => $reservationId, 'owner_id' => $ownerId]);
            $reservation = $statement->fetch();
            if (!$reservation) throw new RuntimeException('So‘rov topilmadi yoki sizga tegishli emas.');
            $currentStatus = (string) $reservation['status'];
            if (!isset($allowedTransitions[$currentStatus]) || !in_array($targetStatus, $allowedTransitions[$currentStatus], true)) throw new RuntimeException('Bu holat o‘zgarishiga ruxsat berilmagan.');
            if (in_array($targetStatus, ['approved', 'ready', 'collected'], true) && ((int) $reservation['book_is_active'] !== 1 || (int) $reservation['student_is_active'] !== 1)) throw new RuntimeException('Arxivdagi kitob yoki o‘quvchi so‘rovini davom ettirib bo‘lmaydi.');

            if ($targetStatus === 'approved') {
                $holds = $pdo->prepare("SELECT r.id FROM reservations r INNER JOIN books b ON b.id=r.book_id WHERE r.book_id=:book_id AND r.id<>:id AND r.status IN ('approved','ready') AND (r.expires_at IS NULL OR r.expires_at>=NOW()) AND b.user_id=:owner_id FOR UPDATE");
                $holds->execute(['book_id' => $reservation['book_id'], 'id' => $reservationId, 'owner_id' => $ownerId]);
                if ((int) $reservation['available_copies'] - count($holds->fetchAll()) < 1) throw new RuntimeException('Tasdiqlash uchun erkin nusxa yo‘q.');
            }

            $fields = ['r.status=:status'];
            $params = ['status' => $targetStatus, 'id' => $reservationId, 'current_status' => $currentStatus, 'owner_id' => $ownerId];
            if ($targetStatus === 'approved') $fields[] = 'r.approved_at=NOW()';
            elseif ($targetStatus === 'ready') $fields[] = 'r.ready_at=NOW()';
            elseif ($targetStatus === 'cancelled') $fields[] = 'r.cancelled_at=NOW()';
            elseif ($targetStatus === 'collected') {
                if ((int) $reservation['available_copies'] < 1) throw new RuntimeException('Kitob javonda yo‘q.');
                $activeLoan = $pdo->prepare("SELECT bt.id FROM borrow_transactions bt INNER JOIN books b ON b.id=bt.book_id WHERE bt.book_id=:book_id AND bt.student_id=:student_id AND bt.status IN ('borrowed','overdue') AND bt.return_date IS NULL AND b.user_id=:owner_id LIMIT 1 FOR UPDATE");
                $activeLoan->execute(['book_id' => $reservation['book_id'], 'student_id' => $reservation['student_id'], 'owner_id' => $ownerId]);
                if ($activeLoan->fetchColumn()) throw new RuntimeException('O‘quvchida bu kitobning faol nusxasi bor.');
                $loan = $pdo->prepare("INSERT INTO borrow_transactions (book_id,student_id,borrow_date,due_date,status) VALUES (:book_id,:student_id,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 14 DAY),'borrowed')");
                $loan->execute(['book_id' => $reservation['book_id'], 'student_id' => $reservation['student_id']]);
                $params['loan_id'] = (int) $pdo->lastInsertId();
                $stock = $pdo->prepare('UPDATE books SET available_copies=available_copies-1 WHERE id=:id AND user_id=:owner_id AND available_copies>0');
                $stock->execute(['id' => $reservation['book_id'], 'owner_id' => $ownerId]);
                if ($stock->rowCount() !== 1) throw new RuntimeException('Kitob qoldig‘ini yangilab bo‘lmadi.');
                $fields[] = 'r.collected_at=NOW()';
                $fields[] = 'r.borrow_transaction_id=:loan_id';
            }
            $update = $pdo->prepare('UPDATE reservations r INNER JOIN books b ON b.id=r.book_id SET ' . implode(',', $fields) . ' WHERE r.id=:id AND r.status=:current_status AND b.user_id=:owner_id');
            $update->execute($params);
            if ($update->rowCount() !== 1) throw new RuntimeException('So‘rov holati boshqa jarayonda o‘zgargan.');
            $pdo->commit();
            set_flash('success', '“' . htmlspecialchars_decode((string) $reservation['title'], ENT_QUOTES) . '” so‘rovi yangilandi.');
            redirect('vendor/reservations.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Holatni yangilab bo‘lmadi.';
        }
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
$validStatuses = ['pending', 'approved', 'ready', 'collected', 'cancelled', 'expired'];
$sql = "SELECT r.*,b.title,b.author,b.available_copies,b.total_copies,b.is_active,s.first_name,s.last_name,s.class_name,s.phone,
    (SELECT COUNT(*) FROM reservations h INNER JOIN books hb ON hb.id=h.book_id WHERE h.book_id=r.book_id AND h.status IN ('approved','ready') AND (h.expires_at IS NULL OR h.expires_at>=NOW()) AND hb.user_id=:hold_owner_id) held_copies
    FROM reservations r INNER JOIN books b ON b.id=r.book_id INNER JOIN students s ON s.id=r.student_id WHERE b.user_id=:owner_id";
$params = ['hold_owner_id' => $ownerId, 'owner_id' => $ownerId];
if (in_array($statusFilter, $validStatuses, true)) { $sql .= ' AND r.status=:status'; $params['status'] = $statusFilter; }
$sql .= " ORDER BY FIELD(r.status,'pending','approved','ready','collected','cancelled','expired'),r.pickup_date,r.created_at";
$statement = $pdo->prepare($sql); $statement->execute($params); $reservations = $statement->fetchAll(); $flash = get_flash();
?>
<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Band qilishlar — <?= e(APP_NAME) ?></title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css"></head>
<body class="admin-body"><div class="admin-layout"><aside class="admin-sidebar"><a class="admin-brand" href="<?= e(APP_URL) ?>/vendor/index.php"><span><i class="fa-solid fa-store"></i></span><div><strong>Marketplace</strong><small>Sotuvchi paneli</small></div></a><?= library_feature_vendor_nav('reservations') ?></aside><main class="admin-main"><header class="admin-topbar"><button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menyuni ochish"><i class="fa-solid fa-bars"></i></button><div><p class="admin-eyebrow">Mening kitoblarim</p><h1>Band qilishlar</h1></div><span class="active-counter"><?= count($reservations) ?> ta</span></header>
<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"><?= e($flash['message']) ?><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button></div><?php endif; ?><?php if ($errors): ?><div class="alert alert-danger alert-dismissible fade show"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button></div><?php endif; ?>
<div class="filter-pills mb-4"><a class="<?= $statusFilter === '' ? 'active' : '' ?>" href="?">Barchasi</a><?php foreach ($validStatuses as $status): ?><a class="<?= $statusFilter === $status ? 'active' : '' ?>" href="?status=<?= e($status) ?>"><?= e(library_feature_reservation_label($status)) ?></a><?php endforeach; ?></div>
<section class="admin-panel"><div class="table-responsive"><table class="table admin-table align-middle"><thead><tr><th>Kitob</th><th>O‘quvchi</th><th>Sana</th><th>Mavjudlik</th><th>Holat / amal</th></tr></thead><tbody><?php if ($reservations === []): ?><tr><td colspan="5" class="text-center py-5 text-secondary">So‘rov yo‘q.</td></tr><?php endif; ?><?php foreach ($reservations as $reservation): ?><?php $free = max(0, (int) $reservation['available_copies'] - (int) $reservation['held_copies']); ?><tr><td><strong><?= e($reservation['title']) ?></strong><small class="d-block"><?= e($reservation['author']) ?></small></td><td><strong><?= e($reservation['first_name'] . ' ' . $reservation['last_name']) ?></strong><small class="d-block"><?= e($reservation['class_name']) ?> · <?= e($reservation['phone'] ?: 'Kontakt yo‘q') ?></small></td><td><?= e(library_feature_date($reservation['pickup_date'])) ?><small class="d-block">So‘rov: <?= e(library_feature_date($reservation['request_date'])) ?></small></td><td><span class="stock-count <?= $free > 0 ? 'in-stock' : 'out-stock' ?>"><?= $free ?> erkin</span></td><td><span class="reservation-status status-<?= e($reservation['status']) ?>"><?= e(library_feature_reservation_label($reservation['status'])) ?></span><?php if (isset($allowedTransitions[$reservation['status']])): ?><form class="d-flex gap-1 mt-2" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $reservation['id'] ?>"><select class="form-select form-select-sm admin-inline-select" name="status"><?php foreach ($allowedTransitions[$reservation['status']] as $next): ?><option value="<?= e($next) ?>"><?= e(library_feature_reservation_label($next)) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary" type="submit">Saqlash</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</main></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(APP_URL) ?>/assets/js/app.js"></script></body></html>
