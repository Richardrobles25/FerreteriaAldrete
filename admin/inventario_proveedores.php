<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

if (isset($_GET['eliminar'])) {
    $pdo->prepare("UPDATE proveedores SET activo = 0 WHERE proveedor_id = ?")->execute([intval($_GET['eliminar'])]);
    header('Location: inventario_proveedores.php?msg=eliminado'); exit();
}
if (isset($_GET['toggle'])) {
    $pdo->prepare("UPDATE proveedores SET activo = NOT activo WHERE proveedor_id = ?")->execute([intval($_GET['toggle'])]);
    header('Location: inventario_proveedores.php'); exit();
}

$errores  = [];
$editando = null;
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM proveedores WHERE proveedor_id = ?");
    $stmt->execute([intval($_GET['editar'])]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre    = trim($_POST['nombre'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $correo    = trim($_POST['correo'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $cats      = $_POST['categorias'] ?? [];
    $id        = intval($_POST['proveedor_id'] ?? 0);

    if (!$nombre) $errores[] = 'El nombre es obligatorio.';

    if (empty($errores)) {
        if ($id) {
            $pdo->prepare("UPDATE proveedores SET nombre=?, telefono=?, correo=?, direccion=? WHERE proveedor_id=?")
                ->execute([$nombre, $telefono, $correo, $direccion, $id]);
            $pdo->prepare("DELETE FROM proveedor_categorias WHERE proveedor_id = ?")->execute([$id]);
            foreach ($cats as $cat) {
                $pdo->prepare("INSERT INTO proveedor_categorias (proveedor_id, categoria_id) VALUES (?,?)")->execute([$id, $cat]);
            }
            header('Location: inventario_proveedores.php?msg=editado');
        } else {
            $pdo->prepare("INSERT INTO proveedores (nombre, telefono, correo, direccion, activo) VALUES (?,?,?,?,1)")
                ->execute([$nombre, $telefono, $correo, $direccion]);
            $nuevoId = $pdo->lastInsertId();
            foreach ($cats as $cat) {
                $pdo->prepare("INSERT INTO proveedor_categorias (proveedor_id, categoria_id) VALUES (?,?)")->execute([$nuevoId, $cat]);
            }
            header('Location: inventario_proveedores.php?msg=creado');
        }
        exit();
    }
}

$busqueda  = trim($_GET['buscar'] ?? '');
$filtrocat = intval($_GET['categoria'] ?? 0);

$where  = "WHERE p.activo = 1";
$params = [];
if ($busqueda)  { $where .= " AND p.nombre LIKE ?"; $params[] = '%'.$busqueda.'%'; }
if ($filtrocat) { $where .= " AND pc.categoria_id = ?"; $params[] = $filtrocat; }

$join = $filtrocat ? "JOIN proveedor_categorias pc ON p.proveedor_id = pc.proveedor_id" : "LEFT JOIN proveedor_categorias pc ON p.proveedor_id = pc.proveedor_id";

$stmt = $pdo->prepare("SELECT DISTINCT p.* FROM proveedores p $join $where ORDER BY p.nombre ASC");
$stmt->execute($params);
$proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categorias = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

// CategorÃ­as del proveedor en ediciÃ³n
$catsEditando = [];
if ($editando) {
    $stmtC = $pdo->prepare("SELECT categoria_id FROM proveedor_categorias WHERE proveedor_id = ?");
    $stmtC->execute([$editando['proveedor_id']]);
    $catsEditando = array_column($stmtC->fetchAll(PDO::FETCH_ASSOC), 'categoria_id');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proveedores â€” FerreterÃ­a Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 16px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 14px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .cats-lista { display: flex; gap: 4px; flex-wrap: wrap; }
    .cat-badge { background: #e3f2fd; color: #1565c0; font-size: 11px; padding: 2px 8px; border-radius: 99px; font-weight: 600; }
    .acciones { display: flex; gap: 6px; }
    .btn-accion { padding: 5px 11px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #e3f2fd; color: #1565c0; }
    .btn-editar:hover { background: #bbdefb; }
    .btn-desactivar { background: #fff8e1; color: #1565c0; }
    .btn-activar { background: #e8f5e9; color: #2e7d32; }
    .btn-eliminar { background: #fdecea; color: #c0392b; }
    .btn-eliminar:hover { background: #ffcdd2; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .form-group input:focus { outline: none; border-color: #14ace7; }
    .cats-check { display: flex; flex-direction: column; gap: 6px; max-height: 150px; overflow-y: auto; border: 1px solid #eee; border-radius: 6px; padding: 10px; }
    .cat-check-row { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #555; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; width: 100%; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar-edit { background: white; color: #666; border: 1px solid #ddd; padding: 10px; border-radius: 6px; cursor: pointer; font-size: 13px; width: 100%; margin-top: 8px; text-decoration: none; display: block; text-align: center; }
</style>

<?php renderAdminSidebar('inventario_proveedores'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Proveedores</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">â€” <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesiÃ³n</button></form>
        </div>
    </div>

    <div class="content">
        <div>
            <?php if (isset($_GET['msg'])): ?>
                <?php $msgs = ['creado'=>'Proveedor creado.','editado'=>'Proveedor actualizado.','eliminado'=>'Proveedor eliminado.']; ?>
                <div class="msg msg-exito"><?= $msgs[$_GET['msg']] ?? '' ?></div>
            <?php endif; ?>

            <form method="GET" action="inventario_proveedores.php">
                <div class="filtros">
                    <div class="filtro-group">
                        <label>Buscar</label>
                        <input type="text" name="buscar" placeholder="Nombre del proveedor..." value="<?= htmlspecialchars($busqueda) ?>">
                    </div>
                    <div class="filtro-group">
                        <label>Filtrar por Ã¡rea</label>
                        <select name="categoria">
                            <option value="">Todas las Ã¡reas</option>
                            <?php foreach ($categorias as $c): ?>
                                <option value="<?= $c['categoria_id'] ?>" <?= $filtrocat===$c['categoria_id']?'selected':'' ?>><?= htmlspecialchars($c['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn-filtrar" type="submit">Filtrar</button>
                    <?php if ($busqueda || $filtrocat): ?><a class="btn-limpiar" href="inventario_proveedores.php">Limpiar</a><?php endif; ?>
                </div>
            </form>

            <div class="card" style="padding:0;">
                <?php if (count($proveedores) > 0): ?>
                <table>
                    <thead>
                        <tr><th>Nombre</th><th>TelÃ©fono</th><th>Correo</th><th>Ãreas</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proveedores as $p):
                            $stmtC = $pdo->prepare("SELECT c.nombre FROM proveedor_categorias pc JOIN categorias c ON pc.categoria_id = c.categoria_id WHERE pc.proveedor_id = ?");
                            $stmtC->execute([$p['proveedor_id']]);
                            $cats = $stmtC->fetchAll(PDO::FETCH_COLUMN);
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($p['nombre']) ?></strong></td>
                            <td><?= htmlspecialchars($p['telefono']??'â€”') ?></td>
                            <td style="font-size:12px;"><?= htmlspecialchars($p['correo']??'â€”') ?></td>
                            <td>
                                <div class="cats-lista">
                                    <?php foreach ($cats as $cat): ?>
                                        <span class="cat-badge"><?= htmlspecialchars($cat) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (!$cats): ?><span style="color:#aaa;font-size:12px;">â€”</span><?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="acciones">
                                    <a class="btn-accion btn-editar" href="inventario_proveedores.php?editar=<?= $p['proveedor_id'] ?>">Editar</a>
                                    <a class="btn-accion <?= $p['activo']?'btn-desactivar':'btn-activar' ?>" href="inventario_proveedores.php?toggle=<?= $p['proveedor_id'] ?>" onclick="return confirm('Â¿Confirmar cambio?')">
                                        <?= $p['activo']?'Desactivar':'Activar' ?>
                                    </a>
                                    <a class="btn-accion btn-eliminar" href="inventario_proveedores.php?eliminar=<?= $p['proveedor_id'] ?>" onclick="return confirm('Â¿Eliminar proveedor?')">Eliminar</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay proveedores registrados.</div>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="card">
                <h3><?= $editando ? 'Editar proveedor' : 'Nuevo proveedor' ?></h3>
                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach($errores as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="proveedor_id" value="<?= $editando['proveedor_id'] ?? 0 ?>">
                    <div class="form-group">
                        <label>Nombre *</label>
                        <input type="text" name="nombre" value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>" placeholder="Nombre del proveedor">
                    </div>
                    <div class="form-group">
                        <label>TelÃ©fono</label>
                        <input type="text" name="telefono" value="<?= htmlspecialchars($editando['telefono'] ?? '') ?>" placeholder="10 dÃ­gitos">
                    </div>
                    <div class="form-group">
                        <label>Correo</label>
                        <input type="email" name="correo" value="<?= htmlspecialchars($editando['correo'] ?? '') ?>" placeholder="correo@ejemplo.com">
                    </div>
                    <div class="form-group">
                        <label>DirecciÃ³n</label>
                        <input type="text" name="direccion" value="<?= htmlspecialchars($editando['direccion'] ?? '') ?>" placeholder="DirecciÃ³n del proveedor">
                    </div>
                    <div class="form-group">
                        <label>Ãreas que abastece</label>
                        <div class="cats-check">
                            <?php foreach ($categorias as $c): ?>
                                <label class="cat-check-row">
                                    <input type="checkbox" name="categorias[]" value="<?= $c['categoria_id'] ?>"
                                        <?= in_array($c['categoria_id'], $catsEditando) ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($c['nombre']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button class="btn-guardar" type="submit"><?= $editando ? 'Guardar cambios' : 'Agregar proveedor' ?></button>
                    <?php if ($editando): ?><a class="btn-cancelar-edit" href="inventario_proveedores.php">Cancelar</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>


