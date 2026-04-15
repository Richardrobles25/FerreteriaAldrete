<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

// Productos con stock bajo
$stmtBajo = $pdo->prepare("
    SELECT producto_id, codigo, nombre_producto, stock_actual, stock_minimo
    FROM productos
    WHERE sucursal_id = ? AND activo = 1 AND stock_actual <= stock_minimo
    ORDER BY (stock_actual / NULLIF(stock_minimo,0)) ASC
    LIMIT 10
");
$stmtBajo->execute([$_SESSION['sucursal_id']]);
$stockBajo = $stmtBajo->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas generales
$stmtStats = $pdo->prepare("
    SELECT
        COUNT(*) AS total_productos,
        SUM(CASE WHEN stock_actual <= stock_minimo THEN 1 ELSE 0 END) AS con_stock_bajo,
        SUM(CASE WHEN stock_actual = 0 THEN 1 ELSE 0 END) AS sin_stock,
        COALESCE(SUM(stock_actual * precio_compra), 0) AS valor_inventario
    FROM productos
    WHERE sucursal_id = ? AND activo = 1
");
$stmtStats->execute([$_SESSION['sucursal_id']]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

// Últimos movimientos
$stmtMov = $pdo->prepare("
    SELECT m.tipo, m.cantidad, m.created_at, p.nombre_producto, m.motivo
    FROM movimientos_inventario m
    JOIN productos p ON m.producto_id = p.producto_id
    WHERE p.sucursal_id = ?
    ORDER BY m.created_at DESC
    LIMIT 8
");
$stmtMov->execute([$_SESSION['sucursal_id']]);
$movimientos = $stmtMov->fetchAll(PDO::FETCH_ASSOC);

// Transferencias pendientes hacia esta sucursal
$stmtTransf = $pdo->prepare("
    SELECT COUNT(*) FROM transferencias
    WHERE sucursal_destino_id = ? AND estado = 'Pendiente'
");
$stmtTransf->execute([$_SESSION['sucursal_id']]);
$transfPendientes = $stmtTransf->fetchColumn();

// Últimas compras a proveedor
$stmtCompras = $pdo->prepare("
    SELECT cp.compras_proveedor_id, cp.total, cp.created_at, p.nombre AS proveedor
    FROM compras_proveedor cp
    JOIN proveedores p ON cp.proveedor_id = p.proveedor_id
    WHERE cp.sucursal_id = ?
    ORDER BY cp.created_at DESC
    LIMIT 5
");
$stmtCompras->execute([$_SESSION['sucursal_id']]);
$ultimasCompras = $stmtCompras->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario — Ferretería Aldrete</title>
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
    .alerta-stock { background: #fdecea; border: 1px solid #ffcdd2; border-radius: 8px; padding: 14px 18px; margin-bottom: 18px; }
    .alerta-stock-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
    .alerta-stock-header h3 { font-size: 14px; font-weight: 700; color: #c0392b; margin: 0; }
    .alerta-stock-header a { font-size: 12px; color: #c0392b; font-weight: 600; text-decoration: none; }
    .alerta-item { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; border-bottom: 0.5px solid #ffcdd2; font-size: 13px; }
    .alerta-item:last-child { border-bottom: none; }
    .alerta-nombre { color: #333; font-weight: 500; }
    .alerta-stock-val { color: #c0392b; font-weight: 700; font-size: 12px; }
    .alerta-minimo { color: #aaa; font-size: 11px; margin-left: 6px; }
    .notif-transf { background: #e3f2fd; border: 1px solid #bbdefb; border-radius: 8px; padding: 12px 18px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: #1565c0; }
    .notif-transf a { color: #1565c0; font-weight: 700; text-decoration: none; }
    .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px,1fr)); gap: 14px; margin-bottom: 20px; }
    .stat { background: white; border-radius: 8px; padding: 16px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 6px; text-transform: uppercase; letter-spacing: 0.5px; }
    .stat h3 { font-size: 22px; font-weight: 700; color: #222; margin: 0; }
    .stat small { font-size: 11px; color: #14ace7; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .tabla { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    .tabla-header { padding: 14px 18px; border-bottom: 0.5px solid #eee; font-size: 14px; font-weight: 600; color: #333; display: flex; justify-content: space-between; align-items: center; }
    .tabla-header a { font-size: 12px; color: #14ace7; text-decoration: none; font-weight: 400; }
    .tabla-row { padding: 10px 18px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; color: #555; display: flex; justify-content: space-between; align-items: center; }
    .tabla-row:last-child { border-bottom: none; }
    .badge-tipo { display: inline-block; padding: 2px 7px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .tipo-entrada { background: #e8f5e9; color: #2e7d32; }
    .tipo-salida { background: #fdecea; color: #c0392b; }
    .tipo-ajuste { background: #e3f2fd; color: #1565c0; }
    .tipo-transferencia { background: #f3e5f5; color: #6a1b9a; }
    .accesos { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-bottom: 20px; }
    .acceso { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; text-decoration: none; display: flex; align-items: center; gap: 10px; transition: border-color 0.15s; }
    .acceso:hover { border-color: #14ace7; }
    .acceso-icon { font-size: 20px; }
    .acceso-label { font-size: 13px; font-weight: 600; color: #333; }
    .acceso-sub { font-size: 11px; color: #aaa; }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>Ferretería Aldrete</h3>
        <p><?= $_SESSION['rol'] ?></p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item active" href="inicioInventario.php">Inicio</a>
        <a class="menu-item" href="productos.php">Productos</a>
        <a class="menu-item" href="categorias.php">Categorías</a>
        <div class="divider"></div>
        <a class="menu-item" href="entradas.php">Entradas de productos</a>
        <a class="menu-item" href="salidas.php">Salidas y mermas</a>
        <a class="menu-item" href="historialMovimientos.php">Historial de movimientos</a>
        <div class="divider"></div>
        <a class="menu-item" href="proveedores.php">Proveedores</a>
        <a class="menu-item" href="compras.php">Compras a proveedor</a>
        <div class="divider"></div>
        <a class="menu-item" href="paquetes.php">Paquetes</a>
        <a class="menu-item" href="transferencias.php">Transferencias <?= $transfPendientes>0?"({$transfPendientes})":'' ?></a>
        <a class="menu-item" href="masVendidos.php">Más vendidos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Panel de Inventario</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Alerta de stock bajo -->
        <?php if (count($stockBajo) > 0): ?>
        <div class="alerta-stock">
            <div class="alerta-stock-header">
                <h3>⚠ <?= count($stockBajo) ?> producto(s) con stock bajo</h3>
                <a href="productos.php?stock_bajo=1">Ver todos</a>
            </div>
            <?php foreach ($stockBajo as $p): ?>
            <div class="alerta-item">
                <div>
                    <span class="alerta-nombre"><?= htmlspecialchars($p['nombre_producto']) ?></span>
                    <span style="font-size:11px;color:#aaa;margin-left:6px;"><?= htmlspecialchars($p['codigo']) ?></span>
                </div>
                <div>
                    <span class="alerta-stock-val"><?= number_format($p['stock_actual'],2) ?></span>
                    <span class="alerta-minimo">mín: <?= number_format($p['stock_minimo'],2) ?></span>
                    <a href="entradas.php?producto_id=<?= $p['producto_id'] ?>"
                       style="background:#14ace7;color:white;font-size:11px;padding:2px 8px;border-radius:4px;text-decoration:none;margin-left:8px;">
                       Entrada
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Notificación de transferencias -->
        <?php if ($transfPendientes > 0): ?>
        <div class="notif-transf">
            <span>📦 Tienes <strong><?= $transfPendientes ?></strong> solicitud(es) de transferencia pendiente(s) de aprobar.</span>
            <a href="transferencias.php">Ver transferencias</a>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats">
            <div class="stat">
                <p>Total productos</p>
                <h3><?= $stats['total_productos'] ?></h3>
                <small>En esta sucursal</small>
            </div>
            <div class="stat">
                <p>Stock bajo</p>
                <h3 style="color:<?= $stats['con_stock_bajo']>0?'#c0392b':'#2e7d32' ?>;"><?= $stats['con_stock_bajo'] ?></h3>
                <small>Bajo mínimo</small>
            </div>
            <div class="stat">
                <p>Sin stock</p>
                <h3 style="color:<?= $stats['sin_stock']>0?'#c0392b':'#2e7d32' ?>;"><?= $stats['sin_stock'] ?></h3>
                <small>En cero</small>
            </div>
            <div class="stat">
                <p>Valor inventario</p>
                <h3 style="font-size:17px;">$<?= number_format($stats['valor_inventario'],0) ?></h3>
                <small>A precio de compra</small>
            </div>
        </div>

        <!-- Accesos rápidos -->
        <div class="accesos">
            <a class="acceso" href="entradas.php">
                <span class="acceso-icon">📥</span>
                <div><div class="acceso-label">Entradas</div><div class="acceso-sub">Registrar mercancía</div></div>
            </a>
            <a class="acceso" href="salidas.php">
                <span class="acceso-icon">📤</span>
                <div><div class="acceso-label">Salidas</div><div class="acceso-sub">Mermas y ajustes</div></div>
            </a>
            <a class="acceso" href="compras.php">
                <span class="acceso-icon">🛒</span>
                <div><div class="acceso-label">Compras</div><div class="acceso-sub">Pedidos a proveedor</div></div>
            </a>
            <a class="acceso" href="transferencias.php">
                <span class="acceso-icon">🔄</span>
                <div><div class="acceso-label">Transferencias</div><div class="acceso-sub"><?= $transfPendientes>0?$transfPendientes.' pendiente(s)':'Entre sucursales' ?></div></div>
            </a>
            <a class="acceso" href="productos.php">
                <span class="acceso-icon">🔧</span>
                <div><div class="acceso-label">Productos</div><div class="acceso-sub">Ver inventario completo</div></div>
            </a>
            <a class="acceso" href="masVendidos.php">
                <span class="acceso-icon">📊</span>
                <div><div class="acceso-label">Más vendidos</div><div class="acceso-sub">Estadísticas</div></div>
            </a>
        </div>

        <div class="grid-2">
            <!-- Últimos movimientos -->
            <div class="tabla">
                <div class="tabla-header">
                    <span>Últimos movimientos</span>
                    <a href="historialMovimientos.php">Ver todos</a>
                </div>
                <?php if (count($movimientos) > 0): ?>
                    <?php foreach ($movimientos as $m): ?>
                    <div class="tabla-row">
                        <div>
                            <div style="font-size:13px;color:#333;"><?= htmlspecialchars($m['nombre_producto']) ?></div>
                            <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($m['motivo']??'—') ?></div>
                        </div>
                        <div style="text-align:right;">
                            <span class="badge-tipo tipo-<?= strtolower($m['tipo']) ?>"><?= $m['tipo'] ?></span>
                            <div style="font-size:11px;color:#aaa;margin-top:2px;"><?= date('d/m H:i', strtotime($m['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="tabla-row"><span style="color:#aaa;">Sin movimientos recientes.</span></div>
                <?php endif; ?>
            </div>

            <!-- Últimas compras -->
            <div class="tabla">
                <div class="tabla-header">
                    <span>Últimas compras a proveedor</span>
                    <a href="compras.php">Ver todas</a>
                </div>
                <?php if (count($ultimasCompras) > 0): ?>
                    <?php foreach ($ultimasCompras as $c): ?>
                    <div class="tabla-row">
                        <div>
                            <div style="font-size:13px;color:#333;"><?= htmlspecialchars($c['proveedor']) ?></div>
                            <div style="font-size:11px;color:#aaa;"><?= date('d/m/Y', strtotime($c['created_at'])) ?></div>
                        </div>
                        <strong style="color:#2e7d32;">$<?= number_format($c['total'],2) ?></strong>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="tabla-row"><span style="color:#aaa;">Sin compras registradas.</span></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>
