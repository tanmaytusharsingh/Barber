<?php
require_once __DIR__.'/../includes/vendor-service.php';
require_role('vendor');
$vendorId=(int)current_user()['id'];
$section=$_GET['section']??'profile';
if (!in_array($section,['profile','services','staff'],true)) { http_response_code(404); exit('Page not found.'); }
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    post_guard();
    try { save_vendor($vendorId,$section,$_POST); flash('success','Changes saved. Existing appointments retain their booked details.'); redirect('vendor/manage.php?section='.$section); }
    catch (Throwable $error) { $errors[]=report_error($error); }
}
$salon=vendor_salon($vendorId); $editId=(int)($_GET['id']??0);
$record=$section==='profile'?$salon:($editId?query("SELECT * FROM $section WHERE id=? AND salon_id=?",[$editId,$salon['id']])->fetch():[]);
if ($editId && !$record) { http_response_code(404); exit('Record not found.'); }
if ($_SERVER['REQUEST_METHOD']==='POST') $record=$_POST;
$rows=$section==='profile'?[]:query("SELECT * FROM $section WHERE salon_id=? ORDER BY name",[$salon['id']])->fetchAll();
$services=query('SELECT * FROM services WHERE salon_id=? ORDER BY name',[$salon['id']])->fetchAll();
$assigned=$section==='staff' && $editId?array_column(query('SELECT service_id FROM staff_services WHERE staff_id=?',[$editId])->fetchAll(),'service_id'):[];
if ($_SERVER['REQUEST_METHOD']==='POST') $assigned=$_POST['service_ids']??[];
$future=query("SELECT * FROM appointments WHERE salon_id=? AND status='confirmed' AND TIMESTAMP(appointment_date,start_time)>NOW() ORDER BY appointment_date,start_time",[$salon['id']])->fetchAll();
$page_title='Manage your salon'; include __DIR__.'/../includes/header.php';
function management_input(string $key,string $label,string $type='text',int $max=160,bool $required=true): void {
    global $record;
    $value=$record[$key]??'';
    if ($type==='time') $value=substr($value,0,5);
    echo '<div class="col-md-6"><label class="form-label" for="'.e($key).'">'.e($label).'</label><input class="form-control" id="'.e($key).'" name="'.e($key).'" type="'.e($type).'" maxlength="'.$max.'" value="'.e((string)$value).'" '.($required?'required':'').'></div>';
}
?>
<main class="dashboard-shell"><div class="container"><h1>Manage your salon</h1>
<nav class="d-flex flex-wrap gap-2 my-4" aria-label="Vendor tools"><a class="btn btn-outline-brand" href="<?= e(url('vendor/dashboard.php')) ?>">Bookings</a><?php foreach(['profile','services','staff'] as $tab): ?><a class="btn btn-outline-brand" href="?section=<?= $tab ?>"><?= ucfirst($tab) ?></a><?php endforeach; ?></nav>
<?php foreach($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<?php if($future): ?><details class="alert alert-warning"><summary>Review <?= count($future) ?> future appointments before changing availability</summary><p>Changes to hours, staff, services or assignments do not cancel these bookings. The customer must make any changes at least 30 minutes before their appointment.</p><ul><?php foreach($future as $a): ?><li><?= e($a['booking_code'].' — '.$a['service_name'].' / '.$a['staff_name'].' — '.$a['appointment_date'].' '.$a['start_time']) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
<div class="row g-4"><div class="col-lg-7"><form method="post" class="dashboard-card p-4 row g-3"><?= form_token() ?><input type="hidden" name="id" value="<?= $editId ?>">
<h2 class="h4"><?= $section==='profile'?'Salon profile':($editId?'Edit ':'Add ').($section==='staff'?'specialist':'service') ?></h2>
<?php management_input('name','Name','text',$section==='profile'?160:120);
if($section==='profile') {
    management_input('address','Address','text',255); management_input('city','City','text',100); management_input('phone','Phone','text',30);
    management_input('opening_time','Opens at (IST)','time',5); management_input('closing_time','Closes at (IST)','time',5); management_input('image_url','Image URL (optional)','url',255,false);
} elseif($section==='services') {
    management_input('price','Price (INR)','text',12); management_input('duration_minutes','Duration in minutes','number',4);
    ?><div class="col-md-6"><label for="category_id" class="form-label">Category</label><select id="category_id" name="category_id" class="form-select"><option value="0">Uncategorized</option><?php foreach(query('SELECT * FROM service_categories ORDER BY name')->fetchAll() as $category): ?><option value="<?= $category['id'] ?>" <?= (int)($record['category_id']??0)===(int)$category['id']?'selected':'' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div><?php
} else {
    management_input('phone','Phone','text',30,false); management_input('specialization','Specialization','text',180,false); management_input('experience_years','Experience in years','text',4,false);
    ?><fieldset class="col-12"><legend class="h6">Assigned services</legend><?php foreach($services as $service): ?><label class="d-block"><input type="checkbox" name="service_ids[]" value="<?= $service['id'] ?>" <?= in_array($service['id'],$assigned)?'checked':'' ?>> <?= e($service['name'].' ('.$service['status'].')') ?></label><?php endforeach; ?><?php if(!$services): ?><p>Add a service first, then assign it here.</p><?php endif; ?></fieldset><?php
}
$descriptionKey=$section==='staff'?'bio':'description'; ?>
<div class="col-12"><label for="description" class="form-label"><?= $section==='staff'?'Bio':'Description' ?></label><textarea id="description" name="<?= $descriptionKey ?>" class="form-control" maxlength="5000"><?= e($record[$descriptionKey]??'') ?></textarea></div>
<?php if($section!=='profile'): ?><div class="col-md-6"><label for="status" class="form-label">Status</label><select class="form-select" id="status" name="status"><option value="active">Active</option><option value="inactive" <?= ($record['status']??'')==='inactive'?'selected':'' ?>>Inactive</option></select></div><?php endif; ?>
<div class="col-12"><button class="btn btn-brand">Save changes</button></div></form></div>
<?php if($section!=='profile'): ?><aside class="col-lg-5"><div class="dashboard-card p-4"><h2 class="h4">Your <?= $section ?></h2><a href="?section=<?= $section ?>">Add new</a><?php foreach($rows as $row): ?><div class="border-top py-3"><strong><?= e($row['name']) ?></strong> <span class="pill"><?= e($row['status']) ?></span><a class="d-block" href="?section=<?= $section ?>&amp;id=<?= $row['id'] ?>">Edit <?= e($row['name']) ?></a></div><?php endforeach; ?><?php if(!$rows): ?><p class="mt-3">No records yet.</p><?php endif; ?></div></aside><?php endif; ?></div></div></main>
<?php include __DIR__.'/../includes/footer.php'; ?>
