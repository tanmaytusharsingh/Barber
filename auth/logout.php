<?php
require_once __DIR__ . '/../includes/functions.php';
logout_user();
header('Location: ' . url('index.php'));
exit;
