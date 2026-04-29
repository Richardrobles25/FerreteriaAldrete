<?php
function verificarSesion() {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: /index.php');
        exit();
    }
}

function verificarRol($rolesPermitidos) {
    if (!in_array($_SESSION['rol'], $rolesPermitidos)) {
        header('Location: /index.php');
        exit();
    }
}
