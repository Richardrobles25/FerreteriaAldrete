<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

$periodo    = $_GET['periodo'] ?? 'mes';
// [FIX-CONSISTENCIA] Igual que admin/inventario_masVendidos.php (FIX-ALTO-C-02): un usuario
// Inventario/Inventario-Cajero (limitado a su propia sucursal) podia editar ?sucursal= (o el
// <select> de abajo, que lista TODAS las sucursales sin filtrar por rol) para ver las ventas
// e ingresos de OTRA sucursal. Solo Administrador puede elegir sucursal libremente.
$esAdmin  = ($_SESSION['rol'] ?? '') === 'Administrador';
$sucursal = $esAdmin ? intval($_GET['sucursal'] ?? $_SESSION['sucursal_id']) : intval($_SESSION['sucursal_id']);
$limite     = intval($_GET['limite'] ?? 20);
if (!in_array($limite, [10, 20, 50], true)) $limite = 20;

$fechaDesde = match($periodo) {
    'hoy'    => date('Y-m-d'),
    'semana' => date('Y-m-d', strtotime('-7 days')),
    'mes'    => date('Y-m-d', strtotime('-30 days')),
    'año'    => date('Y-m-d', strtotime('-365 days')),
    default  => date('Y-m-d', strtotime('-30 days'))
};

// [FIX-CONSISTENCIA] Igual que admin/inventario_masVendidos.php:
// 1) incluir 'Modificado'/'Devuelto' (FIX-ALTO-F-01/02/03) — antes solo 'Completada'
//    subestimaba ventas con devolucion parcial (dinero real que si se cobro).
// 2) restar del devolucion_id via movimientos_inventario la cantidad realmente devuelta —
//    venta_productos nunca se toca en una devolucion, asi que sin esto la mercancia
//    devuelta se seguia contando integra como "vendida".
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
        ss.stock_actual,
        p.precio_venta
    FROM venta_productos vp
    JOIN ventas v ON vp.venta_id = v.venta_id
    -- [FIX] Filtrar las ventas por la sucursal seleccionada (venta → caja → sucursal);
    -- antes solo se filtraba el stock mostrado y los totales sumaban TODAS las sucursales
    JOIN cajas ca ON v.caja_id = ca.caja_id AND ca.sucursal_id = ?
    JOIN productos p ON vp.producto_id = p.producto_id
    LEFT JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
    LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
    $devJoinMV
    WHERE v.estado IN ('Completada','Modificado','Devuelto')
      AND DATE(v.created_at) >= ?
    GROUP BY p.producto_id, p.codigo, p.nombre_producto, c.nombre, ss.stock_actual, p.precio_venta
    ORDER BY total_vendido DESC
    LIMIT $limite
");
$stmt->execute([$sucursal, $sucursal, $fechaDesde]);
$masVendidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total del periodo para calcular porcentaje
$stmtTotal = $pdo->prepare("
    SELECT COALESCE(SUM($cantEfectivaMV),0)
    FROM venta_productos vp
    JOIN ventas v ON vp.venta_id = v.venta_id
    -- [FIX] Mismo filtro por sucursal de la venta que en la consulta principal
    JOIN cajas ca ON v.caja_id = ca.caja_id AND ca.sucursal_id = ?
    JOIN productos p ON vp.producto_id = p.producto_id
    $devJoinMV
    WHERE v.estado IN ('Completada','Modificado','Devuelto') AND DATE(v.created_at) >= ?
");
$stmtTotal->execute([$sucursal, $fechaDesde]);
$totalUnidades = $stmtTotal->fetchColumn() ?: 1;

$sucursales = $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);
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
    .menu-label { padding: 8px 16px 4px; font-size: 10px; font-weight: 700; color: #14ace7; text-transform: uppercase; letter-spacing: 0.5px; }
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

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>Ferretería Aldrete</h3>
        <p>Cajero / Inventario</p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item" href="inicioCajeroInventario.php">Inicio</a>
        <div class="divider"></div>

        <div class="menu-label">Ventas</div>
        <a class="menu-item" href="nuevaVenta.php">Nueva venta</a>
        <a class="menu-item" href="historialVentas.php">Historial de ventas</a>
        <a class="menu-item" href="ventasPendientes.php">Ventas pendientes</a>
        <a class="menu-item" href="devoluciones.php">Devoluciones</a>
        <div class="divider"></div>

        <div class="menu-label">Caja</div>
        <a class="menu-item" href="abrirCaja.php">Abrir caja</a>
        <a class="menu-item" href="corteCaja.php">Corte de caja</a>
        <a class="menu-item" href="historialCortes.php">Historial de cortes</a>
        <div class="divider"></div>

        <div class="menu-label">Clientes</div>
        <a class="menu-item" href="clientes.php">Clientes</a>
        <a class="menu-item" href="creditos.php">Créditos</a>
        <div class="divider"></div>

        <div class="menu-label">Inventario</div>
        <a class="menu-item" href="productos.php">Productos</a>
        <a class="menu-item" href="categorias.php">Categorías</a>
        <a class="menu-item" href="unidades.php">Unidades de medida</a>
        <a class="menu-item" href="entradas.php">Entradas</a>
        <a class="menu-item" href="salidas.php">Salidas y mermas</a>
        <a class="menu-item" href="historial.php">Movimientos</a>
        <div class="divider"></div>

        <div class="menu-label">Proveedores</div>
        <a class="menu-item" href="proveedores.php">Proveedores</a>
        <a class="menu-item" href="compras.php">Compras</a>
        <div class="divider"></div>

        <div class="menu-label">Más</div>
        <a class="menu-item" href="paquetes.php">Paquetes</a>
        <a class="menu-item" href="transferencias.php">Transferencias</a>
        <a class="menu-item active" href="masVendidos.php">Más vendidos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>
<script>
// Restaurar el scroll del sidebar ANTES de que se pinte el resto de la pagina — si se
// hace hasta el <script> del final, primero se ve el sidebar en 0 y luego "salta" a la
// posicion guardada, dando sensacion de lag. Aqui corre justo despues del sidebar, antes
// de que el navegador tenga que parsear el resto del contenido de la pagina.
(function() {
    var menu = document.querySelector('.sidebar-menu');
    if (!menu) return;
    var saved = sessionStorage.getItem('cajeroInvSidebarScroll');
    if (saved !== null) menu.scrollTop = parseInt(saved, 10) || 0;
    menu.addEventListener('scroll', function() {
        sessionStorage.setItem('cajeroInvSidebarScroll', menu.scrollTop);
    });
})();
</script>

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
                <?php if ($esAdmin): ?>
                <div class="filtro-group">
                    <label>Sucursal</label>
                    <select name="sucursal">
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= $s['sucursal_id'] ?>" <?= $sucursal===$s['sucursal_id']?'selected':'' ?>><?= htmlspecialchars($s['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filtro-group">
                    <label>Mostrar top</label>
                    <select name="limite">
                        <option value="10" <?= $limite===10?'selected':'' ?>>Top 10</option>
                        <option value="20" <?= $limite===20?'selected':'' ?>>Top 20</option>
                        <option value="50" <?= $limite===50?'selected':'' ?>>Top 50</option>
                    </select>
                </div>
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
