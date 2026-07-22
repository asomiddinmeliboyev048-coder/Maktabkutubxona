<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
$user = require_role($pdo, 'librarian');
library_feature_expire_reservations($pdo, (int) $user['id']);
$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$errors = [];
$form = ['title'=>'','author'=>'','category_id'=>0,'description'=>'','total_copies'=>1,'listing_type'=>'sale','price'=>'','rental_price'=>'','address'=>'','phone'=>(string)$user['phone'],'cover_image'=>null];

if ($bookId > 0) {
    $statement = $pdo->prepare('SELECT * FROM books WHERE id=:id AND user_id=:user_id');
    $statement->execute(['id'=>$bookId,'user_id'=>$user['id']]);
    $found = $statement->fetch();
    if (!$found) {
        set_flash('danger', 'Kitob topilmadi yoki sizga tegishli emas.');
        redirect('vendor/index.php');
    }
    $form = $found;
}

$categoriesStatement=$pdo->prepare('SELECT id,name FROM categories ORDER BY name');
$categoriesStatement->execute();
$categories=$categoriesStatement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    foreach (['title','author','description','listing_type','price','rental_price','address','phone'] as $field) $form[$field]=trim((string)($_POST[$field]??''));
    $form['category_id']=filter_input(INPUT_POST,'category_id',FILTER_VALIDATE_INT)?:0;
    $form['total_copies']=filter_input(INPUT_POST,'total_copies',FILTER_VALIDATE_INT)?:0;
    if (!verify_csrf_token($_POST['csrf_token']??null)) $errors[]='Xavfsizlik tokeni yaroqsiz.';
    if ($postedId !== $bookId) $errors[]='Kitob identifikatori mos emas.';
    if (mb_strlen($form['title'])<2||mb_strlen($form['title'])>255) $errors[]='Kitob nomi 2–255 belgidan iborat bo‘lsin.';
    if (mb_strlen($form['author'])<2||mb_strlen($form['author'])>180) $errors[]='Muallif 2–180 belgidan iborat bo‘lsin.';
    if (mb_strlen($form['description'])<10||mb_strlen($form['description'])>5000) $errors[]='Tavsif 10–5000 belgidan iborat bo‘lsin.';
    if ($form['category_id']<1) $errors[]='Kategoriyani tanlang.';
    if ($form['total_copies']<1||$form['total_copies']>10000) $errors[]='Nusxalar soni 1–10000 oralig‘ida bo‘lsin.';
    if (!in_array($form['listing_type'],['sale','rental','both'],true)) $errors[]='E’lon turini tanlang.';
    $price = filter_var($form['price'], FILTER_VALIDATE_FLOAT);
    $rentalPrice = filter_var($form['rental_price'], FILTER_VALIDATE_FLOAT);
    if (in_array($form['listing_type'],['sale','both'],true) && ($price===false||$price<=0||$price>9999999999.99)) $errors[]='Sotuv narxini to‘g‘ri kiriting.';
    if (in_array($form['listing_type'],['rental','both'],true) && ($rentalPrice===false||$rentalPrice<=0||$rentalPrice>9999999999.99)) $errors[]='Ijara narxini to‘g‘ri kiriting.';
    if ($form['address']===''||mb_strlen($form['address'])>255) $errors[]='Manzil majburiy va 255 belgidan oshmasin.';
    if (mb_strlen($form['phone'])<7||mb_strlen($form['phone'])>30) $errors[]='Telefon 7–30 belgidan iborat bo‘lsin.';

    $newCover=null;
    if ($errors===[]) $newCover=library_feature_save_cover($_FILES['cover_image']??null,$errors);
    if ($errors===[]) {
        try {
            $pdo->beginTransaction();
            $category=$pdo->prepare('SELECT id FROM categories WHERE id=:id');$category->execute(['id'=>$form['category_id']]);
            if(!$category->fetchColumn()) throw new RuntimeException('Kategoriya topilmadi.');
            $params=['user_id'=>$user['id'],'title'=>$form['title'],'author'=>$form['author'],'category_id'=>$form['category_id'],'description'=>$form['description'],'total'=>$form['total_copies'],'listing_type'=>$form['listing_type'],'price'=>in_array($form['listing_type'],['sale','both'],true)?number_format((float)$price,2,'.',''):null,'rental_price'=>in_array($form['listing_type'],['rental','both'],true)?number_format((float)$rentalPrice,2,'.',''):null,'address'=>$form['address'],'phone'=>$form['phone']];
            $oldCover=null;
            if($bookId>0){
                $currentStatement=$pdo->prepare("SELECT b.*,(SELECT COUNT(*) FROM borrow_transactions bt WHERE bt.book_id=b.id AND bt.status IN ('borrowed','overdue') AND bt.return_date IS NULL) active_loans,(SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status IN ('approved','ready') AND (r.expires_at IS NULL OR r.expires_at>=NOW())) active_holds FROM books b WHERE b.id=:id AND b.user_id=:user_id FOR UPDATE");
                $currentStatement->execute(['id'=>$bookId,'user_id'=>$user['id']]);$current=$currentStatement->fetch();
                if(!$current) throw new RuntimeException('Kitob topilmadi yoki sizga tegishli emas.');
                $committed=(int)$current['active_loans']+(int)$current['active_holds'];
                if((int)$form['total_copies']<$committed) throw new RuntimeException('Nusxalar soni faol majburiyatlardan kam bo‘la olmaydi: '.$committed.'.');
                $oldCover=$current['cover_image'];$params['cover']=$newCover?:$oldCover;$params['available']=(int)$form['total_copies']-(int)$current['active_loans'];$params['id']=$bookId;
                $update=$pdo->prepare('UPDATE books SET title=:title,author=:author,category_id=:category_id,description=:description,cover_image=:cover,total_copies=:total,available_copies=:available,listing_type=:listing_type,price=:price,rental_price=:rental_price,address=:address,phone=:phone WHERE id=:id AND user_id=:user_id');
                $update->execute($params);
            } else {
                $params['cover']=$newCover;$params['available']=$form['total_copies'];
                $insert=$pdo->prepare('INSERT INTO books (user_id,title,author,category_id,description,cover_image,total_copies,available_copies,listing_type,price,rental_price,address,phone,is_active) VALUES (:user_id,:title,:author,:category_id,:description,:cover,:total,:available,:listing_type,:price,:rental_price,:address,:phone,1)');
                $insert->execute($params);
            }
            $pdo->commit();
            if($newCover&&$oldCover&&$newCover!==$oldCover) library_feature_delete_cover($oldCover);
            set_flash('success',$bookId>0?'E’lon yangilandi.':'Yangi e’lon yaratildi.');redirect('vendor/index.php');
        } catch(Throwable $exception){if($pdo->inTransaction())$pdo->rollBack();if($newCover)library_feature_delete_cover($newCover);$errors[]=$exception instanceof RuntimeException?$exception->getMessage():'E’lonni saqlab bo‘lmadi.';}
    }
}
?>
<!doctype html><html lang="uz"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $bookId?'E’lonni tahrirlash':'Yangi e’lon' ?> — <?= e(APP_NAME) ?></title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><link rel="stylesheet" href="<?= e(APP_URL) ?>/assets/css/admin.css"></head><body class="admin-body"><div class="admin-layout"><aside class="admin-sidebar"><a class="admin-brand" href="<?= e(APP_URL) ?>/vendor/index.php"><span><i class="fa-solid fa-store"></i></span><div><strong>Marketplace</strong><small>Sotuvchi paneli</small></div></a><?= library_feature_vendor_nav('add-book') ?></aside><main class="admin-main"><header class="admin-topbar"><button class="sidebar-toggle" type="button" data-sidebar-toggle><i class="fa-solid fa-bars"></i></button><div><p class="admin-eyebrow">Marketplace e’loni</p><h1><?= $bookId?'Kitobni tahrirlash':'Yangi kitob qo‘shish' ?></h1></div><a class="btn btn-outline-light" href="<?= e(APP_URL) ?>/vendor/index.php">Ortga</a></header><?php if($errors):?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as$error):?><li><?= e($error) ?></li><?php endforeach;?></ul></div><?php endif;?><section class="admin-panel"><form class="admin-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $bookId ?>"><div class="row g-3"><div class="col-md-7"><label class="form-label" for="title">Kitob nomi</label><input class="form-control" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="255" required></div><div class="col-md-5"><label class="form-label" for="author">Muallif</label><input class="form-control" id="author" name="author" value="<?= e($form['author']) ?>" maxlength="180" required></div><div class="col-md-6"><label class="form-label" for="category_id">Kategoriya</label><select class="form-select" id="category_id" name="category_id" required><option value="">Tanlang</option><?php foreach($categories as$category):?><option value="<?= (int)$category['id'] ?>" <?= (int)$form['category_id']===(int)$category['id']?'selected':'' ?>><?= e($category['name']) ?></option><?php endforeach;?></select></div><div class="col-md-3"><label class="form-label" for="total_copies">Nusxalar</label><input class="form-control" type="number" id="total_copies" name="total_copies" min="1" max="10000" value="<?= (int)$form['total_copies'] ?>" required></div><div class="col-md-3"><label class="form-label" for="listing_type">E’lon turi</label><select class="form-select" id="listing_type" name="listing_type" required><?php foreach(['sale'=>'Sotuv','rental'=>'Ijara','both'=>'Ikkalasi'] as$value=>$label):?><option value="<?= e($value) ?>" <?= $form['listing_type']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></div><div class="col-md-6"><label class="form-label" for="price">Sotuv narxi (so‘m)</label><input class="form-control" type="number" step="0.01" min="0" id="price" name="price" value="<?= e($form['price']) ?>"></div><div class="col-md-6"><label class="form-label" for="rental_price">Ijara narxi (so‘m)</label><input class="form-control" type="number" step="0.01" min="0" id="rental_price" name="rental_price" value="<?= e($form['rental_price']) ?>"></div><div class="col-md-8"><label class="form-label" for="address">Manzil</label><input class="form-control" id="address" name="address" value="<?= e($form['address']) ?>" maxlength="255" required></div><div class="col-md-4"><label class="form-label" for="phone">Telefon</label><input class="form-control" type="tel" id="phone" name="phone" value="<?= e($form['phone']) ?>" maxlength="30" required></div><div class="col-12"><label class="form-label" for="description">Tavsif</label><textarea class="form-control" id="description" name="description" rows="5" minlength="10" maxlength="5000" required><?= e($form['description']) ?></textarea></div><div class="col-12"><label class="form-label" for="cover_image">Muqova rasmi</label><input class="form-control" type="file" id="cover_image" name="cover_image" accept="image/jpeg,image/png,image/webp"><div class="form-text">JPG, PNG yoki WEBP; 5 MB gacha.</div></div><div class="col-12"><button class="btn btn-primary btn-lg" type="submit">Saqlash</button></div></div></form></section></main></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="<?= e(APP_URL) ?>/assets/js/app.js"></script></body></html>
