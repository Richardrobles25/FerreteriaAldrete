<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);

if (isset($_GET['eliminar'])) {
    $sid = intval($_GET['eliminar']);

    // Bloquear si hay usuarios activos
    $stmtU = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE sucursal_id = ? AND activo = 1");
    $stmtU->execute([$sid]);
    if ($stmtU->fetchColumn() > 0) {
        header('Location: sucursales.php?error=con_usuarios'); exit();
    }

    // Bloquear si hay productos con stock
    $stmtChk = $pdo->prepare("
        SELECT COUNT(*) FROM stock_sucursal ss
        JOIN productos p ON p.producto_id = ss.producto_id AND p.activo = 1
        WHERE ss.sucursal_id = ? AND ss.activo = 1 AND ss.stock_actual > 0
    ");
    $stmtChk->execute([$sid]);
    if ($stmtChk->fetchColumn() > 0) {
        header('Location: sucursales.php?error=con_stock'); exit();
    }

    try {
        $pdo->beginTransaction();
        // abonos → creditos → ventas → cajas
        $pdo->prepare("DELETE a FROM abonos a JOIN creditos cr ON a.credito_id = cr.credito_id JOIN ventas v ON cr.venta_id = v.venta_id JOIN cajas c ON v.caja_id = c.caja_id WHERE c.sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE cr FROM creditos cr JOIN ventas v ON cr.venta_id = v.venta_id JOIN cajas c ON v.caja_id = c.caja_id WHERE c.sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE d FROM devoluciones d JOIN ventas v ON d.venta_id = v.venta_id JOIN cajas c ON v.caja_id = c.caja_id WHERE c.sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE vp FROM venta_productos vp JOIN ventas v ON vp.venta_id = v.venta_id JOIN cajas c ON v.caja_id = c.caja_id WHERE c.sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE v FROM ventas v JOIN cajas c ON v.caja_id = c.caja_id WHERE c.sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM movimientos_caja WHERE sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM cajas WHERE sucursal_id = ?")->execute([$sid]);
        // movimientos_inventario ahora tiene sucursal_id propio — se borran directamente
        $pdo->prepare("DELETE FROM movimientos_inventario WHERE sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM compras_proveedor WHERE sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM transferencias WHERE sucursal_origen_id = ? OR sucursal_destino_id = ?")->execute([$sid, $sid]);
        $pdo->prepare("DELETE FROM stock_sucursal WHERE sucursal_id = ?")->execute([$sid]);
        // Usuarios inactivos se pueden borrar ya que sus movimientos fueron eliminados arriba
        $pdo->prepare("DELETE FROM usuarios WHERE sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM sucursales WHERE sucursal_id = ?")->execute([$sid]);
        $pdo->commit();
        header('Location: sucursales.php?msg=eliminado'); exit();
    } catch (\PDOException $e) {
        $pdo->rollBack();
        header('Location: sucursales.php?error=con_registros&detail=' . urlencode($e->getMessage())); exit();
    }
}

$stmt = $pdo->query("
    SELECT s.*,
        (SELECT COUNT(*) FROM usuarios u WHERE u.sucursal_id = s.sucursal_id AND u.activo = 1) AS total_usuarios,
        (SELECT COUNT(*) FROM stock_sucursal ss
         JOIN productos p ON p.producto_id = ss.producto_id AND p.activo = 1
         WHERE ss.sucursal_id = s.sucursal_id AND ss.activo = 1 AND ss.stock_actual > 0) AS con_stock
    FROM sucursales s
    ORDER BY s.nombre ASC
");
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sucursales — Ferretería Aldrete</title>
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
    .btn-nuevo { background: #14ace7; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-nuevo:hover { background: #1196cb; }
    .sucursales-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px,1fr)); gap: 16px; }
    .suc-card { background: white; border-radius: 10px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .suc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
    .suc-nombre { font-size: 16px; font-weight: 700; color: #222; margin: 0 0 4px; }
    .suc-rfc { font-size: 12px; color: #aaa; font-family: monospace; }
    .suc-info { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
    .suc-dato { font-size: 12px; color: #555; }
    .suc-dato span { display: block; font-size: 10px; color: #aaa; text-transform: uppercase; margin-bottom: 2px; }
    .suc-stats { display: flex; gap: 14px; padding: 12px 0; border-top: 0.5px solid #f5f5f5; border-bottom: 0.5px solid #f5f5f5; margin-bottom: 14px; }
    .suc-stat { text-align: center; flex: 1; }
    .suc-stat strong { font-size: 18px; font-weight: 700; color: #222; display: block; }
    .suc-stat span { font-size: 11px; color: #aaa; }
    .suc-acciones { display: flex; gap: 8px; }
    .btn-accion { padding: 7px 14px; border-radius: 6px; font-size: 13px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #e3f2fd; color: #1565c0; flex: 1; text-align: center; }
    .btn-editar:hover { background: #bbdefb; }
    .btn-eliminar { background: #fdecea; color: #c0392b; }
    .btn-eliminar:hover { background: #ffcdd2; }
    .btn-eliminar-disabled { background: #f5f5f5; color: #bbb; cursor: not-allowed; }
    .ticket-preview { background: #f9f9f9; border-radius: 6px; padding: 10px 12px; font-size: 12px; color: #666; margin-bottom: 14px; font-family: monospace; line-height: 1.6; max-height: 80px; overflow: hidden; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; }
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

<?php renderAdminSidebar('sucursales'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Sucursales</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <div class="content-header">
            <h1>Sucursales</h1>
            <a class="btn-nuevo" href="formSucursal.php">+ Nueva sucursal</a>
        </div>

        <?php if (isset($_GET['msg'])): ?>
            <?php $msgs = ['creado' => 'Sucursal creada.', 'editado' => 'Sucursal actualizada.', 'eliminado' => 'Sucursal eliminada correctamente.']; ?>
            <div style="background:#e8f5e9;color:#2e7d32;padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:16px;border-left:3px solid #2e7d32;">
                <?= htmlspecialchars($msgs[$_GET['msg']] ?? '') ?>
            </div>
        <?php endif; ?>
        <?php
            $errMsgs = [
                'con_stock'    => 'No se puede eliminar la sucursal porque aún tiene productos con stock. Retira o transfiere el stock primero.',
                'con_usuarios' => 'No se puede eliminar la sucursal porque tiene usuarios activos asignados. Desactívalos primero.',
                'con_registros'=> 'Ocurrió un error al eliminar la sucursal. Intenta de nuevo.',
            ];
        ?>
        <?php if (isset($_GET['error']) && isset($errMsgs[$_GET['error']])): ?>
            <div style="background:#fdecea;color:#c0392b;padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:16px;border-left:3px solid #c0392b;">
                <?= $errMsgs[$_GET['error']] ?>
                <?php if (isset($_GET['detail'])): ?>
                    <div style="margin-top:6px;font-size:11px;opacity:.8;font-family:monospace;"><?= htmlspecialchars($_GET['detail']) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (count($sucursales) > 0): ?>
        <div class="sucursales-grid">
            <?php foreach ($sucursales as $s): ?>
            <div class="suc-card">
                <div class="suc-header">
                    <div>
                        <div class="suc-nombre"><?= htmlspecialchars($s['nombre']) ?></div>
                        <?php if ($s['rfc']): ?><div class="suc-rfc"><?= htmlspecialchars($s['rfc']) ?></div><?php endif; ?>
                    </div>
                </div>

                <div class="suc-info">
                    <div class="suc-dato"><span>Dirección</span><?= htmlspecialchars($s['direccion']??'—') ?></div>
                    <div class="suc-dato"><span>Teléfono</span><?= htmlspecialchars($s['telefono']??'—') ?></div>
                </div>

                <?php if ($s['datos_ticket']): ?>
                <div class="ticket-preview"><?= htmlspecialchars($s['datos_ticket']) ?></div>
                <?php endif; ?>

                <div class="suc-stats">
                    <div class="suc-stat"><strong><?= $s['total_usuarios'] ?></strong><span>Usuarios</span></div>
                    <div class="suc-stat"><strong><?= intval($s['con_stock']) ?></strong><span>Productos en stock</span></div>
                </div>

                <div class="suc-acciones">
                    <a class="btn-accion btn-editar" href="formSucursal.php?id=<?= $s['sucursal_id'] ?>">Editar datos</a>
                    <?php if (intval($s['total_usuarios']) > 0): ?>
                        <span class="btn-accion btn-eliminar-disabled" title="No se puede eliminar: tiene usuarios activos">Eliminar</span>
                    <?php elseif (intval($s['con_stock']) > 0): ?>
                        <span class="btn-accion btn-eliminar-disabled" title="No se puede eliminar: tiene <?= intval($s['con_stock']) ?> producto(s) en stock">Eliminar</span>
                    <?php else: ?>
                        <a class="btn-accion btn-eliminar"
                           href="sucursales.php?eliminar=<?= $s['sucursal_id'] ?>"
                           onclick="return confirm('¿Eliminar la sucursal <?= htmlspecialchars($s['nombre'], ENT_QUOTES) ?>? Se eliminaran todos sus datos. Esta accion no se puede deshacer.')">Eliminar</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="sin-resultados">No hay sucursales registradas.</div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>


