<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
session_unset();
session_destroy();
header('Location: /index.php');
exit();
