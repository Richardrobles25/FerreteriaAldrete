<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sucursal_filtro.php';

$periodo    = $_GET['periodo'] ?? 'mes';
// [FIX-ALTO-C-02] Antes se leia $_GET['sucursal'] directo, sin pasar por el rol: un
// usuario Inventario/Inventario-Cajero (limitado a su propia sucursal) podia editar la
// URL para ver las ventas de OTRA sucursal. _admin_sucursal_filtro.php ya calcula
// $sucursalVista respetando el rol (solo Administrador puede elegir via GET) — se usa
// esa variable en vez de leer el parametro de nuevo.
$sucursal   = $sucursalVista;
// [FIX-MEDIO-C-10] $limite se interpola directo en el SQL (LIMIT no acepta placeholder de
// forma confiable en todas las configuraciones de PDO) sin validar signo ni rango — un
// "?limite=-1" producia "LIMIT -1", un error de sintaxis SQL que quedaba sin capturar y
// tronaba con detalle del servidor. Se restringe a los mismos valores que ofrece el
// selector (10/20/50).
$limite     = intval($_GET['limite'] ?? 20);
if (!in_array($limite, [10, 20, 50], true)) $limite = 20;

$fechaDesde = match($periodo) {
    'hoy'    => date('Y-m-d'),
    'semana' => date('Y-m-d', strtotime('-7 days')),
    'mes'    => date('Y-m-d', strtotime('-30 days')),
    'año'    => date('Y-m-d', strtotime('-365 days')),
    default  => date('Y-m-d', strtotime('-30 days'))
};

// [FIX] sucursal=0 ("Todas las sucursales") es el valor por DEFECTO al iniciar sesion como
// Administrador — antes el JOIN exigia "ca.sucursal_id = 0", que ninguna caja real cumple,
// asi que la pantalla mostraba "sin datos" incluso habiendo ventas todo el año. Ahora, si es
// 0, el JOIN no filtra por sucursal (agrega todas); si no, filtra como antes.
$condSucCaja = ($sucursal !== 0) ? ' AND ca.sucursal_id = ?' : '';

// [FIX-PRECISION] Igual que en reporteProductos.php: venta_productos nunca se toca cuando
// hay una devolucion (queda como el registro historico de lo que se vendio originalmente) —
// solo movimientos_inventario (via devolucion_id) sabe cuanto de cada producto realmente
// volvio a stock. Sin este JOIN, un producto devuelto (parcial o totalmente) seguia contando
// integro como "vendido" aqui, inflando el ranking de mas vendidos.
$devJoinMV = "
    LEFT JOIN (
        SELECT d.venta_id, mi.producto_id, mi.paquete_id, SUM(mi.cantidad) AS cant_devuelta
        FROM movimientos_inventario mi
        JOIN devoluciones d ON mi.devolucion_id = d.devolucion_id
        WHERE mi.tipo = 'Entrada' AND mi.devolucion_id IS NOT NULL
        GROUP BY d.venta_id, mi.producto_id, mi.paquete_id
    ) devmv ON devmv.venta_id = vp.venta_id AND devmv.producto_id = vp.producto_id AND devmv.paquete_id <=> vp.paquete_id
";
$cantEfectivaMV = "GREATEST(0, vp.cantidad - COALESCE(devmv.cant_devuelta,0))";
$subEfectivaMV  = "(vp.subtotal * $cantEfectivaMV / NULLIF(vp.cantidad,0))";

$stmt = $pdo->prepare("
    SELECT
        p.producto_id,
        p.codigo,
        p.nombre_producto,
        c.nombre AS categoria,
        SUM($cantEfectivaMV) AS total_vendido,
        SUM($subEfectivaMV) AS total_ingresos,
        COUNT(DISTINCT CASE WHEN $cantEfectivaMV > 0 THEN vp.venta_id END) AS num_ventas,
        (SELECT CASE WHEN ? = 0 THEN SUM(ss2.stock_actual) ELSE MAX(CASE WHEN ss2.sucursal_id = ? THEN ss2.stock_actual END) END
         FROM stock_sucursal ss2 WHERE ss2.producto_id = p.producto_id) AS stock_actual,
        p.precio_venta
    FROM venta_productos vp
    JOIN ventas v ON vp.venta_id = v.venta_id
    JOIN cajas ca ON v.caja_id = ca.caja_id $condSucCaja
    JOIN productos p ON vp.producto_id = p.producto_id
    LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
    $devJoinMV
    WHERE v.estado IN ('Completada','Modificado','Devuelto')
      AND DATE(v.created_at) >= ?
    GROUP BY p.producto_id, p.codigo, p.nombre_producto, c.nombre, p.precio_venta
    ORDER BY total_vendido DESC
    LIMIT $limite
");
$paramsMV = [$sucursal, $sucursal];
if ($sucursal !== 0) { $paramsMV[] = $sucursal; }
$paramsMV[] = $fechaDesde;
$stmt->execute($paramsMV);
$masVendidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total del periodo para calcular porcentaje
$stmtTotal = $pdo->prepare("
    SELECT COALESCE(SUM($cantEfectivaMV),0)
    FROM venta_productos vp
    JOIN ventas v ON vp.venta_id = v.venta_id
    JOIN cajas ca ON v.caja_id = ca.caja_id $condSucCaja
    JOIN productos p ON vp.producto_id = p.producto_id
    $devJoinMV
    WHERE v.estado IN ('Completada','Modificado','Devuelto') AND DATE(v.created_at) >= ?
");
$paramsTotal = [];
if ($sucursal !== 0) { $paramsTotal[] = $sucursal; }
$paramsTotal[] = $fechaDesde;
$stmtTotal->execute($paramsTotal);
$totalUnidades = $stmtTotal->fetchColumn() ?: 1;

if (isset($_GET['exportar']) && in_array($_GET['exportar'], ['pdf','excel'])) {
    require_once __DIR__ . '/export_helper.php';

    // Usar el mismo query que ya tiene el archivo pero sin LIMIT
    $stmtExp = $pdo->prepare("
        SELECT p.codigo, p.nombre_producto, c.nombre AS categoria,
               SUM($cantEfectivaMV) AS total_vendido,
               SUM($subEfectivaMV) AS total_ingresos,
               (SELECT CASE WHEN ? = 0 THEN SUM(ss2.stock_actual) ELSE MAX(CASE WHEN ss2.sucursal_id = ? THEN ss2.stock_actual END) END
                FROM stock_sucursal ss2 WHERE ss2.producto_id = p.producto_id) AS stock_actual
        FROM venta_productos vp
        JOIN ventas v ON vp.venta_id = v.venta_id
        JOIN cajas ca ON v.caja_id = ca.caja_id $condSucCaja
        JOIN productos p ON vp.producto_id = p.producto_id
        LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
        $devJoinMV
        WHERE v.estado IN ('Completada','Modificado','Devuelto') AND DATE(v.created_at) >= ?
        GROUP BY p.producto_id, p.codigo, p.nombre_producto, c.nombre
        ORDER BY total_vendido DESC
    ");
    $paramsExp = [$sucursal, $sucursal];
    if ($sucursal !== 0) { $paramsExp[] = $sucursal; }
    $paramsExp[] = $fechaDesde;
    $stmtExp->execute($paramsExp);
    $expData = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    $totalUnidadesExp = array_sum(array_column($expData,'total_vendido')) ?: 1;

    $titulo = 'Productos Más Vendidos';
    $subtitulo = "Sucursal: $nombreSucursalVista | Período: $periodo | Desde: $fechaDesde";
    $columnas = ['#','Código','Producto','Categoría','Unidades Vendidas','Participación %','Ingresos','Stock Actual'];
    $filas = array_map(function($r, $i) use ($totalUnidadesExp) {
        return [
            $i + 1,
            $r['codigo'],
            $r['nombre_producto'],
            $r['categoria'] ?? '—',
            $r['total_vendido'],
            number_format(($r['total_vendido'] / $totalUnidadesExp) * 100, 1) . '%',
            '$' . number_format($r['total_ingresos'], 2),
            $r['stock_actual'],
        ];
    }, $expData, array_keys($expData));
    $resumen = [
        ['label' => 'Productos en Ranking', 'valor' => count($expData)],
        ['label' => 'Unidades Vendidas', 'valor' => array_sum(array_column($expData,'total_vendido'))],
        ['label' => 'Ingresos Generados', 'valor' => '$' . number_format(array_sum(array_column($expData,'total_ingresos')), 2)],
    ];

    if ($_GET['exportar'] === 'pdf') exportarPDF($titulo, $subtitulo, $columnas, $filas, $resumen, 'L');
    else exportarExcel($titulo, $subtitulo, $columnas, $filas, $resumen);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Más Vendidos — Ferretería Aldrete</title>
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
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 16px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 12px; margin-bottom: 16px; }
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
    .rank { font-size: 16px; font-weight: 700; color: #14ace7; }
    .rank-1 { color: #1565c0; }
    .rank-2 { color: #9e9e9e; }
    .rank-3 { color: #8d6e63; }
    .barra-container { width: 100%; background: #f0f0f0; border-radius: 99px; height: 6px; margin-top: 4px; }
    .barra { background: #14ace7; border-radius: 99px; height: 6px; }
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

<?php renderAdminSidebar('inventario_mas_vendidos'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Productos más vendidos</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <div class="content-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h1 style="font-size:20px;color:#222;font-weight:600;">Productos más vendidos</h1>
            <div style="display:flex;gap:8px;">
                <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'pdf'])) ?>" style="background:#c0392b;color:white;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">⬇ PDF</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'excel'])) ?>" style="background:#1b5e20;color:white;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">⬇ Excel</a>
            </div>
        </div>
        <form method="GET">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Período</label>
                    <select name="periodo">
                        <option value="hoy" <?= $periodo==='hoy'?'selected':'' ?>>Hoy</option>
                        <option value="semana" <?= $periodo==='semana'?'selected':'' ?>>Última semana</option>
                        <option value="mes" <?= $periodo==='mes'?'selected':'' ?>>Último mes</option>
                        <option value="año" <?= $periodo==='año'?'selected':'' ?>>Último año</option>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Mostrar top</label>
                    <select name="limite">
                        <option value="10" <?= $limite===10?'selected':'' ?>>Top 10</option>
                        <option value="20" <?= $limite===20?'selected':'' ?>>Top 20</option>
                        <option value="50" <?= $limite===50?'selected':'' ?>>Top 50</option>
                    </select>
                </div>
                <?php renderSucursalSwitcher(); ?>
                <button class="btn-filtrar" type="submit">Aplicar</button>
            </div>
        </form>

        <?php if (count($masVendidos) > 0):
            $totalIngresos = array_sum(array_column($masVendidos,'total_ingresos'));
            $totalUndVend  = array_sum(array_column($masVendidos,'total_vendido'));
        ?>

        <div class="stats">
            <div class="stat"><p>Productos en ranking</p><h3><?= count($masVendidos) ?></h3></div>
            <div class="stat"><p>Unidades vendidas</p><h3><?= number_format($totalUndVend,0) ?></h3></div>
            <div class="stat"><p>Ingresos generados</p><h3>$<?= number_format($totalIngresos,0) ?></h3></div>
        </div>

        <div class="tabla-wrapper">
            <table>
                <thead>
                    <tr><th>#</th><th>Producto</th><th>Categoría</th><th>Unidades vendidas</th><th>Participación</th><th>Ingresos</th><th>Stock actual</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($masVendidos as $i => $p):
                        $porcentaje = round(($p['total_vendido'] / $totalUnidades) * 100, 1);
                        $rankClass  = match($i) { 0=>'rank rank-1', 1=>'rank rank-2', 2=>'rank rank-3', default=>'rank' };
                    ?>
                    <tr>
                        <td><span class="<?= $rankClass ?>"><?= $i+1 ?></span></td>
                        <td>
                            <strong><?= htmlspecialchars($p['nombre_producto']) ?></strong>
                            <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($p['codigo']) ?></div>
                        </td>
                        <td style="font-size:12px;"><?= htmlspecialchars($p['categoria']??'—') ?></td>
                        <td>
                            <strong><?= number_format($p['total_vendido'],2) ?></strong>
                            <div style="font-size:11px;color:#aaa;"><?= $p['num_ventas'] ?> ventas</div>
                        </td>
                        <td>
                            <span style="font-size:12px;"><?= $porcentaje ?>%</span>
                            <div class="barra-container">
                                <div class="barra" style="width:<?= min($porcentaje*3, 100) ?>%;"></div>
                            </div>
                        </td>
                        <td style="font-weight:600;color:#2e7d32;">$<?= number_format($p['total_ingresos'],2) ?></td>
                        <td style="<?= $p['stock_actual']<=0?'color:#c0392b;font-weight:700;':'' ?>">
                            <?= number_format($p['stock_actual'],2) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="sin-resultados" style="background:white;border-radius:8px;border:0.5px solid #e8e8e8;">
                No hay datos de ventas para este período.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
<script src="../includes/auto_filter.js"></script>
</body>
</html>


