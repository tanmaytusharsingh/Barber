<?php
require_once __DIR__.'/../includes/vendor-service.php'; require_role('vendor');
$salon=vendor_salon((int)current_user()['id']);
$total=(int)query('SELECT COUNT(*) FROM appointments WHERE salon_id=?',[$salon['id']])->fetchColumn();
$completed=(int)query("SELECT COUNT(*) FROM appointments WHERE salon_id=? AND status='completed'",[$salon['id']])->fetchColumn();
$reviewStats=query("SELECT COUNT(*) total, AVG(rating) average_rating FROM reviews WHERE salon_id=? AND status='visible'",[$salon['id']])->fetch();
$page=max(1,(int)($_GET['page']??1)); $offset=($page-1)*10;
$reviews=query("SELECT r.*,u.name customer_name,a.service_name FROM reviews r JOIN users u ON u.id=r.customer_id JOIN appointments a ON a.id=r.appointment_id WHERE r.salon_id=? AND r.status='visible' ORDER BY r.created_at DESC,r.id DESC LIMIT 10 OFFSET $offset",[$salon['id']])->fetchAll();
$page_title='Salon dashboard'; include __DIR__.'/../includes/header.php';
?>
<main class="dashboard-shell"><div class="container"><div class="page-heading"><div><div class="section-label">Your salon at a glance</div><h1><?= e($salon['name']) ?></h1><p class="muted">Appointments, completed visits and feedback from your customers.</p></div><a class="btn btn-brand" href="<?= e(url('vendor/appointments.php')) ?>">View appointments <i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></a></div>
<div class="row g-3 mb-5">
<?php foreach([['Total appointments',$total,'bi-calendar3'],['Completed appointments',$completed,'bi-check2-circle'],['Customer reviews',(int)$reviewStats['total'],'bi-chat-square-text'],['Average rating',$reviewStats['average_rating']!==null?number_format((float)$reviewStats['average_rating'],1).' / 5':'—','bi-star']] as [$label,$value,$icon]): ?><div class="col-sm-6 col-xl-3"><div class="stat-card role-stat"><i class="bi <?= $icon ?> stat-icon" aria-hidden="true"></i><span class="muted small"><?= $label ?></span><div class="stat-number"><?= e((string)$value) ?></div></div></div><?php endforeach; ?>
</div>
<section class="dashboard-card p-4 p-md-5"><div class="section-label">Customer feedback</div><h2 class="h3 mb-4">Reviews</h2>
<?php if(!$reviews): ?><div class="empty-state"><i class="bi bi-chat-square-heart" aria-hidden="true"></i><h3 class="h5 mt-3">No reviews yet</h3><p class="muted mb-0">Customer reviews for your salon will appear here.</p></div><?php endif; ?>
<?php foreach($reviews as $review): ?><article class="review-item"><div class="d-flex flex-wrap justify-content-between gap-2"><div><strong><?= e($review['customer_name']) ?></strong><small class="d-block muted"><?= e($review['service_name']) ?> · <?= date('d M Y',strtotime($review['created_at'])) ?></small></div><span class="review-rating"><i class="bi bi-star-fill" aria-hidden="true"></i> <?= (int)$review['rating'] ?>/5</span></div><p class="mt-3 mb-0"><?= nl2br(e($review['review']?:'No written comment.')) ?></p></article><?php endforeach; ?>
<nav class="d-flex gap-3 mt-3" aria-label="Review pages"><?php if($page>1): ?><a href="?page=<?= $page-1 ?>">Previous</a><?php endif; ?><?php if($offset+10<(int)$reviewStats['total']): ?><a href="?page=<?= $page+1 ?>">Next</a><?php endif; ?></nav>
</section><a class="btn btn-outline-brand mt-4" href="<?= e(url('vendor/manage.php')) ?>">Manage salon, services &amp; staff</a></div></main>
<?php include __DIR__.'/../includes/footer.php'; ?>
