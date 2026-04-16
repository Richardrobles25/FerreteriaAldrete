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
    <title>Ferremateriales Aldrete</title>
    <style>
        :root {
            --azul-principal: #17b6f1;
            --azul-profundo: #099ddb;
            --rojo-marca: #ff1721;
            --negro-marca: #2b2d33;
            --gris-texto: #5b6670;
            --gris-borde: #e5ebf1;
            --gris-fondo: #f5f7fa;
            --blanco: #ffffff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            background-color: var(--gris-fondo);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            padding: 24px;
        }

        .login-box {
            background: var(--blanco);
            padding: 34px 34px 30px;
            border-radius: 20px;
            border: 1px solid var(--gris-borde);
            box-shadow: 0 16px 40px rgba(43,45,51,0.08);
            width: 100%;
            max-width: 420px;
        }

        .brand {
            text-align: center;
            margin-bottom: 26px;
        }

        .brand img {
            width: 100%;
            max-width: 250px;
            height: auto;
            display: block;
            margin: 0 auto 14px;
        }

        .brand p {
            color: var(--gris-texto);
            font-size: 14px;
            line-height: 1.45;
        }

        h1 { display: none; }

        .login-title {
            text-align: center;
            margin-bottom: 24px;
            color: transparent;
            font-size: 0;
            font-weight: 800;
        }

        .login-title::before {
            content: "Iniciar Sesi�n Ferremateriales ";
            color: var(--negro-marca);
            font-size: 24px;
        }

        .login-title::after {
    content: "Aldrete";
    color: var(--azul-principal);
    font-size: 24px;
    font-weight: 900;
}

        label {
            display: block;
            margin-bottom: 6px;
            color: var(--negro-marca);
            font-size: 13px;
            font-weight: 700;
        }

        input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--gris-borde);
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 20px;
            color: var(--negro-marca);
            background: #fbfdff;
        }

        input:focus {
            outline: none;
            border-color: var(--azul-principal);
            box-shadow: 0 0 0 3px rgba(23,182,241,0.12);
            background: var(--blanco);
        }

        button {
            width: 100%;
            padding: 12px;
            background-color: var(--azul-principal);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            cursor: pointer;
            font-weight: 700;
        }

        button:hover { background-color: var(--azul-profundo); }

        .error {
            background: #fff4f4;
            color: #c53030;
            border: 1px solid rgba(255,23,33,0.15);
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 16px;
            text-align: center;
        }

        .hint {
            margin-top: 16px;
            text-align: center;
            color: var(--gris-texto);
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="brand">
            <div class="login-title">Iniciar sesi�n /Ferremateriales Aldrete</div>
            <img src="/logo.jpeg" alt="Logo Ferremateriales Aldrete">
        </div>
        
        <h1>Ferremateriales Aldrete</h1>

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

            <label>Contrase�a</label>
            <input 
                type="password" 
                name="contrasena"
                placeholder="Ingresa tu contrase�a"
            >

            <button type="submit">Iniciar sesi�n</button>
        </form>
        <div class="hint">Ferremateriales Aldrete</div>
    </div>
</body>
</html>

