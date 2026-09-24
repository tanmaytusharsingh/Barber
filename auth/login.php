<?php
require_once __DIR__ . '/../includes/functions.php';
if (current_user()) { redirect(current_user()['role'] . '/dashboard.php'); }
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $identity = trim($_POST['identity'] ?? ''); $password = $_POST['password'] ?? '';
    if ($identity === '' || $password === '') $errors[] = 'Enter your email and password.';
    if (!$errors) {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? OR phone = ? LIMIT 1'); $stmt->execute([$identity, $identity]); $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) $errors[] = 'Those credentials do not match our records.';
        elseif ($user['status'] === 'pending') $errors[] = 'Your partner application is waiting for approval.';
        elseif ($user['status'] !== 'active') $errors[] = 'This account is currently inactive.';
        else { login_user($user); redirect($user['role'] . '/dashboard.php'); }
    }
}
$page_title = 'Sign in'; include __DIR__ . '/../includes/header.php';
?><main class="auth-wrap"><div class="auth-card"><div class="section-label">Welcome back</div><h1 class="display-6 mb-2">Make time for yourself.</h1><p class="muted mb-4">Sign in to manage your appointments and preferences.</p><?php foreach ($errors as $error): ?><div class="alert alert-danger py-2 small"><?= e($error) ?></div><?php endforeach; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label for="identity" class="form-label small fw-semibold">Email or mobile</label><input class="form-control mb-3" id="identity" name="identity" value="<?= old('identity') ?>" autocomplete="username"><label for="password" class="form-label small fw-semibold">Password</label><input class="form-control mb-4" type="password" id="password" name="password" autocomplete="current-password"><button class="btn btn-brand w-100">Sign in <i class="bi bi-arrow-right ms-2"></i></button></form><p class="text-center small muted mt-4 mb-0">New here? <a class="text-decoration-underline" href="<?= e(url('auth/register.php')) ?>">Create an account</a></p></div></main><?php include __DIR__ . '/../includes/footer.php'; ?>
