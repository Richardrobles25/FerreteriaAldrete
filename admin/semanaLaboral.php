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

// Handle resolucion semanal por empleado (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolver_empleado'])) {
    $eid       = intval($_POST['empleado_id']);
    $resolucion = trim($_POST['resolucion_semana']);
    $resVal    = ['Pendiente','Deducido','Compensado','Justificado','Pagado integro'];
    if (in_array($resolucion, $resVal)) {
        $pdo->prepare("
            UPDATE asistencia SET resolucion = ?
            WHERE empleado_id = ? AND fecha BETWEEN ? AND ?
        ")->execute([$resolucion, $eid, $lunes, $sabado]);
    }
    header('Location: semanaLaboral.php?semana=' . $lunes);
    exit();
}

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
           SUM(CASE WHEN tipo = 'Falta'             THEN 1 ELSE 0 END) AS dias_falta,
           SUM(CASE WHEN resolucion != 'Pendiente' AND tipo != 'Asistencia normal' THEN 1 ELSE 0 END) AS resueltos,
           SUM(CASE WHEN tipo != 'Asistencia normal' AND tipo != 'Falta' THEN 1 ELSE 0 END) AS total_incidentes
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
    $totalInc        = intval($asistenciaMap[$eid]['total_incidentes']       ?? 0);
    $resueltos       = intval($asistenciaMap[$eid]['resueltos']              ?? 0);
    $totalReg        = intval($asistenciaMap[$eid]['total_registros']        ?? 0);
    $todoResuelto    = $totalInc > 0 && $resueltos === $totalInc;

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
        'total_inc'        => $totalInc,
        'todo_resuelto'    => $todoResuelto,
    ];
}
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
    .stats { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .stat { background: white; border-radius: 8px; padding: 14px 20px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; flex: 1; min-width: 120px; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 18px; font-weight: 700; color: #222; margin: 0; }
    .stat.rojo { border-top-color: #e74c3c; }
    .stat.rojo h3 { color: #c0392b; }
    .stat.verde { border-top-color: #27ae60; }
    .stat.verde h3 { color: #1e8449; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: auto; }
    table { width: 100%; border-collapse: collapse; min-width: 900px; }
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
    .btn-resolver { background: #fff9e6; border: 1px solid #f0b429; color: #b7860b; padding: 5px 12px; border-radius: 5px; cursor: pointer; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .btn-resolver:hover { background: #fef3cd; }
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
                        <th>Hrs compensadas</th>
                        <th>A deducir</th>
                        <th>Extra neta</th>
                        <th>Deduccion ($)</th>
                        <th>Bono extra ($)</th>
                        <th>Pago final</th>
                        <th>Estado semana</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($filas as $f): ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($f['empleado']) ?></td>
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
                    <td><?= $f['horas_comp'] > 0 ? '<span class="num-azul">' . number_format($f['horas_comp'], 2) . ' h</span>' : '<span class="sin-inc">—</span>' ?></td>
                    <td><?= $f['horas_neta_ded'] > 0 ? '<span class="num-rojo">' . number_format($f['horas_neta_ded'], 2) . ' h</span>' : '<span class="sin-inc">—</span>' ?></td>
                    <td><?= $f['horas_extra_neta'] > 0 ? '<span class="num-verde">+' . number_format($f['horas_extra_neta'], 2) . ' h</span>' : '<span class="sin-inc">—</span>' ?></td>
                    <td><?= $f['deduccion'] > 0 ? '<span class="num-rojo">-$' . number_format($f['deduccion'], 2) . '</span>' : '<span class="sin-inc">—</span>' ?></td>
                    <td><?= $f['bono'] > 0 ? '<span class="num-verde">+$' . number_format($f['bono'], 2) . '</span>' : '<span class="sin-inc">—</span>' ?></td>
                    <td>
                        <span class="pago-final <?= $f['pago_final'] < $f['sueldo'] ? 'pago-menos' : ($f['pago_final'] > $f['sueldo'] ? 'pago-mas' : '') ?>">
                            $<?= number_format($f['pago_final'], 2) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($f['total_inc'] === 0): ?>
                            <span class="sin-inc">Sin incidentes</span>
                        <?php elseif ($f['todo_resuelto']): ?>
                            <span style="color:#27ae60;font-weight:600;font-size:11px;">&#10003; Resuelto</span>
                        <?php else: ?>
                            <button class="btn-resolver" onclick="abrirResolver(<?= $f['empleado_id'] ?>, '<?= htmlspecialchars(addslashes($f['empleado'])) ?>')">
                                Resolver
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($filas) > 1): ?>
                <tr class="tr-total">
                    <td colspan="2">TOTAL</td>
                    <td>$<?= number_format($totalSueldos, 2) ?></td>
                    <td colspan="5"></td>
                    <td><?= $totalDeducciones > 0 ? '-$' . number_format($totalDeducciones, 2) : '—' ?></td>
                    <td><?= $totalBonos > 0 ? '+$' . number_format($totalBonos, 2) : '—' ?></td>
                    <td>$<?= number_format($totalFinal, 2) ?></td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:12px;font-size:11px;color:#aaa;padding:0 4px;">
            * Semana laboral de lunes a sabado (51 hrs). Tarifa hora normal = sueldo / 51. Tarifa hora extra = tarifa x 1.5. Las horas extra primero compensan horas debidas; el excedente se paga al 1.5x.
        </div>
    </div>
</div>

<!-- Modal resolver semana -->
<div class="modal-overlay" id="modalResolver">
    <div class="modal">
        <h3>Resolver semana</h3>
        <p id="textoResolver">Marca como quedo la semana de este empleado.</p>
        <form method="POST" id="formResolver">
            <input type="hidden" name="resolver_empleado" value="1">
            <input type="hidden" name="empleado_id" id="resolverEmpId">
            <select name="resolucion_semana">
                <option value="Deducido">Deducido — se desconto del sueldo</option>
                <option value="Compensado">Compensado — recupero las horas</option>
                <option value="Justificado">Justificado — excusa valida, sin descuento</option>
                <option value="Pagado integro">Pagado integro — se pago sin descuento</option>
            </select>
            <div class="modal-btns">
                <button type="button" class="btn-m-cancel" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn-m-ok">Aplicar</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function abrirResolver(empId, nombre) {
    document.getElementById('resolverEmpId').value = empId;
    document.getElementById('textoResolver').textContent = 'Marca como quedo la semana de ' + nombre + '.';
    document.getElementById('modalResolver').classList.add('visible');
}

function cerrarModal() {
    document.getElementById('modalResolver').classList.remove('visible');
}

document.getElementById('modalResolver').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal();
});
</script>
</body>
</html>
