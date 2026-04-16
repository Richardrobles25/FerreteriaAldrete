<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

// Estado de caja actual
$stmt = $pdo->prepare("SELECT * FROM cajas WHERE usuario_id = ? AND estado = 'Abierta' LIMIT 1");
$stmt->execute([$_SESSION['usuario_id']]);
$cajaActual = $stmt->fetch(PDO::FETCH_ASSOC);

// Estadísticas del turno actual
$ventasHoy = 0;
$totalHoy  = 0;
$pendientes = 0;
$creditosActivos = 0;

if ($cajaActual) {
    $stmtV = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total),0) FROM ventas WHERE caja_id = ? AND estado = 'Completada'");
    $stmtV->execute([$cajaActual['caja_id']]);
    [$ventasHoy, $totalHoy] = $stmtV->fetch(PDO::FETCH_NUM);

    $stmtP = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE caja_id = ? AND estado = 'Pendiente'");
    $stmtP->execute([$cajaActual['caja_id']]);
    $pendientes = $stmtP->fetchColumn();
}

$stmtC = $pdo->prepare("
    SELECT COUNT(*) 
    FROM creditos c
    JOIN ventas v ON c.venta_id = v.venta_id
    JOIN cajas ca ON v.caja_id = ca.caja_id
    WHERE ca.sucursal_id = ?
    AND c.estado = 'Activo'
");
$stmtC->execute([$_SESSION['sucursal_id']]);
$creditosActivos = $stmtC->fetchColumn();

// Últimas ventas
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
    <title>Cajero — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 28px; overflow-y: auto; }
    .content h1 { font-size: 20px; color: #222; margin: 0 0 20px; font-weight: 600; }
    .caja-status { border-radius: 8px; padding: 18px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
    .caja-abierta { background: #e8f5e9; border: 1px solid #c8e6c9; }
    .caja-cerrada { background: #fdecea; border: 1px solid #ffcdd2; }
    .caja-info h3 { font-size: 16px; font-weight: 700; margin: 0 0 4px; }
    .caja-abierta .caja-info h3 { color: #2e7d32; }
    .caja-cerrada .caja-info h3 { color: #c0392b; }
    .caja-info p { font-size: 12px; color: #888; margin: 0; }
    .btn-caja { padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-abrir-caja { background: #14ace7; color: white; }
    .btn-abrir-caja:hover { background: #1196cb; }
    .btn-cerrar-caja { background: #c0392b; color: white; }
    .btn-cerrar-caja:hover { background: #a93226; }
    .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
    .stat { background: white; border-radius: 8px; padding: 18px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 6px; text-transform: uppercase; letter-spacing: 0.5px; }
    .stat h3 { font-size: 24px; font-weight: 700; color: #222; margin: 0; }
    .stat small { font-size: 11px; color: #14ace7; }
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
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
</style>

<?php renderAdminSidebar('cajero_inicio'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Panel de Cajero</h2>
        </div>
        <div class="topbar-right">
<span>
   Bienvenido, <?= htmlspecialchars($_SESSION['nombre_completo']) ?>
    <span style="opacity:0.75;font-size:12px;margin-left:6px;"> <?= htmlspecialchars($nombreSucursal) ?></span>
</span>


            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="content">
        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'cajaAbierta'): ?>
                <div class="msg msg-exito">Caja abierta correctamente. ¡Buen turno!</div>
            <?php elseif ($_GET['msg'] === 'cajaCerrada'): ?>
                <div class="msg msg-exito">Caja cerrada correctamente.</div>
            <?php endif; ?>
        <?php endif; ?>

        <h1>Resumen del turno</h1>

        <!-- Estado de caja -->
        <?php if ($cajaActual): ?>
            <div class="caja-status caja-abierta">
                <div class="caja-info">
                    <h3>Turno #<?= $cajaActual['numero_turno'] ?> — Abierta</h3>
                    <p>Desde <?= date('d/m/Y H:i', strtotime($cajaActual['abierta_en'])) ?> · Monto inicial: $<?= number_format($cajaActual['monto_apertura'], 2) ?></p>
                </div>
                <a class="btn-caja btn-cerrar-caja" href="cajero_corteCaja.php">Cerrar caja</a>
            </div>
        <?php else: ?>
            <div class="caja-status caja-cerrada">
                <div class="caja-info">
                    <h3>Sin caja abierta</h3>
                    <p>Abre tu caja para comenzar a registrar ventas.</p>
                </div>
                <a class="btn-caja btn-abrir-caja" href="cajero_abrirCaja.php">Abrir caja</a>
            </div>
        <?php endif; ?>

        <!-- Estadísticas -->
        <div class="stats">
            <div class="stat">
                <p>Ventas del turno</p>
                <h3><?= $ventasHoy ?></h3>
                <small>Completadas</small>
            </div>
            <div class="stat">
                <p>Total cobrado</p>
                <h3>$<?= number_format($totalHoy, 2) ?></h3>
                <small>En este turno</small>
            </div>
            <div class="stat">
                <p>Pendientes</p>
                <h3><?= $pendientes ?></h3>
                <small>Por entregar</small>
            </div>
            <div class="stat">
                <p>Créditos activos</p>
                <h3><?= $creditosActivos ?></h3>
                <small>Por pagar</small>
            </div>
        </div>

        <!-- Últimas ventas -->
        <div class="tabla">
            <div class="tabla-header">
                <span>Últimas ventas del turno</span>
                <a href="cajero_historialVentas.php">Ver todas</a>
            </div>
            <?php if (count($ultimasVentas) > 0): ?>
                <?php foreach ($ultimasVentas as $v): ?>
                <div class="tabla-row">
                    <span style="color:#aaa;font-size:12px;">#<?= $v['venta_id'] ?></span>
                    <span><?= htmlspecialchars($v['cliente'] ?? 'Público general') ?></span>
                    <span class="badge badge-<?= strtolower($v['metodo_pago']) ?>"><?= $v['metodo_pago'] ?></span>
                    <span style="font-weight:600;">$<?= number_format($v['total'], 2) ?></span>
                    <span style="color:#aaa;font-size:12px;"><?= date('H:i', strtotime($v['created_at'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="tabla-row"><span style="color:#aaa;">No hay ventas en este turno aún.</span></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}
</script>
</body>
</html>


