<?php
require_once __DIR__ . '/../includes/functions.php';
post_guard();
logout_user();
header('Location: ' . url('index.php'));
exit;
