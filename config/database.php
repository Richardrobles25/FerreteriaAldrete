<?php
$host     = 'metro.proxy.rlwy.net';
$port     = '38728';
$db       = 'railway';
$user     = 'root';
$password = 'LsRfHVEwkzhUwDYcyOkdfZPXwlYqrnYI';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=utf8",
        $user,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexiÃ³n: " . $e->getMessage());
}
