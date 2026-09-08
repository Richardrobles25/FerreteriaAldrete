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
// [FIX-CSRF-TYPEERROR-ARRAY] El type-hint "string $token" hacia que un $_POST['_token']
// mandado como array (ej. "_token[]=x", posible con cualquier cliente HTTP que no sea un
// <form> normal) lanzara un TypeError SIN CAPTURAR antes de que esta funcion siquiera
// empezara a ejecutarse -- probado en vivo en admin/formGasto.php y
// admin/gastos_categorias.php: "Fatal error: Uncaught TypeError... must be of type string,
// array given", con la ruta completa del servidor y el stack trace expuestos en la
// respuesta (HTTP 200, sin ningun mensaje de error normal). Esta funcion la usan
// practicamente todos los formularios del sistema, asi que se corrige aqui una sola vez
// en vez de en cada archivo. Se quita el type-hint "string" (para que PHP no rechace la
// llamada antes de entrar) y se valida el tipo adentro con is_string().
function verificarCSRF($token): bool {
    return isset($_SESSION['csrf_token'])
        && is_string($token)
        && $token !== ''
        && hash_equals($_SESSION['csrf_token'], $token);
}

// [AUTOFIX] SEC-01: Verificar CSRF y redirigir con error si falla
function requerirCSRF($token, string $redirectUrl): void {
    if (!verificarCSRF($token)) {
        header('Location: ' . $redirectUrl . '?msg=error_token');
        exit();
    }
}
