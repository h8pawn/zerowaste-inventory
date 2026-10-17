<?php
require_once __DIR__ . '/includes/app.php';

$_SESSION = [];
$cookie = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600] + array_intersect_key($cookie, array_flip(['path', 'domain', 'secure', 'httponly', 'samesite'])));
session_destroy();
redirect('index.php');
