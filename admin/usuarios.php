<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);

// Toggle activo
if (isset($_GET['toggle'])) {
    $pdo->prepare("UPDATE usuarios SET activo = NOT activo WHERE usuario_id = ?")->execute([intval($_GET['toggle'])]);
    header('Location: usuarios.php'); exit();
}

$busqueda = trim($_GET['buscar'] ?? '');
$filtroRol = $_GET['rol'] ?? '';
$filtroSuc = intval($_GET['sucursal'] ?? 0);
$mostrarInactivos = isset($_GET['inactivos']);

$where  = "WHERE 1=1";
$params = [];
if (!$mostrarInactivos) { $where .= " AND u.activo = 1"; }
if ($busqueda)   { $where .= " AND (u.nombre_completo LIKE ? OR u.nombre_usuario LIKE ?)"; $params[] = '%'.$busqueda.'%'; $params[] = '%'.$busqueda.'%'; }
if ($filtroRol)  { $where .= " AND u.rol = ?"; $params[] = $filtroRol; }
if ($filtroSuc)  { $where .= " AND u.sucursal_id = ?"; $params[] = $filtroSuc; }

$stmt = $pdo->prepare("
    SELECT u.*, s.nombre AS nombre_sucursal
    FROM usuarios u
    JOIN sucursales s ON u.sucursal_id = s.sucursal_id
    $where
    ORDER BY u.activo DESC, u.nombre_completo ASC
");
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sucursales = $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Totales
$stmtTot = $pdo->query("SELECT COUNT(*) AS total, SUM(activo) AS activos FROM usuarios WHERE rol != 'Administrador'");
$totales = $stmtTot->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios â€” FerreterÃ­a Aldrete</title>
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
    .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
    .content-header h1 { font-size: 20px; color: #222; font-weight: 600; }
    .btn-nuevo { background: #14ace7; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-nuevo:hover { background: #1196cb; }
    .stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-bottom: 16px; }
    .stat { background: white; border-radius: 8px; padding: 14px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 20px; font-weight: 700; color: #222; margin: 0; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 13px; margin-bottom: 14px; display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 4px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; display: inline-block; }
    .btn-inactivos { background: #f0f0f0; color: #666; border: none; padding: 9px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .btn-inactivos.activo { background: #333; color: white; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    tr.inactivo td { opacity: 0.5; }
    .badge-rol { display: inline-block; padding: 3px 9px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .rol-admin { background: #e3f2fd; color: #1565c0; }
    .rol-inventario { background: #e8f5e9; color: #2e7d32; }
    .rol-cajero { background: #e3f2fd; color: #1565c0; }
    .rol-mixto { background: #f3e5f5; color: #6a1b9a; }
    .acciones { display: flex; gap: 5px; flex-wrap: wrap; }
    .btn-accion { padding: 5px 11px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #e3f2fd; color: #1565c0; }
    .btn-editar:hover { background: #bbdefb; }
    .btn-activar { background: #e8f5e9; color: #2e7d32; }
    .btn-desactivar { background: #fff8e1; color: #1565c0; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
</style>

<?php renderAdminSidebar('usuarios'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Usuarios</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">â€” <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesiÃ³n</button></form>
        </div>
    </div>

    <div class="content">
        <div class="content-header">
            <h1>GestiÃ³n de usuarios</h1>
            <a class="btn-nuevo" href="formUsuario.php">+ Nuevo usuario</a>
        </div>

        <div class="stats">
            <div class="stat"><p>Total usuarios</p><h3><?= $totales['total'] ?></h3></div>
            <div class="stat"><p>Activos</p><h3><?= $totales['activos'] ?></h3></div>
            <div class="stat"><p>Sucursales</p><h3><?= count($sucursales) ?></h3></div>
        </div>

        <form method="GET">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Buscar</label>
                    <input type="text" name="buscar" placeholder="Nombre o usuario..." value="<?= htmlspecialchars($busqueda) ?>" style="width:160px;">
                </div>
                <div class="filtro-group">
                    <label>Rol</label>
                    <select name="rol">
                        <option value="">Todos</option>
                        <option value="Inventario" <?= $filtroRol==='Inventario'?'selected':'' ?>>Inventario</option>
                        <option value="Cajero" <?= $filtroRol==='Cajero'?'selected':'' ?>>Cajero</option>
                        <option value="Inventario/Cajero" <?= $filtroRol==='Inventario/Cajero'?'selected':'' ?>>Inventario/Cajero</option>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Sucursal</label>
                    <select name="sucursal">
                        <option value="0">Todas</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= $s['sucursal_id'] ?>" <?= $filtroSuc===$s['sucursal_id']?'selected':'' ?>><?= htmlspecialchars($s['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($mostrarInactivos): ?><input type="hidden" name="inactivos" value="1"><?php endif; ?>
                <button class="btn-filtrar" type="submit">Filtrar</button>
                <?php if ($busqueda||$filtroRol||$filtroSuc): ?><a class="btn-limpiar" href="usuarios.php">Limpiar</a><?php endif; ?>
                <a class="btn-inactivos <?= $mostrarInactivos?'activo':'' ?>" href="usuarios.php?<?= $mostrarInactivos?'':'inactivos=1' ?>">
                    <?= $mostrarInactivos?'Ocultar inactivos':'Ver inactivos' ?>
                </a>
            </div>
        </form>

        <div class="tabla-wrapper">
            <?php if (count($usuarios) > 0): ?>
            <table>
                <thead>
                    <tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Sucursal</th><th>Estado</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                    <tr class="<?= !$u['activo']?'inactivo':'' ?>">
                        <td>
                            <strong><?= htmlspecialchars($u['nombre_completo']) ?></strong>
                            <?php if ($u['telefono']): ?><div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($u['telefono']) ?></div><?php endif; ?>
                        </td>
                        <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($u['nombre_usuario']) ?></td>
                        <td>
                            <?php
                            $rolClass = match($u['rol']) {
                                'Administrador'    => 'rol-admin',
                                'Inventario'       => 'rol-inventario',
                                'Cajero'           => 'rol-cajero',
                                'Inventario/Cajero'=> 'rol-mixto',
                                default            => ''
                            };
                            ?>
                            <span class="badge-rol <?= $rolClass ?>"><?= $u['rol'] ?></span>
                        </td>
                        <td><?= htmlspecialchars($u['nombre_sucursal']) ?></td>
                        <td>
                            <span style="font-size:12px;color:<?= $u['activo']?'#2e7d32':'#c0392b' ?>;font-weight:600;">
                                <?= $u['activo']?'Activo':'Inactivo' ?>
                            </span>
                        </td>
                        <td>
                            <div class="acciones">
                                <a class="btn-accion btn-editar" href="formUsuario.php?id=<?= $u['usuario_id'] ?>">Editar</a>
                                <?php if ($u['rol'] !== 'Administrador'): ?>
                                <a class="btn-accion <?= $u['activo']?'btn-desactivar':'btn-activar' ?>"
                                   href="usuarios.php?toggle=<?= $u['usuario_id'] ?>"
                                   onclick="return confirm('Â¿Cambiar estado del usuario?')">
                                    <?= $u['activo']?'Desactivar':'Activar' ?>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="sin-resultados">No se encontraron usuarios.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>


