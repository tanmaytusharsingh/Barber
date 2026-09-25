<?php
require_once __DIR__.'/../includes/booking-service.php';
require_role('customer'); $customerId=(int)current_user()['id']; $id=(int)($_GET['id']??0);
$a=query('SELECT * FROM appointments WHERE id=? AND customer_id=?',[$id,$customerId])->fetch();
if (!$a) { http_response_code(404); exit('Appointment not found.'); }
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    post_guard();
    try {
        $action=$_POST['action']??'';
        if ($action==='pay') simulate_payment($customerId,$id,$_POST['outcome']??'',$_POST['request_key']??'');
        else change_appointment($customerId,$id,$action,$_POST);
        flash('success',$action==='pay'?'Simulated payment updated. No real money was moved.':'Appointment updated. The salon has been notified.');
        redirect('customer/appointment.php?id='.$id);
    } catch (Throwable $error) { $errors[]=report_error($error); }
}
$a=query('SELECT * FROM appointments WHERE id=? AND customer_id=?',[$id,$customerId])->fetch();
$salon=query('SELECT * FROM salons WHERE id=?',[$a['salon_id']])->fetch();
$payment=query('SELECT * FROM payments WHERE appointment_id=?',[$id])->fetch();
$date=$_POST['appointment_date']??$_GET['date']??$a['appointment_date'];
$bookedItems=json_decode($a['service_items']??'null',true);
$rescheduleItems=is_array($bookedItems)?array_map(fn($item)=>['id'=>(int)$item['id'],'staff_id'=>(int)($item['staff_id']??$a['staff_id']),'duration_minutes'=>(int)$item['duration_minutes']],$bookedItems):[];
$slots=change_allowed($a)?($rescheduleItems?available_plan_slots_for_change($salon,$rescheduleItems,$date,$id):available_slots($salon,(int)$a['staff_id'],$date,(int)$a['duration_minutes'],$id,1800)):[];
$events=query('SELECT * FROM appointment_events WHERE appointment_id=? ORDER BY id DESC',[$id])->fetchAll();
$paymentEvents=query('SELECT pe.* FROM payment_events pe JOIN payments p ON p.id=pe.payment_id WHERE p.appointment_id=? ORDER BY pe.id DESC',[$id])->fetchAll();
$page_title='Booking '.$a['booking_code']; include __DIR__.'/../includes/header.php';
?>
<main class="dashboard-shell"><div class="container"><a href="<?= e(url('customer/appointments.php')) ?>">← My appointments</a><h1 class="mt-3"><?= e($a['service_name']) ?></h1><p><?= e($a['booking_code'].' · '.$a['salon_name'].' · '.$a['staff_name']) ?></p>
<?php foreach($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<div class="row g-4"><section class="col-lg-6"><div class="dashboard-card p-4"><h2 class="h4"><?= e(ucfirst($a['status'])) ?></h2><p><?= e($a['appointment_date'].' '.substr($a['start_time'],0,5).'–'.substr($a['end_time'],0,5)) ?> IST</p><p><?= money($a['amount']) ?> · <?= (int)$a['duration_minutes'] ?> minutes</p><?php if(is_array($bookedItems) && count($bookedItems)>1): ?><h3 class="h6">Services and specialists</h3><ul><?php foreach($bookedItems as $item): ?><li><?= e($item['name'].' — '.($item['staff_name']??$a['staff_name'])) ?> · <?= (int)$item['duration_minutes'] ?> min</li><?php endforeach; ?></ul><?php endif; ?>
<?php if($a['status']==='confirmed'): ?><p><strong>Change deadline:</strong> <?= appointment_start($a)->modify('-30 minutes')->format('d M Y, H:i') ?> IST</p>
<?php if(change_allowed($a)): ?>
<form method="post"><?= form_token() ?><button name="action" value="cancel" class="btn btn-outline-danger">Cancel appointment</button><p class="small mt-2">Cancellation releases your slot and fully refunds any simulated payment.</p></form>
<hr><h3 class="h5">Reschedule</h3><form method="get" class="d-flex gap-2"><input type="hidden" name="id" value="<?= $id ?>"><div><label for="date" class="form-label">New date</label><input id="date" class="form-control" type="date" name="date" min="<?= date('Y-m-d') ?>" value="<?= e($date) ?>" required></div><button class="btn btn-outline-brand align-self-end">Show times</button></form>
<?php if($slots): ?><form method="post" class="mt-3"><?= form_token() ?><input type="hidden" name="appointment_date" value="<?= e($date) ?>"><label for="new-time" class="form-label">New time (IST)</label><select id="new-time" name="start_time" class="form-select mb-3"><?php foreach($slots as $slot): ?><option><?= e($slot) ?></option><?php endforeach; ?></select><button class="btn btn-brand" name="action" value="reschedule">Save new time</button><p class="small mt-2">Same service, specialist, duration and booked price. The new start must be at least 30 minutes away.</p></form>
<?php else: ?><p class="mt-3">No available times on this date. Choose another date.</p><?php endif; ?>
<?php else: ?><p class="alert alert-info">The change deadline has passed. Changes require at least 30 minutes before the appointment starts.</p><?php endif; endif; ?>
</div></section><section class="col-lg-6"><div class="dashboard-card p-4"><h2 class="h4">Simulated payment</h2><p class="alert alert-warning">Demonstration only — no real money is collected or refunded. Do not enter banking or card details.</p><p><?= e(strtoupper($payment['method'])) ?> · <?= e(ucfirst($payment['status'])) ?> · <?= money($payment['amount']) ?></p>
<?php if(in_array($a['status'],['confirmed','completed'],true) && in_array($payment['status'],['pending','failed'],true)): ?>
<?php if($payment['method']==='cash'): ?><p>Cash remains due until the salon records simulated collection at your visit.</p><?php else: ?>
<form method="post"><?= form_token() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)) ?>"><button name="outcome" value="paid" class="btn btn-brand">Simulate success</button> <button name="outcome" value="failed" class="btn btn-outline-brand">Simulate failure</button></form>
<?php endif; endif; ?>
<?php if($payment['transaction_reference']): ?><p class="small mt-3">Demo receipt: <?= e($payment['transaction_reference']) ?></p><?php endif; ?>
<h3 class="h6 mt-4">Payment history</h3><?php foreach($paymentEvents as $event): ?><p class="small"><?= e($event['created_at'].' · '.$event['status'].' · '.$event['reference']) ?></p><?php endforeach; ?>
</div></section></div><section class="dashboard-card p-4 mt-4"><h2 class="h4">Appointment history</h2><?php foreach($events as $event): ?><p><?= e($event['created_at'].' · '.$event['action']) ?><?php if($event['old_schedule']): ?> · From <?= e($event['old_schedule']) ?><?php endif; ?><?php if($event['new_schedule']): ?> · To <?= e($event['new_schedule']) ?><?php endif; ?></p><?php endforeach; ?></section></div></main>
<?php include __DIR__.'/../includes/footer.php'; ?>
