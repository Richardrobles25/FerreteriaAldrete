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

// Migraciones automáticas (idempotentes)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM venta_productos LIKE 'paquete_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE venta_productos ADD COLUMN paquete_id INT NULL DEFAULT NULL");
    }
} catch (\Throwable $_migEx) {}

try {
    $col = $pdo->query("SHOW COLUMNS FROM ventas LIKE 'estado'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], "'Modificado'") === false) {
        $pdo->exec("ALTER TABLE ventas MODIFY COLUMN estado ENUM('Pendiente','Completada','Cancelada','Devuelto','Modificado') NOT NULL DEFAULT 'Pendiente'");
    }
} catch (\Throwable $_migEx) {}

try {
    $col = $pdo->query("SHOW COLUMNS FROM ventas LIKE 'metodo_pago'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], "'Transferencia'") === false) {
        $pdo->exec("ALTER TABLE ventas MODIFY COLUMN metodo_pago ENUM('Efectivo','Terminal','Credito','Mixto','Transferencia') NOT NULL");
    }
} catch (\Throwable $_migEx) {}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM ventas LIKE 'referencia_transferencia'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE ventas ADD COLUMN referencia_transferencia VARCHAR(100) NULL DEFAULT NULL");
    }
} catch (\Throwable $_migEx) {}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM devoluciones LIKE 'total_devuelto'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE devoluciones ADD COLUMN total_devuelto DECIMAL(10,2) NOT NULL DEFAULT 0");
    }
} catch (\Throwable $_migEx) {}
