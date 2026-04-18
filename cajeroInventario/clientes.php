<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

// Eliminar cliente
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $pdo->prepare("UPDATE clientes SET activo = 0 WHERE cliente_id = ?")->execute([$id]);
    header('Location: clientes.php?msg=eliminado');
    exit();
}

// Toggle activo
if (isset($_GET['toggle'])) {
    $id = intval($_GET['toggle']);
    $pdo->prepare("UPDATE clientes SET activo = NOT activo WHERE cliente_id = ?")->execute([$id]);
    header('Location: clientes.php');
    exit();
}

$errores   = [];
$editando  = null;
$esEdicion = isset($_GET['editar']);

if ($esEdicion) {
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE cliente_id = ?");
    $stmt->execute([intval($_GET['editar'])]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre_completo    = trim($_POST['nombre_completo'] ?? '');
    $telefono           = trim($_POST['telefono'] ?? '');
    $direccion          = trim($_POST['direccion'] ?? '');
    $correo             = trim($_POST['correo'] ?? '');
    $descuento_fijo     = floatval($_POST['descuento_fijo'] ?? 0);
    $notas              = trim($_POST['notas'] ?? '');
    $credito_autorizado = isset($_POST['credito_autorizado']) ? 1 : 0;
    $limite_credito     = floatval($_POST['limite_credito'] ?? 0);
    $cliente_id         = intval($_POST['cliente_id'] ?? 0);

    if (!$nombre_completo) $errores[] = 'El nombre es obligatorio.';

    if (empty($errores)) {
        if ($cliente_id) {
            $pdo->prepare("UPDATE clientes SET nombre_completo=?, telefono=?, direccion=?, correo=?, descuento_fijo=?, notas=?, credito_autorizado=?, limite_credito=? WHERE cliente_id=?")
                ->execute([$nombre_completo, $telefono, $direccion, $correo, $descuento_fijo, $notas, $credito_autorizado, $limite_credito, $cliente_id]);
            header('Location: clientes.php?msg=editado');
        } else {
            $pdo->prepare("INSERT INTO clientes (nombre_completo, telefono, direccion, correo, descuento_fijo, notas, credito_autorizado, limite_credito, activo) VALUES (?,?,?,?,?,?,?,?,1)")
                ->execute([$nombre_completo, $telefono, $direccion, $correo, $descuento_fijo, $notas, $credito_autorizado, $limite_credito]);
            header('Location: clientes.php?msg=creado');
        }
        exit();
    }
}

$busqueda = trim($_GET['buscar'] ?? '');
if ($busqueda) {
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE activo = 1 AND nombre_completo LIKE ? ORDER BY nombre_completo ASC");
    $stmt->execute(['%'.$busqueda.'%']);
} else {
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE activo = 1 ORDER BY nombre_completo ASC");
    $stmt->execute();
}
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes — Ferretería Aldrete</title>
</head>
<body>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Arial, sans-serif; display: flex; height: 100vh; overflow: hidden; }
    .sidebar { width: 220px; background: white; border-right: 1px solid #e8e8e8; display: flex; flex-direction: column; transition: width 0.3s; flex-shrink: 0; overflow: hidden; }
    .sidebar.collapsed { width: 0; }
    .sidebar-header { padding: 18px 16px; border-bottom: 1px solid #f0f0f0; }
    .sidebar-header h3 { font-size: 14px; font-weight: 700; color: #14ace7; margin: 0; }
    .sidebar-header p { font-size: 11px; color: #999; margin: 4px 0 0; }
    .sidebar-menu { flex: 1; padding: 8px 0; overflow-y: auto; }
    .menu-item { display: block; padding: 10px 16px; font-size: 13px; color: #555; cursor: pointer; border-left: 3px solid transparent; text-decoration: none; transition: all 0.15s; white-space: nowrap; }
    .menu-item:hover { background: #eef8ff; color: #14ace7; }
    .menu-item.active { background: #eef8ff; border-left-color: #14ace7; color: #14ace7; font-weight: 600; }
    .divider { height: 1px; background: #f0f0f0; margin: 6px 8px; }
    .sidebar-footer { padding: 12px 16px; border-top: 1px solid #f0f0f0; font-size: 11px; color: #bbb; white-space: nowrap; }
    .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #f7f7f7; }
    .topbar { background: #14ace7; color: white; padding: 0 20px; height: 52px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .topbar-left { display: flex; align-items: center; gap: 12px; }
    .topbar h2 { font-size: 15px; font-weight: 600; }
    .toggle-btn { background: none; border: none; color: white; cursor: pointer; font-size: 20px; padding: 4px 8px; border-radius: 4px; }
    .toggle-btn:hover { background: rgba(255,255,255,0.2); }
    .topbar-right { display: flex; align-items: center; gap: 14px; font-size: 13px; }
    .logout-btn { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); color: white; padding: 5px 14px; border-radius: 5px; cursor: pointer; font-size: 12px; }
    .logout-btn:hover { background: rgba(255,255,255,0.3); }
    .content { flex: 1; padding: 24px; overflow-y: auto; display: grid; grid-template-columns: 1fr 360px; gap: 20px; align-items: start; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 16px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .barra-busqueda { display: flex; gap: 10px; margin-bottom: 14px; }
    .barra-busqueda input { flex: 1; padding: 9px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .barra-busqueda input:focus { outline: none; border-color: #14ace7; }
    .btn-buscar { background: #14ace7; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 10px 13px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 10px 13px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .acciones { display: flex; gap: 5px; flex-wrap: wrap; }
    .btn-accion { padding: 4px 10px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #e3f2fd; color: #1565c0; }
    .btn-editar:hover { background: #bbdefb; }
    .btn-eliminar { background: #fdecea; color: #c0392b; }
    .btn-eliminar:hover { background: #ffcdd2; }
    .badge-credito { background: #e8f5e9; color: #2e7d32; font-size: 11px; padding: 2px 8px; border-radius: 99px; font-weight: 600; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    .form-group { margin-bottom: 13px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; color: #333; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .check-row { display: flex; align-items: center; gap: 8px; margin-bottom: 13px; font-size: 13px; color: #555; }
    .check-row input { width: auto; }
    .credito-campos { display: none; }
    .credito-campos.visible { display: block; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; width: 100%; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar-edit { background: white; color: #666; border: 1px solid #ddd; padding: 10px; border-radius: 6px; cursor: pointer; font-size: 13px; width: 100%; margin-top: 8px; text-decoration: none; display: block; text-align: center; }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>Ferretería Aldrete</h3>
        <p>Cajero / Inventario</p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item" href="inicioCajeroInventario.php">Inicio</a>
        <div class="divider"></div>

        <div class="menu-label">Ventas</div>
        <a class="menu-item" href="nuevaVenta.php">Nueva venta</a>
        <a class="menu-item" href="historialVentas.php">Historial de ventas</a>
        <a class="menu-item" href="ventasPendientes.php">Ventas pendientes</a>
        <a class="menu-item" href="devoluciones.php">Devoluciones</a>
        <div class="divider"></div>

        <div class="menu-label">Caja</div>
        <a class="menu-item" href="abrirCaja.php">Abrir caja</a>
        <a class="menu-item" href="corteCaja.php">Corte de caja</a>
        <a class="menu-item" href="historialCortes.php">Historial de cortes</a>
        <div class="divider"></div>

        <div class="menu-label">Clientes</div>
        <a class="menu-item active" href="clientes.php">Clientes</a>
        <a class="menu-item" href="creditos.php">Créditos</a>
        <a class="menu-item" href="abonos.php">Abonos</a>
        <div class="divider"></div>

        <div class="menu-label">Inventario</div>
        <a class="menu-item" href="productos.php">Productos</a>
        <a class="menu-item" href="categorias.php">Categorías</a>
        <a class="menu-item" href="entradas.php">Entradas</a>
        <a class="menu-item" href="salidas.php">Salidas y mermas</a>
        <a class="menu-item" href="historial.php">Movimientos</a>
        <div class="divider"></div>

        <div class="menu-label">Proveedores</div>
        <a class="menu-item" href="proveedores.php">Proveedores</a>
        <a class="menu-item" href="compras.php">Compras</a>
        <div class="divider"></div>

        <div class="menu-label">Más</div>
        <a class="menu-item" href="paquetes.php">Paquetes</a>
        <a class="menu-item" href="transferencias.php">Transferencias</a>
        <a class="menu-item" href="masVendidos.php">Más vendidos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Clientes</h2>
        </div>
        <div class="topbar-right">
            <span>Hola, <?= htmlspecialchars($_SESSION['nombre_completo']) ?></span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="content">
        <!-- Lista -->
        <div>
            <?php if (isset($_GET['msg'])): ?>
                <?php $msgs = ['creado'=>'Cliente registrado.','editado'=>'Cliente actualizado.','eliminado'=>'Cliente eliminado.']; ?>
                <div class="msg msg-exito"><?= $msgs[$_GET['msg']] ?? '' ?></div>
            <?php endif; ?>

            <form method="GET" action="clientes.php">
                <div class="barra-busqueda">
                    <input type="text" name="buscar" placeholder="Buscar por nombre..." value="<?= htmlspecialchars($busqueda) ?>">
                    <button class="btn-buscar" type="submit">Buscar</button>
                    <?php if ($busqueda): ?><a class="btn-limpiar" href="clientes.php">Limpiar</a><?php endif; ?>
                </div>
            </form>

            <div class="card" style="padding:0;">
                <?php if (count($clientes) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Teléfono</th>
                            <th>Descuento</th>
                            <th>Crédito</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes as $c): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($c['nombre_completo']) ?></strong>
                                <?php if ($c['correo']): ?>
                                    <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($c['correo']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($c['telefono'] ?? '—') ?></td>
                            <td><?= $c['descuento_fijo'] > 0 ? $c['descuento_fijo'].'%' : '—' ?></td>
                            <td>
                                <?php if ($c['credito_autorizado']): ?>
                                    <span class="badge-credito">Autorizado</span>
                                    <div style="font-size:11px;color:#aaa;">Límite: $<?= number_format($c['limite_credito'],2) ?></div>
                                <?php else: ?>
                                    <span style="color:#aaa;font-size:12px;">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="acciones">
                                    <a class="btn-accion btn-editar" href="clientes.php?editar=<?= $c['cliente_id'] ?>">Editar</a>
                                    <a class="btn-accion btn-eliminar" href="clientes.php?eliminar=<?= $c['cliente_id'] ?>" onclick="return confirm('¿Eliminar este cliente?')">Eliminar</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay clientes registrados.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario -->
        <div>
            <div class="card">
                <h3><?= $editando ? 'Editar cliente' : 'Nuevo cliente' ?></h3>

                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach($errores as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="cliente_id" value="<?= $editando['cliente_id'] ?? 0 ?>">

                    <div class="form-group">
                        <label>Nombre completo *</label>
                        <input type="text" name="nombre_completo" value="<?= htmlspecialchars($_POST['nombre_completo'] ?? $editando['nombre_completo'] ?? '') ?>" placeholder="Ej. Juan García">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Teléfono</label>
                            <input type="text" name="telefono" value="<?= htmlspecialchars($_POST['telefono'] ?? $editando['telefono'] ?? '') ?>" placeholder="10 dígitos">
                        </div>
                        <div class="form-group">
                            <label>Descuento fijo (%)</label>
                            <input type="number" name="descuento_fijo" value="<?= $_POST['descuento_fijo'] ?? $editando['descuento_fijo'] ?? 0 ?>" step="0.01" min="0" max="100" placeholder="0">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Dirección</label>
                        <input type="text" name="direccion" value="<?= htmlspecialchars($_POST['direccion'] ?? $editando['direccion'] ?? '') ?>" placeholder="Calle, número, colonia">
                    </div>

                    <div class="form-group">
                        <label>Correo</label>
                        <input type="email" name="correo" value="<?= htmlspecialchars($_POST['correo'] ?? $editando['correo'] ?? '') ?>" placeholder="correo@ejemplo.com">
                    </div>

                    <div class="form-group">
                        <label>Notas</label>
                        <input type="text" name="notas" value="<?= htmlspecialchars($_POST['notas'] ?? $editando['notas'] ?? '') ?>" placeholder="Observaciones del cliente">
                    </div>

                    <div class="check-row">
                        <input type="checkbox" name="credito_autorizado" id="chkCredito"
                            <?= ($_POST['credito_autorizado'] ?? $editando['credito_autorizado'] ?? 0) ? 'checked' : '' ?>
                            onchange="toggleCredito(this.checked)">
                        <label for="chkCredito">Autorizar crédito a este cliente</label>
                    </div>

                    <div class="credito-campos <?= ($_POST['credito_autorizado'] ?? $editando['credito_autorizado'] ?? 0) ? 'visible' : '' ?>" id="creditoCampos">
                        <div class="form-group">
                            <label>Límite de crédito</label>
                            <input type="number" name="limite_credito" value="<?= $_POST['limite_credito'] ?? $editando['limite_credito'] ?? 0 ?>" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>

                    <button class="btn-guardar" type="submit">
                        <?= $editando ? 'Guardar cambios' : 'Registrar cliente' ?>
                    </button>
                    <?php if ($editando): ?>
                        <a class="btn-cancelar-edit" href="clientes.php">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}
function toggleCredito(checked) {
    document.getElementById('creditoCampos').classList.toggle('visible', checked);
}
</script>
</body>
</html>
