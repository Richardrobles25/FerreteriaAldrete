<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/rh_helpers.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);
require_once '../includes/topbar_info.php';

// Toggle activo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    requerirCSRF($_POST['_token'] ?? '', 'empleados.php');
    $eid = intval($_POST['toggle_id']);
    $pdo->prepare("UPDATE empleados SET activo = NOT activo WHERE empleado_id = ?")->execute([$eid]);
    header('Location: empleados.php?msg=actualizado');
    exit();
}

// [FIX-MEDIO-G-18] Se trae tambien el adelanto pendiente de cada empleado para poder avisar
// antes de desactivarlo (ver confirm() del boton Desactivar mas abajo) — un empleado inactivo
// deja de aparecer en el flujo normal de nomina (semanaLaboral.php ya lo sigue mostrando ahi
// si tiene pendientes, pero aqui es donde se decide desactivarlo, y es donde debe verse la
// advertencia primero).
$empleados = $pdo->query("
    SELECT e.*,
           COALESCE((
               SELECT SUM(v.dias_tomados)
               FROM vacaciones v
               WHERE v.empleado_id = e.empleado_id
                 AND v.anio = YEAR(CURDATE())
                 AND v.estado != 'Rechazado'
           ), 0) AS dias_tomados_anio,
           COALESCE((
               SELECT SUM(a.monto)
               FROM adelantos_sueldo a
               WHERE a.empleado_id = e.empleado_id AND a.estado = 'Pendiente'
           ), 0) AS adelanto_pendiente
    FROM empleados e
    ORDER BY e.activo DESC, e.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

$totalActivos = count(array_filter($empleados, fn($e) => $e['activo']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Empleados — Ferreteria Aldrete</title>
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
    .topbar-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .topbar-bar h3 { font-size: 15px; color: #222; font-weight: 700; }
    .btn-nuevo { background: #14ace7; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; }
    .btn-nuevo:hover { background: #119dd4; }
    .stats { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .stat { background: white; border-radius: 8px; padding: 14px 20px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; min-width: 130px; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 20px; font-weight: 700; color: #222; margin: 0; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 13px; text-align: left; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 13px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    tr.inactivo td { opacity: .5; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-activo { background: #e8f8ee; color: #27ae60; }
    .badge-inactivo { background: #f5f5f5; color: #999; }
    .vacaciones-bar { display: flex; align-items: center; gap: 8px; }
    .vac-bg { flex: 1; background: #f0f0f0; border-radius: 99px; height: 6px; min-width: 60px; }
    .vac-fill { background: #14ace7; border-radius: 99px; height: 6px; }
    .btn-editar { background: #f5f5f5; border: none; color: #555; padding: 5px 12px; border-radius: 5px; cursor: pointer; font-size: 11px; text-decoration: none; display: inline-block; }
    .btn-editar:hover { background: #eee; }
    .btn-toggle-on  { background: #fff0f0; border: none; color: #c0392b; padding: 5px 12px; border-radius: 5px; cursor: pointer; font-size: 11px; }
    .btn-toggle-off { background: #e8f8ee; border: none; color: #27ae60; padding: 5px 12px; border-radius: 5px; cursor: pointer; font-size: 11px; }
    .sin-resultados { padding: 48px; text-align: center; color: #aaa; font-size: 14px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    @media (max-width: 768px) {
        .sidebar { position: fixed; top: 0; left: 0; height: 100%; z-index: 300; width: 0; transition: width 0.3s; }
        .sidebar.collapsed { width: 260px; box-shadow: 4px 0 16px rgba(0,0,0,.15); }
        .main { width: 100%; }
        .topbar { padding: 0 12px; height: 48px; }
        .topbar-right > span { display: none; }
        .content { padding: 12px; }
        th, td { padding: 8px 10px; font-size: 12px; }
    }
</style>

<?php renderAdminSidebar('rrhh_empleados'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Empleados</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <div class="topbar-bar">
            <h3>Directorio de empleados</h3>
            <a class="btn-nuevo" href="formEmpleado.php">+ Nuevo empleado</a>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'registrado'): ?>
            <div class="msg msg-exito">Empleado registrado correctamente.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'actualizado'): ?>
            <div class="msg msg-exito">Empleado actualizado correctamente.</div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat">
                <p>Empleados activos</p>
                <h3><?= $totalActivos ?></h3>
            </div>
            <div class="stat">
                <p>Total registrados</p>
                <h3><?= count($empleados) ?></h3>
            </div>
        </div>

        <div class="tabla-wrapper">
            <?php if (count($empleados) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Empleado</th>
                        <th>Fecha ingreso</th>
                        <th>Antigüedad</th>
                        <th>Sueldo semanal</th>
                        <th>Vacaciones <?= date('Y') ?></th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($empleados as $emp):
                    $diasDisp  = calcVacacionesDisponibles($emp['fecha_ingreso']);
                    $diasTom   = intval($emp['dias_tomados_anio']);
                    $diasRest  = max(0, $diasDisp - $diasTom);
                    $pct       = $diasDisp > 0 ? round(($diasTom / $diasDisp) * 100) : 0;
                ?>
                <tr class="<?= $emp['activo'] ? '' : 'inactivo' ?>">
                    <td style="font-weight:600;"><?= htmlspecialchars($emp['nombre']) ?></td>
                    <td style="font-size:12px;"><?= date('d/m/Y', strtotime($emp['fecha_ingreso'])) ?></td>
                    <td style="font-size:12px;"><?= calcAntiguedad($emp['fecha_ingreso']) ?></td>
                    <td style="font-weight:700;">$<?= number_format($emp['sueldo_semanal'], 2) ?></td>
                    <td>
                        <?php if ($diasDisp > 0): ?>
                        <div class="vacaciones-bar">
                            <div class="vac-bg"><div class="vac-fill" style="width:<?= $pct ?>%"></div></div>
                            <span style="font-size:11px;color:#555;white-space:nowrap;"><?= $diasTom ?>/<?= $diasDisp ?> dias</span>
                        </div>
                        <?php else: ?>
                            <span style="font-size:11px;color:#bbb;">Sin derecho aun</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $emp['activo'] ? 'badge-activo' : 'badge-inactivo' ?>"><?= $emp['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                    <td>
                        <a class="btn-editar" href="formEmpleado.php?id=<?= $emp['empleado_id'] ?>">Editar</a>
                        <?php
                            $avisoAdelanto = ($emp['activo'] && floatval($emp['adelanto_pendiente']) > 0)
                                ? ' Ojo: tiene $' . number_format($emp['adelanto_pendiente'], 2) . ' de adelanto pendiente de cobro.'
                                : '';
                        ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿<?= $emp['activo'] ? 'Desactivar' : 'Activar' ?> a <?= htmlspecialchars(addslashes($emp['nombre']), ENT_QUOTES) ?>?<?= htmlspecialchars(addslashes($avisoAdelanto), ENT_QUOTES) ?>')">
                            <input type="hidden" name="toggle_id" value="<?= $emp['empleado_id'] ?>">
                            <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <button type="submit" class="<?= $emp['activo'] ? 'btn-toggle-on' : 'btn-toggle-off' ?>"><?= $emp['activo'] ? 'Desactivar' : 'Activar' ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="sin-resultados">No hay empleados registrados. <a href="formEmpleado.php" style="color:#14ace7;">Agregar el primero</a></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>
