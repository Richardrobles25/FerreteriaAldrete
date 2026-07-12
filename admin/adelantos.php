<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);

// Registrar nuevo adelanto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_adelanto'])) {
    requerirCSRF($_POST['_token'] ?? '', 'adelantos.php');

    $empleado_id = intval($_POST['empleado_id'] ?? 0);
    $monto       = floatval($_POST['monto'] ?? 0);
    $fecha       = $_POST['fecha'] ?? date('Y-m-d');
    $motivo      = trim($_POST['motivo'] ?? '');

    $errores = [];
    if ($empleado_id <= 0) $errores[] = 'Selecciona un empleado.';
    if ($monto <= 0) $errores[] = 'El monto debe ser mayor a $0.00.';

    // El adelanto es contra el sueldo de la semana en curso (lunes a sabado)
    $lunesSemana  = date('Y-m-d', strtotime('monday this week'));
    $sabadoSemana = date('Y-m-d', strtotime($lunesSemana . ' +5 days'));
    if (!$fecha || !strtotime($fecha)) {
        $errores[] = 'La fecha no es valida.';
    } elseif ($fecha < $lunesSemana || $fecha > $sabadoSemana) {
        $errores[] = 'Solo se puede adelantar el sueldo de la semana en curso (' . date('d/m/Y', strtotime($lunesSemana)) . ' al ' . date('d/m/Y', strtotime($sabadoSemana)) . ').';
    }

    // Tope: los adelantos de la semana no pueden superar el sueldo semanal
    if ($empleado_id > 0 && $monto > 0) {
        $stmtSueldo = $pdo->prepare("SELECT sueldo_semanal FROM empleados WHERE empleado_id = ? AND activo = 1");
        $stmtSueldo->execute([$empleado_id]);
        $sueldoSem = floatval($stmtSueldo->fetchColumn());
        if ($sueldoSem <= 0) {
            $errores[] = 'El empleado no es valido o no tiene sueldo registrado.';
        } else {
            $stmtYa = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM adelantos_sueldo WHERE empleado_id = ? AND fecha BETWEEN ? AND ?");
            $stmtYa->execute([$empleado_id, $lunesSemana, $sabadoSemana]);
            $yaAdelantado = floatval($stmtYa->fetchColumn());
            if ($yaAdelantado + $monto > $sueldoSem) {
                $disponible = max(0, $sueldoSem - $yaAdelantado);
                $errores[] = 'No se puede adelantar mas de una semana de sueldo ($' . number_format($sueldoSem, 2) . '). Ya tiene $' . number_format($yaAdelantado, 2) . ' adelantado esta semana; el maximo disponible es $' . number_format($disponible, 2) . '.';
            }
        }
    }

    if (empty($errores)) {
        $pdo->prepare("INSERT INTO adelantos_sueldo (empleado_id, monto, fecha, motivo, estado) VALUES (?, ?, ?, ?, 'Pendiente')")
            ->execute([$empleado_id, $monto, $fecha, $motivo]);
        header('Location: adelantos.php?msg=registrado');
        exit();
    }
    $_SESSION['_errores_adelanto'] = $errores;
    header('Location: adelantos.php');
    exit();
}

// Marcar adelanto como liquidado (ya se le descontó al empleado de su sueldo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['liquidar_id'])) {
    requerirCSRF($_POST['_token'] ?? '', 'adelantos.php');
    $aid = intval($_POST['liquidar_id']);
    $pdo->prepare("UPDATE adelantos_sueldo SET estado = 'Liquidado' WHERE adelanto_id = ?")->execute([$aid]);
    header('Location: adelantos.php?msg=liquidado');
    exit();
}

$errores = $_SESSION['_errores_adelanto'] ?? [];
unset($_SESSION['_errores_adelanto']);

$empleados = $pdo->query("SELECT empleado_id, nombre, sueldo_semanal FROM empleados WHERE activo = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

// Adelantos pendientes agrupados por empleado (lo que se le descontaria de su proximo sueldo)
$pendientesPorEmpleado = $pdo->query("
    SELECT e.empleado_id, e.nombre, e.sueldo_semanal,
           SUM(a.monto) AS total_pendiente
    FROM adelantos_sueldo a
    JOIN empleados e ON e.empleado_id = a.empleado_id
    WHERE a.estado = 'Pendiente'
    GROUP BY e.empleado_id
    ORDER BY total_pendiente DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Historial completo de adelantos (pendientes y liquidados)
$historial = $pdo->query("
    SELECT a.*, e.nombre AS empleado_nombre
    FROM adelantos_sueldo a
    JOIN empleados e ON e.empleado_id = a.empleado_id
    ORDER BY a.estado = 'Pendiente' DESC, a.fecha DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalPendienteGlobal  = array_sum(array_column($pendientesPorEmpleado, 'total_pendiente'));
$totalLiquidadoGlobal  = array_sum(array_map(
    fn($h) => $h['estado'] === 'Liquidado' ? $h['monto'] : 0,
    $historial
));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adelantos de sueldo — Ferreteria Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 16px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .stats { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; grid-column: 1 / -1; }
    .stat { background: white; border-radius: 8px; padding: 14px 20px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; min-width: 150px; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 20px; font-weight: 700; color: #222; margin: 0; }
    label { display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 5px; }
    input, select, textarea { width: 100%; padding: 9px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    input:focus, select:focus, textarea:focus { outline: none; border-color: #14ace7; }
    .btn-guardar { width: 100%; background: #14ace7; color: white; border: none; padding: 11px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 700; }
    .btn-guardar:hover { background: #119dd4; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 10px 13px; text-align: left; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 10px 13px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-pendiente { background: #fff9e6; color: #b7860b; }
    .badge-liquidado { background: #f0fff0; color: #1e8449; }
    .btn-liquidar { background: #f0fff0; border: none; color: #1e8449; padding: 5px 12px; border-radius: 5px; cursor: pointer; font-size: 11px; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    .pct-descuento { font-size: 11px; color: #888; }
    @media (max-width: 900px) {
        .content { grid-template-columns: 1fr; }
    }
</style>

<?php renderAdminSidebar('rrhh_adelantos'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Adelantos de sueldo</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <div class="stats">
            <div class="stat">
                <p>Empleados con adelanto</p>
                <h3><?= count($pendientesPorEmpleado) ?></h3>
            </div>
            <div class="stat" style="border-top-color:#f0b429;">
                <p>Total pendiente por descontar</p>
                <h3 style="color:#b7860b;">$<?= number_format($totalPendienteGlobal, 2) ?></h3>
            </div>
            <div class="stat" style="border-top-color:#27ae60;">
                <p>Total liquidado (histórico)</p>
                <h3 style="color:#1e8449;">$<?= number_format($totalLiquidadoGlobal, 2) ?></h3>
            </div>
        </div>

        <div>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'registrado'): ?>
                <div class="msg msg-exito">Adelanto registrado correctamente.</div>
            <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'liquidado'): ?>
                <div class="msg msg-exito">Adelanto marcado como liquidado.</div>
            <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'error_token'): ?>
                <div class="errores">La accion no se pudo validar, intenta de nuevo.</div>
            <?php endif; ?>

            <?php if (count($pendientesPorEmpleado) > 0): ?>
            <div class="card" style="margin-bottom:20px;">
                <h3>Pendiente de descontar por empleado</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th>Sueldo semanal</th>
                            <th>Adelanto pendiente</th>
                            <th>% de su proximo sueldo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendientesPorEmpleado as $p):
                        $pct = $p['sueldo_semanal'] > 0 ? ($p['total_pendiente'] / $p['sueldo_semanal']) * 100 : 0;
                    ?>
                        <tr>
                            <td style="font-weight:600;"><?= htmlspecialchars($p['nombre']) ?></td>
                            <td>$<?= number_format($p['sueldo_semanal'], 2) ?></td>
                            <td style="font-weight:700;color:#e67e22;">$<?= number_format($p['total_pendiente'], 2) ?></td>
                            <td>
                                <span class="pct-descuento"><?= number_format($pct, 1) ?>%</span>
                                <?php if ($pct >= 100): ?>
                                    <div style="font-size:11px;color:#c0392b;">Supera su sueldo semanal</div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="card">
                <h3>Historial de adelantos</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th>Monto</th>
                            <th>Fecha</th>
                            <th>Motivo</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($historial) > 0): foreach ($historial as $h): ?>
                        <tr>
                            <td style="font-weight:600;"><?= htmlspecialchars($h['empleado_nombre']) ?></td>
                            <td style="font-weight:700;">$<?= number_format($h['monto'], 2) ?></td>
                            <td style="font-size:12px;"><?= date('d/m/Y', strtotime($h['fecha'])) ?></td>
                            <td style="font-size:12px;color:#777;"><?= htmlspecialchars($h['motivo'] ?: '—') ?></td>
                            <td>
                                <span class="badge <?= $h['estado'] === 'Pendiente' ? 'badge-pendiente' : 'badge-liquidado' ?>"><?= htmlspecialchars($h['estado']) ?></span>
                            </td>
                            <td>
                                <?php if ($h['estado'] === 'Pendiente'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Marcar este adelanto como liquidado? Esto significa que ya se descontó del sueldo del empleado.')">
                                    <input type="hidden" name="liquidar_id" value="<?= $h['adelanto_id'] ?>">
                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <button type="submit" class="btn-liquidar">Marcar liquidado</button>
                                </form>
                                <?php else: ?>
                                    <span style="color:#ccc;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="6" class="sin-resultados">Aun no se han registrado adelantos.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>Registrar adelanto</h3>
            <?php if (!empty($errores)): ?>
                <div class="errores">
                    <ul>
                        <?php foreach ($errores as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if (count($empleados) === 0): ?>
                <p style="font-size:13px;color:#888;">No hay empleados activos registrados.</p>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="registrar_adelanto" value="1">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <label for="empleado_id">Empleado</label>
                <select name="empleado_id" id="empleado_id" required>
                    <option value="">— Selecciona —</option>
                    <?php foreach ($empleados as $e): ?>
                        <option value="<?= $e['empleado_id'] ?>"><?= htmlspecialchars($e['nombre']) ?> — $<?= number_format($e['sueldo_semanal'], 2) ?>/sem</option>
                    <?php endforeach; ?>
                </select>

                <label for="monto">Monto del adelanto</label>
                <input type="number" name="monto" id="monto" min="0.01" step="0.01" placeholder="Ej. 500.00" required>

                <label for="fecha">Fecha <span style="font-weight:400;color:#aaa;">(dentro de la semana en curso)</span></label>
                <?php
                    $lunesForm  = date('Y-m-d', strtotime('monday this week'));
                    $sabadoForm = date('Y-m-d', strtotime($lunesForm . ' +5 days'));
                ?>
                <input type="date" name="fecha" id="fecha" value="<?= date('Y-m-d') ?>" min="<?= $lunesForm ?>" max="<?= $sabadoForm ?>" required>

                <label for="motivo">Motivo (opcional)</label>
                <textarea name="motivo" id="motivo" rows="3" placeholder="Ej. Emergencia familiar"></textarea>

                <button type="submit" class="btn-guardar">Registrar adelanto</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>
