<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
$user = require_any_role($pdo, ['librarian', 'admin']);
$isAdmin = $user['role'] === 'admin';
$ownerClause = $isAdmin ? '' : ' AND b.user_id = :owner_id';
$ownerParams = $isAdmin ? [] : ['owner_id' => $user['id']];

sync_overdue_transactions($pdo, $isAdmin ? null : (int) $user['id']);
library_feature_expire_reservations($pdo, $isAdmin ? null : (int) $user['id']);

$errors = [];
$allowedTransitions = [
    'pending' => ['approved', 'cancelled'],
    'approved' => ['ready', 'cancelled'],
    'ready' => ['collected', 'cancelled'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reservationId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    $targetStatus = trim((string) ($_POST['status'] ?? ''));

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Xavfsizlik tokeni yaroqsiz. Sahifani yangilang.';
    }
    if ($reservationId < 1) {
        $errors[] = 'So‘rov tanlanmagan.';
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                'SELECT r.*, b.title, b.available_copies, b.is_active AS book_is_active,
                        s.full_name, s.is_active AS student_is_active
                 FROM reservations r
                 INNER JOIN books b ON b.id = r.book_id
                 INNER JOIN students s ON s.id = r.student_id
                 WHERE r.id = :id AND (:is_admin = 1 OR b.user_id = :owner_id)
                 FOR UPDATE'
            );
            $statement->execute(['id' => $reservationId, 'is_admin' => $isAdmin ? 1 : 0, 'owner_id' => $user['id']]);
            $reservation = $statement->fetch();

            if (!$reservation) {
                throw new RuntimeException('So‘rov topilmadi.');
            }

            $currentStatus = (string) $reservation['status'];
            if (
                !isset($allowedTransitions[$currentStatus])
                || !in_array($targetStatus, $allowedTransitions[$currentStatus], true)
            ) {
                throw new RuntimeException('Bu holat o‘zgarishiga ruxsat berilmagan.');
            }

            if (
                in_array($targetStatus, ['approved', 'ready', 'collected'], true)
                && (int) $reservation['book_is_active'] !== 1
            ) {
                throw new RuntimeException('Arxivdagi kitob so‘rovini davom ettirib bo‘lmaydi.');
            }
            if (
                in_array($targetStatus, ['approved', 'ready', 'collected'], true)
                && (int) $reservation['student_is_active'] !== 1
            ) {
                throw new RuntimeException('Arxivdagi o‘quvchi so‘rovini davom ettirib bo‘lmaydi.');
            }

            if ($targetStatus === 'approved') {
                $holdStatement = $pdo->prepare(
                    "SELECT id
                     FROM reservations
                     WHERE book_id = :book_id
                       AND id <> :id
                       AND status IN ('approved', 'ready')
                       AND (expires_at IS NULL OR expires_at >= NOW())
                     FOR UPDATE"
                );
                $holdStatement->execute([
                    'book_id' => $reservation['book_id'],
                    'id' => $reservationId,
                ]);
                $otherHolds = count($holdStatement->fetchAll());
                if ((int) $reservation['available_copies'] - $otherHolds < 1) {
                    throw new RuntimeException('Tasdiqlash uchun erkin nusxa yo‘q. Qaytarilishini yoki boshqa band bekor bo‘lishini kuting.');
                }
            }

            $fields = ['status = :status'];
            $params = [
                'status' => $targetStatus,
                'id' => $reservationId,
                'current_status' => $currentStatus,
            ];

            if ($targetStatus === 'approved') {
                $fields[] = 'approved_at = NOW()';
            } elseif ($targetStatus === 'ready') {
                $fields[] = 'ready_at = NOW()';
            } elseif ($targetStatus === 'cancelled') {
                $fields[] = 'cancelled_at = NOW()';
            } elseif ($targetStatus === 'collected') {
                if ((int) $reservation['available_copies'] < 1) {
                    throw new RuntimeException('Kitob javonda yo‘q; avval qaytarishni kiriting.');
                }

                $activeLoan = $pdo->prepare(
                    "SELECT id FROM borrow_transactions
                     WHERE book_id = :book_id AND student_id = :student_id
                       AND status IN ('borrowed', 'overdue') AND return_date IS NULL
                     LIMIT 1 FOR UPDATE"
                );
                $activeLoan->execute([
                    'book_id' => $reservation['book_id'],
                    'student_id' => $reservation['student_id'],
                ]);
                if ($activeLoan->fetchColumn()) {
                    throw new RuntimeException('Bu o‘quvchida ushbu kitobning faol nusxasi allaqachon mavjud.');
                }

                $loan = $pdo->prepare(
                    "INSERT INTO borrow_transactions
                     (book_id, student_id, borrow_date, due_date, status)
                     VALUES (:book_id, :student_id, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'borrowed')"
                );
                $loan->execute([
                    'book_id' => $reservation['book_id'],
                    'student_id' => $reservation['student_id'],
                ]);
                $loanId = (int) $pdo->lastInsertId();

                $stock = $pdo->prepare(
                    'UPDATE books
                     SET available_copies = available_copies - 1
                     WHERE id = :id AND (:is_admin = 1 OR user_id = :owner_id) AND available_copies > 0'
                );
                $stock->execute(['id' => $reservation['book_id'], 'is_admin' => $isAdmin ? 1 : 0, 'owner_id' => $user['id']]);
                if ($stock->rowCount() !== 1) {
                    throw new RuntimeException('Kitob qoldig‘ini yangilab bo‘lmadi.');
                }

                $fields[] = 'collected_at = NOW()';
                $fields[] = 'borrow_transaction_id = :loan_id';
                $params['loan_id'] = $loanId;
            }

            $update = $pdo->prepare(
                'UPDATE reservations SET ' . implode(', ', $fields) . '
                 WHERE id = :id AND status = :current_status'
            );
            $update->execute($params);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('So‘rov holati boshqa jarayonda o‘zgargan. Sahifani yangilang.');
            }

            $pdo->commit();
            set_flash(
                'success',
                '“' . htmlspecialchars_decode((string) $reservation['title'], ENT_QUOTES) . '” so‘rovi: '
                . library_feature_reservation_label($targetStatus) . '.'
            );
            redirect('admin/reservations.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Holatni yangilab bo‘lmadi.';
        }
    }
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
$validStatuses = ['pending', 'approved', 'ready', 'collected', 'cancelled', 'expired'];
$sql = "SELECT r.*, b.title, b.author, b.available_copies, b.total_copies, b.is_active,
               s.first_name, s.last_name, s.full_name, s.class_name, s.phone,
               (SELECT COUNT(*) FROM reservations h
                WHERE h.book_id = r.book_id
                  AND h.status IN ('approved', 'ready')
                  AND (h.expires_at IS NULL OR h.expires_at >= NOW())) AS held_copies
        FROM reservations r
        INNER JOIN books b ON b.id = r.book_id
        INNER JOIN students s ON s.id = r.student_id
        WHERE (:is_admin = 1 OR b.user_id = :owner_id)";
$params = ['is_admin' => $isAdmin ? 1 : 0, 'owner_id' => $user['id']];
if (in_array($statusFilter, $validStatuses, true)) {
    $sql .= ' AND r.status = :status';
    $params['status'] = $statusFilter;
}
$sql .= " ORDER BY FIELD(r.status, 'pending', 'approved', 'ready', 'collected', 'cancelled', 'expired'),
          r.pickup_date, r.created_at";
$statement = $pdo->prepare($sql);
$statement->execute($params);
$reservations = $statement->fetchAll();
$flash = get_flash();
?>
<!doctype html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Band qilishlar — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css">
</head>
<body class="admin-body">
<div class="admin-layout">
    <aside class="admin-sidebar">
        <a class="admin-brand" href="<?= e(APP_URL) ?>/admin/index.php"><span><i class="fa-solid fa-book-open"></i></span><div><strong>Kutubxona</strong><small>Boshqaruv paneli</small></div></a>
        <?= library_feature_admin_nav('reservations') ?>
    </aside>
    <main class="admin-main">
        <header class="admin-topbar">
            <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menyuni ochish"><i class="fa-solid fa-bars"></i></button>
            <div><p class="admin-eyebrow">So‘rovlar navbati</p><h1>Band qilishlar</h1></div>
            <span class="active-counter"><?= count($reservations) ?> ta natija</span>
        </header>

        <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"><?= e($flash['message']) ?><button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Yopish"></button></div><?php endif; ?>
        <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <div class="filter-pills mb-4">
            <a class="<?= $statusFilter === '' ? 'active' : '' ?>" href="?">Barchasi</a>
            <?php foreach ($validStatuses as $statusOption): ?>
                <a class="<?= $statusFilter === $statusOption ? 'active' : '' ?>" href="?status=<?= e($statusOption) ?>"><?= e(library_feature_reservation_label($statusOption)) ?></a>
            <?php endforeach; ?>
        </div>

        <section class="admin-panel">
            <div class="table-responsive">
                <table class="table admin-table align-middle">
                    <thead><tr><th>Kitob</th><th>O‘quvchi / aloqa</th><th>So‘rov sanalari</th><th>Mavjudlik</th><th>Holat / amal</th></tr></thead>
                    <tbody>
                    <?php if ($reservations === []): ?><tr><td colspan="5" class="text-center py-5 text-secondary">Bu filtrda so‘rov yo‘q.</td></tr><?php endif; ?>
                    <?php foreach ($reservations as $reservation): ?>
                        <?php
                        $heldCopies = (int) $reservation['held_copies'];
                        $freeCopies = max(0, (int) $reservation['available_copies'] - $heldCopies);
                        $hasOwnHold = in_array($reservation['status'], ['approved', 'ready'], true);
                        ?>
                        <tr>
                            <td><strong><?= e($reservation['title']) ?></strong><small class="d-block"><?= e($reservation['author']) ?> · #<?= (int) $reservation['id'] ?></small><?php if (!(int) $reservation['is_active']): ?><span class="reservation-status status-expired mt-1">Arxiv</span><?php endif; ?></td>
                            <td><strong><?= e($reservation['first_name'] . ' ' . $reservation['last_name']) ?></strong><small class="d-block"><?= e($reservation['class_name']) ?> · <?= e($reservation['phone'] ?: 'Kontakt yo‘q') ?></small></td>
                            <td><small>So‘rov: <?= e(library_feature_date($reservation['request_date'])) ?></small><strong class="d-block">Olib ketish: <?= e(library_feature_date($reservation['pickup_date'])) ?></strong><small class="d-block">Amal muddati: <?= e(library_feature_date($reservation['expires_at'])) ?></small></td>
                            <td><span class="stock-count <?= $freeCopies > 0 ? 'in-stock' : 'out-stock' ?>"><?= $freeCopies ?> erkin</span><small class="d-block"><?= (int) $reservation['available_copies'] ?>/<?= (int) $reservation['total_copies'] ?> javonda · <?= $heldCopies ?> band</small><?php if ($hasOwnHold): ?><small class="d-block text-success">1 nusxa shu so‘rov uchun ajratilgan</small><?php endif; ?></td>
                            <td>
                                <span class="reservation-status status-<?= e($reservation['status']) ?>"><?= e(library_feature_reservation_label($reservation['status'])) ?></span>
                                <?php if (isset($allowedTransitions[$reservation['status']])): ?>
                                    <form class="d-flex gap-1 mt-2" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $reservation['id'] ?>">
                                        <select class="form-select form-select-sm admin-inline-select" name="status" aria-label="Yangi holat">
                                            <?php foreach ($allowedTransitions[$reservation['status']] as $nextStatus): ?><option value="<?= e($nextStatus) ?>"><?= e(library_feature_reservation_label($nextStatus)) ?></option><?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-sm btn-primary" type="submit">Saqlash</button>
                                    </form>
                                <?php elseif ($reservation['borrow_transaction_id']): ?>
                                    <small class="d-block mt-2">Berish #<?= (int) $reservation['borrow_transaction_id'] ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <div class="alert alert-info mt-4 mb-0"><strong>Xavfsiz jarayon:</strong> Kutilmoqda → Tasdiqlangan → Olib ketishga tayyor → Olib ketilgan. “Olib ketilgan” holati 14 kunlik berish yozuvini yaratib, javondagi qoldiqni kamaytiradi. Tasdiqlangan yoki tayyor so‘rov bekor qilinsa, ajratilgan nusxa yana erkin bo‘ladi.</div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(APP_URL) ?>/assets/js/app.js"></script>
</body>
</html>
