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
    // [FIX-ALTO-A-04] CSRF ausente antes — permitía crear/editar cuentas (incluidas
    // Administrador) desde un POST sin ningún token.
    requerirCSRF($_POST['_token'] ?? '', $esEdicion ? 'formUsuario.php?id=' . intval($_GET['id']) : 'formUsuario.php');
    $nombre_completo = trim($_POST['nombre_completo'] ?? '');
    // [FIX-MEDIO-A-12] usuarios.nombre_usuario es VARCHAR(25): antes se validaba el
    // duplicado contra la cadena COMPLETA que mandó el navegador, pero la BD ya guarda
    // los nombres truncados a 25. Con un nombre >25 caracteres, la comprobación de
    // duplicado comparaba contra un valor que nunca existe tal cual en la BD (pasaba
    // limpio), y al guardar, el truncado podía chocar con un nombre_usuario ya existente
    // — que ahora se atrapa como excepción (ver A-11) en vez de crear una colisión
    // silenciosa. Truncar aquí, antes de validar, hace que la comprobación compare
    // exactamente lo mismo que se va a guardar.
    $nombre_usuario  = substr(trim($_POST['nombre_usuario'] ?? ''), 0, 25);
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
    // [FIX-ALTO-A-09] Antes solo se comprobaba que $sucursal_id no fuera 0 — nunca que
    // perteneciera a una sucursal activa real. Se podia asignar (o editar) un usuario
    // a una sucursal ya desactivada, y ese usuario seguia operando esa sucursal con
    // normalidad. $sucursales ya viene filtrado a activo=1 (linea de arriba).
    elseif (!in_array($sucursal_id, array_map('intval', array_column($sucursales, 'sucursal_id')), true))
                           $errores[] = 'La sucursal seleccionada no existe o ya no está activa.';
    if ($telefono !== '' && !preg_match('/^\d{10}$/', $telefono)) $errores[] = 'El teléfono debe tener exactamente 10 dígitos numéricos.';
    if (!$esEdicion && !$contrasena) $errores[] = 'La contraseña es obligatoria.';
    if ($contrasena && $contrasena !== $confirmar) $errores[] = 'Las contraseñas no coinciden.';
    if ($contrasena && strlen($contrasena) < 6) $errores[] = 'La contraseña debe tener al menos 6 caracteres.';

    if ($nombre_usuario) {
        $check = $pdo->prepare("SELECT usuario_id FROM usuarios WHERE nombre_usuario = ? AND usuario_id != ?");
        $check->execute([$nombre_usuario, $esEdicion ? intval($_GET['id']) : 0]);
        if ($check->fetch()) $errores[] = 'Ese nombre de usuario ya está en uso.';
    }

    // [FIX-MEDIO-A-11] Antes una PDOException aquí (p. ej. una colisión de nombre_usuario
    // que la comprobación previa no detectó, o cualquier otro rechazo de la BD) se dejaba
    // sin capturar: HTTP 500 con la ruta del servidor expuesta en vez de un error normal.
    if (empty($errores)) {
        try {
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
        } catch (PDOException $e) {
            $errores[] = 'No se pudo guardar el usuario. Verifica los datos (nombre de usuario en uso, sucursal válida) e intenta de nuevo.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $esEdicion ? 'Editar' : 'Nuevo' ?> Usuario — Ferretería Aldrete</title>
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
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesión</button>
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
                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>Nombre completo *</label>
                        <input type="text" name="nombre_completo"
                            value="<?= htmlspecialchars($_POST['nombre_completo'] ?? $usuario['nombre_completo'] ?? '') ?>"
                            placeholder="Ej. Juan Pérez">
                    </div>
                    <div class="form-group">
                        <label>Nombre de usuario *</label>
                        <input type="text" name="nombre_usuario" maxlength="25"
                            value="<?= htmlspecialchars($_POST['nombre_usuario'] ?? $usuario['nombre_usuario'] ?? '') ?>"
                            placeholder="Ej. jperez">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="tel" name="telefono"
                            value="<?= htmlspecialchars($_POST['telefono'] ?? $usuario['telefono'] ?? '') ?>"
                            placeholder="10 dígitos"
                            maxlength="10"
                            pattern="\d{10}"
                            inputmode="numeric"
                            oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)"
                            title="Ingresa exactamente 10 dígitos numéricos">
                    </div>
                    <div class="form-group">
                        <label>Domicilio</label>
                        <input type="text" name="domicilio"
                            value="<?= htmlspecialchars($_POST['domicilio'] ?? $usuario['domicilio'] ?? '') ?>"
                            placeholder="Dirección del usuario">
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
                        <label><?= $esEdicion ? 'Nueva contraseña' : 'Contraseña *' ?></label>
                        <input type="password" name="contrasena" placeholder="Mínimo 6 caracteres">
                        <?php if ($esEdicion): ?>
                            <span class="hint">Déjala en blanco si no quieres cambiarla.</span>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Confirmar contraseña</label>
                        <input type="password" name="confirmar" placeholder="Repite la contraseña">
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

