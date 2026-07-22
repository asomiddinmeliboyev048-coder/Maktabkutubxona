<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
library_feature_expire_reservations($pdo);

$errors = [];
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$form = ['title'=>'','author'=>'','category_id'=>0,'description'=>'','total_copies'=>1,'cover_image'=>null,'is_active'=>1];
$categoryStatement = $pdo->prepare('SELECT id,name FROM categories ORDER BY name');
$categoryStatement->execute();
$categories = $categoryStatement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) $errors[] = 'Xavfsizlik tokeni yaroqsiz.';

    if ($errors === [] && in_array($action, ['archive','restore','delete'], true)) {
        try {
            $check = $pdo->prepare(
                "SELECT b.cover_image,
                        (SELECT COUNT(*) FROM borrow_transactions WHERE book_id = b.id) +
                        (SELECT COUNT(*) FROM reservations WHERE book_id = b.id) +
                        (SELECT COUNT(*) FROM reviews WHERE book_id = b.id) AS reference_count,
                        (SELECT COUNT(*) FROM borrow_transactions WHERE book_id = b.id AND status IN ('borrowed','overdue') AND return_date IS NULL) AS active_loan_count,
                        (SELECT COUNT(*) FROM reservations WHERE book_id = b.id AND status IN ('pending','approved','ready')) AS active_reservation_count
                 FROM books b WHERE b.id = :id"
            );
            $check->execute(['id' => $id]);
            $bookCheck = $check->fetch();
            if (!$bookCheck) throw new RuntimeException('Kitob topilmadi.');

            $references = (int) $bookCheck['reference_count'];
            $activeCommitments = (int) $bookCheck['active_loan_count'] + (int) $bookCheck['active_reservation_count'];
            if ($action === 'delete') {
                if ($references > 0) throw new RuntimeException('Tarixga bog‘langan kitobni o‘chirib bo‘lmaydi; uni arxivlang.');
                $statement = $pdo->prepare('DELETE FROM books WHERE id = :id');
                $statement->execute(['id' => $id]);
                library_feature_delete_cover($bookCheck['cover_image']);
                $message = 'Kitob butunlay o‘chirildi.';
            } elseif ($action === 'archive') {
                if ($activeCommitments > 0) throw new RuntimeException('Faol berish yoki so‘rovlar tugamaguncha kitobni arxivlab bo‘lmaydi.');
                $statement = $pdo->prepare('UPDATE books SET is_active = 0 WHERE id = :id');
                $statement->execute(['id' => $id]);
                $message = 'Tarix saqlangan holda kitob arxivlandi.';
            } else {
                $statement = $pdo->prepare('UPDATE books SET is_active = 1 WHERE id = :id');
                $statement->execute(['id' => $id]);
                $message = 'Kitob katalogga qaytarildi.';
            }
            set_flash('success',$message); redirect('admin/books.php');
        } catch (Throwable $exception) { $errors[]=$exception instanceof RuntimeException?$exception->getMessage():'Amal bajarilmadi.'; }
    }

    if ($action === 'save') {
        $form['title']=trim((string)($_POST['title']??'')); $form['author']=trim((string)($_POST['author']??''));
        $form['category_id']=filter_input(INPUT_POST,'category_id',FILTER_VALIDATE_INT)?:0;
        $form['description']=trim((string)($_POST['description']??''));
        $form['total_copies']=filter_input(INPUT_POST,'total_copies',FILTER_VALIDATE_INT)?:0;
        if (mb_strlen($form['title'])<2||mb_strlen($form['title'])>255) $errors[]='Kitob nomi 2–255 belgidan iborat bo‘lsin.';
        if (mb_strlen($form['author'])<2||mb_strlen($form['author'])>180) $errors[]='Muallif 2–180 belgidan iborat bo‘lsin.';
        if (mb_strlen($form['description'])<10||mb_strlen($form['description'])>5000) $errors[]='Tavsif 10–5000 belgidan iborat bo‘lsin.';
        if ($form['category_id']<1) $errors[]='Kategoriyani tanlang.';
        if ($form['total_copies']<1||$form['total_copies']>10000) $errors[]='Nusxalar soni 1–10000 oralig‘ida bo‘lsin.';
        $newCover=null;
        if ($errors===[]) $newCover=library_feature_save_cover($_FILES['cover_image']??null,$errors);
        if ($errors===[]) {
            try {
                $pdo->beginTransaction();
                $currentStatement=$pdo->prepare("SELECT b.*,(SELECT COUNT(*) FROM borrow_transactions bt WHERE bt.book_id=b.id AND bt.status IN ('borrowed','overdue') AND bt.return_date IS NULL) active_loans,(SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status IN ('approved','ready')) active_holds FROM books b WHERE b.id=:id FOR UPDATE");
                $currentStatement->execute(['id'=>$id]); $current=$currentStatement->fetch();
                if (!$current) throw new RuntimeException('Kitob topilmadi.');
                $committed=(int)$current['active_loans']+(int)$current['active_holds'];
                if ((int)$form['total_copies']<$committed) throw new RuntimeException('Nusxalar soni faol berilgan va band qilingan nusxalardan kam bo‘la olmaydi (kamida '.$committed.').');
                $cover=$newCover?:$current['cover_image'];
                $statement=$pdo->prepare('UPDATE books SET title=:title,author=:author,category_id=:category_id,description=:description,cover_image=:cover,total_copies=:total,available_copies=:available WHERE id=:id');
                $statement->execute(['title'=>$form['title'],'author'=>$form['author'],'category_id'=>$form['category_id'],'description'=>$form['description'],'cover'=>$cover,'total'=>$form['total_copies'],'available'=>$form['total_copies']-(int)$current['active_loans'],'id'=>$id]);
                $pdo->commit();
                if ($newCover && $current['cover_image']!==$newCover) library_feature_delete_cover($current['cover_image']);
                set_flash('success','Kitob kartasi yangilandi.'); redirect('admin/books.php');
            } catch (Throwable $exception) { if($pdo->inTransaction())$pdo->rollBack(); if($newCover)library_feature_delete_cover($newCover); $errors[]=$exception instanceof RuntimeException?$exception->getMessage():'Kitobni yangilab bo‘lmadi.'; }
        }
    }
}

if ($editId>0 && $_SERVER['REQUEST_METHOD']!=='POST') { $statement=$pdo->prepare('SELECT * FROM books WHERE id=:id');$statement->execute(['id'=>$editId]);$found=$statement->fetch();if($found)$form=$found; }
$statement=$pdo->prepare("SELECT b.*,c.name category_name,
    (SELECT COUNT(*) FROM borrow_transactions bt WHERE bt.book_id=b.id) loan_count,
    (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id) reservation_count,
    (SELECT COUNT(*) FROM reviews rv WHERE rv.book_id=b.id) review_count,
    (SELECT COUNT(*) FROM borrow_transactions bt WHERE bt.book_id=b.id AND bt.status IN ('borrowed','overdue') AND bt.return_date IS NULL) active_loan_count,
    (SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status IN ('pending','approved','ready')) active_reservation_count
    FROM books b JOIN categories c ON c.id=b.category_id ORDER BY b.is_active DESC,b.title");$statement->execute();$books=library_feature_enrich_books($pdo,$statement->fetchAll());$flash=get_flash();
?>
<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kitoblar — <?= e(APP_NAME) ?></title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css"></head><body class="admin-body"><div class="admin-layout"><aside class="admin-sidebar"><a class="admin-brand" href="<?= e(APP_URL) ?>/admin/index.php"><span><i class="fa-solid fa-book-open"></i></span><div><strong>Kutubxona</strong><small>Boshqaruv paneli</small></div></a><?= library_feature_admin_nav('books') ?></aside><main class="admin-main"><header class="admin-topbar"><button class="sidebar-toggle" type="button" data-sidebar-toggle><i class="fa-solid fa-bars"></i></button><div><p class="admin-eyebrow">Inventar kartalari</p><h1>Kitoblar</h1></div><a class="btn btn-primary" href="<?= e(APP_URL) ?>/admin/add-book.php"><i class="fa-solid fa-plus me-2"></i>Yangi kitob</a></header><?php if($flash):?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif;?><?php if($errors):?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as$error):?><li><?= e($error) ?></li><?php endforeach;?></ul></div><?php endif;?>
<?php if($editId):?><section class="admin-panel mb-4"><div class="panel-heading"><div><p class="admin-eyebrow">To‘liq tahrirlash</p><h2 class="h4"><?= e($form['title']) ?></h2></div><a href="<?= e(APP_URL) ?>/admin/books.php">Yopish</a></div><form class="admin-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editId ?>"><div class="row g-3"><div class="col-md-6"><label class="form-label">Kitob nomi</label><input class="form-control" name="title" value="<?= e($form['title']) ?>" required></div><div class="col-md-6"><label class="form-label">Muallif</label><input class="form-control" name="author" value="<?= e($form['author']) ?>" required></div><div class="col-md-6"><label class="form-label">Kategoriya</label><select class="form-select" name="category_id" required><?php foreach($categories as$category):?><option value="<?= (int)$category['id'] ?>" <?= (int)$form['category_id']===(int)$category['id']?'selected':'' ?>><?= e($category['name']) ?></option><?php endforeach;?></select></div><div class="col-md-3"><label class="form-label">Jami nusxa</label><input class="form-control" type="number" min="1" max="10000" name="total_copies" value="<?= (int)$form['total_copies'] ?>" required></div><div class="col-md-3"><label class="form-label">Yangi muqova</label><input class="form-control" type="file" name="cover_image" accept="image/jpeg,image/png,image/webp"></div><div class="col-12"><label class="form-label">Tavsif</label><textarea class="form-control" name="description" rows="5" required><?= e($form['description']) ?></textarea></div><div class="col-12"><button class="btn btn-primary" type="submit">O‘zgarishlarni saqlash</button></div></div></form></section><?php endif;?>
<section class="admin-panel"><div class="table-responsive"><table class="table admin-table align-middle"><thead><tr><th>Kitob</th><th>Kategoriya</th><th>Nusxalar</th><th>Majburiyatlar</th><th>Holat</th><th>Amal</th></tr></thead><tbody><?php foreach($books as$book):?><tr><td><div class="book-cell"><img src="<?= e(book_cover_url($book['cover_image'])) ?>" alt=""><div><strong><?= e($book['title']) ?></strong><small><?= e($book['author']) ?></small></div></div></td><td><?= e($book['category_name']) ?></td><td><span class="stock-count <?= (int)$book['free_copies']?'in-stock':'out-stock' ?>"><?= (int)$book['free_copies'] ?>/<?= (int)$book['total_copies'] ?> erkin</span><small class="d-block"><?= (int)$book['available_copies'] ?> javonda</small></td><td><?= (int)$book['loan_count'] ?> berish · <?= (int)$book['reservation_count'] ?> band · <?= (int)$book['review_count'] ?> fikr</td><td><span class="status-pill <?= (int)$book['is_active']?'status-returned':'status-overdue' ?>"><?= (int)$book['is_active']?'Faol':'Arxiv' ?></span></td><td><div class="d-flex gap-1"><a class="btn btn-sm btn-outline-light" href="?edit=<?= (int)$book['id'] ?>" aria-label="Kitobni tahrirlash"><i class="fa-solid fa-pen"></i></a><?php if (!(int)$book['is_active']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$book['id'] ?>"><input type="hidden" name="action" value="restore"><button class="btn btn-sm btn-outline-success" type="submit" data-confirm-action="Kitobni katalogga qaytarasizmi?" aria-label="Tiklash"><i class="fa-solid fa-rotate-left"></i></button></form><?php elseif ((int)$book['active_loan_count'] + (int)$book['active_reservation_count'] > 0): ?><span class="btn btn-sm btn-outline-light disabled" title="Faol berish yoki so‘rov mavjud" aria-label="Faol majburiyatlar mavjud"><i class="fa-solid fa-lock"></i></span><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$book['id'] ?>"><input type="hidden" name="action" value="<?= (int)$book['loan_count'] + (int)$book['reservation_count'] + (int)$book['review_count'] > 0 ? 'archive' : 'delete' ?>"><button class="btn btn-sm btn-outline-danger" type="submit" data-confirm-action="<?= (int)$book['loan_count'] + (int)$book['reservation_count'] + (int)$book['review_count'] > 0 ? 'Kitobni arxivlaysizmi?' : 'Kitobni butunlay o‘chirasizmi?' ?>" aria-label="<?= (int)$book['loan_count'] + (int)$book['reservation_count'] + (int)$book['review_count'] > 0 ? 'Arxivlash' : 'Butunlay o‘chirish' ?>"><i class="fa-solid <?= (int)$book['loan_count'] + (int)$book['reservation_count'] + (int)$book['review_count'] > 0 ? 'fa-box-archive' : 'fa-trash' ?>"></i></button></form><?php endif; ?></div></td></tr><?php endforeach;?></tbody></table></div></section></main></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(APP_URL) ?>/assets/js/app.js"></script></body></html>
