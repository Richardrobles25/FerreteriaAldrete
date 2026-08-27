<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
// [FIX-CRIT-B-04, ajustado 2026-08-26] Regla confirmada: crear/editar/eliminar categorías
// (necesarias para la importación de productos) es SOLO Administrador — ni Inventario
// (puro) ni Inventario/Cajero.
verificarRol(['Administrador']);
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sucursal_filtro.php';
// Eliminar categoría
if (isset($_GET['eliminar'])) {
    // [FIX-CRIT-B-03] Sin CSRF antes — cualquier página visitada con la sesión del
    // Administrador abierta podía borrar categorías del catálogo global.
    requerirCSRF($_GET['_token'] ?? '', 'inventario_categorias.php');
    $id = intval($_GET['eliminar']);
    // [FIX-ALTO-B-08] Antes solo se contaban productos activos: una categoria con
    // productos desactivados (pero aun ligados por la FK) tronaba el DELETE con un
    // error SQL crudo (violacion de llave foranea) en lugar de un mensaje claro.
    $check = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE categoria_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        header('Location: inventario_categorias.php?msg=error_productos');
        exit();
    }
    $stmt = $pdo->prepare("DELETE FROM categorias WHERE categoria_id = ?");
    $stmt->execute([$id]);
    header('Location: inventario_categorias.php?msg=eliminado');
    exit();
}

// Guardar categoría (crear o editar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [FIX-CRIT-B-03] CSRF ausente antes.
    requerirCSRF($_POST['_token'] ?? '', 'inventario_categorias.php');
    $nombre = trim($_POST['nombre'] ?? '');
    $id     = intval($_POST['categoria_id'] ?? 0);

    // [AUTOFIX] VALIDACION-3A-2: Validar nombre en blanco antes de tocar la BD
    if ($nombre === '') {
        header('Location: inventario_categorias.php?msg=vacio');
        exit();
    }

    // [AUTOFIX] ERROR-CAT-01: Capturar PDOException de clave duplicada en lugar de exponer el error PHP
    try {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE categorias SET nombre = ? WHERE categoria_id = ?");
            $stmt->execute([$nombre, $id]);
            header('Location: inventario_categorias.php?msg=editado');
        } else {
            $stmt = $pdo->prepare("INSERT INTO categorias (nombre) VALUES (?)");
            $stmt->execute([$nombre]);
            header('Location: inventario_categorias.php?msg=creado');
        }
        exit();
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            // Clave duplicada — nombre ya existe
            header('Location: inventario_categorias.php?msg=duplicado');
            exit();
        }
        throw $e; // Cualquier otro error sí se propaga
    }
}

$busqueda = trim($_GET['buscar'] ?? '');
if ($busqueda) {
    $stmt = $pdo->prepare("SELECT c.*, COUNT(p.producto_id) as total_productos FROM categorias c LEFT JOIN productos p ON c.categoria_id = p.categoria_id AND p.activo = 1 WHERE c.nombre LIKE ? GROUP BY c.categoria_id ORDER BY c.categoria_id ASC");
    $stmt->execute(['%' . $busqueda . '%']);
} else {
    $stmt = $pdo->query("SELECT c.*, COUNT(p.producto_id) as total_productos FROM categorias c LEFT JOIN productos p ON c.categoria_id = p.categoria_id AND p.activo = 1 GROUP BY c.categoria_id ORDER BY c.categoria_id ASC");
}
$categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Categoría a editar
$editando = null;
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM categorias WHERE categoria_id = ?");
    $stmt->execute([intval($_GET['editar'])]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorías — Ferretería Aldrete</title>
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
    .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .content-header h1 { font-size: 20px; color: #222; font-weight: 600; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .barra-busqueda { display: flex; gap: 10px; margin-bottom: 16px; }
    .barra-busqueda input { flex: 1; padding: 9px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .barra-busqueda input:focus { outline: none; border-color: #14ace7; }
    .btn-buscar { background: #14ace7; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .msg-error { background: #fdecea; color: #c0392b; border-left: 3px solid #c0392b; }
    table { width: 100%; border-collapse: collapse; }
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
    .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .form-group input:focus { outline: none; border-color: #14ace7; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; width: 100%; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar-edit { background: white; color: #666; border: 1px solid #ddd; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; width: 100%; margin-top: 8px; text-decoration: none; display: block; text-align: center; }
    .btn-cancelar-edit:hover { background: #f5f5f5; }
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

<?php renderAdminSidebar('inventario_categorias'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Categorías</h2>
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
                    <div class="msg msg-exito">Categoría creada correctamente.</div>
                <?php elseif ($_GET['msg'] === 'editado'): ?>
                    <div class="msg msg-exito">Categoría actualizada correctamente.</div>
                <?php elseif ($_GET['msg'] === 'eliminado'): ?>
                    <div class="msg msg-exito">Categoría eliminada correctamente.</div>
                <?php elseif ($_GET['msg'] === 'error_productos'): ?>
                    <div class="msg msg-error">No puedes eliminar esta categoría porque tiene productos asociados.</div>
                <?php elseif ($_GET['msg'] === 'duplicado'): ?>
                    <div class="msg msg-error">Ya existe una categoría con ese nombre. Elige un nombre diferente.</div>
                <?php elseif ($_GET['msg'] === 'vacio'): ?>
                    <div class="msg msg-error">El nombre de la categoría es obligatorio.</div>
                <?php elseif ($_GET['msg'] === 'error_token'): ?>
                    <div class="msg msg-error">La sesión expiró o el enlace no es válido. Intenta de nuevo.</div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="GET" action="inventario_categorias.php">
                <div class="barra-busqueda">
                    <input type="text" name="buscar" placeholder="Buscar categoría..." value="<?= htmlspecialchars($busqueda) ?>" oninput="filtrarTabla(this.value)">
                    <button class="btn-buscar" type="submit">Buscar</button>
                    <?php if ($busqueda): ?>
                        <a class="btn-limpiar" href="inventario_categorias.php">Limpiar</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="card" style="padding:0;">
                <?php if (count($categorias) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Productos</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tablaFiltrable">
                        <?php foreach ($categorias as $c): ?>
                        <tr>
                            <td style="color:#aaa;"><?= $c['categoria_id'] ?></td>
                            <td><strong><?= htmlspecialchars($c['nombre']) ?></strong></td>
                            <td><span class="badge-count"><?= $c['total_productos'] ?> productos</span></td>
                            <td>
                                <div class="acciones">
                                    <a class="btn-accion btn-editar" href="inventario_categorias.php?editar=<?= $c['categoria_id'] ?>">Editar</a>
                                    <a class="btn-accion btn-eliminar" href="inventario_categorias.php?eliminar=<?= $c['categoria_id'] ?>&_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>" onclick="return confirm('¿Eliminar esta categoría?')">Eliminar</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay categorías registradas.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario lateral -->
        <div>
            <div class="card">
                <h3><?= $editando ? 'Editar categoría' : 'Nueva categoría' ?></h3>
                <form method="POST">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="categoria_id" value="<?= $editando['categoria_id'] ?? 0 ?>">
                    <div class="form-group">
                        <label>Nombre *</label>
                        <input type="text" name="nombre" value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>" placeholder="Ej. Plomería" autofocus>
                    </div>
                    <button class="btn-guardar" type="submit">
                        <?= $editando ? 'Guardar cambios' : 'Agregar categoría' ?>
                    </button>
                    <?php if ($editando): ?>
                        <a class="btn-cancelar-edit" href="inventario_categorias.php">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function normalizar(str) {
    return String(str || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
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
</script>
</body>
</html>


