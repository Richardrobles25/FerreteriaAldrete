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
        $password,
        [PDO::ATTR_PERSISTENT => true]   // reutiliza la conexión TCP entre requests
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '-07:00'");
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Zona horaria de PHP: Ixtlán del Río, Nayarit (UTC-7 todo el año)
date_default_timezone_set('America/Mazatlan');
