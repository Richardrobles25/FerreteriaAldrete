<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);

// Ventas de hoy - todas las sucursales
$stmtHoy = $pdo->query("
    SELECT
        COUNT(*) AS ventas_hoy,
        COALESCE(SUM(v.total),0) AS total_hoy,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Efectivo' THEN v.total ELSE 0 END),0) AS ef_hoy,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Terminal' THEN v.total ELSE 0 END),0) AS term_hoy
    FROM ventas v
    WHERE v.estado = 'Completada' AND DATE(v.created_at) = CURDATE()
");
$hoy = $stmtHoy->fetch(PDO::FETCH_ASSOC);

// Ventas por sucursal hoy
$stmtSucHoy = $pdo->query("
    SELECT s.nombre, COUNT(v.venta_id) AS num_ventas, COALESCE(SUM(v.total),0) AS total
    FROM ventas v
    JOIN cajas ca ON v.caja_id = ca.caja_id
    JOIN sucursales s ON ca.sucursal_id = s.sucursal_id
    WHERE v.estado = 'Completada' AND DATE(v.created_at) = CURDATE()
    GROUP BY ca.sucursal_id
    ORDER BY total DESC
");
$ventasSucursal = $stmtSucHoy->fetchAll(PDO::FETCH_ASSOC);

// Cajas abiertas ahora
$stmtCajas = $pdo->query("
    SELECT c.*, u.nombre_completo, s.nombre AS sucursal,
           COUNT(v.venta_id) AS ventas_turno,
           COALESCE(SUM(v.total),0) AS total_turno
    FROM cajas c
    JOIN usuarios u ON c.usuario_id = u.usuario_id
    JOIN sucursales s ON c.sucursal_id = s.sucursal_id
    LEFT JOIN ventas v ON c.caja_id = v.caja_id AND v.estado = 'Completada'
    WHERE c.estado = 'Abierta'
    GROUP BY c.caja_id
    ORDER BY c.abierta_en ASC
");
$cajasAbiertas = $stmtCajas->fetchAll(PDO::FETCH_ASSOC);

// Stock bajo global
$stmtStock = $pdo->query("
    SELECT p.nombre_producto, p.stock_actual, p.stock_minimo, s.nombre AS sucursal
    FROM productos p
    JOIN sucursales s ON p.sucursal_id = s.sucursal_id
    WHERE p.activo = 1 AND p.stock_actual <= p.stock_minimo
    ORDER BY (p.stock_actual / NULLIF(p.stock_minimo,0)) ASC
    LIMIT 8
");
$stockBajo = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

// Créditos vencidos
$stmtCred = $pdo->query("
    SELECT COUNT(*) AS vencidos,
           COALESCE(SUM(saldo_pendiente),0) AS monto_vencido
    FROM creditos WHERE estado = 'Vencido'
");
$creditosVencidos = $stmtCred->fetch(PDO::FETCH_ASSOC);

// Créditos activos totales
$stmtCredAct = $pdo->query("SELECT COUNT(*), COALESCE(SUM(saldo_pendiente),0) FROM creditos WHERE estado='Activo'");
[$credActivos, $montoActivo] = $stmtCredAct->fetch(PDO::FETCH_NUM);

// Transferencias pendientes globales
$stmtTransf = $pdo->query("SELECT COUNT(*) FROM transferencias WHERE estado = 'Pendiente'");
$transfPend = $stmtTransf->fetchColumn();

// Ultimas ventas globales
$stmtUltVentas = $pdo->query("
    SELECT v.venta_id, v.total, v.metodo_pago, v.created_at,
           cl.nombre_completo AS cliente, s.nombre AS sucursal, u.nombre_completo AS cajero
    FROM ventas v
    JOIN cajas ca ON v.caja_id = ca.caja_id
    JOIN sucursales s ON ca.sucursal_id = s.sucursal_id
    JOIN usuarios u ON v.usuario_id = u.usuario_id
    LEFT JOIN clientes cl ON v.cliente_id = cl.cliente_id
    WHERE v.estado = 'Completada'
    ORDER BY v.created_at DESC
    LIMIT 8
");
$ultimasVentas = $stmtUltVentas->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Ferretería Aldrete</title>
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
    .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px,1fr)); gap: 14px; margin-bottom: 20px; }
    .stat { background: white; border-radius: 8px; padding: 16px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 6px; text-transform: uppercase; }
    .stat h3 { font-size: 22px; font-weight: 700; color: #222; margin: 0; }
    .stat small { font-size: 11px; color: #14ace7; }
    .alertas { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
    .alerta { border-radius: 8px; padding: 14px 16px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
    .alerta-roja { background: #fdecea; border: 1px solid #ffcdd2; color: #c0392b; }
    .alerta-azul { background: #e3f2fd; border: 1px solid #bbdefb; color: #1565c0; }
    .alerta-amarilla { background: #fff8e1; border: 1px solid #90caf9; color: #1565c0; }
    .alerta a { font-weight: 700; text-decoration: none; color: inherit; }
    .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 20px; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
    .tabla { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    .tabla-header { padding: 14px 18px; border-bottom: 0.5px solid #eee; font-size: 14px; font-weight: 600; color: #333; display: flex; justify-content: space-between; align-items: center; }
    .tabla-header a { font-size: 12px; color: #14ace7; text-decoration: none; font-weight: 400; }
    .tabla-row { padding: 10px 18px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; color: #555; display: flex; justify-content: space-between; align-items: center; }
    .tabla-row:last-child { border-bottom: none; }
    .caja-activa { padding: 10px 18px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; }
    .caja-activa:last-child { border-bottom: none; }
    .caja-nombre { font-weight: 600; color: #333; }
    .caja-sub { font-size: 11px; color: #888; margin-top: 1px; }
    .caja-monto { text-align: right; }
    .caja-monto strong { font-size: 14px; font-weight: 700; color: #2e7d32; }
    .caja-monto span { font-size: 11px; color: #aaa; display: block; }
    .badge { display: inline-block; padding: 2px 7px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-ef { background: #e8f5e9; color: #2e7d32; }
    .badge-term { background: #e3f2fd; color: #1565c0; }
    .badge-mix { background: #f3e5f5; color: #6a1b9a; }
    .badge-cred { background: #e3f2fd; color: #1565c0; }
    .stock-bajo-item { padding: 9px 18px; border-bottom: 0.5px solid #f5f5f5; display: flex; justify-content: space-between; font-size: 12px; }
    .stock-bajo-item:last-child { border-bottom: none; }
    .sin-datos { padding: 20px; text-align: center; color: #aaa; font-size: 13px; }
    .sucursal-row { padding: 10px 18px; border-bottom: 0.5px solid #f5f5f5; display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
    .sucursal-row:last-child { border-bottom: none; }
</style>

<?php renderAdminSidebar('inicio'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>CHINGA TU MADRE FERNANDA</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">- <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Stats globales de hoy -->
        <div class="stats">
            <div class="stat">
                <p>Ventas hoy</p>
                <h3><?= $hoy['ventas_hoy'] ?></h3>
                <small>Todas las sucursales</small>
            </div>
            <div class="stat">
                <p>Cobrado hoy</p>
                <h3 style="font-size:18px;">$<?= number_format($hoy['total_hoy'],0) ?></h3>
                <small>Completadas</small>
            </div>
            <div class="stat">
                <p>Efectivo hoy</p>
                <h3 style="font-size:18px;">$<?= number_format($hoy['ef_hoy'],0) ?></h3>
            </div>
            <div class="stat">
                <p>Terminal hoy</p>
                <h3 style="font-size:18px;">$<?= number_format($hoy['term_hoy'],0) ?></h3>
            </div>
            <div class="stat">
                <p>Cajas abiertas</p>
                <h3 style="color:<?= count($cajasAbiertas)>0?'#0899d3':'#94a3b8' ?>;"><?= count($cajasAbiertas) ?></h3>
                <small>En este momento</small>
            </div>
            <div class="stat">
                <p>Créditos activos</p>
                <h3><?= $credActivos ?></h3>
                <small>$<?= number_format($montoActivo,0) ?> pendiente</small>
            </div>
        </div>

        <!-- Alertas -->
        <?php if (count($stockBajo) > 0 || $creditosVencidos['vencidos'] > 0 || $transfPend > 0): ?>
        <div class="alertas">
            <?php if (count($stockBajo) > 0): ?>
            <div class="alerta alerta-roja">
                <span>⚠ <strong><?= count($stockBajo) ?></strong> producto(s) con stock bajo</span>
                <a href="../inventario/productos.php?stock_bajo=1">Ver</a>
            </div>
            <?php endif; ?>
            <?php if ($creditosVencidos['vencidos'] > 0): ?>
            <div class="alerta alerta-amarilla">
                <span>💳 <strong><?= $creditosVencidos['vencidos'] ?></strong> crédito(s) vencido(s) - $<?= number_format($creditosVencidos['monto_vencido'],0) ?></span>
                <a href="creditos.php?estado=Vencido">Ver</a>
            </div>
            <?php endif; ?>
            <?php if ($transfPend > 0): ?>
            <div class="alerta alerta-azul">
                <span>📦 <strong><?= $transfPend ?></strong> transferencia(s) pendiente(s) de aprobar</span>
                <a href="../inventario/transferencias.php">Ver</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="grid-2">
            <!-- Ventas por sucursal hoy -->
            <div class="tabla">
                <div class="tabla-header">
                    <span>Ventas por sucursal - hoy</span>
                    <a href="reporteVentas.php">Ver reporte</a>
                </div>
                <?php if (count($ventasSucursal) > 0): ?>
                    <?php foreach ($ventasSucursal as $vs): ?>
                    <div class="sucursal-row">
                        <div>
                            <div style="font-weight:600;"><?= htmlspecialchars($vs['nombre']) ?></div>
                            <div style="font-size:11px;color:#aaa;"><?= $vs['num_ventas'] ?> ventas</div>
                        </div>
                        <strong style="color:#0899d3;">$<?= number_format($vs['total'],2) ?></strong>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="sin-datos">Sin ventas hoy.</div>
                <?php endif; ?>
            </div>

            <!-- Cajas abiertas ahora -->
            <div class="tabla">
                <div class="tabla-header">
                    <span>Cajas abiertas ahora</span>
                    <a href="cortes.php">Ver cortes</a>
                </div>
                <?php if (count($cajasAbiertas) > 0): ?>
                    <?php foreach ($cajasAbiertas as $ca): ?>
                    <div class="caja-activa">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                            <div>
                                <div class="caja-nombre"><?= htmlspecialchars($ca['nombre_completo']) ?></div>
                                <div class="caja-sub">
                                    <?= htmlspecialchars($ca['sucursal']) ?> - Turno #<?= $ca['numero_turno'] ?> - desde <?= date('H:i', strtotime($ca['abierta_en'])) ?>
                                </div>
                            </div>
                            <div class="caja-monto">
                                <strong>$<?= number_format($ca['total_turno'],2) ?></strong>
                                <span><?= $ca['ventas_turno'] ?> ventas</span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="sin-datos">No hay cajas abiertas en este momento.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid-2">
            <!-- Ultimas ventas globales -->
            <div class="tabla">
                <div class="tabla-header">
                    <span>Ultimas ventas</span>
                    <a href="reporteVentas.php">Ver todas</a>
                </div>
                <?php if (count($ultimasVentas) > 0): ?>
                    <?php foreach ($ultimasVentas as $v): ?>
                    <div class="tabla-row">
                        <div>
                            <div style="font-size:13px;"><?= htmlspecialchars($v['cliente']??'Público general') ?></div>
                            <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($v['sucursal']) ?> - <?= date('H:i', strtotime($v['created_at'])) ?></div>
                        </div>
                        <div style="text-align:right;">
                            <span class="badge badge-<?= strtolower(substr($v['metodo_pago'],0,2))==='ef'?'ef':(strtolower(substr($v['metodo_pago'],0,2))==='te'?'term':(strtolower(substr($v['metodo_pago'],0,2))==='mi'?'mix':'cred')) ?>"><?= $v['metodo_pago'] ?></span>
                            <div style="font-weight:700;font-size:13px;margin-top:2px;">$<?= number_format($v['total'],2) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="sin-datos">Sin ventas hoy.</div>
                <?php endif; ?>
            </div>

            <!-- Stock bajo global -->
            <div class="tabla">
                <div class="tabla-header">
                    <span>Stock bajo - todas las sucursales</span>
                    <a href="../inventario/productos.php?stock_bajo=1">Ver todo</a>
                </div>
                <?php if (count($stockBajo) > 0): ?>
                    <?php foreach ($stockBajo as $p): ?>
                    <div class="stock-bajo-item">
                        <div>
                            <div style="font-weight:500;color:#333;"><?= htmlspecialchars($p['nombre_producto']) ?></div>
                            <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($p['sucursal']) ?></div>
                        </div>
                        <div style="text-align:right;">
                            <span style="color:#c0392b;font-weight:700;"><?= number_format($p['stock_actual'],2) ?></span>
                            <span style="color:#aaa;font-size:11px;"> / min <?= number_format($p['stock_minimo'],2) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="sin-datos" style="color:#0899d3;">Todo el inventario en buen estado</div>
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



