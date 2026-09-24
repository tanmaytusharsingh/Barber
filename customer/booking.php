<?php
require_once __DIR__.'/../includes/booking-service.php';
require_role('customer');
$errors=[];
$salonId=(int)($_POST['salon_id']??$_GET['salon_id']??0);
$serviceId=(int)($_POST['service_id']??$_GET['service_id']??0);
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
$service=null; foreach($services as $item) if ((int)$item['id']===$serviceId) $service=$item;
$staff=$service?query("SELECT st.* FROM staff st JOIN staff_services ss ON ss.staff_id=st.id WHERE st.salon_id=? AND ss.service_id=? AND st.status='active' ORDER BY st.name",[$salonId,$serviceId])->fetchAll():[];
$person=null; foreach($staff as $item) if ((int)$item['id']===$staffId) $person=$item;
$slots=$person?available_slots($salon,$staffId,$date,(int)$service['duration_minutes']):[];
$page_title='Book an appointment'; include __DIR__.'/../includes/header.php';
?>
<main class="section-pad"><div class="container"><div class="row g-4"><div class="col-lg-8">
<div class="section-label">Make it yours</div><h1>Book at <?= e($salon['name']) ?></h1>
<p>Appointments are confirmed immediately. All times are India Standard Time (IST).</p>
<?php foreach($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<?php if(!$services): ?><div class="alert alert-info">No services with an available specialist are currently bookable at this salon.</div><?php else: ?>
<form method="get" class="dashboard-card p-4 row g-3">
<input type="hidden" name="salon_id" value="<?= $salonId ?>">
<div class="col-md-6"><label for="service" class="form-label">1. Service</label><select id="service" name="service_id" class="form-select"><option value="">Choose a service</option><?php foreach($services as $item): ?><option value="<?= $item['id'] ?>" <?= (int)$item['id']===$serviceId?'selected':'' ?>><?= e($item['name']) ?> — <?= money($item['price']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label for="staff" class="form-label">2. Specialist</label><select id="staff" name="staff_id" class="form-select"><option value="">Choose a specialist</option><?php foreach($staff as $item): ?><option value="<?= $item['id'] ?>" <?= (int)$item['id']===$staffId?'selected':'' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select><small>Select a service and click Show available times to load its specialists.</small></div>
<div class="col-md-6"><label for="date" class="form-label">3. Date</label><input id="date" name="appointment_date" class="form-control" type="date" min="<?= date('Y-m-d') ?>" value="<?= e($date) ?>" required></div>
<div class="col-12"><button class="btn btn-outline-brand">Show available times</button></div></form>
<?php if($person): ?>
<?php if(!$slots): ?><div class="alert alert-info mt-3">No available times for this specialist on this date. Choose another date or specialist.</div><?php else: ?>
<form method="post" class="dashboard-card p-4 mt-3 row g-3"><?= form_token() ?>
<input type="hidden" name="request_key" value="<?= e($requestKey) ?>"><input type="hidden" name="salon_id" value="<?= $salonId ?>"><input type="hidden" name="service_id" value="<?= $serviceId ?>"><input type="hidden" name="staff_id" value="<?= $staffId ?>"><input type="hidden" name="appointment_date" value="<?= e($date) ?>">
<div class="col-md-6"><label for="time" class="form-label">4. Available time (IST)</label><select id="time" name="start_time" class="form-select" required><?php foreach($slots as $slot): ?><option <?= ($_POST['start_time']??'')===$slot?'selected':'' ?>><?= e($slot) ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label for="payment" class="form-label">5. Payment method (simulation)</label><select id="payment" name="payment_method" class="form-select"><?php foreach(['cash'=>'Cash at salon','upi'=>'UPI demo','card'=>'Card demo'] as $value=>$label): ?><option value="<?= $value ?>" <?= ($_POST['payment_method']??'cash')===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<p>You can cancel or reschedule until 30 minutes before your appointment. Payments are simulated; no real money is collected.</p>
<div class="col-12"><button class="btn btn-brand">Book appointment</button></div></form>
<?php endif; endif; endif; ?>
</div><aside class="col-lg-4"><div class="dashboard-card p-4"><h2 class="h4"><?= e($salon['name']) ?></h2><p><?= e($salon['address'].', '.$salon['city']) ?></p><?php if($service): ?><hr><h3 class="h5"><?= e($service['name']) ?></h3><p><?= money($service['price']) ?> · <?= (int)$service['duration_minutes'] ?> minutes</p><?php endif; ?></div></aside></div></div></main>
<script>document.getElementById('service')?.addEventListener('change',function(){document.getElementById('staff').value='';this.form.requestSubmit()});document.getElementById('staff')?.addEventListener('change',function(){this.form.requestSubmit()});</script>
<?php include __DIR__.'/../includes/footer.php'; ?>
