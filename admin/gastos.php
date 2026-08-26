<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);
require_once '../includes/topbar_info.php';

// Eliminar gasto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_id'])) {
    // [FIX-CRIT-G-01] CSRF ausente antes — permitía crear, reescribir o borrar la
    // bitácora de gastos completa con un POST desde cualquier página.
    requerirCSRF($_POST['_token'] ?? '', 'gastos.php');
    $pdo->prepare("DELETE FROM gastos WHERE gasto_id = ?")->execute([intval($_POST['eliminar_id'])]);
    header('Location: gastos.php?' . http_build_query(array_filter([
        'fecha_inicio'       => $_POST['fi'] ?? '',
        'fecha_fin'          => $_POST['ff'] ?? '',
        'sucursal'           => $_POST['suc'] ?? '',
        'categoria_gasto_id' => $_POST['cat'] ?? '',
    ])));
    exit();
}

$fechaInicio = $_GET['fecha_inicio']       ?? date('Y-m-01');
$fechaFin    = $_GET['fecha_fin']          ?? date('Y-m-d');
$sucursal    = intval($_GET['sucursal']    ?? 0);
$categoria   = intval($_GET['categoria_gasto_id'] ?? 0);

$where  = "WHERE 1=1";
$params = [];
if ($sucursal)  { $where .= " AND g.sucursal_id = ?";          $params[] = $sucursal; }
if ($categoria) { $where .= " AND g.categoria_gasto_id = ?";   $params[] = $categoria; }
if ($fechaInicio && $fechaFin) { $where .= " AND g.fecha BETWEEN ? AND ?"; $params[] = $fechaInicio; $params[] = $fechaFin; }
elseif ($fechaInicio)          { $where .= " AND g.fecha >= ?";             $params[] = $fechaInicio; }
elseif ($fechaFin)             { $where .= " AND g.fecha <= ?";             $params[] = $fechaFin; }

$stmt = $pdo->prepare("
    SELECT g.*,
           s.nombre AS nombre_sucursal,
           u.nombre_completo,
           cg.nombre AS nombre_categoria
    FROM gastos g
    JOIN sucursales s         ON g.sucursal_id = s.sucursal_id
    JOIN usuarios u           ON g.usuario_id = u.usuario_id
    JOIN categorias_gastos cg ON g.categoria_gasto_id = cg.categoria_gasto_id
    $where
    ORDER BY g.fecha DESC, g.gasto_id DESC
    LIMIT 500
");
$stmt->execute($params);
$gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// [FIX-ALTO-G-09] Antes el total y el desglose por categoria se calculaban en PHP a
// partir de $gastos, que trae como maximo 500 filas (LIMIT 500 de la consulta de arriba,
// para no reventar la tabla en pantalla con miles de filas). Si el filtro activo tenia mas
// de 500 gastos, el total silenciosamente solo sumaba los 500 mas recientes — gasto real
// desaparecia del total sin ningun aviso. Ahora el total y el desglose salen de un SUM/
// GROUP BY en SQL sobre el mismo filtro, sin el LIMIT, así que siempre reflejan TODO lo
// que cae en el filtro aunque la tabla de abajo solo muestre las 500 filas mas recientes.
$stmtTotal = $pdo->prepare("
    SELECT COALESCE(SUM(g.monto), 0) AS total
    FROM gastos g
    JOIN sucursales s         ON g.sucursal_id = s.sucursal_id
    JOIN categorias_gastos cg ON g.categoria_gasto_id = cg.categoria_gasto_id
    $where
");
$stmtTotal->execute($params);
$totalMonto = floatval($stmtTotal->fetchColumn());

$stmtPorCat = $pdo->prepare("
    SELECT cg.nombre AS categoria, SUM(g.monto) AS total
    FROM gastos g
    JOIN sucursales s         ON g.sucursal_id = s.sucursal_id
    JOIN categorias_gastos cg ON g.categoria_gasto_id = cg.categoria_gasto_id
    $where
    GROUP BY cg.categoria_gasto_id, cg.nombre
");
$stmtPorCat->execute($params);
$porCategoria = [];
foreach ($stmtPorCat->fetchAll(PDO::FETCH_ASSOC) as $pc) {
    $porCategoria[$pc['categoria']] = floatval($pc['total']);
}
arsort($porCategoria);

// [FIX-MEDIO-G-21] El filtro de sucursal solo listaba "activo=1" — un gasto ya registrado en
// una sucursal que despues se desactivo seguia contando en "Todas", pero ya no habia forma de
// filtrar/aislar especificamente esa sucursal para revisarlo (la opcion desaparecia del
// combo). Se incluyen tambien las sucursales inactivas que SI tienen gastos registrados, para
// que su historial siga siendo consultable.
$sucursales  = $pdo->query("
    SELECT sucursal_id, nombre, activo FROM sucursales
    WHERE activo = 1 OR sucursal_id IN (SELECT DISTINCT sucursal_id FROM gastos)
    ORDER BY activo DESC, nombre
")->fetchAll(PDO::FETCH_ASSOC);
$categorias  = $pdo->query("SELECT categoria_gasto_id, nombre FROM categorias_gastos WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

$filtrosActivos = $sucursal || $categoria || ($fechaInicio !== date('Y-m-01')) || ($fechaFin !== date('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bitacora de Gastos — Ferretería Aldrete</title>
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
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 14px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 12px; margin-bottom: 14px; }
    .stat { background: white; border-radius: 8px; padding: 14px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 18px; font-weight: 700; color: #222; margin: 0; }
    .stat.stat-rojo { border-top-color: #e74c3c; }
    .stat.stat-rojo h3 { color: #c0392b; }
    .cat-breakdown { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 14px; }
    .cat-breakdown h4 { font-size: 12px; color: #888; text-transform: uppercase; margin-bottom: 10px; }
    .cat-row { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; font-size: 13px; }
    .cat-row span:first-child { flex: 1; color: #555; }
    .cat-row span:last-child { font-weight: 700; color: #222; min-width: 80px; text-align: right; }
    .cat-bar-bg { flex: 2; background: #f0f0f0; border-radius: 99px; height: 6px; }
    .cat-bar { background: #14ace7; border-radius: 99px; height: 6px; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 13px; text-align: left; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 13px; font-size: 12px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: top; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .badge-cat { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #eef8ff; color: #14ace7; }
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
        body { overflow-x: hidden; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100%; z-index: 300; width: 0; transition: width 0.3s; }
        .sidebar.collapsed { width: 260px; box-shadow: 4px 0 16px rgba(0,0,0,.15); }
        .main { width: 100%; }
        .topbar { padding: 0 12px; height: 48px; }
        .topbar h2 { font-size: 13px; }
        .topbar-right > span { display: none; }
        .content { padding: 12px; }
        th, td { padding: 8px 10px; font-size: 12px; }
    }
</style>

<?php renderAdminSidebar('gastos'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Bitacora de Gastos</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <form method="GET">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Fecha inicio</label>
                    <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fechaInicio) ?>">
                </div>
                <div class="filtro-group">
                    <label>Fecha fin</label>
                    <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fechaFin) ?>">
                </div>
                <div class="filtro-group">
                    <label>Sucursal</label>
                    <select name="sucursal">
                        <option value="0">Todas</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= $s['sucursal_id'] ?>" <?= $sucursal === intval($s['sucursal_id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['nombre']) ?><?= !$s['activo'] ? ' (inactiva)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Categoria</label>
                    <select name="categoria_gasto_id">
                        <option value="0">Todas</option>
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?= $cat['categoria_gasto_id'] ?>" <?= $categoria === intval($cat['categoria_gasto_id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-filtrar" type="submit">Filtrar</button>
                <?php if ($filtrosActivos): ?><a class="btn-limpiar" href="gastos.php">Limpiar</a><?php endif; ?>
                <a class="btn-nuevo" href="formGasto.php" style="margin-left:auto;">+ Nuevo gasto</a>
            </div>
        </form>

        <div class="stats">
            <div class="stat stat-rojo">
                <p>Total gastos (periodo)</p>
                <h3>$<?= number_format($totalMonto, 2) ?></h3>
            </div>
            <div class="stat">
                <p>Registros</p>
                <h3><?= count($gastos) ?></h3>
            </div>
            <?php if (count($porCategoria) > 0): ?>
            <div class="stat">
                <p>Mayor gasto</p>
                <h3><?= htmlspecialchars(array_key_first($porCategoria)) ?></h3>
            </div>
            <?php endif; ?>
        </div>

        <?php if (count($porCategoria) > 1): ?>
        <div class="cat-breakdown">
            <h4>Desglose por categoria</h4>
            <?php foreach ($porCategoria as $nombre => $monto): ?>
            <div class="cat-row">
                <span><?= htmlspecialchars($nombre) ?></span>
                <div class="cat-bar-bg">
                    <div class="cat-bar" style="width:<?= round(($monto / $totalMonto) * 100) ?>%"></div>
                </div>
                <span>$<?= number_format($monto, 2) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="tabla-wrapper">
            <?php if (count($gastos) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Categoria</th>
                        <th>Descripcion</th>
                        <th>Sucursal</th>
                        <th>Registrado por</th>
                        <th>Monto</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gastos as $g): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
                        <td><span class="badge-cat"><?= htmlspecialchars($g['nombre_categoria']) ?></span></td>
                        <td>
                            <?= htmlspecialchars($g['descripcion']) ?>
                            <?php if ($g['notas']): ?>
                                <div style="font-size:11px;color:#aaa;margin-top:3px;"><?= htmlspecialchars($g['notas']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:11px;"><?= htmlspecialchars($g['nombre_sucursal']) ?></td>
                        <td style="font-size:11px;"><?= htmlspecialchars($g['nombre_completo']) ?></td>
                        <td style="font-weight:700;color:#c0392b;">$<?= number_format($g['monto'], 2) ?></td>
                        <td>
                            <a class="btn-editar" href="formGasto.php?id=<?= $g['gasto_id'] ?>">Editar</a>
                            <button class="btn-eliminar" onclick="confirmarEliminar(<?= $g['gasto_id'] ?>, '<?= htmlspecialchars(addslashes($g['descripcion'])) ?>')">Eliminar</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="sin-resultados">No hay gastos registrados en este periodo.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal de confirmación de eliminación -->
<div class="modal-overlay" id="modalEliminar">
    <div class="modal">
        <h3>Eliminar gasto</h3>
        <p id="modalTexto">¿Seguro que deseas eliminar este gasto?</p>
        <form method="POST" id="formEliminar">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="eliminar_id" id="eliminarId">
            <input type="hidden" name="fi" value="<?= htmlspecialchars($fechaInicio) ?>">
            <input type="hidden" name="ff" value="<?= htmlspecialchars($fechaFin) ?>">
            <input type="hidden" name="suc" value="<?= $sucursal ?>">
            <input type="hidden" name="cat" value="<?= $categoria ?>">
            <div class="modal-btns">
                <button type="button" class="btn-cancelar-modal" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn-confirmar-modal">Eliminar</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function confirmarEliminar(id, descripcion) {
    document.getElementById('eliminarId').value = id;
    document.getElementById('modalTexto').textContent = 'Se eliminara el gasto: "' + descripcion + '". Esta accion no se puede deshacer.';
    document.getElementById('modalEliminar').classList.add('visible');
}

function cerrarModal() {
    document.getElementById('modalEliminar').classList.remove('visible');
}

document.getElementById('modalEliminar').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal();
});
</script>
<script src="../includes/auto_filter.js"></script>
</body>
</html>
