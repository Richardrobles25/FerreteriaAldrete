<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';

verificarSesion();
verificarRol(['Administrador']);

$usuario = null;
$errores = [];
$esEdicion = isset($_GET['id']);

if ($esEdicion) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario_id = ?");
    $stmt->execute([$id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
        header('Location: usuarios.php');
        exit();
    }
}

$sucursales = $pdo->query("SELECT * FROM sucursales WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre_completo = trim($_POST['nombre_completo'] ?? '');
    $nombre_usuario  = trim($_POST['nombre_usuario'] ?? '');
    $telefono        = trim($_POST['telefono'] ?? '');
    $domicilio       = trim($_POST['domicilio'] ?? '');
    $rol             = $_POST['rol'] ?? '';
    $sucursal_id     = intval($_POST['sucursal_id'] ?? 0);
    $contrasena      = trim($_POST['contrasena'] ?? '');
    $confirmar       = trim($_POST['confirmar'] ?? '');

    if (!$nombre_completo) $errores[] = 'El nombre completo es obligatorio.';
    if (!$nombre_usuario)  $errores[] = 'El nombre de usuario es obligatorio.';
    if (!$rol)             $errores[] = 'El rol es obligatorio.';
    if (!$sucursal_id)     $errores[] = 'La sucursal es obligatoria.';
    if (!$esEdicion && !$contrasena) $errores[] = 'La contraseÃ±a es obligatoria.';
    if ($contrasena && $contrasena !== $confirmar) $errores[] = 'Las contraseÃ±as no coinciden.';
    if ($contrasena && strlen($contrasena) < 6) $errores[] = 'La contraseÃ±a debe tener al menos 6 caracteres.';

    if ($nombre_usuario) {
        $check = $pdo->prepare("SELECT usuario_id FROM usuarios WHERE nombre_usuario = ? AND usuario_id != ?");
        $check->execute([$nombre_usuario, $esEdicion ? intval($_GET['id']) : 0]);
        if ($check->fetch()) $errores[] = 'Ese nombre de usuario ya estÃ¡ en uso.';
    }

    if (empty($errores)) {
        if ($esEdicion) {
            $id = intval($_GET['id']);
            if ($contrasena) {
                $stmt = $pdo->prepare("
                    UPDATE usuarios SET nombre_completo=?, nombre_usuario=?, telefono=?,
                    domicilio=?, rol=?, sucursal_id=?, contrasena=? WHERE usuario_id=?
                ");
                $stmt->execute([
                    $nombre_completo, $nombre_usuario, $telefono, $domicilio,
                    $rol, $sucursal_id, password_hash($contrasena, PASSWORD_DEFAULT), $id
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE usuarios SET nombre_completo=?, nombre_usuario=?, telefono=?,
                    domicilio=?, rol=?, sucursal_id=? WHERE usuario_id=?
                ");
                $stmt->execute([$nombre_completo, $nombre_usuario, $telefono, $domicilio, $rol, $sucursal_id, $id]);
            }
            header('Location: usuarios.php?msg=editado');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO usuarios (sucursal_id, nombre_completo, telefono, domicilio, nombre_usuario, contrasena, rol, activo)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $sucursal_id, $nombre_completo, $telefono, $domicilio,
                $nombre_usuario, password_hash($contrasena, PASSWORD_DEFAULT), $rol
            ]);
            header('Location: usuarios.php?msg=creado');
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
    <title><?= $esEdicion ? 'Editar' : 'Nuevo' ?> Usuario â€” FerreterÃ­a Aldrete</title>
    <link rel="stylesheet" href="css/formUsuario.css">

</head>
<body>

<?php renderAdminSidebar('form_usuario'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2><?= $esEdicion ? 'Editar Usuario' : 'Nuevo Usuario' ?></h2>
        </div>
        <div class="topbar-right">
            <span>Hola, <?= htmlspecialchars($_SESSION['nombre_completo']) ?></span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesiÃ³n</button>
            </form>
        </div>
    </div>

    <div class="content">
        <div class="breadcrumb">
            <a href="usuarios.php">Usuarios</a> / <?= $esEdicion ? 'Editar usuario' : 'Nuevo usuario' ?>
        </div>

        <div class="form-card">
            <h1><?= $esEdicion ? 'Editar usuario' : 'Agregar nuevo usuario' ?></h1>

            <?php if (!empty($errores)): ?>
                <div class="errores">
                    <strong>Por favor corrige los siguientes errores:</strong>
                    <ul>
                        <?php foreach ($errores as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label>Nombre completo *</label>
                        <input type="text" name="nombre_completo"
                            value="<?= htmlspecialchars($_POST['nombre_completo'] ?? $usuario['nombre_completo'] ?? '') ?>"
                            placeholder="Ej. Juan PÃ©rez">
                    </div>
                    <div class="form-group">
                        <label>Nombre de usuario *</label>
                        <input type="text" name="nombre_usuario"
                            value="<?= htmlspecialchars($_POST['nombre_usuario'] ?? $usuario['nombre_usuario'] ?? '') ?>"
                            placeholder="Ej. jperez">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>TelÃ©fono</label>
                        <input type="text" name="telefono"
                            value="<?= htmlspecialchars($_POST['telefono'] ?? $usuario['telefono'] ?? '') ?>"
                            placeholder="10 dÃ­gitos">
                    </div>
                    <div class="form-group">
                        <label>Domicilio</label>
                        <input type="text" name="domicilio"
                            value="<?= htmlspecialchars($_POST['domicilio'] ?? $usuario['domicilio'] ?? '') ?>"
                            placeholder="DirecciÃ³n del usuario">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Rol *</label>
                        <select name="rol">
                            <option value="">-- Selecciona un rol --</option>
                            <?php
                            $roles = ['Administrador', 'Inventario', 'Cajero', 'Inventario/Cajero'];
                            $rolActual = $_POST['rol'] ?? $usuario['rol'] ?? '';
                            foreach ($roles as $r):
                            ?>
                            <option value="<?= $r ?>" <?= $rolActual === $r ? 'selected' : '' ?>>
                                <?= $r ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sucursal *</label>
                        <select name="sucursal_id">
                            <option value="">-- Selecciona sucursal --</option>
                            <?php
                            $sucursalActual = intval($_POST['sucursal_id'] ?? $usuario['sucursal_id'] ?? 0);
                            foreach ($sucursales as $s):
                            ?>
                            <option value="<?= $s['sucursal_id'] ?>" <?= $sucursalActual === $s['sucursal_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['nombre']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><?= $esEdicion ? 'Nueva contraseÃ±a' : 'ContraseÃ±a *' ?></label>
                        <input type="password" name="contrasena" placeholder="MÃ­nimo 6 caracteres">
                        <?php if ($esEdicion): ?>
                            <span class="hint">DÃ©jala en blanco si no quieres cambiarla.</span>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Confirmar contraseÃ±a</label>
                        <input type="password" name="confirmar" placeholder="Repite la contraseÃ±a">
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn-guardar" type="submit">
                        <?= $esEdicion ? 'Guardar cambios' : 'Crear usuario' ?>
                    </button>
                    <a class="btn-cancelar" href="usuarios.php">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}
</script>
</body>
</html>
