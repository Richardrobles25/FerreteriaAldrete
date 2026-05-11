<?php
function verificarSesion() {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: /index.php');
        exit();
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
