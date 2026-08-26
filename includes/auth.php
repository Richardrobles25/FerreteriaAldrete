<?php
function verificarSesion() {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: /index.php');
        exit();
    }

    // [FIX-CRIT-A-03] Antes, rol/sucursal_id/activo quedaban congelados en la sesión
    // desde el login y nunca se contrastaban contra la BD. Un usuario desactivado (o
    // cambiado de rol/sucursal) seguía operando con total normalidad mientras tuviera
    // la sesión abierta. Ahora se revalida en cada petición: si ya no existe o está
    // inactivo, se destruye la sesión; si su rol o sucursal cambiaron, se refresca la
    // sesión con el valor real antes de que verificarRol()/el resto de la página lo usen.
    global $pdo;
    if (isset($pdo)) {
        $stmtRevalida = $pdo->prepare("SELECT rol, sucursal_id, activo FROM usuarios WHERE usuario_id = ?");
        $stmtRevalida->execute([$_SESSION['usuario_id']]);
        $usuarioActual = $stmtRevalida->fetch(PDO::FETCH_ASSOC);

        if (!$usuarioActual || !intval($usuarioActual['activo'])) {
            session_unset();
            session_destroy();
            header('Location: /index.php?msg=sesion_invalida');
            exit();
        }

        $_SESSION['rol']         = $usuarioActual['rol'];
        $_SESSION['sucursal_id'] = $usuarioActual['sucursal_id'];
    }

    // [AUTOFIX] SEC-01: Generar CSRF token por sesion si aun no existe
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function verificarRol($rolesPermitidos) {
    if (!in_array($_SESSION['rol'], $rolesPermitidos)) {
        header('Location: /index.php');
        exit();
    }
}

// [AUTOFIX] SEC-01: Helper para verificar CSRF token (GET y POST)
function verificarCSRF(string $token): bool {
    return isset($_SESSION['csrf_token'])
        && !empty($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

// [AUTOFIX] SEC-01: Verificar CSRF y redirigir con error si falla
function requerirCSRF(string $token, string $redirectUrl): void {
    if (!verificarCSRF($token)) {
        header('Location: ' . $redirectUrl . '?msg=error_token');
        exit();
    }
}
