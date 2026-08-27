<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [FIX-A1] Verificar CSRF antes de procesar la salida
    requerirCSRF($_POST['_token'] ?? '', 'salidas.php');
    $producto_id = intval($_POST['producto_id'] ?? 0);
    $cantidad    = floatval($_POST['cantidad'] ?? 0);
    $motivo      = trim($_POST['motivo'] ?? '');

    if (!$producto_id) $errores[] = 'Selecciona un producto.';
    if ($cantidad <= 0) $errores[] = 'La cantidad debe ser mayor a 0.';
    if (!$motivo)       $errores[] = 'El motivo es obligatorio.';

    if (empty($errores)) {
        // [AUTOFIX] INFO-3E-1: Incluir stock_minimo para detectar si queda bajo el mínimo
        $stmtP = $pdo->prepare("
            SELECT p.nombre_producto, p.tipo_venta, ss.stock_actual, ss.stock_minimo
            FROM productos p
            INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
            WHERE p.producto_id = ?
        ");
        $stmtP->execute([$_SESSION['sucursal_id'], $producto_id]);
        $prod = $stmtP->fetch(PDO::FETCH_ASSOC);

        // [FIX-CONSISTENCIA] Igual que en admin/inventario_salidas.php (FIX-ALTO-C-03):
        // un producto que NO se maneja por Suelto/granel no debe aceptar una salida con
        // cantidad decimal (ej. 2.5 piezas no tiene sentido para el conteo fisico). Faltaba
        // aqui esta validacion.
        if ($prod && $prod['tipo_venta'] !== 'Suelto' && floor($cantidad) != $cantidad) {
            $errores[] = 'Este producto se maneja por unidad; la cantidad debe ser un número entero.';
        }

        if (!empty($errores)) {
            // ya se agrego el error de cantidad no entera; no continuar con el registro.
        } elseif (!$prod) {
            $errores[] = 'Producto no encontrado.';
        } elseif ($cantidad > $prod['stock_actual']) {
            $errores[] = 'La cantidad no puede ser mayor al stock actual (' . floatval($prod['stock_actual']) . ' disponibles).';
        } else {
            // [FIX-A5] Bloquear la fila de stock dentro de una transaccion y revalidar con el
            // valor mas reciente: dos salidas simultaneas del mismo producto podian pisarse
            // (perdida de actualizacion) o dejar stock negativo si ambas pasaban el chequeo
            // de arriba con el mismo valor leido antes de escribir.
            $pdo->beginTransaction();
            $stmtLockStock = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
            $stmtLockStock->execute([$producto_id, $_SESSION['sucursal_id']]);
            $stockAnterior = floatval($stmtLockStock->fetchColumn());

            if ($cantidad > $stockAnterior) {
                $pdo->rollBack();
                $errores[] = 'La cantidad no puede ser mayor al stock actual (' . $stockAnterior . ' disponibles).';
            } else {
                $stockNuevo = $stockAnterior - $cantidad;

                $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")->execute([$stockNuevo, $producto_id, $_SESSION['sucursal_id']]);
                $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Salida',?,?,?,?)")
                    ->execute([$producto_id, $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $cantidad, $stockAnterior, $stockNuevo, $motivo]);
                $pdo->commit();

                // [AUTOFIX] INFO-3E-1: Redirigir con msg=exito_minimo si quedó bajo el mínimo
                $stockMinimo = floatval($prod['stock_minimo'] ?? 0);
                $msgRedir = ($stockMinimo > 0 && $stockNuevo < $stockMinimo) ? 'exito_minimo' : 'exito';
                header('Location: salidas.php?msg=' . $msgRedir);
                exit();
            }
        }
    }
}

// Historial de salidas
$stmt = $pdo->prepare("
    SELECT m.*, p.nombre_producto, p.codigo
    FROM movimientos_inventario m
    JOIN productos p ON m.producto_id = p.producto_id
    -- [FIX] Filtrar por la sucursal del MOVIMIENTO (antes se veían salidas de otras sucursales
    -- si el producto también existía aquí). NULL = registros viejos sin sucursal, se conservan.
    WHERE m.tipo = 'Salida' AND (m.sucursal_id = ? OR m.sucursal_id IS NULL)
    ORDER BY m.created_at DESC LIMIT 30
");
$stmt->execute([$_SESSION['sucursal_id']]);
$historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT p.producto_id, p.codigo, p.nombre_producto, ss.stock_actual, p.tipo_venta
    FROM productos p
    INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
    WHERE p.activo = 1 AND ss.activo = 1
    ORDER BY p.nombre_producto ASC
");
$stmt->execute([$_SESSION['sucursal_id']]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salidas y mermas — Ferretería Aldrete</title>
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
    .menu-label { padding: 8px 16px 4px; font-size: 10px; font-weight: 700; color: #14ace7; text-transform: uppercase; letter-spacing: 0.5px; }
    .sidebar-footer { padding: 12px 16px; border-top: 1px solid #f0f0f0; font-size: 11px; color: #bbb; white-space: nowrap; }
    .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #f7f7f7; }
    .topbar { background: #14ace7; color: white; padding: 0 20px; height: 52px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .topbar-left { display: flex; align-items: center; gap: 12px; }
    .topbar h2 { font-size: 15px; font-weight: 600; }
    .toggle-btn { background: none; border: none; color: white; cursor: pointer; font-size: 20px; padding: 4px 8px; border-radius: 4px; }
    .toggle-btn:hover { background: rgba(255,255,255,0.2); }
    .topbar-right { display: flex; align-items: center; gap: 14px; font-size: 13px; }
    .topbar-right form { display: contents; }
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
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .motivos-rapidos { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
    .motivo-btn { background: #f0f0f0; border: none; padding: 5px 12px; border-radius: 99px; font-size: 12px; cursor: pointer; color: #555; }
    .motivo-btn:hover { background: #bbdefb; color: #1565c0; }
    .prod-busq-wrap { position: relative; }
    .prod-drop { display:none; position:absolute; top:calc(100% + 2px); left:0; right:0; background:#fff; border:1px solid #e0e0e0; border-radius:6px; box-shadow:0 4px 16px rgba(0,0,0,0.10); z-index:999; max-height:240px; overflow-y:auto; }
    .prod-drop-item { display:flex; align-items:center; gap:8px; padding:9px 12px; cursor:pointer; border-bottom:0.5px solid #f5f5f5; min-width:0; }
    .prod-drop-item:hover { background:#eef8ff; }
    .prod-drop-item:last-child { border-bottom:none; }
    .prod-sel-panel { background:#f0f9ff; border:1px solid #dbeafe; border-radius:6px; padding:12px 14px; margin-bottom:14px; }
    .prod-sel-nombre { font-size:13px; font-weight:700; color:#1e3a5f; margin-bottom:10px; }
    .prod-sel-fila { display:flex; align-items:center; gap:10px; }
    .prod-sel-fila input[type=number] { width:90px; padding:8px 10px; border:1px solid #bcd6f7; border-radius:6px; font-size:14px; font-weight:600; text-align:center; }
    .prod-sel-stock { font-size:12px; color:#555; flex:1; }
    .prod-sel-cancelar { background:none; border:none; color:#aaa; font-size:18px; cursor:pointer; line-height:1; padding:2px 6px; border-radius:4px; }
    .prod-sel-cancelar:hover { color:#c0392b; }
    .btn-guardar { background: #c0392b; color: white; border: none; padding: 12px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; width: 100%; }
    .btn-guardar:hover { background: #a93226; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 10px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 10px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    @media (max-width: 768px) {
        body { overflow-x: hidden; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100%; z-index: 300; width: 0; transition: width 0.3s; }
        .sidebar.collapsed { width: 260px; box-shadow: 4px 0 16px rgba(0,0,0,.15); }
        .main { width: 100%; }
        .topbar { padding: 0 12px; height: 48px; }
        .topbar h2 { font-size: 13px; }
        .topbar-right { gap: 8px; font-size: 12px; }
        .topbar-right > span { display: none; }
        .content { padding: 12px !important; display: block !important; }
        .content > div + div { margin-top: 12px; }
        .card { overflow-x: auto; }
        th, td { padding: 8px 10px; font-size: 12px; }
        .form-group input, .form-group select, .form-group textarea { font-size: 16px; }
        .logout-btn { padding: 5px 10px; font-size: 11px; }
    }
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
        <a class="menu-item" href="clientes.php">Clientes</a>
        <a class="menu-item" href="creditos.php">Créditos</a>
        <div class="divider"></div>

        <div class="menu-label">Inventario</div>
        <a class="menu-item" href="productos.php">Productos</a>
        <a class="menu-item" href="categorias.php">Categorías</a>
        <a class="menu-item" href="unidades.php">Unidades de medida</a>
        <a class="menu-item" href="entradas.php">Entradas</a>
        <a class="menu-item active" href="salidas.php">Salidas y mermas</a>
        <a class="menu-item" href="historial.php">Movimientos</a>
        <div class="divider"></div>

        <div class="menu-label">Proveedores</div>
        <a class="menu-item" href="proveedores.php">Proveedores</a>
        <a class="menu-item" href="compras.php">Compras</a>
        <div class="divider"></div>

        <div class="menu-label">Más</div>
        <a class="menu-item" href="paquetes.php">Paquetes</a>
        <a class="menu-item" href="transferencias.php">Transferencias</a>
        <a class="menu-item" href="promociones.php">Promociones</a>
        <a class="menu-item" href="masVendidos.php">Más vendidos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>
<script>
// Restaurar el scroll del sidebar ANTES de que se pinte el resto de la pagina — si se
// hace hasta el <script> del final, primero se ve el sidebar en 0 y luego "salta" a la
// posicion guardada, dando sensacion de lag. Aqui corre justo despues del sidebar, antes
// de que el navegador tenga que parsear el resto del contenido de la pagina.
(function() {
    var menu = document.querySelector('.sidebar-menu');
    if (!menu) return;
    var saved = sessionStorage.getItem('cajeroInvSidebarScroll');
    if (saved !== null) menu.scrollTop = parseInt(saved, 10) || 0;
    menu.addEventListener('scroll', function() {
        sessionStorage.setItem('cajeroInvSidebarScroll', menu.scrollTop);
    });
})();
</script>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Salidas y mermas</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="content">
        <div>
            <div class="card">
                <h3>Registrar salida</h3>

                <?php if (isset($_GET['msg']) && $_GET['msg'] === 'exito' && empty($errores)): ?>
                    <div class="msg msg-exito">Salida registrada correctamente.</div>
                <?php endif; ?>
                <?php if (isset($_GET['msg']) && $_GET['msg'] === 'exito_minimo' && empty($errores)): ?>
                    <!-- [AUTOFIX] INFO-3E-1: Advertencia de stock bajo mínimo después del registro -->
                    <div class="msg" style="background:#fff8e1;color:#e65100;border-left:3px solid #e65100;">
                        ⚠️ Salida registrada, pero el stock quedó por debajo del mínimo establecido.
                    </div>
                <?php endif; ?>
                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach($errores as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
                <?php endif; ?>

                <form method="POST">
                    <!-- [FIX-A1] Token CSRF para proteger el registro de salida -->
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="form-group">
                        <label>Producto *</label>
                        <div class="prod-busq-wrap">
                            <!-- [AUTOFIX] OBS-7: Eliminar onfocus que disparaba el dropdown al hacer clic en "Quitar selección".
                                 cancelarSelSalida() llama a .focus() que activaba onfocus → mostraba el primer producto disponible.
                                 Ahora el dropdown solo aparece cuando el usuario escribe (oninput). -->
                            <input type="text" id="buscarProductoSalida" placeholder="Buscar por nombre o código..."
                                autocomplete="off"
                                oninput="filtrarDropSalida(this.value)"
                                onblur="setTimeout(ocultarDropSalida, 200)"
                                style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                            <div id="dropProductosSalida" class="prod-drop"></div>
                        </div>
                        <input type="hidden" name="producto_id" id="inputProductoSalidaId">
                    </div>

                    <div class="prod-sel-panel" id="panelSelSalida" style="display:none;">
                        <div class="prod-sel-nombre" id="panelSelSalidaNombre"></div>
                        <div class="prod-sel-fila">
                            <input type="number" name="cantidad" id="inputCantidad" min="1" step="1" value="" placeholder="0" inputmode="numeric" oninput="sanitizarCantidadSalida()">
                            <span class="prod-sel-stock">Stock: <strong id="stockActual"></strong></span>
                            <button type="button" class="prod-sel-cancelar" onclick="cancelarSelSalida()" title="Quitar selección">✕</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Motivo *</label>
                        <div class="motivos-rapidos">
                            <button type="button" class="motivo-btn" onclick="setMotivo('Merma por daño')">Daño</button>
                            <button type="button" class="motivo-btn" onclick="setMotivo('Producto vencido')">Vencido</button>
                            <button type="button" class="motivo-btn" onclick="setMotivo('Error de inventario')">Error inventario</button>
                            <button type="button" class="motivo-btn" onclick="setMotivo('Robo o extravío')">Robo/Extravío</button>
                            <button type="button" class="motivo-btn" onclick="setMotivo('Muestra o regalo')">Muestra</button>
                        </div>
                        <input type="text" name="motivo" id="inputMotivo" placeholder="Describe el motivo de la salida...">
                    </div>

                    <button class="btn-guardar" type="submit">Registrar salida</button>
                </form>
            </div>
        </div>

        <div>
            <div class="card" style="padding:0;">
                <div style="padding:16px 20px;border-bottom:0.5px solid #eee;">
                    <h3 style="margin:0;">Salidas recientes</h3>
                </div>
                <?php if (count($historial) > 0): ?>
                <table>
                    <thead>
                        <tr><th>Producto</th><th>Cantidad</th><th>Stock ant.</th><th>Stock nuevo</th><th>Motivo</th><th>Fecha</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historial as $h): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($h['nombre_producto']) ?></strong>
                                <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($h['codigo']) ?></div>
                            </td>
                            <td style="color:#c0392b;font-weight:700;">-<?= number_format($h['cantidad'],2) ?></td>
                            <td><?= number_format($h['stock_anterior'],2) ?></td>
                            <td><?= number_format($h['stock_nuevo'],2) ?></td>
                            <td style=”font-size:12px;color:#888;”><?= htmlspecialchars($h['motivo']??'—') ?></td>
                            <td style="font-size:12px;color:#aaa;"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay salidas registradas.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const prodsSalida = <?= json_encode(array_values(array_map(fn($p) => [
    'id'    => (int)$p['producto_id'],
    'nombre'=> $p['nombre_producto'],
    'codigo'=> $p['codigo'],
    'stock' => (float)$p['stock_actual'],
    'tipo'  => $p['tipo_venta'],
    'texto' => mb_strtolower($p['codigo'].' '.$p['nombre_producto']),
], $productos))) ?>;

let prodSelSalida = null;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
function normalizar(s) { return String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }

function filtrarDropSalida(q) {
    const qn = normalizar(q);
    const drop = document.getElementById('dropProductosSalida');
    const resultados = qn.length < 1
        ? prodsSalida.slice(0, 30)
        : prodsSalida.filter(p => normalizar(p.texto).includes(qn)).slice(0, 50);
    if (!resultados.length) {
        drop.innerHTML = '<div style="padding:12px;text-align:center;color:#aaa;font-size:13px;">Sin resultados.</div>';
    } else {
        drop.innerHTML = resultados.map(p => `
            <div class="prod-drop-item" onclick="seleccionarProdSalida(${p.id})">
                <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:600;">${p.nombre}</span>
                <span style="font-size:11px;color:#aaa;white-space:nowrap;flex-shrink:0;">${p.codigo}</span>
                <span style="font-size:12px;color:#c0392b;font-weight:600;white-space:nowrap;flex-shrink:0;">${p.stock % 1 === 0 ? p.stock : p.stock.toFixed(2)}</span>
            </div>
        `).join('');
    }
    drop.style.display = 'block';
}

function ocultarDropSalida() {
    document.getElementById('dropProductosSalida').style.display = 'none';
}

function seleccionarProdSalida(id) {
    prodSelSalida = prodsSalida.find(p => p.id === id);
    if (!prodSelSalida) return;
    ocultarDropSalida();
    document.getElementById('buscarProductoSalida').value = '';
    document.getElementById('inputProductoSalidaId').value = prodSelSalida.id;
    document.getElementById('panelSelSalidaNombre').textContent = prodSelSalida.nombre + ' · ' + prodSelSalida.codigo;
    document.getElementById('stockActual').textContent = prodSelSalida.stock % 1 === 0 ? prodSelSalida.stock : prodSelSalida.stock.toFixed(2);
    const input = document.getElementById('inputCantidad');
    input.max = prodSelSalida.stock;
    if (prodSelSalida.tipo === 'Suelto') {
        input.step = '0.001';
        input.min = '0.001';
        input.setAttribute('inputmode', 'decimal');
    } else {
        input.step = '1';
        input.min = '1';
        input.setAttribute('inputmode', 'numeric');
    }
    input.value = '';
    document.getElementById('panelSelSalida').style.display = 'block';
    setTimeout(() => input.focus(), 50);
}

function sanitizarCantidadSalida() {
    if (!prodSelSalida || prodSelSalida.tipo === 'Suelto') return;
    const input = document.getElementById('inputCantidad');
    const val = input.value;
    if (val !== '' && val.includes('.')) {
        const n = parseFloat(val);
        if (!isNaN(n)) input.value = Math.floor(n) || '';
    }
}

function cancelarSelSalida() {
    prodSelSalida = null;
    document.getElementById('panelSelSalida').style.display = 'none';
    document.getElementById('inputProductoSalidaId').value = '';
    document.getElementById('inputCantidad').value = '';
    document.getElementById('buscarProductoSalida').value = '';
    document.getElementById('buscarProductoSalida').focus();
}

function setMotivo(texto) { document.getElementById('inputMotivo').value = texto; }
</script>
</body>
</html>


