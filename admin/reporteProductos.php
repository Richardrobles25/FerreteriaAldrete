<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador']);

$periodo = $_GET['periodo'] ?? 'mes';
$sucursal = intval($_GET['sucursal'] ?? 0);
$categoria = intval($_GET['categoria'] ?? 0);
$busqueda = trim($_GET['buscar'] ?? '');

$fechaDesde = match ($periodo) {
    'hoy' => date('Y-m-d'),
    'semana' => date('Y-m-d', strtotime('-7 days')),
    'mes' => date('Y-m-d', strtotime('-30 days')),
    'trimestre' => date('Y-m-d', strtotime('-90 days')),
    'anio' => date('Y-m-d', strtotime('-365 days')),
    default => date('Y-m-d', strtotime('-30 days'))
};
$fechaHasta = date('Y-m-d');

if ($periodo === 'personalizado') {
    $fechaDesde = $_GET['desde'] ?? date('Y-m-d');
    $fechaHasta = $_GET['hasta'] ?? date('Y-m-d');
}

$where = "WHERE v.estado = 'Completada' AND DATE(v.created_at) BETWEEN ? AND ?";
$params = [$fechaDesde, $fechaHasta];

if ($sucursal) {
    $where .= " AND ca.sucursal_id = ?";
    $params[] = $sucursal;
}
if ($categoria) {
    $where .= " AND p.categoria_id = ?";
    $params[] = $categoria;
}
if ($busqueda !== '') {
    $where .= " AND (p.nombre_producto LIKE ? OR p.codigo LIKE ?)";
    $params[] = '%' . $busqueda . '%';
    $params[] = '%' . $busqueda . '%';
}

$stmtResumen = $pdo->prepare("
    SELECT
        COUNT(DISTINCT vp.producto_id) AS productos_vendidos,
        COUNT(DISTINCT vp.venta_id) AS ventas_con_productos,
        COALESCE(SUM(vp.cantidad), 0) AS piezas_vendidas,
        COALESCE(SUM(vp.subtotal), 0) AS ingreso_generado
    FROM venta_productos vp
    JOIN ventas v ON vp.venta_id = v.venta_id
    JOIN productos p ON vp.producto_id = p.producto_id
    JOIN cajas ca ON v.caja_id = ca.caja_id
    $where
");
$stmtResumen->execute($params);
$resumen = $stmtResumen->fetch(PDO::FETCH_ASSOC);

$stmtTop = $pdo->prepare("
    SELECT
        p.producto_id,
        p.codigo,
        p.nombre_producto,
        c.nombre AS categoria,
        COALESCE(SUM(vp.cantidad), 0) AS cantidad_vendida,
        COALESCE(SUM(vp.subtotal), 0) AS total_generado,
        COUNT(DISTINCT vp.venta_id) AS apariciones,
        MAX(v.created_at) AS ultima_venta
    FROM venta_productos vp
    JOIN ventas v ON vp.venta_id = v.venta_id
    JOIN productos p ON vp.producto_id = p.producto_id
    LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
    JOIN cajas ca ON v.caja_id = ca.caja_id
    $where
    GROUP BY p.producto_id, p.codigo, p.nombre_producto, c.nombre
    ORDER BY cantidad_vendida DESC, total_generado DESC, p.nombre_producto ASC
    LIMIT 100
");
$stmtTop->execute($params);
$productos = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

$stmtSuc = $pdo->prepare("
    SELECT
        s.nombre AS sucursal,
        COALESCE(SUM(vp.cantidad), 0) AS cantidad_vendida,
        COALESCE(SUM(vp.subtotal), 0) AS total_generado,
        COUNT(DISTINCT vp.producto_id) AS productos_distintos
    FROM venta_productos vp
    JOIN ventas v ON vp.venta_id = v.venta_id
    JOIN productos p ON vp.producto_id = p.producto_id
    JOIN cajas ca ON v.caja_id = ca.caja_id
    JOIN sucursales s ON ca.sucursal_id = s.sucursal_id
    $where
    GROUP BY s.sucursal_id, s.nombre
    ORDER BY total_generado DESC, cantidad_vendida DESC
");
$stmtSuc->execute($params);
$ventasPorSucursal = $stmtSuc->fetchAll(PDO::FETCH_ASSOC);

$productoTop = $productos[0] ?? null;
$promedioTicket = !empty($resumen['ventas_con_productos'])
    ? floatval($resumen['ingreso_generado']) / max(1, intval($resumen['ventas_con_productos']))
    : 0;

$sucursales = $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$categorias = $pdo->query("SELECT categoria_id, nombre FROM categorias ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos mas vendidos - Ferreteria Aldrete</title>
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
    .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .content-header h1 { font-size: 20px; color: #222; font-weight: 600; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 16px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 12px; margin-bottom: 16px; }
    .stat { background: white; border-radius: 8px; padding: 16px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 20px; font-weight: 700; color: #222; margin: 0; }
    .stat small { font-size: 11px; color: #14ace7; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    .card-header { padding: 14px 18px; border-bottom: 0.5px solid #eee; font-size: 14px; font-weight: 600; color: #333; }
    .resumen-row { display: flex; justify-content: space-between; padding: 10px 18px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; color: #444; }
    .resumen-row:last-child { border-bottom: none; }
    .sin-datos { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    table { width: 100%; border-collapse: collapse; }
    th { padding: 10px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; border-bottom: 1px solid #eee; background: #f9f9f9; }
    td { padding: 10px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .badge-categoria { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; background: #e3f2fd; color: #1565c0; }
    .campo-personalizado { display: none; }
    .campo-personalizado.visible { display: flex; gap: 10px; }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>Ferreteria Aldrete</h3>
        <p>Administrador</p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item" href="inicioAdmin.php">Inicio</a>
        <a class="menu-item" href="usuarios.php">Usuarios</a>
        <a class="menu-item" href="sucursales.php">Sucursales</a>
        <div class="divider"></div>
        <a class="menu-item" href="reporteVentas.php">Ventas</a>
        <a class="menu-item active" href="reporteProductos.php">Productos mas vendidos</a>
        <a class="menu-item" href="historial.php">Historial de movimientos</a>
        <a class="menu-item" href="cortes.php">Cortes de caja</a>
        <div class="divider"></div>
        <a class="menu-item" href="../inventario/inicioInventario.php">Inventario</a>
        <a class="menu-item" href="../cajero/inicioCajero.php">Cajero</a>
        <div class="divider"></div>
        <a class="menu-item" href="clientes.php">Clientes</a>
        <a class="menu-item" href="creditos.php">Creditos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Productos mas vendidos</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">- <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <div class="content-header"><h1>Reporte de productos mas vendidos</h1></div>
        <form method="GET">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Periodo</label>
                    <select name="periodo" onchange="togglePersonalizado(this.value)">
                        <option value="hoy" <?= $periodo === 'hoy' ? 'selected' : '' ?>>Hoy</option>
                        <option value="semana" <?= $periodo === 'semana' ? 'selected' : '' ?>>Ultima semana</option>
                        <option value="mes" <?= $periodo === 'mes' ? 'selected' : '' ?>>Ultimo mes</option>
                        <option value="trimestre" <?= $periodo === 'trimestre' ? 'selected' : '' ?>>Ultimo trimestre</option>
                        <option value="anio" <?= $periodo === 'anio' ? 'selected' : '' ?>>Ultimo anio</option>
                        <option value="personalizado" <?= $periodo === 'personalizado' ? 'selected' : '' ?>>Personalizado</option>
                    </select>
                </div>
                <div class="campo-personalizado <?= $periodo === 'personalizado' ? 'visible' : '' ?>" id="campoPers">
                    <div class="filtro-group">
                        <label>Desde</label>
                        <input type="date" name="desde" value="<?= htmlspecialchars($_GET['desde'] ?? $fechaDesde) ?>">
                    </div>
                    <div class="filtro-group">
                        <label>Hasta</label>
                        <input type="date" name="hasta" value="<?= htmlspecialchars($_GET['hasta'] ?? $fechaHasta) ?>">
                    </div>
                </div>
                <div class="filtro-group">
                    <label>Sucursal</label>
                    <select name="sucursal">
                        <option value="0">Todas</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= $s['sucursal_id'] ?>" <?= $sucursal === intval($s['sucursal_id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Categoria</label>
                    <select name="categoria">
                        <option value="0">Todas</option>
                        <?php foreach ($categorias as $c): ?>
                            <option value="<?= $c['categoria_id'] ?>" <?= $categoria === intval($c['categoria_id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Buscar</label>
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Producto o codigo">
                </div>
                <button class="btn-filtrar" type="submit">Aplicar</button>
                <?php if ($sucursal || $categoria || $busqueda !== '' || $periodo !== 'mes'): ?><a class="btn-limpiar" href="reporteProductos.php">Limpiar</a><?php endif; ?>
            </div>
        </form>

        <div class="stats">
            <div class="stat"><p>Productos vendidos</p><h3><?= intval($resumen['productos_vendidos'] ?? 0) ?></h3></div>
            <div class="stat"><p>Ventas con productos</p><h3><?= intval($resumen['ventas_con_productos'] ?? 0) ?></h3></div>
            <div class="stat"><p>Piezas vendidas</p><h3><?= number_format(floatval($resumen['piezas_vendidas'] ?? 0), 0) ?></h3></div>
            <div class="stat"><p>Ingreso generado</p><h3>$<?= number_format(floatval($resumen['ingreso_generado'] ?? 0), 0) ?></h3></div>
            <div class="stat"><p>Ticket promedio</p><h3>$<?= number_format($promedioTicket, 0) ?></h3></div>
            <div class="stat"><p>Producto lider</p><h3 style="font-size:16px;"><?= htmlspecialchars($productoTop['codigo'] ?? '-') ?></h3><small><?= htmlspecialchars($productoTop['nombre_producto'] ?? 'Sin datos') ?></small></div>
        </div>

        <div class="grid-2">
            <div class="card">
                <div class="card-header">Producto con mejor desempeno</div>
                <?php if ($productoTop): ?>
                    <div class="resumen-row"><span>Producto</span><strong><?= htmlspecialchars($productoTop['nombre_producto']) ?></strong></div>
                    <div class="resumen-row"><span>Codigo</span><strong><?= htmlspecialchars($productoTop['codigo']) ?></strong></div>
                    <div class="resumen-row"><span>Categoria</span><span class="badge-categoria"><?= htmlspecialchars($productoTop['categoria'] ?? 'Sin categoria') ?></span></div>
                    <div class="resumen-row"><span>Cantidad vendida</span><strong><?= number_format($productoTop['cantidad_vendida'], 3) ?></strong></div>
                    <div class="resumen-row"><span>Total generado</span><strong>$<?= number_format($productoTop['total_generado'], 2) ?></strong></div>
                    <div class="resumen-row"><span>Ventas donde aparecio</span><strong><?= intval($productoTop['apariciones']) ?></strong></div>
                <?php else: ?>
                    <div class="sin-datos">No hay ventas en el periodo seleccionado.</div>
                <?php endif; ?>
            </div>
            <div class="card">
                <div class="card-header">Desempeno por sucursal</div>
                <?php if (count($ventasPorSucursal) > 0): ?>
                    <?php foreach ($ventasPorSucursal as $vs): ?>
                        <div class="resumen-row">
                            <div><strong><?= htmlspecialchars($vs['sucursal']) ?></strong><div style="font-size:11px;color:#aaa;"><?= intval($vs['productos_distintos']) ?> productos distintos</div></div>
                            <div style="text-align:right;"><strong>$<?= number_format($vs['total_generado'], 2) ?></strong><div style="font-size:11px;color:#aaa;"><?= number_format($vs['cantidad_vendida'], 0) ?> piezas</div></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="sin-datos">Sin movimientos para mostrar.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Ranking de productos</div>
            <?php if (count($productos) > 0): ?>
                <table>
                    <thead><tr><th>#</th><th>Producto</th><th>Categoria</th><th>Cantidad</th><th>Total generado</th><th>Ventas</th><th>Ultima venta</th></tr></thead>
                    <tbody>
                        <?php foreach ($productos as $index => $producto): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><strong><?= htmlspecialchars($producto['nombre_producto']) ?></strong><div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($producto['codigo']) ?></div></td>
                                <td><?= htmlspecialchars($producto['categoria'] ?? 'Sin categoria') ?></td>
                                <td><?= number_format($producto['cantidad_vendida'], 3) ?></td>
                                <td style="font-weight:700;">$<?= number_format($producto['total_generado'], 2) ?></td>
                                <td><?= intval($producto['apariciones']) ?></td>
                                <td><?= $producto['ultima_venta'] ? date('d/m/Y H:i', strtotime($producto['ultima_venta'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="sin-datos">No se encontraron productos vendidos con esos filtros.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
function togglePersonalizado(val) { document.getElementById('campoPers').classList.toggle('visible', val === 'personalizado'); }
</script>
</body>
</html>
