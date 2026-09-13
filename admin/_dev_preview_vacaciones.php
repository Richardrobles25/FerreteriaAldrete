<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/rh_helpers.php';
verificarSesion();
verificarRol(['Administrador']);

$hoy     = date('Y-m-d');
$en1anio = date('Y-m-d', strtotime('+1 year'));

$stmt = $pdo->query("SELECT empleado_id, nombre, fecha_ingreso, saldo_vacaciones_ajuste, saldo_vacaciones_ajuste_fecha FROM empleados WHERE activo=1 ORDER BY nombre");
$empleados = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Vista previa: simulación de vacaciones (temporal)</title>
<style>
    body { background:#1a1a1a; color:#eee; font-family: Arial, sans-serif; padding: 30px; }
    h1 { font-size: 20px; }
    p.aviso { color:#e0a030; font-size: 13px; }
    table { border-collapse: collapse; width: 100%; margin-top: 20px; }
    th, td { border: 1px solid #444; padding: 10px 14px; text-align: left; font-size: 14px; }
    th { background: #262626; }
    tr:nth-child(even) { background: #222; }
    .num { text-align: center; font-weight: bold; }
</style>
</head>
<body>
<h1>Simulación de saldo de vacaciones — SIN cambiar la fecha del sistema</h1>
<p class="aviso">Página temporal solo para verificación. Usa la misma función <code>calcSaldoVacaciones()</code> que ya usa el sistema real, pidiéndole el saldo "a fecha X" en vez de esperar que pase el tiempo. No modifica ningún dato.</p>
<table>
<tr>
    <th>Empleado</th>
    <th>Fecha de ingreso</th>
    <th>Ajuste manual capturado</th>
    <th>Saldo HOY (<?= $hoy ?>)</th>
    <th>Saldo dentro de 1 año (<?= $en1anio ?>)</th>
</tr>
<?php foreach ($empleados as $e): ?>
<?php
    $saldoHoy   = calcSaldoVacaciones($pdo, (int)$e['empleado_id'], $e['fecha_ingreso'], $hoy);
    $saldo1anio = calcSaldoVacaciones($pdo, (int)$e['empleado_id'], $e['fecha_ingreso'], $en1anio);
    $ajuste = $e['saldo_vacaciones_ajuste'] !== null
        ? $e['saldo_vacaciones_ajuste'] . ' días (' . $e['saldo_vacaciones_ajuste_fecha'] . ')'
        : '— (sin ajuste, se calcula desde fecha de ingreso)';
?>
<tr>
    <td><?= htmlspecialchars($e['nombre']) ?></td>
    <td><?= htmlspecialchars($e['fecha_ingreso']) ?></td>
    <td><?= htmlspecialchars($ajuste) ?></td>
    <td class="num"><?= $saldoHoy ?></td>
    <td class="num"><?= $saldo1anio ?></td>
</tr>
<?php endforeach; ?>
</table>
</body>
</html>
