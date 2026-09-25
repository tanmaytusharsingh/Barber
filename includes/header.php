<?php
require_once __DIR__ . '/functions.php';
$page_title = $page_title ?? 'The Barber Company';
$user = current_user();
$role = $user['role'] ?? 'guest';
$home = in_array($role, ['vendor', 'admin'], true) ? $role . '/dashboard.php' : 'index.php';
$links = match ($role) {
    'vendor' => ['vendor/dashboard.php' => 'Dashboard', 'vendor/appointments.php' => 'Appointments', 'vendor/manage.php' => 'Manage salon'],
    'admin' => ['admin/dashboard.php' => 'Dashboard', 'admin/salons.php' => 'Manage salons'],
    'customer' => ['customer/dashboard.php' => 'Dashboard', 'salons.php' => 'Explore salons', 'customer/appointments.php' => 'My appointments'],
    default => ['salons.php' => 'Explore salons', 'services.php' => 'Services']
};
$unread = 0;
if ($user) {
    $count = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
    $count->execute([$user['id']]);
    $unread = (int)$count->fetchColumn();
}
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
$styleVersion = filemtime(__DIR__ . '/../assets/css/app.css');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> | The Barber Company</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css') . '?v=' . $styleVersion) ?>">
</head>

<body class="role-<?= e($role) ?>">
    <nav class="navbar navbar-expand-xl sticky-top" aria-label="Main navigation">
        <div class="container py-2">
            <a class="brand-mark" href="<?= e(url($home)) ?>"><img class="brand-symbol" src="<?= e(url('assets/logo-mark.svg')) ?>" alt="The Barber Company logo">The Barber Company</a>
            <button class="navbar-toggler" aria-label="Toggle navigation" aria-controls="mainNav" aria-expanded="false" data-bs-toggle="collapse" data-bs-target="#mainNav"><i class="bi bi-list" aria-hidden="true"></i></button>
            <div class="collapse navbar-collapse" id="mainNav">
                <div class="navbar-nav ms-auto align-items-xl-center gap-xl-3">
                    <?php foreach ($links as $path => $label): ?><a class="nav-link <?= $currentPath === url($path) ? 'active' : '' ?>" <?= $currentPath === url($path) ? 'aria-current="page"' : '' ?> href="<?= e(url($path)) ?>"><?= e($label) ?></a><?php endforeach; ?>
                    <?php if ($user): ?><div class="account-actions">
                            <details class="profile-menu" data-notifications-url="<?= e(url('notifications-count.php')) ?>">
                                <summary aria-label="Profile menu for <?= e($user['name']) ?>">
                                    <span class="profile-avatar" aria-hidden="true"><?= e(initials($user['name'])) ?><span class="notification-badge" data-unread-count <?= !$unread ? 'hidden' : '' ?>><?= $unread > 99 ? '99+' : $unread ?></span></span><span class="profile-name"><?= e($user['name']) ?></span><i class="bi bi-chevron-down" aria-hidden="true"></i><span class="visually-hidden" data-unread-label aria-live="polite"><?= $unread ?> unread notifications</span>
                                </summary>
                                <div class="profile-dropdown">
                                    <div class="profile-dropdown-heading"><?= e(ucfirst($role)) ?> account</div><a href="<?= e(url('profile.php')) ?>"><i class="bi bi-person" aria-hidden="true"></i> Edit profile</a><a href="<?= e(url('notifications.php')) ?>"><i class="bi bi-bell" aria-hidden="true"></i> Notifications <span class="ms-auto small muted" data-menu-count><?= $unread ?: '' ?></span></a>
                                </div>
                            </details>
                            <form method="post" action="<?= e(url('auth/logout.php')) ?>"><?= form_token() ?><button class="btn btn-brand signout-button">Sign out</button></form>
                        </div>
                    <?php else: ?><a class="nav-link" href="<?= e(url('auth/login.php')) ?>">Sign in</a><a class="btn btn-brand" href="<?= e(url('auth/register.php')) ?>">Join The Club</a><?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
    <?php if (in_array($role, ['vendor', 'admin'], true)): ?><div class="workspace-strip">
            <div class="container"><?= $role === 'vendor' ? 'SALON PARTNER' : 'PLATFORM ADMINISTRATION' ?></div>
        </div><?php endif; ?>
    <?php if ($message = flash('success')): ?><div class="container mt-3">
            <div class="alert alert-success" role="status"><?= e($message) ?></div>
        </div><?php endif; ?>
    <?php if ($message = flash('error')): ?><div class="container mt-3">
            <div class="alert alert-danger" role="alert"><?= e($message) ?></div>
        </div><?php endif; ?>
    <script src="<?= e(url('assets/js/profile-menu.js')) ?>" defer></script>