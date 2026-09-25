<?php
require_once __DIR__.'/../includes/booking-service.php';
require_role('customer');
$errors=[];
$salonId=(int)($_POST['salon_id']??$_GET['salon_id']??0);
$selection=$_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET;
$serviceIds=[];
if (isset($selection['service_ids']) || !empty($selection['service_id'])) {
    try { $serviceIds=booking_service_ids($selection); } catch (InvalidArgumentException $error) { $errors[]=$error->getMessage(); }
}
$staffId=(int)($_POST['staff_id']??$_GET['staff_id']??0);
$date=$_POST['appointment_date']??$_GET['appointment_date']??date('Y-m-d');
$requestKey=$_POST['request_key']??bin2hex(random_bytes(24));
if ($_SERVER['REQUEST_METHOD']==='POST') {
    post_guard();
    try { $id=create_booking((int)current_user()['id'],$_POST); flash('success','Your appointment is confirmed. The salon has received your booking details.'); redirect('customer/appointment.php?id='.$id); }
    catch (Throwable $error) { $errors[]=report_error($error); }
}
$salon=query("SELECT s.* FROM salons s JOIN users u ON u.id=s.vendor_id WHERE s.id=? AND s.status='approved' AND u.status='active'",[$salonId])->fetch();
if (!$salon) { http_response_code(404); exit('Salon is not available for booking.'); }
$services=query("SELECT sv.* FROM services sv WHERE sv.salon_id=? AND sv.status='active' AND EXISTS (SELECT 1 FROM staff_services ss JOIN staff st ON st.id=ss.staff_id WHERE ss.service_id=sv.id AND st.salon_id=sv.salon_id AND st.status='active') ORDER BY sv.name",[$salonId])->fetchAll();
$selectedServices=array_values(array_filter($services,fn($item)=>in_array((int)$item['id'],$serviceIds,true)));
$duration=array_sum(array_column($selectedServices,'duration_minutes'));
$total=array_sum(array_map(fn($item)=>(int)round((float)$item['price']*100),$selectedServices))/100;
$validSelection=$serviceIds && count($selectedServices)===count($serviceIds);
$staff=[];
if ($validSelection) {
    $marks=implode(',',array_fill(0,count($serviceIds),'?'));
    $staff=query("SELECT st.* FROM staff st WHERE st.salon_id=? AND st.status='active' AND (SELECT COUNT(DISTINCT ss.service_id) FROM staff_services ss WHERE ss.staff_id=st.id AND ss.service_id IN ($marks))=? ORDER BY st.name",[$salonId,...$serviceIds,count($serviceIds)])->fetchAll();
}
$person=null; foreach($staff as $item) if ((int)$item['id']===$staffId) $person=$item;
$slots=$person?available_slots($salon,$staffId,$date,(int)$duration):[];
$page_title='Book an appointment'; include __DIR__.'/../includes/header.php';
?>
<main class="section-pad"><div class="container"><div class="row g-4"><div class="col-lg-8">
<div class="section-label">Make it yours</div><h1>Book at <?= e($salon['name']) ?></h1>
<p>Appointments are confirmed immediately. All times are India Standard Time (IST).</p>
<?php foreach($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<?php if(!$services): ?><div class="alert alert-info">No services with an available specialist are currently bookable at this salon.</div><?php else: ?>
<form method="get" class="dashboard-card p-4 row g-3">
<input type="hidden" name="salon_id" value="<?= $salonId ?>">
<fieldset class="col-12"><legend class="h6">1. Services — choose one or more</legend><p class="small muted">One specialist performs all selected services consecutively. Select your services, then click Show available times to load eligible specialists.</p><div class="row g-2"><?php foreach($services as $item): ?><div class="col-md-6"><label class="d-flex gap-2 border rounded p-3 h-100"><input class="form-check-input flex-shrink-0" type="checkbox" name="service_ids[]" value="<?= $item['id'] ?>" <?= in_array((int)$item['id'],$serviceIds,true)?'checked':'' ?>><span><?= e($item['name']) ?><small class="d-block muted"><?= money($item['price']) ?> · <?= (int)$item['duration_minutes'] ?> min</small></span></label></div><?php endforeach; ?></div></fieldset>
<div class="col-md-6"><label for="staff" class="form-label">2. Specialist</label><select id="staff" name="staff_id" class="form-select"><option value="">Choose a specialist</option><?php foreach($staff as $item): ?><option value="<?= $item['id'] ?>" <?= (int)$item['id']===$staffId?'selected':'' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select><?php if($validSelection && !$staff): ?><small class="text-danger">No specialist can perform all selected services. Choose a different combination.</small><?php endif; ?></div>
<div class="col-md-6"><label for="date" class="form-label">3. Date</label><input id="date" name="appointment_date" class="form-control" type="date" min="<?= date('Y-m-d') ?>" value="<?= e($date) ?>" required></div>
<div class="col-12"><button class="btn btn-outline-brand">Show available times</button></div></form>
<?php if($person): ?>
<?php if(!$slots): ?><div class="alert alert-info mt-3">No available times for this specialist on this date. Choose another date or specialist.</div><?php else: ?>
<form method="post" class="dashboard-card p-4 mt-3 row g-3"><?= form_token() ?>
<input type="hidden" name="request_key" value="<?= e($requestKey) ?>"><input type="hidden" name="salon_id" value="<?= $salonId ?>"><?php foreach($serviceIds as $selectedId): ?><input type="hidden" name="service_ids[]" value="<?= $selectedId ?>"><?php endforeach; ?><input type="hidden" name="staff_id" value="<?= $staffId ?>"><input type="hidden" name="appointment_date" value="<?= e($date) ?>">
<div class="col-md-6"><label for="time" class="form-label">4. Available time (IST)</label><select id="time" name="start_time" class="form-select" required><?php foreach($slots as $slot): ?><option <?= ($_POST['start_time']??'')===$slot?'selected':'' ?>><?= e($slot) ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label for="payment" class="form-label">5. Payment method (simulation)</label><select id="payment" name="payment_method" class="form-select"><?php foreach(['cash'=>'Cash at salon','upi'=>'UPI demo','card'=>'Card demo'] as $value=>$label): ?><option value="<?= $value ?>" <?= ($_POST['payment_method']??'cash')===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<p>You can cancel or reschedule until 30 minutes before your appointment. Payments are simulated; no real money is collected.</p>
<div class="col-12"><button class="btn btn-brand">Book appointment</button></div></form>
<?php endif; endif; endif; ?>
</div><aside class="col-lg-4"><div class="dashboard-card p-4"><h2 class="h4"><?= e($salon['name']) ?></h2><p><?= e($salon['address'].', '.$salon['city']) ?></p><?php if($selectedServices): ?><hr><h3 class="h5">Selected services</h3><?php foreach($selectedServices as $item): ?><p><?= e($item['name']) ?> — <?= money($item['price']) ?> · <?= (int)$item['duration_minutes'] ?> min</p><?php endforeach; ?><strong>Total: <?= money($total) ?> · <?= (int)$duration ?> minutes</strong><?php endif; ?></div></aside></div></div></main>
<script>document.querySelectorAll('input[name="service_ids[]"]').forEach(input=>input.addEventListener('change',()=>{document.getElementById('staff').value='';const booking=document.querySelector('form[method="post"]');if(booking)booking.hidden=true;}));document.getElementById('staff')?.addEventListener('change',function(){this.form.requestSubmit()});</script>
<?php include __DIR__.'/../includes/footer.php'; ?>
