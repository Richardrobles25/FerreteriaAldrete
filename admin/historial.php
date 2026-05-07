<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);

$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-d', strtotime('-7 days'));
$fechaFin    = $_GET['fecha_fin']    ?? date('Y-m-d');
$tipo        = $_GET['tipo']         ?? '';
$sucursal    = intval($_GET['sucursal'] ?? 0);
$busqueda    = trim($_GET['buscar']  ?? '');

$where  = "WHERE 1=1";
$params = [];

if ($sucursal)                      { $where .= " AND m.sucursal_id = ?";                        $params[] = $sucursal; }
if ($fechaInicio && $fechaFin)      { $where .= " AND DATE(m.created_at) BETWEEN ? AND ?";       $params[] = $fechaInicio; $params[] = $fechaFin; }
elseif ($fechaInicio)               { $where .= " AND DATE(m.created_at) >= ?";                  $params[] = $fechaInicio; }
elseif ($fechaFin)                  { $where .= " AND DATE(m.created_at) <= ?";                  $params[] = $fechaFin; }
if ($tipo)                          { $where .= " AND m.tipo = ?";                               $params[] = $tipo; }
if ($busqueda)                      { $where .= " AND p.nombre_producto LIKE ?";                 $params[] = '%'.$busqueda.'%'; }

// ── Exportar ─────────────────────────────────────────────────────────
if (isset($_GET['exportar']) && in_array($_GET['exportar'], ['pdf','excel'])) {
    require_once __DIR__ . '/export_helper.php';

    $stmtExp = $pdo->prepare("
        SELECT m.*, p.nombre_producto, p.codigo, u.nombre_completo AS usuario, s.nombre AS sucursal
        FROM movimientos_inventario m
        JOIN productos p ON m.producto_id = p.producto_id
        JOIN usuarios u ON m.usuario_id = u.usuario_id
        JOIN sucursales s ON m.sucursal_id = s.sucursal_id
        $where
        ORDER BY m.created_at DESC
    ");
    $stmtExp->execute($params);
    $expData = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    $titulo = 'Historial General de Movimientos';
    $rangoFecha = $fechaInicio && $fechaFin ? "Del $fechaInicio al $fechaFin"
                : ($fechaInicio ? "Desde $fechaInicio" : ($fechaFin ? "Hasta $fechaFin" : ''));
    $subtitulo = implode(' | ', array_filter([
        $rangoFecha,
        $tipo     ? "Tipo: $tipo"    : '',
        $sucursal ? "Sucursal ID: $sucursal" : '',
        $busqueda ? "Búsqueda: $busqueda" : '',
    ])) ?: 'Todos los movimientos';
    $columnas = ['Producto','Código','Tipo','Cantidad','Stock Ant.','Stock Nuevo','Motivo','Usuario','Sucursal','Fecha'];
    $filas = array_map(fn($r) => [
        $r['nombre_producto'],
        $r['codigo'],
        $r['tipo'],
        $r['cantidad'],
        $r['stock_anterior'],
        $r['stock_nuevo'],
        $r['motivo'] ?? '',
        $r['usuario'],
        $r['sucursal'],
        date('d/m/Y H:i', strtotime($r['created_at'])),
    ], $expData);

    if ($_GET['exportar'] === 'pdf') {
        exportarPDF($titulo, $subtitulo, $columnas, $filas, [], 'L');
    } else {
        exportarExcel($titulo, $subtitulo, $columnas, $filas, []);
    }
}

$stmt = $pdo->prepare("
    SELECT m.*, p.nombre_producto, p.codigo, u.nombre_completo AS usuario, s.nombre AS sucursal
    FROM movimientos_inventario m
    JOIN productos p ON m.producto_id = p.producto_id
    JOIN usuarios u ON m.usuario_id = u.usuario_id
    JOIN sucursales s ON m.sucursal_id = s.sucursal_id
    $where
    ORDER BY m.created_at DESC
    LIMIT 300
");
$stmt->execute($params);
$movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resumen
$stmtRes = $pdo->prepare("
    SELECT
        SUM(CASE WHEN m.tipo='Entrada' OR (m.tipo='Ajuste' AND m.stock_nuevo > m.stock_anterior) THEN m.cantidad ELSE 0 END) AS total_entradas,
        SUM(CASE WHEN m.tipo='Salida' OR (m.tipo='Ajuste' AND m.stock_nuevo < m.stock_anterior) THEN m.cantidad ELSE 0 END) AS total_salidas,
        COUNT(CASE WHEN m.tipo='Ajuste' THEN 1 END) AS total_ajustes,
        COUNT(CASE WHEN m.tipo='Transferencia' THEN 1 END) AS total_transf
    FROM movimientos_inventario m
    JOIN productos p ON m.producto_id = p.producto_id
    JOIN usuarios u ON m.usuario_id = u.usuario_id
    JOIN sucursales s ON m.sucursal_id = s.sucursal_id
    $where
");
$stmtRes->execute($params);
$resumen = $stmtRes->fetch(PDO::FETCH_ASSOC);

$sucursales = $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Movimientos — Ferretería Aldrete</title>
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
    .stat h3 { font-size: 20px; font-weight: 700; color: #222; margin: 0; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .badge-tipo { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .tipo-entrada { background: #e8f5e9; color: #2e7d32; }
    .tipo-salida { background: #fdecea; color: #c0392b; }
    .tipo-ajuste { background: #e3f2fd; color: #1565c0; }
    .tipo-transferencia { background: #f3e5f5; color: #6a1b9a; }
    .cantidad-entrada { color: #2e7d32; font-weight: 700; }
    .cantidad-salida { color: #c0392b; font-weight: 700; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
    @media (max-width: 768px) {
        body { overflow-x: hidden; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100%; z-index: 300; width: 0; transition: width 0.3s; }
        .sidebar.collapsed { width: 260px; box-shadow: 4px 0 16px rgba(0,0,0,.15); }
        .main { width: 100%; }
        .topbar { padding: 0 12px; height: 48px; }
        .topbar h2 { font-size: 13px; }
        .topbar-right { gap: 8px; font-size: 12px; }
        .topbar-right > span { display: none; }
        .content { padding: 12px !important; display: block !important; }
        .content > div + div { margin-top: 12px; }
        .card { overflow-x: auto; }
        th, td { padding: 8px 10px; font-size: 12px; }
        .form-group input, .form-group select, .form-group textarea { font-size: 16px; }
        .logout-btn { padding: 5px 10px; font-size: 11px; }
    }
    </style>

<?php renderAdminSidebar('historial_admin'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Historial de Movimientos</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <div class="content-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <h1 style="font-size:20px;color:#222;font-weight:600;">Historial de Movimientos</h1>
            <div style="display:flex;gap:8px;">
                <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'pdf'])) ?>" style="background:#c0392b;color:white;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">⬇ PDF</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'excel'])) ?>" style="background:#1b5e20;color:white;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">⬇ Excel</a>
            </div>
        </div>
        <form method="GET">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Buscar producto</label>
                    <input type="text" name="buscar" placeholder="Nombre..." value="<?= htmlspecialchars($busqueda) ?>" style="width:160px;" oninput="filtrarTabla(this.value)">
                </div>
                <div class="filtro-group">
                    <label>Fecha inicio</label>
                    <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fechaInicio) ?>">
                </div>
                <div class="filtro-group">
                    <label>Fecha fin</label>
                    <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fechaFin) ?>">
                </div>
                <div class="filtro-group">
                    <label>Tipo</label>
                    <select name="tipo">
                        <option value="">Todos</option>
                        <option value="Entrada" <?= $tipo==='Entrada'?'selected':'' ?>>Entrada</option>
                        <option value="Salida" <?= $tipo==='Salida'?'selected':'' ?>>Salida</option>
                        <option value="Ajuste" <?= $tipo==='Ajuste'?'selected':'' ?>>Ajuste</option>
                        <option value="Transferencia" <?= $tipo==='Transferencia'?'selected':'' ?>>Transferencia</option>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Sucursal</label>
                    <select name="sucursal">
                        <option value="0">Todas</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= $s['sucursal_id'] ?>" <?= $sucursal===$s['sucursal_id']?'selected':'' ?>><?= htmlspecialchars($s['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-filtrar" type="submit">Filtrar</button>
                <?php if ($fechaInicio||$fechaFin||$tipo||$sucursal||$busqueda): ?><a class="btn-limpiar" href="historial.php">Limpiar</a><?php endif; ?>
            </div>
        </form>

        <div class="stats">
            <div class="stat"><p>Entradas</p><h3><?= number_format($resumen['total_entradas'] ?? 0, 2) ?></h3></div>
            <div class="stat"><p>Salidas</p><h3><?= number_format($resumen['total_salidas'] ?? 0, 2) ?></h3></div>
            <div class="stat"><p>Ajustes</p><h3><?= $resumen['total_ajustes'] ?? 0 ?></h3></div>
            <div class="stat"><p>Transferencias</p><h3><?= $resumen['total_transf'] ?? 0 ?></h3></div>
        </div>

        <div class="tabla-wrapper">
            <?php if (count($movimientos) > 0): ?>
            <table>
                <thead>
                    <tr><th>Producto</th><th>Sucursal</th><th>Tipo</th><th>Cantidad</th><th>Stock ant.</th><th>Stock nuevo</th><th>Motivo</th><th>Usuario</th><th>Fecha</th></tr>
                </thead>
                <tbody id="tablaFiltrable">
                    <?php foreach ($movimientos as $m): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($m['nombre_producto']) ?></strong>
                            <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($m['codigo']) ?></div>
                        </td>
                        <td style="font-size:12px;"><?= htmlspecialchars($m['sucursal']) ?></td>
                        <td><span class="badge-tipo tipo-<?= strtolower($m['tipo']) ?>"><?= $m['tipo'] ?></span></td>
                        <td class="<?= $m['tipo']==='Entrada'?'cantidad-entrada':'cantidad-salida' ?>">
                            <?= ($m['tipo']==='Entrada'?'+':'-').number_format($m['cantidad'],3) ?>
                        </td>
                        <td><?= number_format($m['stock_anterior'],3) ?></td>
                        <td><?= number_format($m['stock_nuevo'],3) ?></td>
                        <td style="font-size:12px;color:#888;"><?= htmlspecialchars($m['motivo']??'—') ?></td>
                        <td style="font-size:12px;"><?= htmlspecialchars($m['usuario']) ?></td>
                        <td style="font-size:12px;color:#aaa;"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="sin-resultados">No hay movimientos registrados.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function normalizar(str) {
    return String(str || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}
function filtrarTabla(q) {
    q = normalizar(q);
    document.querySelectorAll('#tablaFiltrable tr').forEach(function(tr) {
        tr.style.display = normalizar(tr.textContent).includes(q) ? '' : 'none';
    });
}
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
<script src="../includes/auto_filter.js"></script>
</body>
</html>


