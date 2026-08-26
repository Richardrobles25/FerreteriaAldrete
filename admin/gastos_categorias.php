<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);
require_once '../includes/topbar_info.php';

// [FIX-MEDIO-G-20] La tabla categorias_gastos ya existia y gastos.php/formGasto.php ya la leian,
// pero no habia ninguna pantalla para administrarla (agregar una categoria nueva, corregir un
// nombre, o retirar una que ya no se usa) — solo se podia tocar directo en la base de datos.

$editando = null;
$errores  = [];

// Guardar (crear o editar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    requerirCSRF($_POST['_token'] ?? '', 'gastos_categorias.php');
    $nombre = trim($_POST['nombre'] ?? '');
    $id     = intval($_POST['categoria_gasto_id'] ?? 0);

    if ($nombre === '') {
        $errores[] = 'El nombre es obligatorio.';
    } elseif (mb_strlen($nombre) > 100) {
        // categorias_gastos.nombre es VARCHAR(100)
        $errores[] = 'El nombre no puede tener más de 100 caracteres.';
    }

    if (empty($errores)) {
        try {
            if ($id) {
                $pdo->prepare("UPDATE categorias_gastos SET nombre = ? WHERE categoria_gasto_id = ?")
                    ->execute([$nombre, $id]);
                header('Location: gastos_categorias.php?msg=editado');
            } else {
                $pdo->prepare("INSERT INTO categorias_gastos (nombre, activo) VALUES (?, 1)")
                    ->execute([$nombre]);
                header('Location: gastos_categorias.php?msg=creado');
            }
            exit();
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                $errores[] = 'Ya existe una categoría con ese nombre.';
            } else {
                $errores[] = 'No se pudo guardar la categoría. Intenta de nuevo.';
            }
        }
    }
    if (!empty($errores)) {
        $editando = $id ? ['categoria_gasto_id' => $id, 'nombre' => $nombre] : null;
    }
}

// Activar / desactivar (no se borra: gastos.categoria_gasto_id la referencia por FK, y borrarla
// destruiria el historial de gastos ya registrados con esa categoria)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    requerirCSRF($_POST['_token'] ?? '', 'gastos_categorias.php');
    $id = intval($_POST['toggle_id']);
    $pdo->prepare("UPDATE categorias_gastos SET activo = NOT activo WHERE categoria_gasto_id = ?")->execute([$id]);
    header('Location: gastos_categorias.php?msg=actualizado');
    exit();
}

if (isset($_GET['editar']) && !$editando) {
    $stmt = $pdo->prepare("SELECT * FROM categorias_gastos WHERE categoria_gasto_id = ?");
    $stmt->execute([intval($_GET['editar'])]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
}

$categorias = $pdo->query("
    SELECT cg.*, COUNT(g.gasto_id) AS total_gastos
    FROM categorias_gastos cg
    LEFT JOIN gastos g ON g.categoria_gasto_id = cg.categoria_gasto_id
    GROUP BY cg.categoria_gasto_id
    ORDER BY cg.activo DESC, cg.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorias de gasto — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; display: grid; grid-template-columns: 1fr 320px; gap: 20px; align-items: start; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 16px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; border-left: 3px solid #c0392b; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    tr.inactiva td { opacity: .5; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-activo { background: #e8f8ee; color: #27ae60; }
    .badge-inactivo { background: #f5f5f5; color: #999; }
    .badge-count { background: #f0f0f0; color: #666; font-size: 11px; padding: 2px 10px; border-radius: 99px; font-weight: 600; }
    .acciones { display: flex; gap: 6px; }
    .btn-accion { padding: 5px 12px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #e3f2fd; color: #1565c0; }
    .btn-toggle-on  { background: #fff0f0; border: none; color: #c0392b; padding: 5px 12px; border-radius: 5px; cursor: pointer; font-size: 11px; }
    .btn-toggle-off { background: #e8f8ee; border: none; color: #27ae60; padding: 5px 12px; border-radius: 5px; cursor: pointer; font-size: 11px; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .form-group input:focus { outline: none; border-color: #14ace7; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; width: 100%; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar-edit { background: white; color: #666; border: 1px solid #ddd; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; width: 100%; margin-top: 8px; text-decoration: none; display: block; text-align: center; }
    .btn-cancelar-edit:hover { background: #f5f5f5; }
    @media (max-width: 900px) {
        .content { grid-template-columns: 1fr; }
    }
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
        .logout-btn { padding: 5px 10px; font-size: 11px; }
    }
    </style>

<?php renderAdminSidebar('gastos_categorias'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Categorias de gasto</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <div>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'creado'): ?>
                <div class="msg msg-exito">Categoría creada correctamente.</div>
            <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'editado'): ?>
                <div class="msg msg-exito">Categoría actualizada correctamente.</div>
            <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'actualizado'): ?>
                <div class="msg msg-exito">Categoría actualizada correctamente.</div>
            <?php endif; ?>

            <div class="card" style="padding:0;">
                <?php if (count($categorias) > 0): ?>
                <table>
                    <thead>
                        <tr><th>Nombre</th><th>Gastos registrados</th><th>Estado</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categorias as $cat): ?>
                        <tr class="<?= $cat['activo'] ? '' : 'inactiva' ?>">
                            <td style="font-weight:600;"><?= htmlspecialchars($cat['nombre']) ?></td>
                            <td><span class="badge-count"><?= intval($cat['total_gastos']) ?> gastos</span></td>
                            <td><span class="badge <?= $cat['activo'] ? 'badge-activo' : 'badge-inactivo' ?>"><?= $cat['activo'] ? 'Activa' : 'Inactiva' ?></span></td>
                            <td>
                                <div class="acciones">
                                    <a class="btn-accion btn-editar" href="gastos_categorias.php?editar=<?= $cat['categoria_gasto_id'] ?>">Editar</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿<?= $cat['activo'] ? 'Desactivar' : 'Activar' ?> la categoría &quot;<?= htmlspecialchars(addslashes($cat['nombre']), ENT_QUOTES) ?>&quot;?')">
                                        <input type="hidden" name="toggle_id" value="<?= $cat['categoria_gasto_id'] ?>">
                                        <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <button type="submit" class="<?= $cat['activo'] ? 'btn-toggle-on' : 'btn-toggle-off' ?>"><?= $cat['activo'] ? 'Desactivar' : 'Activar' ?></button>
                                    </form>
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

        <div>
            <div class="card">
                <h3><?= $editando ? 'Editar categoría' : 'Nueva categoría' ?></h3>

                <?php if (!empty($errores)): ?>
                    <div class="errores"><?= htmlspecialchars($errores[0]) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="guardar" value="1">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="categoria_gasto_id" value="<?= $editando['categoria_gasto_id'] ?? 0 ?>">

                    <div class="form-group">
                        <label>Nombre *</label>
                        <input type="text" name="nombre" maxlength="100"
                            value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>"
                            placeholder="Ej. Vehiculos, Mantenimiento" autofocus required>
                    </div>

                    <button class="btn-guardar" type="submit">
                        <?= $editando ? 'Guardar cambios' : 'Agregar categoría' ?>
                    </button>
                    <?php if ($editando): ?>
                        <a class="btn-cancelar-edit" href="gastos_categorias.php">Cancelar</a>
                    <?php endif; ?>
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
