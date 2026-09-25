<?php
require_once __DIR__.'/../includes/vendor-service.php'; require_role('vendor');
$vendorId=(int)current_user()['id']; $salon=vendor_salon($vendorId); $errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    post_guard();
    try {
        $action=$_POST['action']??'';
        if($action==='complete') complete_appointment($vendorId,(int)($_POST['appointment_id']??0));
        elseif($action==='cash') simulate_payment($vendorId,(int)($_POST['appointment_id']??0),'paid',$_POST['request_key']??'',true);
        else throw new InvalidArgumentException('Vendors cannot cancel or reschedule appointments.');
        flash('success','Appointment updated.'); redirect('vendor/appointments.php');
    } catch(Throwable $error) { $errors[]=report_error($error); }
}
$status=$_GET['status']??''; $date=$_GET['date']??'';
$filter='a.salon_id=?'; $params=[$salon['id']];
if(in_array($status,['confirmed','completed','cancelled'],true)) { $filter.=' AND a.status=?'; $params[]=$status; }
if($date!=='') {
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed || $parsed->format('Y-m-d')!==$date) { $errors[]='Choose a valid filter date.'; $date=''; }
    else { $filter.=' AND a.appointment_date=?'; $params[]=$date; }
}
$now=date('Y-m-d H:i:s'); $tables=[];
foreach(['upcoming'=>'Next appointments','previous'=>'Previous appointments'] as $key=>$title) {
    $page=max(1,(int)($_GET[$key.'_page']??1)); $offset=($page-1)*20;
    $condition=$key==='upcoming'?"a.status IN ('confirmed','pending') AND TIMESTAMP(a.appointment_date,a.end_time)>?":"(a.status NOT IN ('confirmed','pending') OR TIMESTAMP(a.appointment_date,a.end_time)<=?)";
    $order=$key==='upcoming'?'ASC':'DESC';
    $total=(int)query("SELECT COUNT(*) FROM appointments a WHERE $filter AND $condition",[...$params,$now])->fetchColumn();
    $rows=query("SELECT a.*,u.name customer_name,u.phone customer_phone,u.email customer_email FROM appointments a JOIN users u ON u.id=a.customer_id WHERE $filter AND $condition ORDER BY a.appointment_date $order,a.start_time $order,a.id $order LIMIT 20 OFFSET $offset",[...$params,$now])->fetchAll();
    $tables[$key]=compact('title','page','offset','total','rows');
}
function appointment_colour(array $a): array {
    if(in_array($a['status'],['cancelled','rejected'],true)) return ['cancelled','Cancelled'];
    if($a['payment_method']==='cash') return ['cash','Cash at salon'];
    if($a['payment_status']==='paid') return ['prepaid','Prepaid · Paid'];
    return ['unpaid',ucfirst($a['payment_status']).' payment'];
}
$page_title='Salon appointments'; include __DIR__.'/../includes/header.php';
?>
<main class="dashboard-shell"><div class="container"><div class="page-heading"><div><div class="section-label"><?= e($salon['name']) ?></div><h1>Appointments</h1><p class="muted">Your next visits and booking history, in one place. All times are IST.</p></div></div>
<?php foreach($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<form class="dashboard-card p-3 row g-3 mx-0 mb-4"><div class="col-sm-5"><label for="filter-status" class="form-label">Status</label><select id="filter-status" name="status" class="form-select"><option value="">All statuses</option><?php foreach(['confirmed','completed','cancelled'] as $option): ?><option value="<?= $option ?>" <?= $status===$option?'selected':'' ?>><?= ucfirst($option) ?></option><?php endforeach; ?></select></div><div class="col-sm-5"><label for="filter-date" class="form-label">Appointment date</label><input id="filter-date" type="date" name="date" value="<?= e($date) ?>" class="form-control"></div><div class="col-sm-2 align-self-end"><button class="btn btn-brand w-100">Filter</button></div></form>
<div class="appointment-legend mb-4" aria-label="Appointment colour legend"><span class="booking-tag cancelled">Cancelled</span><span class="booking-tag prepaid">Prepaid · Paid</span><span class="booking-tag cash">Cash at salon</span><span class="booking-tag unpaid">Pending / failed payment</span></div>
<?php foreach($tables as $key=>$table): ?><section class="dashboard-card p-3 p-md-4 mb-4" id="<?= $key ?>"><div class="d-flex align-items-center justify-content-between gap-3 mb-3"><h2 class="h4 mb-0"><?= $table['title'] ?></h2><span class="count-chip"><?= $table['total'] ?></span></div>
<?php if($key==='upcoming'): ?><p class="small muted">Confirmed upcoming visits and appointments currently in progress.</p><?php else: ?><p class="small muted">Ended, completed and cancelled bookings, including future visits that were cancelled.</p><?php endif; ?>
<div class="table-responsive" tabindex="0" aria-label="<?= $table['title'] ?> table"><table class="table appointment-table align-middle"><thead><tr><th scope="col">Customer</th><th scope="col">Service &amp; specialist</th><th scope="col">Date &amp; time</th><th scope="col">Payment</th><th scope="col">Status &amp; actions</th></tr></thead><tbody>
<?php foreach($table['rows'] as $a): [$colour,$label]=appointment_colour($a); ?><tr class="appointment-row appointment-<?= $colour ?>"><td><strong><?= e($a['customer_name']) ?></strong><small class="d-block"><?= e($a['customer_phone']) ?></small><small class="d-block"><?= e($a['customer_email']) ?></small><small class="d-block muted"><?= e($a['booking_code']) ?></small></td><td><?= e($a['service_name']) ?><small class="d-block muted"><?= e($a['staff_name']) ?></small></td><td><?= date('d M Y',strtotime($a['appointment_date'])) ?><small class="d-block"><?= e(substr($a['start_time'],0,5).' – '.substr($a['end_time'],0,5)) ?> IST</small></td><td><strong><?= money($a['amount']) ?></strong><span class="booking-tag <?= $colour ?> d-table mt-2"><?= e($label) ?></span><small class="d-block mt-1"><?= e(strtoupper($a['payment_method']).' · '.ucfirst($a['payment_status'])) ?></small>
<?php if(in_array($a['status'],['confirmed','completed'],true) && $a['payment_method']==='cash' && in_array($a['payment_status'],['pending','failed'],true) && appointment_start($a)<=new DateTimeImmutable()): ?><form method="post" class="mt-2"><?= form_token() ?><input type="hidden" name="appointment_id" value="<?= $a['id'] ?>"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)) ?>"><button class="btn btn-sm btn-outline-brand" name="action" value="cash">Record simulated cash</button></form><?php endif; ?></td>
<td><strong><?= e(ucfirst($a['status'])) ?></strong><?php if($key==='upcoming' && appointment_start($a)<=new DateTimeImmutable()): ?><small class="d-block muted">In progress</small><?php endif; ?>
<?php if($a['status']==='confirmed' && new DateTimeImmutable($a['appointment_date'].' '.$a['end_time'])<=new DateTimeImmutable()): ?><form method="post" class="mt-2"><?= form_token() ?><input type="hidden" name="appointment_id" value="<?= $a['id'] ?>"><button class="btn btn-sm btn-brand" name="action" value="complete">Mark completed</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$table['rows']): ?><tr><td colspan="5" class="text-center py-5 muted">No <?= $key==='upcoming'?'upcoming':'previous' ?> appointments match these filters.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="d-flex gap-3 mt-3" aria-label="<?= $table['title'] ?> pages"><?php foreach([-1=>'Previous',1=>'Next'] as $direction=>$label): if(($direction===-1 && $table['page']<=1) || ($direction===1 && $table['offset']+20>=$table['total'])) continue; $pageQuery=['status'=>$status,'date'=>$date,'upcoming_page'=>$tables['upcoming']['page'],'previous_page'=>$tables['previous']['page']]; $pageQuery[$key.'_page']=$table['page']+$direction; ?><a href="?<?= e(http_build_query($pageQuery)) ?>#<?= $key ?>"><?= $label ?></a><?php endforeach; ?></nav></section><?php endforeach; ?>
<p class="small muted">Bookings confirm automatically. Only customers can cancel or reschedule, at least 30 minutes before the appointment. Payment processing is simulated.</p>
</div></main><?php include __DIR__.'/../includes/footer.php'; ?>
