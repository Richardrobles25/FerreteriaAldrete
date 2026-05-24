<?php
$host     = '127.0.0.1';
$port     = '3306';
$db       = 'ferreteria_aldrete';
$user     = 'ferreteria';
$password = 'Ferreteria2024$';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=utf8",
        $user,
        $password,
        [PDO::ATTR_PERSISTENT => true]
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '-07:00'");
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

date_default_timezone_set('America/Mazatlan');