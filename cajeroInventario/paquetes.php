<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

// Listar paquetes globales
$stmt     = $pdo->query("SELECT * FROM paquetes ORDER BY activo DESC, nombre ASC");
$paquetes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paquetes — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 20px; overflow-y: auto; }
    .global-badge { background: #e3f2fd; color: #1565c0; font-size: 11px; padding: 2px 8px; border-radius: 99px; font-weight: 600; }
    .paquete-item { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 16px; margin-bottom: 12px; }
    .paquete-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
    .paquete-info h4 { font-size: 14px; color: #333; margin: 0 0 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .paquete-codigo { font-size: 11px; color: #14ace7; font-weight: 700; background: #e3f2fd; padding: 2px 8px; border-radius: 99px; }
    .paquete-desc { font-size: 12px; color: #888; margin: 2px 0 0; }
    .paquete-precios { font-size: 12px; color: #aaa; margin-top: 4px; }
    .ahorro-label { color: #2e7d32; font-weight: 600; }
    .paquete-precio { font-size: 20px; font-weight: 700; color: #14ace7; white-space: nowrap; margin-left: 16px; }
    .paquete-prods { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 10px; }
    .prod-tag { background: #f5f5f5; font-size: 11px; padding: 3px 10px; border-radius: 99px; color: #555; }
    .badge-inactivo { background: #f0f0f0; color: #999; font-size: 11px; padding: 2px 8px; border-radius: 99px; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; }
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
        <a class="menu-item active" href="paquetes.php">Paquetes</a>
        <a class="menu-item" href="transferencias.php">Transferencias</a>
        <a class="menu-item" href="promociones.php">Promociones</a>
        <a class="menu-item" href="masVendidos.php">Más vendidos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>


<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Paquetes de productos</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <?php if (count($paquetes) > 0): ?>
            <?php foreach ($paquetes as $paq):
                $stmtPP = $pdo->prepare("
                    SELECT pp.cantidad,
                           p.nombre_producto,
                           p.precio_venta
                    FROM paquete_productos pp
                    JOIN productos p ON pp.producto_id = p.producto_id AND p.activo = 1
                    WHERE pp.paquete_id = ?
                ");
                $stmtPP->execute([$paq['paquete_id']]);
                $prods = $stmtPP->fetchAll(PDO::FETCH_ASSOC);
                $precioSeparado = array_sum(array_map(fn($p) => $p['cantidad'] * $p['precio_venta'], $prods));
                $ahorro = $precioSeparado > 0 ? $precioSeparado - $paq['precio_paquete'] : 0;
            ?>
            <div class="paquete-item">
                <div class="paquete-header">
                    <div class="paquete-info">
                        <h4>
                            <?= htmlspecialchars($paq['nombre']) ?>
                            <span class="paquete-codigo"><?= htmlspecialchars($paq['codigo']) ?></span>
                            <span class="global-badge">Global</span>
                            <?php if (!$paq['activo']): ?>
                                <span class="badge-inactivo">Inactivo</span>
                            <?php endif; ?>
                        </h4>
                        <?php if ($paq['descripcion']): ?>
                            <div class="paquete-desc"><?= htmlspecialchars($paq['descripcion']) ?></div>
                        <?php endif; ?>
                        <?php if ($precioSeparado > 0): ?>
                            <div class="paquete-precios">
                                Sin paquete: $<?= number_format($precioSeparado,2) ?>
                                <?php if ($ahorro > 0): ?>
                                    · <span class="ahorro-label">Ahorro: $<?= number_format($ahorro,2) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="paquete-precio">$<?= number_format($paq['precio_paquete'],2) ?></div>
                </div>
                <div class="paquete-prods">
                    <?php foreach ($prods as $pr): ?>
                        <span class="prod-tag"><?= htmlspecialchars($pr['nombre_producto']) ?> × <?= number_format($pr['cantidad'],2) ?></span>
                    <?php endforeach; ?>
                    <?php if (empty($prods)): ?>
                        <span style="font-size:11px;color:#aaa;">Sin productos asignados</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="sin-resultados">No hay paquetes registrados.</div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>
