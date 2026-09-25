<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

const APP_URL = '/Barber';

if (PHP_SAPI === 'cli') { $_SESSION = []; }
elseif (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly'=>true, 'samesite'=>'Lax', 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path'=>APP_URL . '/']);
    session_start();
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(function (Throwable $error): void {
    error_log('Barber unhandled ' . get_class($error) . ': ' . $error->getMessage());
    http_response_code($error instanceof InvalidArgumentException ? 422 : 500);
    echo $error instanceof InvalidArgumentException ? e($error->getMessage()) : 'Something went wrong. Please try again later.';
});
if (is_file(__DIR__ . '/../config/maintenance.lock') && PHP_SAPI !== 'cli') {
    http_response_code(503); header('Retry-After: 60'); exit('Maintenance in progress. Please try again shortly.');
}
if (PHP_SAPI !== 'cli') {
    // A migration takes the exclusive counterpart, draining requests before DDL.
    $maintenanceGuard=fopen(__DIR__.'/../config/maintenance.guard','c');
    if (!$maintenanceGuard || !flock($maintenanceGuard,LOCK_SH)) { http_response_code(503); exit('Temporarily unavailable.'); }
    if (is_file(__DIR__.'/../config/maintenance.lock')) { http_response_code(503); header('Retry-After: 60'); exit('Maintenance in progress. Please try again shortly.'); }
}
foreach ([$_GET, $_POST] as $input) {
    foreach ($input as $key=>$value) {
        $serviceList=$key==='service_ids' && is_array($value) && count($value)<=100 && count(array_filter($value,'is_string'))===count($value);
        $staffPlan=$key==='staff_plan' && is_array($value) && count($value)<=20 && count(array_filter(array_keys($value),fn($id)=>ctype_digit((string)$id)))===count($value) && count(array_filter($value,fn($id)=>is_string($id) && ctype_digit($id) && (int)$id>0))===count($value);
        if (is_array($value) && !$serviceList && !$staffPlan) {
            http_response_code(400); exit('Invalid request field.');
        }
        if (is_string($value) && strlen($value) > 10000) { http_response_code(400); exit('Request field is too long.'); }
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(mixed $token): bool
{
    $stored = $_SESSION['csrf_token'] ?? null;
    return is_string($token) && $token !== '' && is_string($stored) && $stored !== '' && hash_equals($stored, $token);
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $value;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $statement = db()->prepare('SELECT id, name, email, phone, role, status, created_at FROM users WHERE id = ? LIMIT 1');
    $statement->execute([(int) $_SESSION['user_id']]);
    $user = $statement->fetch();
    if (!$user || $user['status'] !== 'active') {
        unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['csrf_token']);
        session_regenerate_id(true);
        return null;
    }
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_role'] = $user['role'];
    unset($_SESSION['csrf_token']);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_role(string ...$roles): void
{
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        flash('error', 'Please sign in with an authorized account to continue.');
        redirect('auth/login.php');
    }
}

function money(float|int|string $amount): string
{
    return '₹' . number_format((float) $amount, 2);
}

function post_guard(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('POST required.'); }
    if (!verify_csrf($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Your session expired. Please reload the page and try again.'); }
}
function field(array $data, string $key, int $max = 160, bool $required = true): string {
    $value = $data[$key] ?? '';
    if (!is_string($value) || mb_strlen(trim($value)) > $max || ($required && trim($value) === '')) throw new InvalidArgumentException('Please enter a valid ' . str_replace('_',' ',$key) . '.');
    return trim($value);
}
function image_url(?string $value): string {
    return $value && filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($value, PHP_URL_SCHEME) ?: ''), ['https','http'], true) ? $value : url('assets/salon-placeholder.svg');
}
function price_label(mixed $price): string { return $price === null ? 'No services available' : 'From ' . money($price); }
function form_token(): string { return '<input type="hidden" name="csrf_token" value="'.e(csrf_token()).'">'; }
function report_error(Throwable $error): string {
    if ($error instanceof InvalidArgumentException) return $error->getMessage();
    error_log('Barber operation failed: ' . $error->getMessage());
    return 'We could not save this change. Please try again.';
}

function old(string $key): string
{
    return e($_POST[$key] ?? '');
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    return strtoupper(substr($parts[0] ?? 'T', 0, 1) . substr($parts[count($parts) - 1] ?? '', 0, 1));
}
