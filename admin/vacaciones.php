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

// Eliminar vacacion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_id'])) {
    requerirCSRF($_POST['_token'] ?? '', 'vacaciones.php');
    $pdo->prepare("DELETE FROM vacaciones WHERE vacacion_id = ?")->execute([intval($_POST['eliminar_id'])]);
    header('Location: vacaciones.php?msg=eliminado');
    exit();
}

// Cambiar estado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estado'])) {
    requerirCSRF($_POST['_token'] ?? '', 'vacaciones.php');
    $vid    = intval($_POST['vacacion_id']);
    $estado = $_POST['nuevo_estado'];
    $estadosVal = ['Solicitado','Aprobado','Rechazado'];
    if (in_array($estado, $estadosVal)) {
        $pdo->prepare("UPDATE vacaciones SET estado=? WHERE vacacion_id=?")->execute([$estado, $vid]);
    }
    header('Location: vacaciones.php?msg=actualizado');
    exit();
}

$empleadoFiltro = intval($_GET['empleado'] ?? 0);
$anioFiltro     = intval($_GET['anio']     ?? date('Y'));

$where  = "WHERE 1=1";
$params = [];
if ($empleadoFiltro) { $where .= " AND v.empleado_id=?"; $params[] = $empleadoFiltro; }
if ($anioFiltro)     { $where .= " AND v.anio=?";         $params[] = $anioFiltro; }

$stmt = $pdo->prepare("
    SELECT v.*, e.nombre AS nombre_empleado, e.fecha_ingreso, e.sueldo_semanal
    FROM vacaciones v
    JOIN empleados e ON v.empleado_id = e.empleado_id
    $where
    ORDER BY v.fecha_inicio DESC
");
$stmt->execute($params);
$vacaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resumen por empleado
$empleados = $pdo->query("
    SELECT e.*
    FROM empleados e
    WHERE e.activo = 1
    ORDER BY e.nombre
")->fetchAll(PDO::FETCH_ASSOC);

// Dias tomados por empleado en el año actual
$stmtTom = $pdo->prepare("
    SELECT empleado_id, SUM(dias_tomados) AS total
    FROM vacaciones
    WHERE anio = ? AND estado != 'Rechazado'
    GROUP BY empleado_id
");
$stmtTom->execute([date('Y')]);
$diasTomadosMap = [];
foreach ($stmtTom->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $diasTomadosMap[$row['empleado_id']] = intval($row['total']);
}

$colorEstado = [
    'Solicitado' => ['bg'=>'#fff9e6','color'=>'#b7860b','border'=>'#f0b429'],
    'Aprobado'   => ['bg'=>'#f0fff0','color'=>'#1e8449','border'=>'#27ae60'],
    'Rechazado'  => ['bg'=>'#fff0f0','color'=>'#c0392b','border'=>'#e74c3c'],
];

// [FIX-MEDIO-G-22] El filtro de año era un rango fijo (año actual y los 3 anteriores). Un
// registro con "anio" fuera de ese rango (datos historicos mas viejos, o una fila corrupta de
// antes del fix G-04 con un año atipico) quedaba inaccesible desde la interfaz: no aparecia en
// la vista por defecto (año actual) y tampoco se podia seleccionar en el dropdown para
// encontrarlo. Se listan los años que realmente existen en la tabla (mas el año actual, para
// que siempre pueda registrarse uno nuevo aunque aun no haya datos), y se agrega "Todos" para
// nunca depender de adivinar el año correcto.
$aniosReales = array_map('intval', $pdo->query("SELECT DISTINCT anio FROM vacaciones ORDER BY anio DESC")->fetchAll(PDO::FETCH_COLUMN));
$aniosList   = array_unique(array_merge([intval(date('Y'))], $aniosReales));
rsort($aniosList);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vacaciones — Ferreteria Aldrete</title>
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
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    /* Resumen empleados */
    .resumen-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px,1fr)); gap: 10px; margin-bottom: 20px; }
    .emp-card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; }
    .emp-card h4 { font-size: 13px; font-weight: 700; color: #222; margin-bottom: 2px; }
    .emp-card p { font-size: 11px; color: #aaa; margin-bottom: 8px; }
    .vac-bar-bg { background: #f0f0f0; border-radius: 99px; height: 6px; margin-bottom: 5px; }
    .vac-bar-fill { background: #14ace7; border-radius: 99px; height: 6px; }
    .vac-nums { display: flex; justify-content: space-between; font-size: 11px; }
    .vac-disp { color: #555; }
    .vac-rest { font-weight: 700; color: #1a7db5; }
    .vac-sin { font-size: 11px; color: #bbb; }
    /* Filtros */
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 14px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    /* Tabla */
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 13px; text-align: left; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 13px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .badge-estado { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 600; border: 1px solid; }
    .btn-editar { background: #f5f5f5; border: none; color: #555; padding: 4px 10px; border-radius: 5px; cursor: pointer; font-size: 11px; text-decoration: none; display: inline-block; }
    .btn-editar:hover { background: #eee; }
    .btn-eliminar { background: #fff0f0; border: none; color: #c0392b; padding: 4px 10px; border-radius: 5px; cursor: pointer; font-size: 11px; }
    .btn-eliminar:hover { background: #fde8e8; }
    .btn-estado { background: #f5f5f5; border: 1px solid #ddd; color: #555; padding: 4px 10px; border-radius: 5px; cursor: pointer; font-size: 11px; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 500; align-items: center; justify-content: center; }
    .modal-overlay.visible { display: flex; }
    .modal { background: white; border-radius: 10px; padding: 24px; width: 360px; max-width: 90%; }
    .modal h3 { font-size: 15px; margin-bottom: 8px; }
    .modal p { font-size: 13px; color: #666; margin-bottom: 16px; }
    .modal-btns { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }
    .btn-m { border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-m-cancel { background: white; border: 1px solid #ddd !important; color: #555; }
    .btn-m-aprobado { background: #27ae60; color: white; }
    .btn-m-rechazado { background: #e74c3c; color: white; }
    .btn-m-eliminar { background: #e74c3c; color: white; }
    .modal-elim h3 { color: #c0392b; }
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

<?php renderAdminSidebar('rrhh_vacaciones'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Vacaciones</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <div class="topbar-bar">
            <h3>Dias disponibles — <?= date('Y') ?></h3>
            <a class="btn-nuevo" href="formVacacion.php">+ Registrar vacaciones</a>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'eliminado'): ?>
            <div class="msg msg-exito">Registro eliminado correctamente.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'actualizado'): ?>
            <div class="msg msg-exito">Estado actualizado correctamente.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'registrado'): ?>
            <div class="msg msg-exito">Vacaciones registradas correctamente.</div>
        <?php endif; ?>

        <!-- Resumen por empleado -->
        <div class="resumen-grid">
            <?php foreach ($empleados as $emp):
                $diasDisp = calcVacacionesDisponibles($emp['fecha_ingreso']);
                $diasTom  = intval($diasTomadosMap[$emp['empleado_id']] ?? 0);
                $diasRest = max(0, $diasDisp - $diasTom);
                $pct      = $diasDisp > 0 ? round(($diasTom / $diasDisp) * 100) : 0;
            ?>
            <div class="emp-card">
                <h4><?= htmlspecialchars($emp['nombre']) ?></h4>
                <?php if ($diasDisp > 0): ?>
                    <div class="vac-bar-bg">
                        <div class="vac-bar-fill" style="width:<?= min(100,$pct) ?>%"></div>
                    </div>
                    <div class="vac-nums">
                        <span class="vac-disp">Tomados: <?= $diasTom ?>/<?= $diasDisp ?></span>
                        <span class="vac-rest">Restantes: <?= $diasRest ?></span>
                    </div>
                <?php else: ?>
                    <span class="vac-sin">Sin derecho a vacaciones aun</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Filtros -->
        <form method="GET" id="formFiltros">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Empleado</label>
                    <select name="empleado" onchange="document.getElementById('formFiltros').submit()">
                        <option value="0">Todos</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?= $emp['empleado_id'] ?>" <?= $empleadoFiltro == $emp['empleado_id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Año</label>
                    <select name="anio" onchange="document.getElementById('formFiltros').submit()">
                        <option value="0" <?= $anioFiltro === 0 ? 'selected' : '' ?>>Todos</option>
                        <?php foreach ($aniosList as $a): ?>
                            <option value="<?= $a ?>" <?= $anioFiltro == $a ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <a class="btn-limpiar" href="vacaciones.php">Limpiar</a>
            </div>
        </form>

        <!-- Tabla de registros -->
        <div class="tabla-wrapper">
            <?php if (count($vacaciones) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Empleado</th>
                        <th>Desde</th>
                        <th>Hasta</th>
                        <th>Dias</th>
                        <th>Año</th>
                        <th>Estado</th>
                        <th>Notas</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($vacaciones as $vac):
                    $c = $colorEstado[$vac['estado']] ?? $colorEstado['Solicitado'];
                ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($vac['nombre_empleado']) ?></td>
                    <td><?= date('d/m/Y', strtotime($vac['fecha_inicio'])) ?></td>
                    <td><?= date('d/m/Y', strtotime($vac['fecha_fin'])) ?></td>
                    <td style="font-weight:700;"><?= $vac['dias_tomados'] ?></td>
                    <td><?= $vac['anio'] ?></td>
                    <td>
                        <span class="badge-estado" style="background:<?= $c['bg'] ?>;color:<?= $c['color'] ?>;border-color:<?= $c['border'] ?>;">
                            <?= htmlspecialchars($vac['estado']) ?>
                        </span>
                    </td>
                    <td style="font-size:11px;color:#888;"><?= htmlspecialchars($vac['notas'] ?? '—') ?></td>
                    <td style="white-space:nowrap;">
                        <a class="btn-editar" href="formVacacion.php?id=<?= $vac['vacacion_id'] ?>">Editar</a>
                        <?php if ($vac['estado'] === 'Solicitado'): ?>
                            <button class="btn-estado" onclick="cambiarEstado(<?= $vac['vacacion_id'] ?>, '<?= htmlspecialchars(addslashes($vac['nombre_empleado'])) ?>')">Resolver</button>
                        <?php endif; ?>
                        <button class="btn-eliminar" onclick="confirmarEliminar(<?= $vac['vacacion_id'] ?>, '<?= htmlspecialchars(addslashes($vac['nombre_empleado'])) ?>')">Eliminar</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="sin-resultados">No hay registros de vacaciones para este periodo.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal cambiar estado -->
<div class="modal-overlay" id="modalEstado">
    <div class="modal">
        <h3>Cambiar estado</h3>
        <p id="textoEstado">¿Que decision tomas para las vacaciones de este empleado?</p>
        <form method="POST" id="formEstado">
            <input type="hidden" name="vacacion_id" id="estadoVacId">
            <input type="hidden" name="nuevo_estado" id="nuevoEstado">
            <input type="hidden" name="cambiar_estado" value="1">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="modal-btns">
                <button type="button" class="btn-m btn-m-cancel" onclick="cerrarModales()">Cancelar</button>
                <button type="button" class="btn-m btn-m-rechazado" onclick="setEstado('Rechazado')">Rechazar</button>
                <button type="button" class="btn-m btn-m-aprobado" onclick="setEstado('Aprobado')">Aprobar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal eliminar -->
<div class="modal-overlay" id="modalEliminar">
    <div class="modal modal-elim">
        <h3>Eliminar vacaciones</h3>
        <p id="textoEliminar">¿Seguro que deseas eliminar este registro?</p>
        <form method="POST" id="formEliminar">
            <input type="hidden" name="eliminar_id" id="eliminarId">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="modal-btns">
                <button type="button" class="btn-m btn-m-cancel" onclick="cerrarModales()">Cancelar</button>
                <button type="submit" class="btn-m btn-m-eliminar">Eliminar</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function cambiarEstado(id, nombre) {
    document.getElementById('estadoVacId').value = id;
    document.getElementById('textoEstado').textContent = 'Vacaciones de ' + nombre + ': aprueba o rechaza la solicitud.';
    document.getElementById('modalEstado').classList.add('visible');
}

function setEstado(estado) {
    document.getElementById('nuevoEstado').value = estado;
    document.getElementById('formEstado').submit();
}

function confirmarEliminar(id, nombre) {
    document.getElementById('eliminarId').value = id;
    document.getElementById('textoEliminar').textContent = 'Se eliminara el registro de vacaciones de "' + nombre + '". Esta accion no se puede deshacer.';
    document.getElementById('modalEliminar').classList.add('visible');
}

function cerrarModales() {
    document.getElementById('modalEstado').classList.remove('visible');
    document.getElementById('modalEliminar').classList.remove('visible');
}

document.getElementById('modalEstado').addEventListener('click', function(e)  { if (e.target===this) cerrarModales(); });
document.getElementById('modalEliminar').addEventListener('click', function(e) { if (e.target===this) cerrarModales(); });
</script>
</body>
</html>
