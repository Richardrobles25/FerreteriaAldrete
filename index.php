<?php
session_start();
require_once 'config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre_usuario = trim($_POST['nombre_usuario'] ?? '');
    $contrasena     = trim($_POST['contrasena'] ?? '');

    if ($nombre_usuario && $contrasena) {
        $stmt = $pdo->prepare("
            SELECT * FROM usuarios 
            WHERE nombre_usuario = ? AND activo = 1
        ");
        $stmt->execute([$nombre_usuario]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario && password_verify($contrasena, $usuario['contrasena'])) {
            $_SESSION['usuario_id']      = $usuario['usuario_id'];
            $_SESSION['nombre_completo'] = $usuario['nombre_completo'];
            $_SESSION['rol']             = $usuario['rol'];
            $_SESSION['sucursal_id']     = $usuario['sucursal_id'];

            switch ($usuario['rol']) {
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
        } else {
            $error = 'Usuario o contraseña incorrectos.';
        }
    } else {
        $error = 'Por favor completa todos los campos.';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ferretería Aldrete</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .login-box {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 380px;
        }

        h1 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
            font-size: 22px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: #555;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        input:focus {
            outline: none;
            border-color: #e65c00;
        }

        button {
            width: 100%;
            padding: 12px;
            background-color: #e65c00;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover { background-color: #cc5200; }

        .error {
            background: #fdecea;
            color: #c0392b;
            padding: 10px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 16px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>Ferretería Aldrete</h1>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <label>Usuario</label>
            <input 
                type="text" 
                name="nombre_usuario"
                value="<?= htmlspecialchars($_POST['nombre_usuario'] ?? '') ?>"
                placeholder="Ingresa tu usuario"
                autofocus
            >

            <label>Contraseña</label>
            <input 
                type="password" 
                name="contrasena"
                placeholder="Ingresa tu contraseña"
            >

            <button type="submit">Iniciar sesión</button>
        </form>
    </div>
</body>
</html>