<?php
require_once __DIR__.'/../includes/booking-service.php';
require_role('customer');
$page=max(1,(int)($_GET['page']??1)); $offset=($page-1)*20;
$appointments=query("SELECT * FROM appointments WHERE customer_id=? ORDER BY appointment_date DESC,start_time DESC LIMIT 20 OFFSET $offset",[current_user()['id']])->fetchAll();
$page_title='My appointments'; include __DIR__.'/../includes/header.php';
?>
<main class="dashboard-shell"><div class="container"><h1>My appointments</h1><p>Bookings confirm automatically. Only you can cancel or reschedule, at least 30 minutes before the start.</p>
<a class="btn btn-brand mb-4" href="<?= e(url('salons.php')) ?>">Book a new visit</a>
<div class="dashboard-card p-4 table-responsive"><table class="table"><thead><tr><th>Booking</th><th>Service</th><th>When (IST)</th><th>Payment</th><th>Status</th><th>Details</th></tr></thead><tbody>
<?php foreach($appointments as $a): ?><tr><td><?= e($a['booking_code']) ?><small class="d-block"><?= e($a['salon_name']) ?></small></td><td><?= e($a['service_name']) ?><small class="d-block"><?= e($a['staff_name']) ?></small></td><td><?= e($a['appointment_date'].' '.substr($a['start_time'],0,5)) ?></td><td><?= money($a['amount']) ?><small class="d-block">Demo: <?= e($a['payment_status']) ?></small></td><td><?= e(ucfirst($a['status'])) ?></td><td><a href="<?= e(url('customer/appointment.php?id='.$a['id'])) ?>">View booking</a></td></tr><?php endforeach; ?>
</tbody></table><?php if(!$appointments): ?><p>No appointments to show.</p><?php endif; ?></div>
<nav class="d-flex gap-3 mt-3" aria-label="Appointment pages"><?php if($page>1): ?><a href="?page=<?= $page-1 ?>">Previous</a><?php endif; ?><?php if(count($appointments)===20): ?><a href="?page=<?= $page+1 ?>">Next</a><?php endif; ?></nav>
</div></main><?php include __DIR__.'/../includes/footer.php'; ?>
