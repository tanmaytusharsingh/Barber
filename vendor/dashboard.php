<?php
require_once __DIR__.'/../includes/vendor-service.php'; require_role('vendor');
$vendorId=(int)current_user()['id']; $salon=vendor_salon($vendorId); $errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    post_guard();
    try {
        $action=$_POST['action']??'';
        if ($action==='complete') complete_appointment($vendorId,(int)($_POST['appointment_id']??0));
        elseif ($action==='cash') simulate_payment($vendorId,(int)($_POST['appointment_id']??0),'paid',$_POST['request_key']??'',true);
        else throw new InvalidArgumentException('Vendors cannot cancel or reschedule appointments.');
        flash('success','Appointment updated.'); redirect('vendor/dashboard.php');
    } catch (Throwable $error) { $errors[]=report_error($error); }
}
$where='a.salon_id=?'; $params=[$salon['id']]; $status=$_GET['status']??''; $date=$_GET['date']??'';
if (in_array($status,['confirmed','completed','cancelled'],true)) { $where.=' AND a.status=?'; $params[]=$status; }
if ($date!=='') { $where.=' AND a.appointment_date=?'; $params[]=$date; }
$page=max(1,(int)($_GET['page']??1)); $offset=($page-1)*20;
$appointments=query("SELECT a.*,u.name customer_name,u.phone customer_phone,u.email customer_email FROM appointments a JOIN users u ON u.id=a.customer_id WHERE $where ORDER BY a.appointment_date DESC,a.start_time DESC LIMIT 20 OFFSET $offset",$params)->fetchAll();
$total=query('SELECT COUNT(*) FROM appointments WHERE salon_id=?',[$salon['id']])->fetchColumn();
$today=query("SELECT COUNT(*) FROM appointments WHERE salon_id=? AND appointment_date=CURDATE() AND status IN ('confirmed','completed')",[$salon['id']])->fetchColumn();
$collections=query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE vendor_id=? AND status='paid'",[$vendorId])->fetchColumn();
$completed=query("SELECT COALESCE(SUM(amount),0) FROM appointments WHERE salon_id=? AND status='completed'",[$salon['id']])->fetchColumn();
$page_title='Vendor dashboard'; include __DIR__.'/../includes/header.php';
?>
<main class="dashboard-shell"><div class="container"><h1><?= e($salon['name']) ?></h1><p>Bookings are confirmed automatically. Only customers can cancel or reschedule, at least 30 minutes before the start.</p><a class="btn btn-brand mb-4" href="<?= e(url('vendor/manage.php')) ?>">Manage salon, services & staff</a>
<?php foreach($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<div class="row g-3 mb-4"><?php foreach(['Net simulated collections'=>money($collections),'Completed services total'=>money($completed),'Total bookings'=>$total,"Today's active/completed visits"=>$today] as $label=>$value): ?><div class="col-md-3"><div class="stat-card"><span><?= e($label) ?></span><div class="stat-number"><?= e((string)$value) ?></div></div></div><?php endforeach; ?></div>
<h2>Customer bookings</h2><form class="row g-2 mb-3"><div class="col-md-4"><label for="filter-status" class="form-label">Status</label><select id="filter-status" name="status" class="form-select"><option value="">All statuses</option><?php foreach(['confirmed','completed','cancelled'] as $option): ?><option value="<?= $option ?>" <?= $status===$option?'selected':'' ?>><?= ucfirst($option) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label for="filter-date" class="form-label">Date</label><input id="filter-date" type="date" name="date" value="<?= e($date) ?>" class="form-control"></div><div class="col-md-4 align-self-end"><button class="btn btn-outline-brand">Filter</button></div></form>
<div class="dashboard-card p-3 table-responsive"><table class="table align-middle"><thead><tr><th>Customer</th><th>Service / specialist</th><th>When (IST)</th><th>Payment (demo)</th><th>Status / actions</th></tr></thead><tbody>
<?php foreach($appointments as $a): ?><tr><td><strong><?= e($a['customer_name']) ?></strong><small class="d-block"><?= e($a['customer_phone']) ?></small><small class="d-block"><?= e($a['customer_email']) ?></small><small class="d-block"><?= e($a['booking_code']) ?></small></td><td><?= e($a['service_name']) ?><small class="d-block"><?= e($a['staff_name']) ?></small></td><td><?= e($a['appointment_date'].' '.substr($a['start_time'],0,5)) ?></td><td><?= money($a['amount']) ?><small class="d-block"><?= e(strtoupper($a['payment_method']).' · '.$a['payment_status']) ?></small>
<?php if(in_array($a['status'],['confirmed','completed'],true) && $a['payment_method']==='cash' && in_array($a['payment_status'],['pending','failed'],true) && appointment_start($a)<=new DateTimeImmutable()): ?><form method="post"><?= form_token() ?><input type="hidden" name="appointment_id" value="<?= $a['id'] ?>"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)) ?>"><button class="btn btn-sm btn-outline-brand" name="action" value="cash">Record simulated cash</button></form><?php endif; ?></td>
<td><span class="pill"><?= e(ucfirst($a['status'])) ?></span><?php if($a['status']==='confirmed' && new DateTimeImmutable($a['appointment_date'].' '.$a['end_time'])<=new DateTimeImmutable()): ?><form method="post" class="mt-2"><?= form_token() ?><input type="hidden" name="appointment_id" value="<?= $a['id'] ?>"><button class="btn btn-sm btn-brand" name="action" value="complete">Mark completed</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table><?php if(!$appointments): ?><p>No bookings match these filters.</p><?php endif; ?></div>
<nav class="d-flex gap-3 mt-3" aria-label="Booking pages"><?php if($page>1): ?><a href="?<?= e(http_build_query(['page'=>$page-1,'status'=>$status,'date'=>$date])) ?>">Previous</a><?php endif; ?><?php if(count($appointments)===20): ?><a href="?<?= e(http_build_query(['page'=>$page+1,'status'=>$status,'date'=>$date])) ?>">Next</a><?php endif; ?></nav></div></main>
<?php include __DIR__.'/../includes/footer.php'; ?>
