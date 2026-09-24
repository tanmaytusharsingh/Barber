<?php
require_once __DIR__ . '/../includes/auth-check.php';
require_role('customer');
$salonId = (int)($_GET['salon_id'] ?? $_POST['salon_id'] ?? 0); $serviceId = (int)($_GET['service_id'] ?? $_POST['service_id'] ?? 0);
$salonStmt = db()->prepare("SELECT * FROM salons WHERE id=? AND status='approved'"); $salonStmt->execute([$salonId]); $salon = $salonStmt->fetch(); if (!$salon) redirect('salons.php');
$servicesStmt = db()->prepare("SELECT * FROM services WHERE salon_id=? AND status='active' ORDER BY name"); $servicesStmt->execute([$salonId]); $services = $servicesStmt->fetchAll();
$selectedService = null; foreach ($services as $service) if ((int)$service['id'] === $serviceId) $selectedService = $service;
$staff = [];
if ($selectedService) { $staffStmt = db()->prepare("SELECT st.* FROM staff st JOIN staff_services ss ON ss.staff_id=st.id WHERE ss.service_id=? AND st.status='active' ORDER BY st.name"); $staffStmt->execute([$serviceId]); $staff=$staffStmt->fetchAll(); }
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) $errors[]='Your session expired. Please try again.';
    $staffId=(int)($_POST['staff_id']??0); $date=$_POST['appointment_date']??''; $time=$_POST['start_time']??''; $method=$_POST['payment_method']??'cash';
    if (!$selectedService || !$staffId || !$date || !$time || !in_array($method,['cash','upi','card'],true)) $errors[]='Complete each booking step before confirming.';
    if ($date < date('Y-m-d')) $errors[]='Choose a date from today onward.';
    if (!$errors) {
        $validStaff=db()->prepare('SELECT 1 FROM staff_services WHERE staff_id=? AND service_id=?'); $validStaff->execute([$staffId,$serviceId]);
        if (!$validStaff->fetchColumn()) $errors[]='That specialist is not available for this service.';
    }
    if (!$errors) {
        $endTime=date('H:i:s',strtotime($time)+((int)$selectedService['duration_minutes']*60));
        $pdo=db(); $pdo->beginTransaction();
        try {
            $code='TBC-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
            $insert=$pdo->prepare("INSERT INTO appointments (booking_code,customer_id,salon_id,service_id,staff_id,appointment_date,start_time,end_time,amount,payment_method) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $insert->execute([$code,current_user()['id'],$salonId,$serviceId,$staffId,$date,$time,$endTime,$selectedService['price'],$method]); $appointmentId=(int)$pdo->lastInsertId();
            $payment=$pdo->prepare("INSERT INTO payments (appointment_id,customer_id,vendor_id,amount,method) SELECT ?,?,?,?,u.id FROM users u JOIN salons s ON s.vendor_id=u.id WHERE s.id=?"); $payment->execute([$appointmentId,current_user()['id'],$selectedService['price'],$method,$salonId]);
            $note=$pdo->prepare('INSERT INTO notifications (user_id,title,message) VALUES (?,?,?)'); $note->execute([current_user()['id'],'Appointment requested','Your booking '.$code.' is waiting for salon confirmation.']);
            $pdo->commit(); flash('success','Your appointment request is confirmed.'); redirect('customer/appointments.php');
        } catch (PDOException $exception) { $pdo->rollBack(); $errors[]=$exception->errorInfo[1]===1062?'That time has just been booked. Please choose another slot.':'We could not complete this booking.'; }
    }
}
$page_title='Book an appointment'; include __DIR__.'/../includes/header.php';
?><main class="section-pad"><div class="container"><div class="row g-5"><div class="col-lg-7"><div class="section-label">Make it yours</div><h1 class="display-5">Book at <?= e($salon['name']) ?>.</h1><p class="muted">Choose your service and specialist. We will hold the details together for you.</p><?php foreach($errors as $error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endforeach; ?><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="salon_id" value="<?= $salonId ?>"><div class="col-12"><label class="form-label small fw-semibold">1. Service</label><select class="form-select" name="service_id" onchange="this.form.submit()"><option value="">Choose a service</option><?php foreach($services as $service): ?><option value="<?= $service['id'] ?>" <?= $serviceId===$service['id']?'selected':'' ?>><?= e($service['name']) ?> · <?= money($service['price']) ?> · <?= e($service['duration_minutes']) ?> min</option><?php endforeach; ?></select></div><?php if($selectedService): ?><div class="col-12"><label class="form-label small fw-semibold">2. Specialist</label><div class="row g-2"><?php foreach($staff as $person): ?><div class="col-md-6"><label class="staff-card d-flex gap-3 align-items-center p-3"><input type="radio" name="staff_id" value="<?= $person['id'] ?>" required><div class="avatar"><?= e(initials($person['name'])) ?></div><div><strong><?= e($person['name']) ?></strong><small class="d-block muted"><?= e($person['specialization']) ?></small></div></label></div><?php endforeach; ?></div></div><div class="col-md-6"><label class="form-label small fw-semibold">3. Date</label><input class="form-control" type="date" name="appointment_date" min="<?= date('Y-m-d') ?>" value="<?= e($_POST['appointment_date']??date('Y-m-d')) ?>" required></div><div class="col-md-6"><label class="form-label small fw-semibold">Time</label><input class="form-control" type="time" name="start_time" min="<?= e(substr($salon['opening_time'],0,5)) ?>" max="<?= e(substr($salon['closing_time'],0,5)) ?>" value="<?= e($_POST['start_time']??'10:00') ?>" required></div><div class="col-12"><label class="form-label small fw-semibold">Payment method</label><div class="d-flex gap-2 flex-wrap"><?php foreach(['cash'=>'Cash','upi'=>'UPI','card'=>'Card'] as $value=>$label): ?><label class="btn btn-outline-brand"><input class="me-1" type="radio" name="payment_method" value="<?= $value ?>" <?= ($_POST['payment_method']??'cash')===$value?'checked':'' ?>> <?= $label ?></label><?php endforeach; ?></div></div><div class="col-12"><button class="btn btn-brand">Confirm appointment <i class="bi bi-arrow-right ms-2"></i></button></div><?php endif; ?></form></div><aside class="col-lg-4"><div class="dashboard-card p-4"><div class="section-label">Your booking</div><h2 class="h3 mt-2"><?= e($salon['name']) ?></h2><p class="muted"><i class="bi bi-geo-alt me-2"></i><?= e($salon['address']) ?>, <?= e($salon['city']) ?></p><?php if($selectedService): ?><hr><div class="d-flex justify-content-between"><span><?= e($selectedService['name']) ?></span><strong><?= money($selectedService['price']) ?></strong></div><small class="muted"><?= e($selectedService['duration_minutes']) ?> minutes</small><?php endif; ?></div></aside></div></div></main><?php include __DIR__.'/../includes/footer.php'; ?>
