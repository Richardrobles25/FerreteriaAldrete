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

$editando  = null;
$errores   = [];
$esEdicion = isset($_GET['id']);

if ($esEdicion) {
    $stmt = $pdo->prepare("SELECT * FROM gastos WHERE gasto_id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editando) { header('Location: gastos.php'); exit(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [FIX-CRIT-G-01] CSRF ausente antes. Se valida con verificarCSRF() (no
    // requerirCSRF()) para que el error se muestre igual que las demás validaciones
    // de este formulario — inline, sin perder lo que el usuario ya había capturado.
    if (!verificarCSRF($_POST['_token'] ?? '')) {
        $errores[] = 'La sesión expiró o el formulario no es válido. Recarga la página e intenta de nuevo.';
    }
    $sucursal_id        = intval($_POST['sucursal_id']        ?? 0);
    $categoria_gasto_id = intval($_POST['categoria_gasto_id'] ?? 0);
    $descripcion        = trim($_POST['descripcion']          ?? '');
    $monto              = floatval(str_replace(',', '', $_POST['monto'] ?? 0));
    $fecha              = trim($_POST['fecha']                ?? '');
    $notas              = trim($_POST['notas']                ?? '');
    $gasto_id           = intval($_POST['gasto_id']           ?? 0);

    if (!$sucursal_id)                               $errores[] = 'Selecciona una sucursal.';
    if (!$categoria_gasto_id)                        $errores[] = 'Selecciona una categoria.';
    if ($descripcion === '')                         $errores[] = 'La descripcion es obligatoria.';
    if ($monto <= 0)                                 $errores[] = 'El monto debe ser mayor a $0.';
    // [FIX-PRECIO-MAX-GASTO] monto es DECIMAL(10,2); sin tope, un valor absurdo caía en el
    // catch generico de abajo con un mensaje que no explica la causa real.
    if ($monto > 500000)                             $errores[] = 'El monto no puede ser mayor a $500,000.00. Verifica la cantidad capturada.';
    if (!$fecha || !strtotime($fecha))               $errores[] = 'La fecha no es valida.';
    // [FIX-MEDIO-G-16] No habia tope contra fechas futuras.
    elseif ($fecha > date('Y-m-d'))                  $errores[] = 'La fecha no puede ser en el futuro.';

    // [FIX-ALTO-G-12] Antes una PDOException (p. ej. sucursal_id/categoria_gasto_id de un
    // <select> desactualizado que ya no existe o esta inactivo, violando la FK) se dejaba
    // sin capturar: HTTP 500 con la ruta del servidor y el esquema de la BD expuestos en
    // vez de un mensaje de error normal en el formulario.
    if (empty($errores)) {
        try {
            if ($gasto_id) {
                $pdo->prepare("
                    UPDATE gastos SET sucursal_id = ?, categoria_gasto_id = ?, descripcion = ?, monto = ?, fecha = ?, notas = ?
                    WHERE gasto_id = ?
                ")->execute([$sucursal_id, $categoria_gasto_id, $descripcion, $monto, $fecha, $notas ?: null, $gasto_id]);
            } else {
                $pdo->prepare("
                    INSERT INTO gastos (sucursal_id, usuario_id, categoria_gasto_id, descripcion, monto, fecha, notas)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([$sucursal_id, $_SESSION['usuario_id'], $categoria_gasto_id, $descripcion, $monto, $fecha, $notas ?: null]);
            }
            header('Location: gastos.php');
            exit();
        } catch (PDOException $e) {
            $errores[] = 'No se pudo guardar el gasto. Verifica que la sucursal y la categoría sigan siendo válidas e intenta de nuevo.';
        }
    }
}

// [FIX-MEDIO-G-21] El combo de sucursales solo traia "activo=1" — si la sucursal de un gasto
// ya existente se desactivaba despues, el <select> al editar ya no tenia esa opcion, el
// navegador caia al valor en blanco, y guardar CUALQUIER cambio (hasta solo corregir la
// descripcion) quedaba bloqueado por "Selecciona una sucursal" a menos que se reasignara el
// gasto a una sucursal distinta de la real. Se incluye la sucursal actual del gasto aunque
// este inactiva, marcada como tal.
$sucursalActualId = $editando['sucursal_id'] ?? null;
if ($sucursalActualId) {
    $sucursales = $pdo->prepare("SELECT sucursal_id, nombre, activo FROM sucursales WHERE activo = 1 OR sucursal_id = ? ORDER BY nombre");
    $sucursales->execute([$sucursalActualId]);
    $sucursales = $sucursales->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sucursales = $pdo->query("SELECT sucursal_id, nombre, activo FROM sucursales WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
}
$categorias = $pdo->query("SELECT categoria_gasto_id, nombre FROM categorias_gastos WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

$v = [
    'sucursal_id'        => $_POST['sucursal_id']        ?? $editando['sucursal_id']        ?? '',
    'categoria_gasto_id' => $_POST['categoria_gasto_id'] ?? $editando['categoria_gasto_id'] ?? '',
    'descripcion'        => $_POST['descripcion']        ?? $editando['descripcion']        ?? '',
    'monto'              => $_POST['monto']              ?? $editando['monto']              ?? '',
    'fecha'              => $_POST['fecha']              ?? $editando['fecha']              ?? date('Y-m-d'),
    'notas'              => $_POST['notas']              ?? $editando['notas']              ?? '',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $esEdicion ? 'Editar' : 'Nuevo' ?> Gasto — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; display: flex; justify-content: center; align-items: flex-start; }
    .card { background: white; border-radius: 10px; border: 0.5px solid #e8e8e8; padding: 28px; width: 100%; max-width: 520px; }
    .card-title { font-size: 16px; font-weight: 700; color: #222; margin-bottom: 22px; padding-bottom: 14px; border-bottom: 1px solid #f0f0f0; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; margin-bottom: 6px; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .form-group textarea { resize: vertical; min-height: 70px; }
    .monto-prefix { position: relative; }
    .monto-prefix span { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 13px; color: #888; }
    .monto-prefix input { padding-left: 22px; }
    .errores { background: #fff0f0; border: 1px solid #fdd; border-radius: 7px; padding: 12px 14px; margin-bottom: 16px; }
    .errores ul { padding-left: 18px; }
    .errores li { font-size: 13px; color: #c0392b; margin-bottom: 4px; }
    .form-actions { display: flex; gap: 10px; margin-top: 24px; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 11px 24px; border-radius: 7px; cursor: pointer; font-size: 14px; font-weight: 600; flex: 1; }
    .btn-guardar:hover { background: #119dd4; }
    .btn-cancelar { background: white; color: #666; border: 1px solid #ddd; padding: 11px 20px; border-radius: 7px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; text-align: center; }
    .btn-cancelar:hover { background: #f5f5f5; }
    @media (max-width: 768px) {
        .sidebar { position: fixed; top: 0; left: 0; height: 100%; z-index: 300; width: 0; transition: width 0.3s; }
        .sidebar.collapsed { width: 260px; box-shadow: 4px 0 16px rgba(0,0,0,.15); }
        .main { width: 100%; }
        .topbar { padding: 0 12px; height: 48px; }
        .topbar-right > span { display: none; }
        .content { padding: 12px; }
        .form-group input, .form-group select, .form-group textarea { font-size: 16px; }
    }
</style>

<?php renderAdminSidebar('gastos'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2><?= $esEdicion ? 'Editar Gasto' : 'Nuevo Gasto' ?></h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <div class="card">
            <div class="card-title"><?= $esEdicion ? 'Editar gasto' : 'Registrar nuevo gasto' ?></div>

            <?php if (!empty($errores)): ?>
            <div class="errores">
                <ul>
                    <?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php if ($esEdicion): ?>
                    <input type="hidden" name="gasto_id" value="<?= $editando['gasto_id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Sucursal</label>
                    <select name="sucursal_id" required>
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= $s['sucursal_id'] ?>" <?= $v['sucursal_id'] == $s['sucursal_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nombre']) ?><?= !$s['activo'] ? ' (inactiva)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Categoria</label>
                    <select name="categoria_gasto_id" required>
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?= $cat['categoria_gasto_id'] ?>" <?= $v['categoria_gasto_id'] == $cat['categoria_gasto_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Descripcion</label>
                    <input type="text" name="descripcion" value="<?= htmlspecialchars($v['descripcion']) ?>" placeholder="Ej. Cambio de llanta camioneta de reparto" maxlength="500" required>
                </div>

                <div class="form-group">
                    <label>Monto</label>
                    <div class="monto-prefix">
                        <span>$</span>
                        <input type="number" name="monto" value="<?= htmlspecialchars($v['monto']) ?>" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Fecha</label>
                    <input type="date" name="fecha" value="<?= htmlspecialchars($v['fecha']) ?>" max="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label>Notas <span style="font-weight:400;text-transform:none;color:#bbb;">(opcional)</span></label>
                    <textarea name="notas" placeholder="Informacion adicional..."><?= htmlspecialchars($v['notas']) ?></textarea>
                </div>

                <div class="form-actions">
                    <a class="btn-cancelar" href="gastos.php">Cancelar</a>
                    <button class="btn-guardar" type="submit"><?= $esEdicion ? 'Guardar cambios' : 'Registrar gasto' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>
