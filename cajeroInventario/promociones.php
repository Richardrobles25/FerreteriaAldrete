<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

$hoy = date('Y-m-d');

// Promociones de esta sucursal (activas, próximas y vencidas recientes)
// [FIX-ALTO-B-09] Filtrar tambien por pr.sucursal_id (o legacy NULL = todas), ya que
// ahora las promociones pueden ser especificas de una sola sucursal.
$stmt = $pdo->prepare("
    SELECT pr.*,
           p.nombre_producto, p.codigo, p.precio_venta, p.tipo_venta
    FROM promociones pr
    JOIN productos p ON pr.producto_id = p.producto_id
    JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
    WHERE (pr.sucursal_id = ? OR pr.sucursal_id IS NULL)
    ORDER BY
        CASE
            WHEN pr.activo = 1 AND ? BETWEEN pr.fecha_inicio AND pr.fecha_fin THEN 0
            WHEN pr.activo = 1 AND pr.fecha_inicio > ?                        THEN 1
            ELSE 2
        END,
        pr.fecha_inicio DESC
    LIMIT 100
");
$stmt->execute([$_SESSION['sucursal_id'], $_SESSION['sucursal_id'], $hoy, $hoy]);
$promociones = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Promociones — Ferretería Aldrete</title>
</head>
<body>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Arial, sans-serif; display: flex; height: 100vh; overflow: hidden; }
    .sidebar { width: 220px; background: white; border-right: 1px solid #e8e8e8; display: flex; flex-direction: column; transition: width 0.3s; flex-shrink: 0; overflow: hidden; }
    .sidebar.collapsed { width: 0; }
    .sidebar-header { padding: 18px 16px; border-bottom: 1px solid #f0f0f0; }
    .sidebar-header h3 { font-size: 14px; font-weight: 700; color: #14ace7; margin: 0; }
    .sidebar-header p  { font-size: 11px; color: #999; margin: 4px 0 0; }
    .sidebar-menu { flex: 1; padding: 8px 0; overflow-y: auto; }
    .menu-item { display: block; padding: 10px 16px; font-size: 13px; color: #555; cursor: pointer; border-left: 3px solid transparent; text-decoration: none; transition: all 0.15s; white-space: nowrap; }
    .menu-item:hover { background: #eef8ff; color: #14ace7; }
    .menu-item.active { background: #eef8ff; border-left-color: #14ace7; color: #14ace7; font-weight: 600; }
    .menu-label { padding: 8px 16px 4px; font-size: 10px; font-weight: 700; color: #14ace7; text-transform: uppercase; letter-spacing: 0.5px; }
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
    .content { flex: 1; padding: 20px; overflow-y: auto; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 18px; margin-bottom: 14px; }
    .card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 14px; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 10px 12px; text-align: left; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid #eee; }
    td { padding: 10px 12px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-activa   { background: #e8f5e9; color: #2e7d32; }
    .badge-proxima  { background: #e3f2fd; color: #1565c0; }
    .badge-vencida  { background: #f0f0f0; color: #888; }
    .badge-inactiva { background: #fdecea; color: #c0392b; }
    .precio-normal  { font-size: 12px; color: #aaa; text-decoration: line-through; }
    .precio-promo   { font-size: 16px; font-weight: 700; color: #2e7d32; }
    .ahorro-chip    { display: inline-block; background: #e8f5e9; color: #2e7d32; border-radius: 99px; padding: 2px 8px; font-size: 11px; font-weight: 700; margin-left: 6px; }
    .sin-res { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
    .promo-activa-row td { background: #f9fff9; }
    .dias-badge { font-size: 11px; color: #888; display: block; margin-top: 2px; }
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
        <a class="menu-item active" href="promociones.php">Promociones</a>
        <a class="menu-item" href="masVendidos.php">Más vendidos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Promociones activas — <?= htmlspecialchars($nombreSucursal) ?></h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <div class="card" style="padding:0;">
            <?php if (count($promociones) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Precio normal</th>
                        <th>Precio en oferta</th>
                        <th>Ahorro</th>
                        <th>Período</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promociones as $pr):
                        $inicio = $pr['fecha_inicio'];
                        $fin    = $pr['fecha_fin'];
                        if (!$pr['activo']) {
                            $estBadge = 'badge-inactiva'; $estLabel = 'Inactiva';
                            $esActiva = false;
                        } elseif ($hoy < $inicio) {
                            $estBadge = 'badge-proxima'; $estLabel = 'Próxima';
                            $diasFalta = (new DateTime($inicio))->diff(new DateTime($hoy))->days;
                            $esActiva = false;
                        } elseif ($hoy > $fin) {
                            $estBadge = 'badge-vencida'; $estLabel = 'Vencida';
                            $esActiva = false;
                        } else {
                            $estBadge = 'badge-activa'; $estLabel = 'Activa';
                            $esActiva = true;
                            $diasRestan = (new DateTime($hoy))->diff(new DateTime($fin))->days;
                        }
                        $ahorroPct = $pr['precio_venta'] > 0
                            ? round((1 - $pr['precio_promocional'] / $pr['precio_venta']) * 100, 1)
                            : 0;
                        $ahorroAbs = $pr['precio_venta'] - $pr['precio_promocional'];
                    ?>
                    <tr <?= $esActiva ? 'class="promo-activa-row"' : '' ?>>
                        <td>
                            <strong><?= htmlspecialchars($pr['nombre_producto']) ?></strong>
                            <div style="font-size:10px;color:#aaa;"><?= htmlspecialchars($pr['codigo']) ?></div>
                            <?php if ($pr['descripcion']): ?>
                            <div style="font-size:11px;color:#888;margin-top:2px;">🏷 <?= htmlspecialchars($pr['descripcion']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="precio-normal">$<?= number_format($pr['precio_venta'], 2) ?></td>
                        <td>
                            <span class="precio-promo">$<?= number_format($pr['precio_promocional'], 2) ?></span>
                            <?php if ($pr['tipo_venta'] === 'Suelto'): ?>
                            <span style="font-size:11px;color:#888;">/kg</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="ahorro-chip">-<?= $ahorroPct ?>%</span>
                            <div style="font-size:11px;color:#2e7d32;margin-top:2px;">$<?= number_format($ahorroAbs, 2) ?> menos</div>
                        </td>
                        <td style="font-size:12px;color:#555;">
                            <?= date('d/m/Y', strtotime($inicio)) ?> →
                            <?= date('d/m/Y', strtotime($fin)) ?>
                            <?php if ($esActiva && isset($diasRestan)): ?>
                                <span class="dias-badge"><?= $diasRestan === 0 ? 'Vence hoy' : "Vence en {$diasRestan} día(s)" ?></span>
                            <?php elseif (!$esActiva && $estLabel === 'Próxima' && isset($diasFalta)): ?>
                                <span class="dias-badge" style="color:#1565c0;">Inicia en <?= $diasFalta ?> día(s)</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= $estBadge ?>"><?= $estLabel ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="sin-res">No hay promociones registradas para esta sucursal.</div>
            <?php endif; ?>
        </div>

        <p style="font-size:12px;color:#aaa;text-align:center;">
            Las promociones se aplican automáticamente al agregar productos en <a href="nuevaVenta.php" style="color:#14ace7;">Nueva venta</a>.
            Solo el administrador puede crearlas o modificarlas.
        </p>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>
