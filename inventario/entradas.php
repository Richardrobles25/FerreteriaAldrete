<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

$productoPreseleccionado = null;
if (isset($_GET['producto_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM productos WHERE producto_id = ? AND sucursal_id = ?");
    $stmt->execute([intval($_GET['producto_id']), $_SESSION['sucursal_id']]);
    $productoPreseleccionado = $stmt->fetch(PDO::FETCH_ASSOC);
}

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $producto_id  = intval($_POST['producto_id'] ?? 0);
    $cantidad     = intval($_POST['cantidad'] ?? 0);
    $motivo       = trim($_POST['motivo'] ?? 'Entrada manual');

    if (!$producto_id) $errores[] = 'Selecciona un producto.';
    if ($cantidad < 1) $errores[] = 'La cantidad debe ser al menos 1.';

    if (empty($errores)) {
        $stmtP = $pdo->prepare("SELECT stock_actual, nombre_producto FROM productos WHERE producto_id = ? AND sucursal_id = ?");
        $stmtP->execute([$producto_id, $_SESSION['sucursal_id']]);
        $prod = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            $errores[] = 'Producto no encontrado.';
        } else {
            $stockAnterior = floatval($prod['stock_actual']);
            $stockNuevo    = $stockAnterior + $cantidad;

            $pdo->prepare("UPDATE productos SET stock_actual = ? WHERE producto_id = ?")->execute([$stockNuevo, $producto_id]);
            $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,'Entrada',?,?,?,?)")
                ->execute([$producto_id, $_SESSION['usuario_id'], $cantidad, $stockAnterior, $stockNuevo, $motivo]);

            header('Location: entradas.php?msg=exito&prod='.urlencode($prod['nombre_producto']));
            exit();
        }
    }
}

// Historial de entradas recientes
$stmtH = $pdo->prepare("
    SELECT m.cantidad, m.stock_anterior, m.stock_nuevo, m.motivo, m.created_at,
           p.nombre_producto, p.codigo
    FROM movimientos_inventario m
    JOIN productos p ON m.producto_id = p.producto_id
    WHERE m.tipo = 'Entrada' AND p.sucursal_id = ?
    ORDER BY m.created_at DESC
    LIMIT 25
");
$stmtH->execute([$_SESSION['sucursal_id']]);
$historial = $stmtH->fetchAll(PDO::FETCH_ASSOC);

// Productos de la sucursal
$stmtProds = $pdo->prepare("
    SELECT producto_id, codigo, nombre_producto, stock_actual, stock_minimo, stock_maximo
    FROM productos
    WHERE sucursal_id = ? AND activo = 1
    ORDER BY nombre_producto ASC
");
$stmtProds->execute([$_SESSION['sucursal_id']]);
$productos = $stmtProds->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entradas de Productos — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; display: grid; grid-template-columns: 380px 1fr; gap: 20px; align-items: start; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 16px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group select:focus { outline: none; border-color: #14ace7; }
    .stock-info { background: #e8f5e9; border-radius: 6px; padding: 10px 14px; font-size: 13px; color: #2e7d32; margin-bottom: 14px; display: none; }
    .stock-info.bajo { background: #fdecea; color: #c0392b; }
    .motivos-rapidos { display: flex; gap: 5px; flex-wrap: wrap; margin-bottom: 8px; }
    .motivo-chip { background: #f0f0f0; border: none; padding: 5px 12px; border-radius: 99px; font-size: 12px; cursor: pointer; color: #555; }
    .motivo-chip:hover { background: #bbdefb; color: #1565c0; }
    .btn-guardar { background: #2e7d32; color: white; border: none; padding: 12px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; width: 100%; }
    .btn-guardar:hover { background: #1b5e20; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 10px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 10px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>Ferretería Aldrete</h3>
        <p><?= $_SESSION['rol'] ?></p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item" href="inicioInventario.php">Inicio</a>
        <a class="menu-item" href="productos.php">Productos</a>
        <a class="menu-item" href="categorias.php">Categorías</a>
        <div class="divider"></div>
        <a class="menu-item active" href="entradas.php">Entradas de productos</a>
        <a class="menu-item" href="salidas.php">Salidas y mermas</a>
        <a class="menu-item" href="historialMovimientos.php">Historial de movimientos</a>
        <div class="divider"></div>
        <a class="menu-item" href="proveedores.php">Proveedores</a>
        <a class="menu-item" href="compras.php">Compras a proveedor</a>
        <div class="divider"></div>
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
            <h2>Entradas de productos</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Formulario -->
        <div>
            <div class="card">
                <h3>Registrar entrada</h3>

                <?php if (isset($_GET['msg']) && $_GET['msg'] === 'exito'): ?>
                    <div class="msg msg-exito">
                        Entrada registrada: <strong><?= htmlspecialchars($_GET['prod'] ?? '') ?></strong>
                    </div>
                <?php endif; ?>
                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach($errores as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label>Producto *</label>
                        <select name="producto_id" id="selectProducto" onchange="mostrarStockInfo(this)">
                            <option value="">-- Selecciona un producto --</option>
                            <?php foreach ($productos as $p): ?>
                                <option value="<?= $p['producto_id'] ?>"
                                    data-stock="<?= $p['stock_actual'] ?>"
                                    data-minimo="<?= $p['stock_minimo'] ?>"
                                    data-maximo="<?= $p['stock_maximo'] ?>"
                                    <?= ($productoPreseleccionado && $productoPreseleccionado['producto_id']==$p['producto_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['codigo'].' — '.$p['nombre_producto']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="stock-info" id="stockInfo">
                        Stock actual: <strong id="stockActualVal"></strong>
                        · Mínimo: <span id="stockMinimoVal"></span>
                        · Máximo: <span id="stockMaximoVal"></span>
                    </div>

                    <div class="form-group">
                        <label>Cantidad a ingresar *</label>
                        <input type="number" name="cantidad" id="inputCantidad"
                            placeholder="0" step="1" min="1"
                            value="<?= $_POST['cantidad'] ?? '' ?>">
                    </div>

                    <div class="form-group">
                        <label>Motivo / Descripción</label>
                        <div class="motivos-rapidos">
                            <button type="button" class="motivo-chip" onclick="setMotivo('Compra a proveedor')">Compra a proveedor</button>
                            <button type="button" class="motivo-chip" onclick="setMotivo('Ajuste de inventario')">Ajuste</button>
                            <button type="button" class="motivo-chip" onclick="setMotivo('Devolución de cliente')">Devolución</button>
                            <button type="button" class="motivo-chip" onclick="setMotivo('Inventario inicial')">Inventario inicial</button>
                        </div>
                        <input type="text" name="motivo" id="inputMotivo"
                            value="<?= htmlspecialchars($_POST['motivo'] ?? 'Entrada manual') ?>"
                            placeholder="Describe el origen de esta entrada">
                    </div>

                    <button class="btn-guardar" type="submit">Registrar entrada</button>
                </form>
            </div>
        </div>

        <!-- Historial -->
        <div>
            <div class="card" style="padding:0;">
                <div style="padding:16px 20px;border-bottom:0.5px solid #eee;">
                    <h3 style="margin:0;">Entradas recientes</h3>
                </div>
                <?php if (count($historial) > 0): ?>
                <table>
                    <thead>
                        <tr><th>Producto</th><th>Cant. ingresada</th><th>Stock ant.</th><th>Stock nuevo</th><th>Motivo</th><th>Fecha</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historial as $h): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($h['nombre_producto']) ?></strong>
                                <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($h['codigo']) ?></div>
                            </td>
                            <td style="color:#2e7d32;font-weight:700;">+<?= number_format($h['cantidad'],0) ?></td>
                            <td style="color:#888;"><?= number_format($h['stock_anterior'],2) ?></td>
                            <td style="font-weight:600;"><?= number_format($h['stock_nuevo'],2) ?></td>
                            <td style="font-size:12px;color:#888;"><?= htmlspecialchars($h['motivo']??'—') ?></td>
                            <td style="font-size:12px;color:#aaa;"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay entradas registradas.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function mostrarStockInfo(sel) {
    const opt  = sel.options[sel.selectedIndex];
    const info = document.getElementById('stockInfo');
    if (!sel.value) { info.style.display = 'none'; return; }

    const stock   = parseFloat(opt.dataset.stock);
    const minimo  = parseFloat(opt.dataset.minimo);
    const maximo  = parseFloat(opt.dataset.maximo);

    document.getElementById('stockActualVal').textContent = stock.toFixed(2);
    document.getElementById('stockMinimoVal').textContent = minimo.toFixed(2);
    document.getElementById('stockMaximoVal').textContent = maximo.toFixed(2);

    info.style.display = 'block';
    info.className = 'stock-info' + (stock <= minimo ? ' bajo' : '');
}

function setMotivo(texto) {
    document.getElementById('inputMotivo').value = texto;
}

// Si hay producto preseleccionado, mostrar info
window.addEventListener('load', () => {
    const sel = document.getElementById('selectProducto');
    if (sel.value) mostrarStockInfo(sel);
});
</script>
</body>
</html>
