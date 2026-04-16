<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);

$editando  = null;
$errores   = [];
$esEdicion = isset($_GET['id']);

if ($esEdicion) {
    $stmt = $pdo->prepare("SELECT * FROM sucursales WHERE sucursal_id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editando) { header('Location: sucursales.php'); exit(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre       = trim($_POST['nombre'] ?? '');
    $rfc          = strtoupper(trim($_POST['rfc'] ?? ''));
    $direccion    = trim($_POST['direccion'] ?? '');
    $telefono     = trim($_POST['telefono'] ?? '');
    $datos_ticket = trim($_POST['datos_ticket'] ?? '');
    $sucursal_id  = intval($_POST['sucursal_id'] ?? 0);

    if (!$nombre) $errores[] = 'El nombre de la sucursal es obligatorio.';

    if (empty($errores)) {
        if ($sucursal_id) {
            $pdo->prepare("UPDATE sucursales SET nombre=?,rfc=?,direccion=?,telefono=?,datos_ticket=? WHERE sucursal_id=?")
                ->execute([$nombre,$rfc,$direccion,$telefono,$datos_ticket,$sucursal_id]);
            header('Location: sucursales.php?msg=editado');
        } else {
            $pdo->prepare("INSERT INTO sucursales (nombre,rfc,direccion,telefono,datos_ticket,activo) VALUES (?,?,?,?,?,1)")
                ->execute([$nombre,$rfc,$direccion,$telefono,$datos_ticket]);
            header('Location: sucursales.php?msg=creado');
        }
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $editando?'Editar':'Nueva' ?> Sucursal â€” FerreterÃ­a Aldrete</title>
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
    .content { flex: 1; padding: 28px; overflow-y: auto; display: flex; justify-content: center; }
    .form-card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 28px; width: 100%; max-width: 540px; height: fit-content; }
    .form-card h1 { font-size: 18px; font-weight: 600; color: #222; margin: 0 0 6px; }
    .form-card p { font-size: 13px; color: #888; margin: 0 0 22px; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 18px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; color: #333; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .form-group textarea { min-height: 100px; resize: vertical; font-size: 13px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .hint { font-size: 11px; color: #aaa; margin-top: 4px; }
    .ticket-preview-box { background: #f5f5f5; border-radius: 6px; padding: 12px; margin-top: 8px; font-family: monospace; font-size: 12px; color: #555; line-height: 1.7; min-height: 60px; white-space: pre-wrap; }
    .acciones-form { display: flex; gap: 10px; margin-top: 4px; }
    .btn-guardar { flex: 1; background: #14ace7; color: white; border: none; padding: 12px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar { background: white; color: #666; border: 1px solid #ddd; padding: 12px 20px; border-radius: 6px; font-size: 14px; cursor: pointer; text-decoration: none; display: flex; align-items: center; }
    .btn-cancelar:hover { background: #f5f5f5; }
</style>

<?php renderAdminSidebar('form_sucursal'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2><?= $editando?'Editar sucursal':'Nueva sucursal' ?></h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">â€” <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesiÃ³n</button></form>
        </div>
    </div>

    <div class="content">
        <div class="form-card">
            <h1><?= $editando?'Editar sucursal':'Nueva sucursal' ?></h1>
            <p><?= $editando?'Actualiza los datos de la sucursal.':'Registra una nueva sucursal de la ferreterÃ­a.' ?></p>

            <?php if (!empty($errores)): ?>
                <div class="errores">
                    <ul><?php foreach($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="sucursal_id" value="<?= $editando['sucursal_id'] ?? 0 ?>">

                <div class="form-group">
                    <label>Nombre de la sucursal *</label>
                    <input type="text" name="nombre"
                        value="<?= htmlspecialchars($_POST['nombre'] ?? $editando['nombre'] ?? '') ?>"
                        placeholder="Ej. FerreterÃ­a Aldrete Centro">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>RFC</label>
                        <input type="text" name="rfc"
                            value="<?= htmlspecialchars($_POST['rfc'] ?? $editando['rfc'] ?? '') ?>"
                            placeholder="AAAA000000AAA"
                            oninput="this.value=this.value.toUpperCase()">
                    </div>
                    <div class="form-group">
                        <label>TelÃ©fono</label>
                        <input type="text" name="telefono"
                            value="<?= htmlspecialchars($_POST['telefono'] ?? $editando['telefono'] ?? '') ?>"
                            placeholder="10 dÃ­gitos">
                    </div>
                </div>

                <div class="form-group">
                    <label>DirecciÃ³n</label>
                    <input type="text" name="direccion"
                        value="<?= htmlspecialchars($_POST['direccion'] ?? $editando['direccion'] ?? '') ?>"
                        placeholder="Calle, nÃºmero, colonia, ciudad">
                </div>

                <div class="form-group">
                    <label>Datos del ticket</label>
                    <textarea name="datos_ticket"
                        id="datosTicket"
                        placeholder="Texto que aparecerÃ¡ en los tickets de venta&#10;Ej:&#10;FerreterÃ­a Aldrete S.A. de C.V.&#10;RFC: AAAA000000AAA&#10;Calle Morelos #45, Col. Centro&#10;Tel: 8711234567"
                        oninput="actualizarPreview(this.value)"><?= htmlspecialchars($_POST['datos_ticket'] ?? $editando['datos_ticket'] ?? '') ?></textarea>
                    <div class="hint">Este texto aparece en todos los tickets de venta de esta sucursal.</div>
                    <div class="ticket-preview-box" id="ticketPreview"><?= htmlspecialchars($_POST['datos_ticket'] ?? $editando['datos_ticket'] ?? 'Vista previa del ticket...') ?></div>
                </div>

                <div class="acciones-form">
                    <a class="btn-cancelar" href="sucursales.php">Cancelar</a>
                    <button class="btn-guardar" type="submit">
                        <?= $editando?'Guardar cambios':'Crear sucursal' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
function actualizarPreview(val) {
    document.getElementById('ticketPreview').textContent = val || 'Vista previa del ticket...';
}
</script>
</body>
</html>


