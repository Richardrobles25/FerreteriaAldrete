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
    $stmt = $pdo->prepare("SELECT * FROM empleados WHERE empleado_id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editando) { header('Location: empleados.php'); exit(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre         = trim($_POST['nombre']         ?? '');
    $fecha_ingreso  = trim($_POST['fecha_ingreso']  ?? '');
    $sueldo_semanal = floatval(str_replace(',', '', $_POST['sueldo_semanal'] ?? 0));
    $activo         = isset($_POST['activo']) ? 1 : 0;
    $empleado_id    = intval($_POST['empleado_id']  ?? 0);

    if ($nombre === '')                                    $errores[] = 'El nombre es obligatorio.';
    if (!$fecha_ingreso || !strtotime($fecha_ingreso))    $errores[] = 'La fecha de ingreso no es valida.';
    if ($sueldo_semanal <= 0)                             $errores[] = 'El sueldo semanal debe ser mayor a $0.';

    if (empty($errores)) {
        if ($empleado_id) {
            $pdo->prepare("
                UPDATE empleados SET nombre=?, fecha_ingreso=?, sueldo_semanal=?, activo=?
                WHERE empleado_id=?
            ")->execute([$nombre, $fecha_ingreso, $sueldo_semanal, $activo, $empleado_id]);
        } else {
            $pdo->prepare("
                INSERT INTO empleados (nombre, fecha_ingreso, sueldo_semanal, activo)
                VALUES (?, ?, ?, 1)
            ")->execute([$nombre, $fecha_ingreso, $sueldo_semanal]);
        }
        header('Location: empleados.php');
        exit();
    }
}

$v = [
    'nombre'         => $_POST['nombre']         ?? $editando['nombre']         ?? '',
    'fecha_ingreso'  => $_POST['fecha_ingreso']  ?? $editando['fecha_ingreso']  ?? '',
    'sueldo_semanal' => $_POST['sueldo_semanal'] ?? $editando['sueldo_semanal'] ?? '',
    'activo'         => $_POST['activo']         ?? $editando['activo']         ?? 1,
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $esEdicion ? 'Editar' : 'Nuevo' ?> Empleado — Ferreteria Aldrete</title>
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
    .card { background: white; border-radius: 10px; border: 0.5px solid #e8e8e8; padding: 28px; width: 100%; max-width: 480px; }
    .card-title { font-size: 16px; font-weight: 700; color: #222; margin-bottom: 22px; padding-bottom: 14px; border-bottom: 1px solid #f0f0f0; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; margin-bottom: 6px; }
    .form-group input, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group select:focus { outline: none; border-color: #14ace7; }
    .prefix-wrap { position: relative; }
    .prefix-wrap span { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 13px; color: #888; }
    .prefix-wrap input { padding-left: 22px; }
    .check-row { display: flex; align-items: center; gap: 10px; }
    .check-row input[type=checkbox] { width: 16px; height: 16px; accent-color: #14ace7; cursor: pointer; }
    .check-row label { font-size: 13px; color: #555; font-weight: 400; text-transform: none; margin: 0; cursor: pointer; }
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
    }
</style>

<?php renderAdminSidebar('rrhh_form_empleado'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2><?= $esEdicion ? 'Editar Empleado' : 'Nuevo Empleado' ?></h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <div class="card">
            <div class="card-title"><?= $esEdicion ? 'Editar datos del empleado' : 'Registrar nuevo empleado' ?></div>

            <?php if (!empty($errores)): ?>
            <div class="errores"><ul><?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <form method="POST">
                <?php if ($esEdicion): ?>
                    <input type="hidden" name="empleado_id" value="<?= $editando['empleado_id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Nombre completo</label>
                    <input type="text" name="nombre" value="<?= htmlspecialchars($v['nombre']) ?>" placeholder="Ej. Juan Lopez Garcia" maxlength="100" required>
                </div>

                <div class="form-group">
                    <label>Fecha de ingreso</label>
                    <input type="date" name="fecha_ingreso" value="<?= htmlspecialchars($v['fecha_ingreso']) ?>" required>
                </div>

                <div class="form-group">
                    <label>Sueldo semanal</label>
                    <div class="prefix-wrap">
                        <span>$</span>
                        <input type="number" name="sueldo_semanal" value="<?= htmlspecialchars($v['sueldo_semanal']) ?>" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                </div>

                <?php if ($esEdicion): ?>
                <div class="form-group">
                    <div class="check-row">
                        <input type="checkbox" name="activo" id="activo" <?= $v['activo'] ? 'checked' : '' ?>>
                        <label for="activo">Empleado activo</label>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-actions">
                    <a class="btn-cancelar" href="empleados.php">Cancelar</a>
                    <button class="btn-guardar" type="submit"><?= $esEdicion ? 'Guardar cambios' : 'Registrar empleado' ?></button>
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
