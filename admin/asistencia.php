<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);

// Eliminar registro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_id'])) {
    requerirCSRF($_POST['_token'] ?? '', 'asistencia.php');
    $pdo->prepare("DELETE FROM asistencia WHERE asistencia_id = ?")->execute([intval($_POST['eliminar_id'])]);
    $sep = strpos($_SERVER['REQUEST_URI'], '?') !== false ? '&' : '?';
    header('Location: ' . $_SERVER['REQUEST_URI'] . $sep . 'msg=eliminado');
    exit();
}

// Filtros
$empleadoFiltro = intval($_GET['empleado']   ?? 0);
$tipoFiltro     = trim($_GET['tipo']         ?? '');
$fechaInicio    = $_GET['fecha_inicio']      ?? date('Y-m-d', strtotime('monday this week'));
$fechaFin       = $_GET['fecha_fin']         ?? date('Y-m-d', strtotime('saturday this week'));

$where  = "WHERE a.fecha BETWEEN ? AND ?";
$params = [$fechaInicio, $fechaFin];

if ($empleadoFiltro) { $where .= " AND a.empleado_id = ?"; $params[] = $empleadoFiltro; }
if ($tipoFiltro)     { $where .= " AND a.tipo = ?";         $params[] = $tipoFiltro; }

$stmt = $pdo->prepare("
    SELECT a.*, e.nombre AS nombre_empleado
    FROM asistencia a
    JOIN empleados e ON a.empleado_id = e.empleado_id
    $where
    ORDER BY a.fecha DESC, e.nombre ASC
");
$stmt->execute($params);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tiempos fuera por registro
$ids = array_column($registros, 'asistencia_id');
$tiemposFuera = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tfStmt = $pdo->prepare("SELECT * FROM asistencia_tiempos_fuera WHERE asistencia_id IN ($placeholders) ORDER BY hora_salida");
    $tfStmt->execute($ids);
    foreach ($tfStmt->fetchAll(PDO::FETCH_ASSOC) as $tf) {
        $tiemposFuera[$tf['asistencia_id']][] = $tf;
    }
}

// Stats
$totalRegistros  = count($registros);
$totalHorasNT    = array_sum(array_column($registros, 'horas_no_trabajadas'));
$totalHorasExtra = array_sum(array_column($registros, 'horas_extra'));

$empleados   = $pdo->query("SELECT empleado_id, nombre FROM empleados WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$tipos       = ['Asistencia normal','Falta','Tiempo fuera','Horas extra'];

$filtrosActivos = $empleadoFiltro || $tipoFiltro
    || ($fechaInicio !== date('Y-m-d', strtotime('monday this week')))
    || ($fechaFin    !== date('Y-m-d', strtotime('saturday this week')));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asistencia — Ferreteria Aldrete</title>
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
    .btn-nuevo { background: #14ace7; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap; }
    .btn-nuevo:hover { background: #119dd4; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 14px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .stats { display: flex; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
    .stat { background: white; border-radius: 8px; padding: 14px 20px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; flex: 1; min-width: 120px; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 20px; font-weight: 700; color: #222; margin: 0; }
    .stat.naranja { border-top-color: #f0b429; }
    .stat.naranja h3 { color: #b7860b; }
    .stat.rojo { border-top-color: #e74c3c; }
    .stat.rojo h3 { color: #c0392b; }
    .stat.verde { border-top-color: #27ae60; }
    .stat.verde h3 { color: #1e8449; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 13px; text-align: left; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 10px 13px; font-size: 12px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: top; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .badge-tipo { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #f0f0f0; color: #555; }
    .badge-tipo.normal { background: #e8f8ee; color: #1e8449; }
    .tf-list { font-size: 11px; color: #888; margin-top: 3px; }
    .btn-editar { background: #f5f5f5; border: none; color: #555; padding: 4px 10px; border-radius: 5px; cursor: pointer; font-size: 11px; text-decoration: none; display: inline-block; }
    .btn-editar:hover { background: #eee; }
    .btn-eliminar { background: #fff0f0; border: none; color: #c0392b; padding: 4px 10px; border-radius: 5px; cursor: pointer; font-size: 11px; }
    .btn-eliminar:hover { background: #fde8e8; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 500; align-items: center; justify-content: center; }
    .modal-overlay.visible { display: flex; }
    .modal { background: white; border-radius: 10px; padding: 24px; width: 340px; max-width: 90%; }
    .modal h3 { font-size: 15px; margin-bottom: 8px; }
    .modal p { font-size: 13px; color: #666; margin-bottom: 20px; }
    .modal-btns { display: flex; gap: 10px; justify-content: flex-end; }
    .btn-cancelar-modal { background: white; border: 1px solid #ddd; color: #555; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
    .btn-confirmar-modal { background: #e74c3c; border: none; color: white; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    @media (max-width: 768px) {
        .sidebar { position: fixed; top: 0; left: 0; height: 100%; z-index: 300; width: 0; transition: width 0.3s; }
        .sidebar.collapsed { width: 260px; box-shadow: 4px 0 16px rgba(0,0,0,.15); }
        .main { width: 100%; }
        .topbar { padding: 0 12px; height: 48px; }
        .topbar-right > span { display: none; }
        .content { padding: 12px; }
        th, td { padding: 8px 8px; font-size: 11px; }
    }
</style>

<?php renderAdminSidebar('rrhh_asistencia'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Bitacora de Asistencia</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'eliminado'): ?>
            <div class="msg msg-exito">Registro eliminado correctamente.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'registrado'): ?>
            <div class="msg msg-exito">Registro guardado correctamente.</div>
        <?php endif; ?>

        <form method="GET">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Desde</label>
                    <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fechaInicio) ?>">
                </div>
                <div class="filtro-group">
                    <label>Hasta</label>
                    <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fechaFin) ?>">
                </div>
                <div class="filtro-group">
                    <label>Empleado</label>
                    <select name="empleado">
                        <option value="0">Todos</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?= $emp['empleado_id'] ?>" <?= $empleadoFiltro == $emp['empleado_id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Tipo</label>
                    <select name="tipo">
                        <option value="">Todos</option>
                        <?php foreach ($tipos as $t): ?>
                            <option value="<?= $t ?>" <?= $tipoFiltro === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-filtrar" type="submit">Filtrar</button>
                <?php if ($filtrosActivos): ?><a class="btn-limpiar" href="asistencia.php">Limpiar</a><?php endif; ?>
                <a class="btn-nuevo" href="formAsistencia.php" style="margin-left:auto;">+ Registrar asistencia</a>
            </div>
        </form>

        <div class="stats">
            <div class="stat">
                <p>Registros</p>
                <h3><?= $totalRegistros ?></h3>
            </div>
            <div class="stat rojo">
                <p>Hrs no trabajadas</p>
                <h3><?= number_format($totalHorasNT, 1) ?></h3>
            </div>
            <div class="stat verde">
                <p>Hrs extra</p>
                <h3><?= number_format($totalHorasExtra, 1) ?></h3>
            </div>
        </div>

        <div class="tabla-wrapper">
            <?php if (count($registros) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Empleado</th>
                        <th>Tipo</th>
                        <th>Entrada / Salida</th>
                        <th>Tiempo fuera</th>
                        <th>Hrs no trab.</th>
                        <th>Hrs extra</th>
                        <th>Razon / Notas</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($registros as $reg):
                    $tfs = $tiemposFuera[$reg['asistencia_id']] ?? [];
                ?>
                <tr>
                    <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($reg['fecha'])) ?><br><span style="font-size:10px;color:#aaa;"><?= ['1'=>'Lun','2'=>'Mar','3'=>'Mie','4'=>'Jue','5'=>'Vie','6'=>'Sab'][date('N', strtotime($reg['fecha']))] ?? '' ?></span></td>
                    <td style="font-weight:600;"><?= htmlspecialchars($reg['nombre_empleado']) ?></td>
                    <td><span class="badge-tipo <?= $reg['tipo'] === 'Asistencia normal' ? 'normal' : '' ?>"><?= htmlspecialchars($reg['tipo']) ?></span></td>
                    <td style="white-space:nowrap;">
                        <?php if ($reg['hora_entrada'] && $reg['hora_salida']): ?>
                            <?= date('h:i A', strtotime($reg['hora_entrada'])) ?><br><?= date('h:i A', strtotime($reg['hora_salida'])) ?>
                        <?php else: ?>
                            <span style="color:#bbb;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($tfs): ?>
                            <div class="tf-list">
                                <?php foreach ($tfs as $tf): ?>
                                    <?= date('h:i A', strtotime($tf['hora_salida'])) ?>–<?= date('h:i A', strtotime($tf['hora_regreso'])) ?><br>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span style="color:#bbb;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:600;color:<?= floatval($reg['horas_no_trabajadas']) > 0 ? '#c0392b' : '#aaa' ?>;">
                        <?= floatval($reg['horas_no_trabajadas']) > 0 ? number_format($reg['horas_no_trabajadas'], 2) . ' h' : '—' ?>
                    </td>
                    <td style="font-weight:600;color:<?= floatval($reg['horas_extra']) > 0 ? '#27ae60' : '#aaa' ?>;">
                        <?= floatval($reg['horas_extra']) > 0 ? '+' . number_format($reg['horas_extra'], 2) . ' h' : '—' ?>
                    </td>
                    <td style="max-width:180px;">
                        <?= htmlspecialchars($reg['razon'] ?? '—') ?>
                        <?php if ($reg['notas']): ?>
                            <div style="font-size:11px;color:#aaa;margin-top:2px;"><?= htmlspecialchars($reg['notas']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <a class="btn-editar" href="formAsistencia.php?id=<?= $reg['asistencia_id'] ?>">Editar</a>
                        <button class="btn-eliminar" onclick="confirmarEliminar(<?= $reg['asistencia_id'] ?>, '<?= htmlspecialchars(addslashes($reg['nombre_empleado'])) ?>')">Eliminar</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="sin-resultados">No hay registros para el periodo seleccionado.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modalEliminar">
    <div class="modal">
        <h3>Eliminar registro</h3>
        <p id="modalTexto">¿Seguro que deseas eliminar este registro?</p>
        <form method="POST" id="formEliminar">
            <input type="hidden" name="eliminar_id" id="eliminarId">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="modal-btns">
                <button type="button" class="btn-cancelar-modal" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn-confirmar-modal">Eliminar</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function confirmarEliminar(id, nombre) {
    document.getElementById('eliminarId').value = id;
    document.getElementById('modalTexto').textContent = 'Se eliminara el registro de "' + nombre + '". Esta accion no se puede deshacer.';
    document.getElementById('modalEliminar').classList.add('visible');
}

function cerrarModal() { document.getElementById('modalEliminar').classList.remove('visible'); }

document.getElementById('modalEliminar').addEventListener('click', function(e) { if (e.target === this) cerrarModal(); });
</script>
<script src="../includes/auto_filter.js"></script>
</body>
</html>
