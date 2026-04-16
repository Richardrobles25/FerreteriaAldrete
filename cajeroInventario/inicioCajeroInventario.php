<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Inventario/Cajero']);

// Estado de caja
$stmt = $pdo->prepare("SELECT * FROM cajas WHERE usuario_id = ? AND estado = 'Abierta' ORDER BY abierta_en DESC LIMIT 1");
$stmt->execute([$_SESSION['usuario_id']]);
$cajaActual = $stmt->fetch(PDO::FETCH_ASSOC);

// EstadÃ­sticas del turno
$ventasTurno = 0;
$totalTurno  = 0;
$pendientes  = 0;

if ($cajaActual) {
    $stmtV = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total),0) FROM ventas WHERE caja_id = ? AND estado = 'Completada'");
    $stmtV->execute([$cajaActual['caja_id']]);
    [$ventasTurno, $totalTurno] = $stmtV->fetch(PDO::FETCH_NUM);

    $stmtP = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE caja_id = ? AND estado = 'Pendiente'");
    $stmtP->execute([$cajaActual['caja_id']]);
    $pendientes = $stmtP->fetchColumn();
}

// Alertas de stock bajo
$stmtStock = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE sucursal_id = ? AND activo = 1 AND stock_actual <= stock_minimo");
$stmtStock->execute([$_SESSION['sucursal_id']]);
$stockBajo = $stmtStock->fetchColumn();

// CrÃ©ditos activos
$stmtCred = $pdo->query("SELECT COUNT(*) FROM creditos WHERE estado = 'Activo'");
$creditosActivos = $stmtCred->fetchColumn();

// Ãšltimas ventas del turno
$ultimasVentas = [];
if ($cajaActual) {
    $stmtUV = $pdo->prepare("
        SELECT v.venta_id, v.total, v.metodo_pago, v.created_at, c.nombre_completo as cliente
        FROM ventas v
        LEFT JOIN clientes c ON v.cliente_id = c.cliente_id
        WHERE v.caja_id = ?
        ORDER BY v.created_at DESC LIMIT 5
    ");
    $stmtUV->execute([$cajaActual['caja_id']]);
    $ultimasVentas = $stmtUV->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio â€” FerreterÃ­a Aldrete</title>
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
    .menu-label { padding: 8px 16px 4px; font-size: 10px; color: #bbb; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; }
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
    .caja-status { border-radius: 8px; padding: 16px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
    .caja-abierta { background: #e8f5e9; border: 1px solid #c8e6c9; }
    .caja-cerrada { background: #fdecea; border: 1px solid #ffcdd2; }
    .caja-info h3 { font-size: 15px; font-weight: 700; margin: 0 0 4px; }
    .caja-abierta .caja-info h3 { color: #2e7d32; }
    .caja-cerrada .caja-info h3 { color: #c0392b; }
    .caja-info p { font-size: 12px; color: #888; margin: 0; }
    .btn-caja { padding: 9px 18px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-abrir { background: #14ace7; color: white; }
    .btn-abrir:hover { background: #1196cb; }
    .btn-cerrar { background: #c0392b; color: white; }
    .btn-cerrar:hover { background: #a93226; }
    .alerta-stock { background: #fdecea; border: 1px solid #ffcdd2; border-radius: 8px; padding: 12px 18px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: #c0392b; }
    .alerta-stock a { color: #c0392b; font-weight: 700; text-decoration: none; }
    .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px,1fr)); gap: 14px; margin-bottom: 20px; }
    .stat { background: white; border-radius: 8px; padding: 16px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 6px; text-transform: uppercase; letter-spacing: 0.5px; }
    .stat h3 { font-size: 24px; font-weight: 700; color: #222; margin: 0; }
    .stat small { font-size: 11px; color: #14ace7; }
    .accesos { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px; }
    .acceso-card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 16px; text-decoration: none; display: flex; align-items: center; gap: 12px; transition: border-color 0.15s; }
    .acceso-card:hover { border-color: #14ace7; }
    .acceso-icon { width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .icon-venta { background: #e3f2fd; }
    .icon-inv { background: #e8f5e9; }
    .icon-clientes { background: #e3f2fd; }
    .icon-creditos { background: #f3e5f5; }
    .icon-productos { background: #e8f5e9; }
    .icon-entradas { background: #e3f2fd; }
    .acceso-info h4 { font-size: 13px; font-weight: 600; color: #333; margin: 0 0 2px; }
    .acceso-info p { font-size: 11px; color: #aaa; margin: 0; }
    .tabla { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    .tabla-header { padding: 14px 18px; border-bottom: 0.5px solid #eee; font-size: 14px; font-weight: 600; color: #333; display: flex; justify-content: space-between; align-items: center; }
    .tabla-header a { font-size: 12px; color: #14ace7; text-decoration: none; font-weight: 400; }
    .tabla-row { padding: 11px 18px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; color: #555; display: flex; justify-content: space-between; align-items: center; }
    .tabla-row:last-child { border-bottom: none; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-efectivo { background: #e8f5e9; color: #2e7d32; }
    .badge-terminal { background: #e3f2fd; color: #1565c0; }
    .badge-mixto { background: #f3e5f5; color: #6a1b9a; }
    .badge-credito { background: #e3f2fd; color: #1565c0; }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>FerreterÃ­a Aldrete</h3>
        <p>Cajero / Inventario</p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item active" href="inicioCajeroInventario.php">Inicio</a>
        <div class="divider"></div>

        <div class="menu-label">Ventas</div>
        <a class="menu-item" href="../cajero/nuevaVenta.php">Nueva venta</a>
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
        <a class="menu-item" href="creditos.php">CrÃ©ditos</a>
        <a class="menu-item" href="abonos.php">Abonos</a>
        <div class="divider"></div>

        <div class="menu-label">Inventario</div>
        <a class="menu-item" href="../inventario/productos.php">Productos</a>
        <a class="menu-item" href="../inventario/categorias.php">CategorÃ­as</a>
        <a class="menu-item" href="../inventario/entradas.php">Entradas</a>
        <a class="menu-item" href="../inventario/salidas.php">Salidas y mermas</a>
        <a class="menu-item" href="../inventario/historialMovimientos.php">Movimientos</a>
        <div class="divider"></div>

        <div class="menu-label">Proveedores</div>
        <a class="menu-item" href="../inventario/proveedores.php">Proveedores</a>
        <a class="menu-item" href="../inventario/compras.php">Compras</a>
        <div class="divider"></div>

        <div class="menu-label">MÃ¡s</div>
        <a class="menu-item" href="../inventario/paquetes.php">Paquetes</a>
        <a class="menu-item" href="../inventario/transferencias.php">Transferencias</a>
        <a class="menu-item" href="../inventario/masVendidos.php">MÃ¡s vendidos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Panel Cajero / Inventario</h2>
        </div>
        <div class="topbar-right">
            <span>
                <?= htmlspecialchars($_SESSION['nombre_completo']) ?>
                <span style="opacity:.75;font-size:12px;">â€” <?= htmlspecialchars($nombreSucursal) ?></span>
            </span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesiÃ³n</button>
            </form>
        </div>
    </div>

    <div class="content">
        <!-- Estado de caja -->
        <?php if ($cajaActual): ?>
            <div class="caja-status caja-abierta">
                <div class="caja-info">
                    <h3>Turno #<?= $cajaActual['numero_turno'] ?> â€” Abierta</h3>
                    <p>Desde <?= date('d/m/Y H:i', strtotime($cajaActual['abierta_en'])) ?> Â· Apertura: $<?= number_format($cajaActual['monto_apertura'],2) ?></p>
                </div>
                <a class="btn-caja btn-cerrar" href="corteCaja.php">Cerrar caja</a>
            </div>
        <?php else: ?>
            <div class="caja-status caja-cerrada">
                <div class="caja-info">
                    <h3>Sin caja abierta</h3>
                    <p>Abre tu caja para comenzar a registrar ventas.</p>
                </div>
                <a class="btn-caja btn-abrir" href="abrirCaja.php">Abrir caja</a>
            </div>
        <?php endif; ?>

        <!-- Alerta stock bajo -->
        <?php if ($stockBajo > 0): ?>
            <div class="alerta-stock">
                <span>âš  Hay <strong><?= $stockBajo ?></strong> producto(s) con stock bajo en tu sucursal.</span>
                <a href="../inventario/productos.php?stock_bajo=1">Ver productos</a>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats">
            <div class="stat">
                <p>Ventas del turno</p>
                <h3><?= $ventasTurno ?></h3>
                <small>Completadas</small>
            </div>
            <div class="stat">
                <p>Total cobrado</p>
                <h3>$<?= number_format($totalTurno,2) ?></h3>
                <small>En este turno</small>
            </div>
            <div class="stat">
                <p>Pendientes</p>
                <h3><?= $pendientes ?></h3>
                <small>Por entregar</small>
            </div>
            <div class="stat">
                <p>CrÃ©ditos activos</p>
                <h3><?= $creditosActivos ?></h3>
                <small>Global</small>
            </div>
            <div class="stat">
                <p>Stock bajo</p>
                <h3 style="color:<?= $stockBajo>0?'#c0392b':'#2e7d32' ?>;"><?= $stockBajo ?></h3>
                <small>Productos</small>
            </div>
        </div>

        <!-- Accesos rÃ¡pidos -->
        <div class="accesos">
            <a class="acceso-card" href="nuevaVenta.php">
                <div class="acceso-icon icon-venta">ðŸ›’</div>
                <div class="acceso-info"><h4>Nueva venta</h4><p>Registrar cobro</p></div>
            </a>
            <a class="acceso-card" href="../inventario/entradas.php">
                <div class="acceso-icon icon-entradas">ðŸ“¦</div>
                <div class="acceso-info"><h4>Entrada de productos</h4><p>Registrar mercancÃ­a</p></div>
            </a>
            <a class="acceso-card" href="clientes.php">
                <div class="acceso-icon icon-clientes">ðŸ‘¤</div>
                <div class="acceso-info"><h4>Clientes</h4><p>Buscar o registrar</p></div>
            </a>
            <a class="acceso-card" href="../inventario/productos.php">
                <div class="acceso-icon icon-productos">ðŸ”§</div>
                <div class="acceso-info"><h4>Inventario</h4><p>Ver productos y stock</p></div>
            </a>
            <a class="acceso-card" href="creditos.php">
                <div class="acceso-icon icon-creditos">ðŸ’³</div>
                <div class="acceso-info"><h4>CrÃ©ditos</h4><p>Ver saldos pendientes</p></div>
            </a>
            <a class="acceso-card" href="../inventario/transferencias.php">
                <div class="acceso-icon icon-inv">ðŸ”„</div>
                <div class="acceso-info"><h4>Transferencias</h4><p>Stock entre sucursales</p></div>
            </a>
        </div>

        <!-- Ãšltimas ventas -->
        <div class="tabla">
            <div class="tabla-header">
                <span>Ãšltimas ventas del turno</span>
                <a href="historialVentas.php">Ver todas</a>
            </div>
            <?php if (count($ultimasVentas) > 0): ?>
                <?php foreach ($ultimasVentas as $v): ?>
                <div class="tabla-row">
                    <span style="color:#aaa;font-size:12px;">#<?= $v['venta_id'] ?></span>
                    <span><?= htmlspecialchars($v['cliente'] ?? 'PÃºblico general') ?></span>
                    <span class="badge badge-<?= strtolower($v['metodo_pago']) ?>"><?= $v['metodo_pago'] ?></span>
                    <span style="font-weight:600;">$<?= number_format($v['total'],2) ?></span>
                    <span style="color:#aaa;font-size:12px;"><?= date('H:i', strtotime($v['created_at'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="tabla-row"><span style="color:#aaa;">No hay ventas en este turno.</span></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>

