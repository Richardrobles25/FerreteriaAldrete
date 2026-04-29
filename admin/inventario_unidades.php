<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);
require_once __DIR__ . '/_admin_sucursal_filtro.php';

$esAdmin = $_SESSION['rol'] === 'Administrador';

// Eliminar unidad
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $u = $pdo->prepare("SELECT nombre, sucursal_id FROM unidades_medida WHERE unidad_id = ?");
    $u->execute([$id]);
    $unidadRow = $u->fetch(PDO::FETCH_ASSOC);
    if ($unidadRow) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE unidad_medida = ? AND sucursal_id = ? AND activo = 1");
        $check->execute([$unidadRow['nombre'], $unidadRow['sucursal_id']]);
        if ($check->fetchColumn() > 0) {
            header('Location: inventario_unidades.php?msg=error_productos');
            exit();
        }
    }
    $pdo->prepare("DELETE FROM unidades_medida WHERE unidad_id = ?")->execute([$id]);
    header('Location: inventario_unidades.php?msg=eliminado');
    exit();
}

// Guardar unidad (crear o editar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre     = trim($_POST['nombre'] ?? '');
    $id         = intval($_POST['unidad_id'] ?? 0);
    $sucursalId = $esAdmin ? intval($_POST['sucursal_id'] ?? $sucursalVista) : intval($_SESSION['sucursal_id']);
    if ($sucursalId === 0 && !$esAdmin) $sucursalId = intval($_SESSION['sucursal_id']);

    if ($nombre) {
        if ($id) {
            $pdo->prepare("UPDATE unidades_medida SET nombre = ?, sucursal_id = ? WHERE unidad_id = ?")
                ->execute([$nombre, $sucursalId, $id]);
            header('Location: inventario_unidades.php?msg=editado');
        } else {
            $pdo->prepare("INSERT INTO unidades_medida (nombre, sucursal_id) VALUES (?, ?)")
                ->execute([$nombre, $sucursalId]);
            header('Location: inventario_unidades.php?msg=creado');
        }
        exit();
    }
}

$busqueda = trim($_GET['buscar'] ?? '');

// Cargar unidades según rol y filtro de sucursal
if ($esAdmin && $sucursalVista === 0) {
    if ($busqueda) {
        $stmt = $pdo->prepare("
            SELECT u.*, s.nombre AS nombre_sucursal,
                   COUNT(p.producto_id) AS total_productos
            FROM unidades_medida u
            LEFT JOIN sucursales s ON u.sucursal_id = s.sucursal_id
            LEFT JOIN productos p ON p.unidad_medida = u.nombre AND p.sucursal_id = u.sucursal_id AND p.activo = 1
            WHERE u.nombre LIKE ?
            GROUP BY u.unidad_id
            ORDER BY s.nombre ASC, u.nombre ASC
        ");
        $stmt->execute(['%'.$busqueda.'%']);
    } else {
        $stmt = $pdo->query("
            SELECT u.*, s.nombre AS nombre_sucursal,
                   COUNT(p.producto_id) AS total_productos
            FROM unidades_medida u
            LEFT JOIN sucursales s ON u.sucursal_id = s.sucursal_id
            LEFT JOIN productos p ON p.unidad_medida = u.nombre AND p.sucursal_id = u.sucursal_id AND p.activo = 1
            GROUP BY u.unidad_id
            ORDER BY s.nombre ASC, u.nombre ASC
        ");
    }
} else {
    $filtroSuc = ($esAdmin && $sucursalVista !== 0) ? $sucursalVista : intval($_SESSION['sucursal_id']);
    if ($busqueda) {
        $stmt = $pdo->prepare("
            SELECT u.*, s.nombre AS nombre_sucursal,
                   COUNT(p.producto_id) AS total_productos
            FROM unidades_medida u
            LEFT JOIN sucursales s ON u.sucursal_id = s.sucursal_id
            LEFT JOIN productos p ON p.unidad_medida = u.nombre AND p.sucursal_id = u.sucursal_id AND p.activo = 1
            WHERE u.sucursal_id = ? AND u.nombre LIKE ?
            GROUP BY u.unidad_id
            ORDER BY u.nombre ASC
        ");
        $stmt->execute([$filtroSuc, '%'.$busqueda.'%']);
    } else {
        $stmt = $pdo->prepare("
            SELECT u.*, s.nombre AS nombre_sucursal,
                   COUNT(p.producto_id) AS total_productos
            FROM unidades_medida u
            LEFT JOIN sucursales s ON u.sucursal_id = s.sucursal_id
            LEFT JOIN productos p ON p.unidad_medida = u.nombre AND p.sucursal_id = u.sucursal_id AND p.activo = 1
            WHERE u.sucursal_id = ?
            GROUP BY u.unidad_id
            ORDER BY u.nombre ASC
        ");
        $stmt->execute([$filtroSuc]);
    }
}
$unidades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unidad a editar
$editando = null;
if (isset($_GET['editar'])) {
    $stmt2 = $pdo->prepare("SELECT * FROM unidades_medida WHERE unidad_id = ?");
    $stmt2->execute([intval($_GET['editar'])]);
    $editando = $stmt2->fetch(PDO::FETCH_ASSOC);
}

// Sucursales para el select del form (solo admin)
$todasSucursales = $esAdmin
    ? $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC)
    : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unidades de medida — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 28px; overflow-y: auto; display: grid; grid-template-columns: 1fr 320px; gap: 20px; align-items: start; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 16px; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .barra-busqueda { display: flex; gap: 10px; margin-bottom: 16px; }
    .barra-busqueda input { flex: 1; padding: 9px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .barra-busqueda input:focus { outline: none; border-color: #14ace7; }
    .btn-buscar { background: #14ace7; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .msg-error { background: #fdecea; color: #c0392b; border-left: 3px solid #c0392b; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    thead { background: #f9f9f9; }
    th { padding: 11px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .acciones { display: flex; gap: 6px; }
    .btn-accion { padding: 5px 12px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #e3f2fd; color: #1565c0; }
    .btn-editar:hover { background: #bbdefb; }
    .btn-eliminar { background: #fdecea; color: #c0392b; }
    .btn-eliminar:hover { background: #ffcdd2; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
    .badge-count { background: #f0f0f0; color: #666; font-size: 11px; padding: 2px 10px; border-radius: 99px; font-weight: 600; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .form-group input:focus, .form-group select:focus { outline: none; border-color: #14ace7; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; width: 100%; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar-edit { background: white; color: #666; border: 1px solid #ddd; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; width: 100%; margin-top: 8px; text-decoration: none; display: block; text-align: center; }
    .btn-cancelar-edit:hover { background: #f5f5f5; }
    .hint { font-size: 11px; color: #aaa; margin-top: 4px; }
</style>

<?php renderAdminSidebar('inventario_unidades'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Unidades de medida</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="content">
        <!-- Lista -->
        <div>
            <div class="filtros"><?php renderSucursalSwitcher(); ?></div>

            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'creado'): ?>
                    <div class="msg msg-exito">Unidad creada correctamente.</div>
                <?php elseif ($_GET['msg'] === 'editado'): ?>
                    <div class="msg msg-exito">Unidad actualizada correctamente.</div>
                <?php elseif ($_GET['msg'] === 'eliminado'): ?>
                    <div class="msg msg-exito">Unidad eliminada correctamente.</div>
                <?php elseif ($_GET['msg'] === 'error_productos'): ?>
                    <div class="msg msg-error">No puedes eliminar esta unidad porque tiene productos asociados.</div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="GET" action="inventario_unidades.php">
                <div class="barra-busqueda">
                    <input type="text" name="buscar" placeholder="Buscar unidad..." value="<?= htmlspecialchars($busqueda) ?>" oninput="filtrarTabla(this.value)">
                    <button class="btn-buscar" type="submit">Buscar</button>
                    <?php if ($busqueda): ?>
                        <a class="btn-limpiar" href="inventario_unidades.php">Limpiar</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="card" style="padding:0;">
                <?php if (count($unidades) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <?php if ($esAdmin && $sucursalVista === 0): ?><th>Sucursal</th><?php endif; ?>
                            <th>Productos</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tablaFiltrable">
                        <?php foreach ($unidades as $u): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($u['nombre']) ?></strong></td>
                            <?php if ($esAdmin && $sucursalVista === 0): ?>
                                <td style="color:#888;"><?= htmlspecialchars($u['nombre_sucursal'] ?? '—') ?></td>
                            <?php endif; ?>
                            <td><span class="badge-count"><?= $u['total_productos'] ?> productos</span></td>
                            <td>
                                <div class="acciones">
                                    <a class="btn-accion btn-editar" href="inventario_unidades.php?editar=<?= $u['unidad_id'] ?>">Editar</a>
                                    <a class="btn-accion btn-eliminar" href="inventario_unidades.php?eliminar=<?= $u['unidad_id'] ?>" onclick="return confirm('¿Eliminar esta unidad?')">Eliminar</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay unidades registradas<?= $busqueda ? ' con esa búsqueda' : '' ?>.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario lateral -->
        <div>
            <div class="card">
                <h3><?= $editando ? 'Editar unidad' : 'Nueva unidad' ?></h3>
                <form method="POST">
                    <input type="hidden" name="unidad_id" value="<?= $editando['unidad_id'] ?? 0 ?>">

                    <div class="form-group">
                        <label>Nombre *</label>
                        <input type="text" name="nombre"
                            value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>"
                            placeholder="Ej. pieza, kg, metro, litro" autofocus>
                        <div class="hint">Este nombre aparecerá en el punto de venta junto a la cantidad.</div>
                    </div>

                    <?php if ($esAdmin): ?>
                    <div class="form-group">
                        <label>Sucursal</label>
                        <select name="sucursal_id">
                            <?php foreach ($todasSucursales as $s): ?>
                                <option value="<?= $s['sucursal_id'] ?>"
                                    <?= (($editando['sucursal_id'] ?? $sucursalVista) == $s['sucursal_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <button class="btn-guardar" type="submit">
                        <?= $editando ? 'Guardar cambios' : 'Agregar unidad' ?>
                    </button>
                    <?php if ($editando): ?>
                        <a class="btn-cancelar-edit" href="inventario_unidades.php">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Sugerencias comunes -->
            <?php if (!$editando): ?>
            <div class="card" style="margin-top:14px;">
                <h3 style="margin-bottom:10px;">Unidades comunes</h3>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    <?php foreach (['pieza','kg','gramo','litro','metro','cm','caja','bolsa','rollo','par','juego','lb','tonelada','ml','m²'] as $sug): ?>
                        <button type="button" onclick="usarSugerida('<?= $sug ?>')"
                            style="background:#f0f9ff;border:1px solid #b3e0f7;color:#0077a8;padding:5px 12px;border-radius:99px;font-size:12px;cursor:pointer;">
                            <?= $sug ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function normalizar(str) {
    return String(str || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}
function filtrarTabla(q) {
    q = normalizar(q);
    document.querySelectorAll('#tablaFiltrable tr').forEach(function(tr) {
        tr.style.display = normalizar(tr.textContent).includes(q) ? '' : 'none';
    });
}
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}
function usarSugerida(nombre) {
    const inp = document.querySelector('input[name="nombre"]');
    if (inp) { inp.value = nombre; inp.focus(); }
}
</script>
</body>
</html>
