<?php
require_once __DIR__.'/includes/booking-service.php'; require_role('customer','vendor','admin');
$userId=(int)current_user()['id'];
if ($_SERVER['REQUEST_METHOD']==='POST') { post_guard(); query('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?',[(int)($_POST['id']??0),$userId]); redirect('notifications.php'); }
$page=max(1,(int)($_GET['page']??1)); $offset=($page-1)*20;
$notes=query("SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 20 OFFSET $offset",[$userId])->fetchAll();
$page_title='Notifications'; include __DIR__.'/includes/header.php';
?>
<main class="dashboard-shell"><div class="container"><h1>Notifications</h1><?php foreach($notes as $note): ?><article class="dashboard-card p-4 mb-3"><h2 class="h5"><?= e($note['title']) ?><?php if(!$note['is_read']): ?> <span class="pill">New</span><?php endif; ?></h2><p><?= e($note['message']) ?></p><small><?= e($note['created_at']) ?> IST</small><?php if(!$note['is_read']): ?><form method="post" class="mt-2"><?= form_token() ?><input type="hidden" name="id" value="<?= $note['id'] ?>"><button class="btn btn-sm btn-outline-brand">Mark read</button></form><?php endif; ?></article><?php endforeach; ?><?php if(!$notes): ?><p>No notifications yet.</p><?php endif; ?><nav class="d-flex gap-3" aria-label="Notification pages"><?php if($page>1): ?><a href="?page=<?= $page-1 ?>">Previous</a><?php endif; ?><?php if(count($notes)===20): ?><a href="?page=<?= $page+1 ?>">Next</a><?php endif; ?></nav></div></main>
<?php include __DIR__.'/includes/footer.php'; ?>
