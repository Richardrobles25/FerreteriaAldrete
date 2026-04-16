<?php
session_start();

// Si no hay sesiÃ³n, lo mandas al login
if (!isset($_SESSION['rol'])) {
    header('Location: /index.php');
    exit();
}

// RedirecciÃ³n segÃºn rol
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
