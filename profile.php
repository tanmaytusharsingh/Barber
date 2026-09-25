<?php
require_once __DIR__.'/includes/booking-service.php'; require_role('customer','vendor','admin');
$account=current_user(); $errors=[]; $values=$account;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    post_guard(); $values=array_merge($account,$_POST);
    try {
        $name=field($_POST,'name',120); $email=field($_POST,'email',160); $phone=field($_POST,'phone',30);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
        if (!preg_match('/^\+?[0-9][0-9 ()-]{5,29}$/D',$phone)) throw new InvalidArgumentException('Enter a valid phone number.');
        if (query('SELECT id FROM users WHERE email=? AND id<>?',[$email,$account['id']])->fetchColumn()) throw new InvalidArgumentException('That email is already used by another account.');
        query('UPDATE users SET name=?,email=?,phone=? WHERE id=?',[$name,$email,$phone,$account['id']]);
        flash('success','Your profile has been updated.'); redirect('profile.php');
    } catch (Throwable $error) {
        $errors[]=($error instanceof PDOException && ($error->errorInfo[1]??null)===1062)?'That email is already used by another account.':report_error($error);
    }
}
$page_title='Edit profile'; include __DIR__.'/includes/header.php';
?>
<main class="dashboard-shell"><div class="container"><div class="page-heading"><div><div class="section-label">Your account</div><h1>Edit profile</h1><p class="muted">Keep your name and contact details up to date.</p></div></div>
<div class="profile-editor dashboard-card p-4 p-md-5"><div class="d-flex align-items-center gap-3 mb-4"><div class="avatar"><?= e(initials($account['name'])) ?></div><div><strong><?= e($account['name']) ?></strong><small class="d-block muted"><?= e(ucfirst($account['role'])) ?> · Member since <?= date('M Y',strtotime($account['created_at'])) ?></small></div></div>
<?php foreach($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<form method="post" class="row g-3"><?= form_token() ?>
<?php foreach(['name'=>['Full name','text',120],'email'=>['Email address','email',160],'phone'=>['Phone number','tel',30]] as $key=>[$label,$type,$max]): ?><div class="col-12"><label for="<?= $key ?>" class="form-label"><?= $label ?></label><input id="<?= $key ?>" name="<?= $key ?>" type="<?= $type ?>" maxlength="<?= $max ?>" value="<?= e($values[$key]??'') ?>" class="form-control" autocomplete="<?= $key==='phone'?'tel':$key ?>" required></div><?php endforeach; ?>
<div class="col-12 mt-4"><button class="btn btn-brand">Save profile</button></div></form></div></div></main>
<?php include __DIR__.'/includes/footer.php'; ?>
