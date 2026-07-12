<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);

// Semana activa: lunes a sabado
$semanaParam = trim($_GET['semana'] ?? '');
if ($semanaParam && strtotime($semanaParam)) {
    $dtLunes = new DateTime($semanaParam);
    // Asegurar que sea lunes
    $dow = (int)$dtLunes->format('N');
    if ($dow !== 1) $dtLunes->modify('last monday');
} else {
    $dtLunes = new DateTime();
    $dow = (int)$dtLunes->format('N');
    if ($dow === 7) $dtLunes->modify('+1 day');
    elseif ($dow !== 1) $dtLunes->modify('last monday');
}
$dtSabado = (clone $dtLunes)->modify('+5 days');

$lunes  = $dtLunes->format('Y-m-d');
$sabado = $dtSabado->format('Y-m-d');
$hoy    = date('Y-m-d');

// Corte: hasta hoy si estamos en la semana activa, hasta sabado si es semana pasada
$esSemanaActual = ($hoy >= $lunes && $hoy <= $sabado);
$fechaCorte     = $esSemanaActual ? $hoy : $sabado;
$semanaCompleta = ($fechaCorte === $sabado);

$semanaAnterior  = (clone $dtLunes)->modify('-7 days')->format('Y-m-d');
$semanaSiguiente = (clone $dtLunes)->modify('+7 days')->format('Y-m-d');
$etiquetaSemana  = $dtLunes->format('d/m/Y') . ' — ' . $dtSabado->format('d/m/Y');

// Dias transcurridos en la semana (1=lunes ... 6=sabado)
$diasTranscurridos = 0;
if ($esSemanaActual) {
    $dowHoy = (int)(new DateTime($hoy))->format('N');
    $diasTranscurridos = min($dowHoy, 6);
} else {
    $diasTranscurridos = 6;
}

// Todos los empleados activos
$empleados = $pdo->query("
    SELECT e.*
    FROM empleados e
    WHERE e.activo = 1
    ORDER BY e.nombre
")->fetchAll(PDO::FETCH_ASSOC);

// Registros de asistencia acumulados hasta el corte
// DAYOFWEEK: 1=Dom, 2=Lun...7=Sab → sabado=7 → 6hrs, resto=9hrs
$stmtA = $pdo->prepare("
    SELECT empleado_id,
           SUM(horas_no_trabajadas) AS total_no_trabajadas,
           SUM(horas_extra)         AS total_extra,
           COUNT(*)                 AS total_registros,
           SUM(CASE WHEN tipo != 'Falta'
               THEN (CASE WHEN DAYOFWEEK(fecha) = 7 THEN 6.0 ELSE 9.0 END)
                    - horas_no_trabajadas + horas_extra
               ELSE 0 END)         AS total_horas_trabajadas,
           SUM(CASE WHEN tipo = 'Asistencia normal' THEN 1 ELSE 0 END) AS dias_normales,
           SUM(CASE WHEN tipo = 'Falta'             THEN 1 ELSE 0 END) AS dias_falta
    FROM asistencia
    WHERE fecha BETWEEN ? AND ?
    GROUP BY empleado_id
");
$stmtA->execute([$lunes, $fechaCorte]);
$asistenciaMap = [];
foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $asistenciaMap[$row['empleado_id']] = $row;
}

// Armar tabla
$filas = [];
$totalSueldos = 0;
$totalDeducciones = 0;
$totalBonos = 0;
$totalFinal = 0;

foreach ($empleados as $emp) {
    $eid    = $emp['empleado_id'];
    $sueldo = floatval($emp['sueldo_semanal']);
    $tarifa = $sueldo / 51;

    $horasNT         = floatval($asistenciaMap[$eid]['total_no_trabajadas']  ?? 0);
    $horasExtra      = floatval($asistenciaMap[$eid]['total_extra']          ?? 0);
    $horasTrabajadas = floatval($asistenciaMap[$eid]['total_horas_trabajadas'] ?? 0);
    $diasNormales    = intval($asistenciaMap[$eid]['dias_normales']          ?? 0);
    $diasFalta       = intval($asistenciaMap[$eid]['dias_falta']             ?? 0);
    $totalReg        = intval($asistenciaMap[$eid]['total_registros']        ?? 0);

    $horasCompensadas   = min($horasNT, $horasExtra);
    $horasNetaDeducir   = round($horasNT    - $horasCompensadas, 2);
    $horasExtraNeta     = round($horasExtra - $horasCompensadas, 2);

    $deduccion  = round($horasNetaDeducir * $tarifa,       2);
    $bono       = round($horasExtraNeta   * $tarifa * 1.5, 2);
    $pagoFinal  = round($sueldo - $deduccion + $bono,      2);

    $totalSueldos     += $sueldo;
    $totalDeducciones += $deduccion;
    $totalBonos       += $bono;
    $totalFinal       += $pagoFinal;

    $filas[] = [
        'empleado_id'      => $eid,
        'empleado'         => $emp['nombre'],
        'sueldo'           => $sueldo,
        'horas_trabajadas' => round($horasTrabajadas, 2),
        'dias_normales'    => $diasNormales,
        'dias_falta'       => $diasFalta,
        'total_reg'        => $totalReg,
        'horas_nt'         => $horasNT,
        'horas_extra'      => $horasExtra,
        'horas_comp'       => $horasCompensadas,
        'horas_neta_ded'   => $horasNetaDeducir,
        'horas_extra_neta' => $horasExtraNeta,
        'deduccion'        => $deduccion,
        'bono'             => $bono,
        'pago_final'       => $pagoFinal,
    ];
}

// Vacaciones aprobadas que caen en esta semana
$stmtVac = $pdo->prepare("
    SELECT empleado_id,
           GREATEST(fecha_inicio, ?) AS desde,
           LEAST(fecha_fin,    ?)    AS hasta
    FROM vacaciones
    WHERE estado = 'Aprobado'
      AND fecha_inicio <= ?
      AND fecha_fin    >= ?
");
$stmtVac->execute([$lunes, $sabado, $sabado, $lunes]);
$vacMap = [];
foreach ($stmtVac->fetchAll(PDO::FETCH_ASSOC) as $vr) {
    $dias = (new DateTime($vr['desde']))->diff(new DateTime($vr['hasta']))->days + 1;
    $vacMap[$vr['empleado_id']] = ($vacMap[$vr['empleado_id']] ?? 0) + $dias;
}

// Adelantos por empleado de ESTA semana (el adelanto se descuenta del sueldo
// de la semana en que se pidio, sin importar si ya se marco liquidado)
$stmtAdel = $pdo->prepare("
    SELECT empleado_id, SUM(monto) AS total
    FROM adelantos_sueldo
    WHERE fecha BETWEEN ? AND ?
    GROUP BY empleado_id
");
$stmtAdel->execute([$lunes, $sabado]);
$adelantosMap = [];
foreach ($stmtAdel->fetchAll(PDO::FETCH_ASSOC) as $ar) {
    $adelantosMap[$ar['empleado_id']] = floatval($ar['total']);
}

// Registrar pago de nomina (se coloca aqui porque necesita $filas y $adelantosMap ya calculados)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pagar_empleado'])) {
    requerirCSRF($_POST['_token'] ?? '', 'semanaLaboral.php');
    $eidPago = intval($_POST['empleado_id']);

    $filaPago = null;
    foreach ($filas as $f) {
        if ($f['empleado_id'] === $eidPago) { $filaPago = $f; break; }
    }

    if ($filaPago) {
        $adelantoPago = $adelantosMap[$eidPago] ?? 0;
        $montoPagado  = max(0, $filaPago['pago_final'] - $adelantoPago);
        try {
            $pdo->prepare("
                INSERT INTO pagos_nomina (empleado_id, semana_inicio, sueldo_base, deduccion, bono, adelanto_descontado, monto_pagado)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([$eidPago, $lunes, $filaPago['sueldo'], $filaPago['deduccion'], $filaPago['bono'], $adelantoPago, $montoPagado]);

            // El pago ya descuenta el adelanto de la semana: liquidarlo automaticamente
            $pdo->prepare("
                UPDATE adelantos_sueldo SET estado = 'Liquidado'
                WHERE empleado_id = ? AND fecha BETWEEN ? AND ? AND estado = 'Pendiente'
            ")->execute([$eidPago, $lunes, $sabado]);

            header('Location: semanaLaboral.php?semana=' . $lunes . '&msg=pagado');
        } catch (PDOException $e) {
            // Clave unica: ya existe un pago de esa semana para ese empleado
            header('Location: semanaLaboral.php?semana=' . $lunes . '&msg=ya_pagado');
        }
        exit();
    }
    header('Location: semanaLaboral.php?semana=' . $lunes);
    exit();
}

// Pagos ya registrados de esta semana
$stmtPagos = $pdo->prepare("SELECT * FROM pagos_nomina WHERE semana_inicio = ?");
$stmtPagos->execute([$lunes]);
$pagosMap = [];
foreach ($stmtPagos->fetchAll(PDO::FETCH_ASSOC) as $pg) {
    $pagosMap[$pg['empleado_id']] = $pg;
}
$totalPagadoSemana = array_sum(array_map(fn($pg) => floatval($pg['monto_pagado']), $pagosMap));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semana Laboral — Ferreteria Aldrete</title>
</head>
<body>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Arial, sans-serif; display: flex; height: 100vh; overflow: hidden; }
    .sidebar { width: 220px; background: white; border-right: 1px solid #e8e8e8; display: flex; flex-direction: column; transition: width 0.3s; flex-shrink: 0; overflow: hidden; }
    .sidebar.collapsed { width: 0; }
    .sidebar-header { padding: 18px 16px; border-bottom: 1px solid #f0f0f0; }
    .sidebar-header h3 { font-size: 14px; font-weight: 700; color: #14ace7; margin: 0; }
    .sidebar-header p { font-size: 11px; color: #999; margin: 4px 0 0; }
    .sidebar-menu { flex: 1; padding: 8px 0; overflow-y: auto; }
    .menu-item { display: block; padding: 10px 16px; font-size: 13px; color: #555; cursor: pointer; border-left: 3px solid transparent; text-decoration: none; transition: all 0.15s; white-space: nowrap; }
    .menu-item:hover { background: #eef8ff; color: #14ace7; }
    .menu-item.active { background: #eef8ff; border-left-color: #14ace7; color: #14ace7; font-weight: 600; }
    .divider { height: 1px; background: #f0f0f0; margin: 6px 8px; }
    .sidebar-footer { padding: 12px 16px; border-top: 1px solid #f0f0f0; font-size: 11px; color: #bbb; white-space: nowrap; }
    .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #f7f7f7; }
    .topbar { background: #14ace7; color: white; padding: 0 20px; height: 52px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .topbar-left { display: flex; align-items: center; gap: 12px; }
    .topbar h2 { font-size: 15px; font-weight: 600; }
    .toggle-btn { background: none; border: none; color: white; cursor: pointer; font-size: 20px; padding: 4px 8px; border-radius: 4px; }
    .toggle-btn:hover { background: rgba(255,255,255,0.2); }
    .topbar-right { display: flex; align-items: center; gap: 14px; font-size: 13px; }
    .logout-btn { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); color: white; padding: 5px 14px; border-radius: 5px; cursor: pointer; font-size: 12px; }
    .logout-btn:hover { background: rgba(255,255,255,0.3); }
    .content { flex: 1; padding: 24px; overflow-y: auto; }
    .nav-semana { display: flex; align-items: center; gap: 12px; background: white; border-radius: 8px; padding: 14px 20px; border: 0.5px solid #e8e8e8; margin-bottom: 16px; }
    .nav-semana a { background: #f0f0f0; border: none; color: #555; padding: 7px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; }
    .nav-semana a:hover { background: #e8e8e8; }
    .nav-semana h3 { flex: 1; text-align: center; font-size: 14px; color: #222; font-weight: 700; }
    .nav-semana input[type=date] { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .nav-semana button { background: #14ace7; color: white; border: none; padding: 7px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .stats { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .stat { background: white; border-radius: 8px; padding: 14px 20px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; flex: 1; min-width: 120px; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 18px; font-weight: 700; color: #222; margin: 0; }
    .stat.rojo { border-top-color: #e74c3c; }
    .stat.rojo h3 { color: #c0392b; }
    .stat.verde { border-top-color: #27ae60; }
    .stat.verde h3 { color: #1e8449; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: auto; }
    table { width: 100%; border-collapse: collapse; min-width: 760px; }
    thead { background: #f9f9f9; }
    th { padding: 11px 12px; text-align: left; font-size: 10px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid #eee; white-space: nowrap; }
    td { padding: 11px 12px; font-size: 12px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: middle; white-space: nowrap; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .tr-total { background: #f9f9f9; font-weight: 700; }
    .tr-total td { border-top: 2px solid #eee; color: #222; }
    .num-rojo { color: #c0392b; font-weight: 600; }
    .num-verde { color: #1e8449; font-weight: 600; }
    .num-azul { color: #1a7db5; font-weight: 600; }
    .pago-final { font-size: 14px; font-weight: 700; color: #222; }
    .pago-mas { color: #1e8449; }
    .pago-menos { color: #c0392b; }
    .sin-inc { color: #bbb; font-size: 11px; }
    .btn-pagar { background: #27ae60; border: none; color: white; padding: 6px 14px; border-radius: 5px; cursor: pointer; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .btn-pagar:hover { background: #1e8449; }
    .pagado-chip { font-size: 11px; color: #1e8449; font-weight: 600; white-space: nowrap; }
    .pagado-fecha { font-size: 10px; color: #aaa; margin-top: 2px; }
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 500; align-items: center; justify-content: center; }
    .modal-overlay.visible { display: flex; }
    .modal { background: white; border-radius: 10px; padding: 24px; width: 360px; max-width: 90%; }
    .modal h3 { font-size: 15px; margin-bottom: 6px; }
    .modal p { font-size: 13px; color: #666; margin-bottom: 16px; }
    .modal select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; margin-bottom: 16px; }
    .modal select:focus { outline: none; border-color: #14ace7; }
    .modal-btns { display: flex; gap: 10px; justify-content: flex-end; }
    .btn-m-cancel { background: white; border: 1px solid #ddd; color: #555; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
    .btn-m-ok { background: #14ace7; border: none; color: white; padding: 8px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    @media (max-width: 768px) {
        .sidebar { position: fixed; top: 0; left: 0; height: 100%; z-index: 300; width: 0; transition: width 0.3s; }
        .sidebar.collapsed { width: 260px; box-shadow: 4px 0 16px rgba(0,0,0,.15); }
        .main { width: 100%; }
        .topbar { padding: 0 12px; height: 48px; }
        .topbar-right > span { display: none; }
        .content { padding: 12px; }
        .nav-semana { flex-wrap: wrap; }
    }
</style>

<?php renderAdminSidebar('rrhh_semana'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Semana Laboral</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'pagado'): ?>
            <div class="msg msg-exito">Pago registrado correctamente. El adelanto de la semana (si habia) quedo liquidado.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'ya_pagado'): ?>
            <div class="msg" style="background:#fff9e6;color:#b7860b;border-left:3px solid #f0b429;">Este empleado ya tiene un pago registrado para esta semana.</div>
        <?php endif; ?>

        <!-- Navegacion de semana -->
        <div class="nav-semana">
            <a href="semanaLaboral.php?semana=<?= $semanaAnterior ?>">&larr; Anterior</a>
            <div style="flex:1;text-align:center;">
                <div style="font-size:14px;font-weight:700;color:#222;"><?= $etiquetaSemana ?></div>
                <?php if ($esSemanaActual): ?>
                    <div style="font-size:11px;color:#14ace7;margin-top:2px;">
                        Semana en curso &mdash; acumulado hasta hoy (<?= date('d/m/Y') ?>, d&iacute;a <?= $diasTranscurridos ?>/6)
                    </div>
                <?php else: ?>
                    <div style="font-size:11px;color:#999;margin-top:2px;">Semana cerrada</div>
                <?php endif; ?>
            </div>
            <a href="semanaLaboral.php?semana=<?= $semanaSiguiente ?>">Siguiente &rarr;</a>
            <form method="GET" style="display:flex;gap:8px;align-items:center;">
                <input type="date" name="semana" value="<?= $lunes ?>">
                <button type="submit">Ir</button>
            </form>
        </div>

        <!-- Estadisticas globales -->
        <div class="stats">
            <div class="stat">
                <p>Nomina base</p>
                <h3>$<?= number_format($totalSueldos, 2) ?></h3>
            </div>
            <div class="stat rojo">
                <p>Total deducciones</p>
                <h3><?= $totalDeducciones > 0 ? '-$' . number_format($totalDeducciones, 2) : '$0.00' ?></h3>
            </div>
            <div class="stat verde">
                <p>Total bonos extra</p>
                <h3><?= $totalBonos > 0 ? '+$' . number_format($totalBonos, 2) : '$0.00' ?></h3>
            </div>
            <div class="stat">
                <p>Total a pagar</p>
                <h3>$<?= number_format($totalFinal, 2) ?></h3>
            </div>
            <div class="stat verde">
                <p>Pagado esta semana</p>
                <h3>$<?= number_format($totalPagadoSemana, 2) ?> <span style="font-size:11px;font-weight:400;color:#999;">(<?= count($pagosMap) ?>/<?= count($filas) ?> empleados)</span></h3>
            </div>
        </div>

        <!-- Tabla por empleado -->
        <div class="tabla-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Empleado</th>
                        <th>Sueldo base</th>
                        <th>Hrs trabajadas</th>
                        <th>Hrs no trab.</th>
                        <th>Hrs extra</th>
                        <th>Ajuste ($)</th>
                        <th>Pago final</th>
                        <th>Pago</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($filas as $f): ?>
                <tr>
                    <td style="font-weight:600;">
                        <?= htmlspecialchars($f['empleado']) ?>
                        <?php if (isset($vacMap[$f['empleado_id']])): ?>
                            <div style="font-size:10px;background:#eef8ff;color:#1a7db5;border:1px solid #cce5f7;border-radius:99px;padding:1px 8px;display:inline-block;margin-top:3px;white-space:nowrap;">
                                De vacaciones &mdash; <?= $vacMap[$f['empleado_id']] ?> d&iacute;a<?= $vacMap[$f['empleado_id']] != 1 ? 's' : '' ?> esta semana
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>$<?= number_format($f['sueldo'], 2) ?></td>
                    <td>
                        <?php if ($f['total_reg'] > 0): ?>
                            <span style="font-weight:700;color:#222;"><?= number_format($f['horas_trabajadas'], 2) ?> h</span>
                            <div style="font-size:10px;color:#aaa;margin-top:2px;">
                                <?= $f['total_reg'] ?> d&iacute;a<?= $f['total_reg'] != 1 ? 's' : '' ?> registrados
                                <?= $f['dias_falta'] > 0 ? ' · <span style="color:#c0392b;">' . $f['dias_falta'] . ' falta' . ($f['dias_falta'] > 1 ? 's' : '') . '</span>' : '' ?>
                            </div>
                        <?php else: ?>
                            <span class="sin-inc">Sin registros</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $f['horas_nt'] > 0 ? '<span class="num-rojo">' . number_format($f['horas_nt'], 2) . ' h</span>' : '<span class="sin-inc">0</span>' ?></td>
                    <td><?= $f['horas_extra'] > 0 ? '<span class="num-verde">+' . number_format($f['horas_extra'], 2) . ' h</span>' : '<span class="sin-inc">0</span>' ?></td>
                    <td>
                        <?php $ajuste = $f['bono'] - $f['deduccion']; ?>
                        <?php if ($ajuste < 0): ?>
                            <span class="num-rojo">-$<?= number_format(abs($ajuste), 2) ?></span>
                        <?php elseif ($ajuste > 0): ?>
                            <span class="num-verde">+$<?= number_format($ajuste, 2) ?></span>
                        <?php else: ?>
                            <span class="sin-inc">—</span>
                        <?php endif; ?>
                        <?php if ($f['horas_comp'] > 0): ?>
                            <div style="font-size:10px;color:#1a7db5;margin-top:2px;"><?= number_format($f['horas_comp'], 2) ?> h compensadas</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="pago-final <?= $f['pago_final'] < $f['sueldo'] ? 'pago-menos' : ($f['pago_final'] > $f['sueldo'] ? 'pago-mas' : '') ?>">
                            $<?= number_format($f['pago_final'], 2) ?>
                        </span>
                        <?php
                            $adelantoPend = $adelantosMap[$f['empleado_id']] ?? 0;
                            if ($adelantoPend > 0):
                                $pagoReal = max(0, $f['pago_final'] - $adelantoPend);
                        ?>
                            <div style="font-size:10px;color:#c0392b;margin-top:3px;">- $<?= number_format($adelantoPend, 2) ?> adelanto</div>
                            <div style="font-size:12px;font-weight:700;color:#222;border-top:1px solid #eee;margin-top:2px;padding-top:2px;">= $<?= number_format($pagoReal, 2) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                            $pagoReg      = $pagosMap[$f['empleado_id']] ?? null;
                            $adelantoFila = $adelantosMap[$f['empleado_id']] ?? 0;
                            $aPagar       = max(0, $f['pago_final'] - $adelantoFila);
                        ?>
                        <?php if ($pagoReg): ?>
                            <div class="pagado-chip">&#10003; Pagado $<?= number_format($pagoReg['monto_pagado'], 2) ?></div>
                            <div class="pagado-fecha"><?= date('d/m/Y H:i', strtotime($pagoReg['pagado_en'])) ?></div>
                        <?php else: ?>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('¿Registrar pago de $<?= number_format($aPagar, 2) ?> a <?= htmlspecialchars(addslashes($f['empleado']), ENT_QUOTES) ?> por la semana <?= $etiquetaSemana ?>?<?= $adelantoFila > 0 ? ' Se descuenta $' . number_format($adelantoFila, 2) . ' de adelanto y quedara liquidado.' : '' ?><?= !$semanaCompleta ? ' OJO: la semana aun esta en curso, el calculo es parcial.' : '' ?>')">
                                <input type="hidden" name="pagar_empleado" value="1">
                                <input type="hidden" name="empleado_id" value="<?= $f['empleado_id'] ?>">
                                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <button type="submit" class="btn-pagar">Pagar $<?= number_format($aPagar, 2) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($filas) > 1): ?>
                <tr class="tr-total">
                    <td>TOTAL</td>
                    <td>$<?= number_format($totalSueldos, 2) ?></td>
                    <td colspan="3"></td>
                    <?php $ajusteTotal = $totalBonos - $totalDeducciones; ?>
                    <td><?= $ajusteTotal < 0 ? '-$' . number_format(abs($ajusteTotal), 2) : ($ajusteTotal > 0 ? '+$' . number_format($ajusteTotal, 2) : '—') ?></td>
                    <td>$<?= number_format($totalFinal, 2) ?></td>
                    <td></td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:12px;font-size:11px;color:#aaa;padding:0 4px;">
            * Semana de lunes a sabado (51 hrs). El ajuste se calcula asi: las horas extra primero recuperan las horas debidas (compensadas); si aun debe horas se descuentan (sueldo/51 por hora) y si le sobran extras se pagan a 1.5x.
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>
