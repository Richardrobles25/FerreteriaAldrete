<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador']);

if (isset($_GET['toggle'])) {
    $pdo->prepare("UPDATE sucursales SET activo = NOT activo WHERE sucursal_id = ?")->execute([intval($_GET['toggle'])]);
    header('Location: sucursales.php'); exit();
}

$stmt = $pdo->query("
    SELECT s.*,
        COUNT(DISTINCT u.usuario_id) AS total_usuarios,
        COUNT(DISTINCT p.producto_id) AS total_productos
    FROM sucursales s
    LEFT JOIN usuarios u ON s.sucursal_id = u.sucursal_id AND u.activo = 1
    LEFT JOIN productos p ON s.sucursal_id = p.sucursal_id AND p.activo = 1
    GROUP BY s.sucursal_id
    ORDER BY s.activo DESC, s.nombre ASC
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
    .sidebar-header h3 { font-size: 14px; font-weight: 700; color: #ff8c00; margin: 0; }
    .sidebar-header p { font-size: 11px; color: #999; margin: 4px 0 0; }
    .sidebar-menu { flex: 1; padding: 8px 0; overflow-y: auto; }
    .menu-item { display: block; padding: 10px 16px; font-size: 13px; color: #555; cursor: pointer; border-left: 3px solid transparent; text-decoration: none; transition: all 0.15s; white-space: nowrap; }
    .menu-item:hover { background: #fff5e6; color: #ff8c00; }
    .menu-item.active { background: #fff5e6; border-left-color: #ff8c00; color: #ff8c00; font-weight: 600; }
    .divider { height: 1px; background: #f0f0f0; margin: 6px 8px; }
    .sidebar-footer { padding: 12px 16px; border-top: 1px solid #f0f0f0; font-size: 11px; color: #bbb; white-space: nowrap; }
    .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #f7f7f7; }
    .topbar { background: #ff8c00; color: white; padding: 0 20px; height: 52px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
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
    .btn-nuevo { background: #ff8c00; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-nuevo:hover { background: #e07800; }
    .sucursales-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px,1fr)); gap: 16px; }
    .suc-card { background: white; border-radius: 10px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .suc-card.inactiva { opacity: 0.6; }
    .suc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
    .suc-nombre { font-size: 16px; font-weight: 700; color: #222; margin: 0 0 4px; }
    .suc-rfc { font-size: 12px; color: #aaa; font-family: monospace; }
    .badge-activa { background: #e8f5e9; color: #2e7d32; font-size: 11px; padding: 3px 10px; border-radius: 99px; font-weight: 600; }
    .badge-inactiva { background: #f0f0f0; color: #999; font-size: 11px; padding: 3px 10px; border-radius: 99px; font-weight: 600; }
    .suc-info { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
    .suc-dato { font-size: 12px; color: #555; }
    .suc-dato span { display: block; font-size: 10px; color: #aaa; text-transform: uppercase; margin-bottom: 2px; }
    .suc-stats { display: flex; gap: 14px; padding: 12px 0; border-top: 0.5px solid #f5f5f5; border-bottom: 0.5px solid #f5f5f5; margin-bottom: 14px; }
    .suc-stat { text-align: center; flex: 1; }
    .suc-stat strong { font-size: 18px; font-weight: 700; color: #222; display: block; }
    .suc-stat span { font-size: 11px; color: #aaa; }
    .suc-acciones { display: flex; gap: 8px; }
    .btn-accion { padding: 7px 14px; border-radius: 6px; font-size: 13px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #fff3e0; color: #e65c00; flex: 1; text-align: center; }
    .btn-editar:hover { background: #ffe0b2; }
    .btn-activar { background: #e8f5e9; color: #2e7d32; }
    .btn-desactivar { background: #fff8e1; color: #f57f17; }
    .ticket-preview { background: #f9f9f9; border-radius: 6px; padding: 10px 12px; font-size: 12px; color: #666; margin-bottom: 14px; font-family: monospace; line-height: 1.6; max-height: 80px; overflow: hidden; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>Ferretería Aldrete</h3>
        <p>Administrador</p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item" href="inicioAdmin.php">Inicio</a>
        <a class="menu-item" href="usuarios.php">Usuarios</a>
        <a class="menu-item active" href="sucursales.php">Sucursales</a>
        <div class="divider"></div>
        <a class="menu-item" href="reporteVentas.php">Ventas</a>
        <a class="menu-item" href="reporteProductos.php">Productos más vendidos</a>
        <a class="menu-item" href="historial.php">Historial de movimientos</a>
        <a class="menu-item" href="cortes.php">Cortes de caja</a>
        <div class="divider"></div>
        <a class="menu-item" href="../inventario/inicioInventario.php">Inventario</a>
        <a class="menu-item" href="../cajero/inicioCajero.php">Cajero</a>
        <div class="divider"></div>
        <a class="menu-item" href="clientes.php">Clientes</a>
        <a class="menu-item" href="creditos.php">Créditos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>

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
            <?php $msgs=['creado'=>'Sucursal creada.','editado'=>'Sucursal actualizada.']; ?>
            <div style="background:#e8f5e9;color:#2e7d32;padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:16px;border-left:3px solid #2e7d32;">
                <?= $msgs[$_GET['msg']] ?? '' ?>
            </div>
        <?php endif; ?>

        <?php if (count($sucursales) > 0): ?>
        <div class="sucursales-grid">
            <?php foreach ($sucursales as $s): ?>
            <div class="suc-card <?= !$s['activo']?'inactiva':'' ?>">
                <div class="suc-header">
                    <div>
                        <div class="suc-nombre"><?= htmlspecialchars($s['nombre']) ?></div>
                        <?php if ($s['rfc']): ?><div class="suc-rfc"><?= htmlspecialchars($s['rfc']) ?></div><?php endif; ?>
                    </div>
                    <span class="<?= $s['activo']?'badge-activa':'badge-inactiva' ?>"><?= $s['activo']?'Activa':'Inactiva' ?></span>
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
                    <div class="suc-stat"><strong><?= $s['total_productos'] ?></strong><span>Productos</span></div>
                </div>

                <div class="suc-acciones">
                    <a class="btn-accion btn-editar" href="formSucursal.php?id=<?= $s['sucursal_id'] ?>">Editar datos</a>
                    <a class="btn-accion <?= $s['activo']?'btn-desactivar':'btn-activar' ?>"
                       href="sucursales.php?toggle=<?= $s['sucursal_id'] ?>"
                       onclick="return confirm('¿Cambiar estado de la sucursal?')">
                        <?= $s['activo']?'Desactivar':'Activar' ?>
                    </a>
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