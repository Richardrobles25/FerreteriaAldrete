<?php
session_start();

// Si no hay sesión, lo mandas al login
if (!isset($_SESSION['rol'])) {
    header('Location: /index.php');
    exit();
}

// Redirección según rol
switch ($_SESSION['rol']) {
    case 'Administrador':
        header('Location: /admin/inicioAdmin.php');
        break;
    case 'Inventario':
        header('Location: /inventario/inicioInventario.php');
        break;
    case 'Cajero':
        header('Location: /cajero/inicioCajero.php');
        break;
    case 'Inventario/Cajero':
        header('Location: /cajeroInventario/inicioCajeroInventario.php');
        break;
    default:
        header('Location: /index.php');
}

exit();
