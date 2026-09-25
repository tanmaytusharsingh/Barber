<?php
require_once __DIR__.'/includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); header('Allow: GET'); echo '{"error":"GET required"}'; exit; }
$user=current_user();
if (!$user) { http_response_code(401); echo '{"error":"Sign in required"}'; exit; }
$stmt=db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0'); $stmt->execute([$user['id']]);
echo json_encode(['count'=>(int)$stmt->fetchColumn()]);
