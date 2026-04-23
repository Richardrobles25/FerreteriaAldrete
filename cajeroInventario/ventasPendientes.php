<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

// Liquidar venta pendiente
if (isset($_GET['liquidar'])) {
    $venta_id = intval($_GET['liquidar']);
    // Asignar folio si aún no tiene
    $stmtChk = $pdo->prepare("SELECT folio FROM ventas WHERE venta_id = ? AND estado = 'Pendiente'");
    $stmtChk->execute([$venta_id]);
    $ventaLiq = $stmtChk->fetch(PDO::FETCH_ASSOC);
    if ($ventaLiq && empty($ventaLiq['folio'])) {
        $mesFolio  = date('m');
        $anioFolio = date('Y');
        $stmtFolio = $pdo->prepare("SELECT COUNT(*)+1 FROM ventas WHERE MONTH(created_at)=? AND YEAR(created_at)=?");
        $stmtFolio->execute([$mesFolio, $anioFolio]);
        $numFolio = intval($stmtFolio->fetchColumn());
        $folio = str_pad($numFolio, 4, '0', STR_PAD_LEFT) . '-' . $mesFolio . '-' . $anioFolio;
        $pdo->prepare("UPDATE ventas SET estado = 'Completada', folio = ? WHERE venta_id = ? AND estado = 'Pendiente'")->execute([$folio, $venta_id]);
    } else {
        $pdo->prepare("UPDATE ventas SET estado = 'Completada' WHERE venta_id = ? AND estado = 'Pendiente'")->execute([$venta_id]);
    }
    header('Location: ventasPendientes.php?msg=liquidado');
    exit();
}

// Cancelar venta pendiente
if (isset($_GET['cancelar'])) {
    $venta_id = intval($_GET['cancelar']);

    // Devolver stock
    $stmtProd = $pdo->prepare("SELECT producto_id, cantidad FROM venta_productos WHERE venta_id = ?");
    $stmtProd->execute([$venta_id]);
    $productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    foreach ($productos as $p) {
        $stmtS = $pdo->prepare("SELECT stock_actual FROM productos WHERE producto_id = ?");
        $stmtS->execute([$p['producto_id']]);
        $stockAnterior = $stmtS->fetchColumn();
        $stockNuevo = $stockAnterior + $p['cantidad'];

        $pdo->prepare("UPDATE productos SET stock_actual = ? WHERE producto_id = ?")->execute([$stockNuevo, $p['producto_id']]);
        $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,'Entrada',?,?,?,'Cancelación venta pendiente')")
            ->execute([$p['producto_id'], $_SESSION['usuario_id'], $p['cantidad'], $stockAnterior, $stockNuevo]);
    }

    $pdo->prepare("UPDATE ventas SET estado = 'Cancelada' WHERE venta_id = ?")->execute([$venta_id]);
    header('Location: ventasPendientes.php?msg=cancelado');
    exit();
}

// Crear venta pendiente (domicilio)
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("SELECT caja_id FROM cajas WHERE usuario_id = ? AND estado = 'Abierta' LIMIT 1");
    $stmt->execute([$_SESSION['usuario_id']]);
    $caja = $stmt->fetchColumn();

    if (!$caja) {
        $errores[] = 'Debes tener una caja abierta para registrar ventas.';
    } else {
        $items      = json_decode($_POST['items'] ?? '[]', true);
        $cliente_id = intval($_POST['cliente_id'] ?? 0) ?: null;
        $notas      = trim($_POST['notas'] ?? '');
        $subtotal   = floatval($_POST['subtotal'] ?? 0);
        $total      = floatval($_POST['total'] ?? 0);

        if (!empty($items)) {
            $pdo->prepare("INSERT INTO ventas (caja_id, cliente_id, usuario_id, subtotal, total, metodo_pago, estado, notas) VALUES (?,?,?,?,?,'Efectivo','Pendiente',?)")
                ->execute([$caja, $cliente_id, $_SESSION['usuario_id'], $subtotal, $total, $notas]);
            $venta_id = $pdo->lastInsertId();

            foreach ($items as $item) {
                $subtotalItem = $item['cantidad'] * $item['precio'];
                $pdo->prepare("INSERT INTO venta_productos (venta_id, producto_id, cantidad, precio_unitario, subtotal) VALUES (?,?,?,?,?)")
                    ->execute([$venta_id, $item['producto_id'], $item['cantidad'], $item['precio'], $subtotalItem]);

                $stmtS = $pdo->prepare("SELECT stock_actual FROM productos WHERE producto_id = ?");
                $stmtS->execute([$item['producto_id']]);
                $stockAnterior = $stmtS->fetchColumn();
                $stockNuevo = $stockAnterior - $item['cantidad'];
                $pdo->prepare("UPDATE productos SET stock_actual = ? WHERE producto_id = ?")->execute([$stockNuevo, $item['producto_id']]);
                $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,'Salida',?,?,?,'Venta pendiente domicilio')")
                    ->execute([$item['producto_id'], $_SESSION['usuario_id'], $item['cantidad'], $stockAnterior, $stockNuevo]);
            }

            header('Location: ventasPendientes.php?msg=creado');
            exit();
        }
    }
}

// Obtener ventas pendientes
$stmt = $pdo->prepare("
    SELECT v.*, c.nombre_completo as cliente, ca.numero_turno
    FROM ventas v
    LEFT JOIN clientes c ON v.cliente_id = c.cliente_id
    JOIN cajas ca ON v.caja_id = ca.caja_id
    WHERE v.estado = 'Pendiente' AND v.usuario_id = ?
    ORDER BY v.created_at DESC
");
$stmt->execute([$_SESSION['usuario_id']]);
$pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Productos para agregar
$stmt = $pdo->prepare("SELECT producto_id, codigo, nombre_producto, precio_venta, stock_actual FROM productos WHERE sucursal_id = ? AND activo = 1 AND stock_actual > 0 ORDER BY nombre_producto");
$stmt->execute([$_SESSION['sucursal_id']]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Clientes
$clientes = $pdo->query("SELECT cliente_id, nombre_completo FROM clientes WHERE activo = 1 ORDER BY nombre_completo")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas Pendientes — Ferretería Aldrete</title>
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
    .logout-btn { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); color: white; padding: 5px 14px; border-radius: 5px; cursor: pointer; font-size: 12px; }
    .logout-btn:hover { background: rgba(255,255,255,0.3); }
    .content { flex: 1; padding: 20px; overflow-y: auto; display: grid; grid-template-columns: 1fr 380px; gap: 16px; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 18px; margin-bottom: 14px; }
    .card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 14px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
    .pendiente-item { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px 16px; margin-bottom: 10px; }
    .pendiente-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
    .pendiente-info h4 { font-size: 14px; color: #333; margin: 0 0 4px; }
    .pendiente-info p { font-size: 12px; color: #888; margin: 0; }
    .pendiente-acciones { display: flex; gap: 6px; }
    .btn-accion { padding: 6px 12px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-liquidar { background: #e8f5e9; color: #2e7d32; }
    .btn-liquidar:hover { background: #c8e6c9; }
    .btn-cancelar { background: #fdecea; color: #c0392b; }
    .btn-cancelar:hover { background: #ffcdd2; }
    .pendiente-productos { border-top: 0.5px solid #f5f5f5; padding-top: 10px; }
    .prod-row { display: flex; justify-content: space-between; font-size: 12px; color: #555; padding: 3px 0; }
    .form-group { margin-bottom: 13px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 600; }
    .form-group input, .form-group select { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .form-group input:focus, .form-group select:focus { outline: none; border-color: #14ace7; }
    .carrito-mini { border: 0.5px solid #eee; border-radius: 6px; padding: 10px; margin-bottom: 12px; min-height: 60px; }
    .carrito-vacio-mini { text-align: center; color: #aaa; font-size: 12px; padding: 14px; }
    .item-mini { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-bottom: 0.5px solid #f5f5f5; font-size: 12px; }
    .item-mini:last-child { border-bottom: none; }
    .btn-quitar-mini { background: none; border: none; color: #c0392b; cursor: pointer; font-size: 14px; }
    .total-mini { display: flex; justify-content: space-between; font-size: 14px; font-weight: 700; color: #222; padding-top: 8px; border-top: 1px solid #eee; margin-top: 4px; }
    .btn-guardar { width: 100%; background: #14ace7; color: white; border: none; padding: 11px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-guardar:hover { background: #1196cb; }
    .sin-pendientes { text-align: center; color: #aaa; padding: 40px; font-size: 13px; background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; }
    .busqueda-pend { width: 100%; margin-bottom: 12px; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .busqueda-pend:focus { outline: none; border-color: #14ace7; }
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
        <a class="menu-item active" href="ventasPendientes.php">Ventas pendientes</a>
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
            <h2>Ventas pendientes</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Lista de pendientes -->
        <div>
            <?php if (isset($_GET['msg'])): ?>
                <?php $msgs = ['creado'=>'Venta pendiente registrada.','liquidado'=>'Venta liquidada correctamente.','cancelado'=>'Venta cancelada y stock devuelto.']; ?>
                <div class="msg msg-exito"><?= $msgs[$_GET['msg']] ?? '' ?></div>
            <?php endif; ?>
            <input type="text" class="busqueda-pend" id="buscarPendientes" placeholder="Buscar cliente, notas o producto..." oninput="filtrarPendientes(this.value)">

            <?php if (count($pendientes) > 0): ?>
                <?php foreach ($pendientes as $p): ?>
                <div class="pendiente-item" data-pendiente-texto="<?= htmlspecialchars(mb_strtolower(($p['cliente'] ?? 'cliente general') . ' ' . ($p['notas'] ?? ''))) ?>">
                    <div class="pendiente-header">
                        <div class="pendiente-info">
                            <h4><?= htmlspecialchars($p['cliente'] ?? 'Cliente general') ?></h4>
                            <p><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?> · Total: <strong>$<?= number_format($p['total'],2) ?></strong></p>
                            <?php if ($p['notas']): ?><p style="color:#14ace7;"><?= htmlspecialchars($p['notas']) ?></p><?php endif; ?>
                        </div>
                        <div class="pendiente-acciones">
                            <a class="btn-accion btn-liquidar" href="ventasPendientes.php?liquidar=<?= $p['venta_id'] ?>" onclick="return confirm('¿Liquidar esta venta?')">Liquidar</a>
                            <a class="btn-accion btn-cancelar" href="ventasPendientes.php?cancelar=<?= $p['venta_id'] ?>" onclick="return confirm('¿Cancelar y devolver stock?')">Cancelar</a>
                        </div>
                    </div>
                    <?php
                    $stmtProd = $pdo->prepare("SELECT vp.cantidad, vp.precio_unitario, p.nombre_producto FROM venta_productos vp JOIN productos p ON vp.producto_id = p.producto_id WHERE vp.venta_id = ?");
                    $stmtProd->execute([$p['venta_id']]);
                    $prods = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <div class="pendiente-productos">
                        <?php foreach ($prods as $prod): ?>
                        <div class="prod-row">
                            <span><?= htmlspecialchars($prod['nombre_producto']) ?> × <?= $prod['cantidad'] ?></span>
                            <span>$<?= number_format($prod['precio_unitario'] * $prod['cantidad'],2) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="sin-pendientes">No hay ventas pendientes.</div>
            <?php endif; ?>
        </div>

        <!-- Nueva venta pendiente -->
        <div>
            <div class="card">
                <h3>Nueva venta a domicilio</h3>

                <?php if (!empty($errores)): ?>
                    <div class="errores"><?= htmlspecialchars($errores[0]) ?></div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Agregar producto</label>
                    <div style="position:relative;">
                        <input type="text" id="buscarProductoPendiente" placeholder="Buscar producto por nombre o código..."
                            autocomplete="off"
                            oninput="filtrarProductosPendientes(this.value)"
                            onfocus="filtrarProductosPendientes(this.value)"
                            onblur="setTimeout(ocultarDropPend, 200)"
                            style="width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                        <div id="dropdownProdsPend"
                            style="display:none;position:absolute;top:100%;left:0;right:0;background:white;border:1px solid #e0e0e0;border-radius:6px;max-height:220px;overflow-y:auto;z-index:100;box-shadow:0 4px 12px rgba(0,0,0,0.1);margin-top:2px;"></div>
                    </div>
                </div>

                <div class="carrito-mini" id="carritoMini">
                    <div class="carrito-vacio-mini">Sin productos</div>
                </div>
                <div class="total-mini" id="totalMini" style="display:none;">
                    <span>Total</span><span id="totalValor">$0.00</span>
                </div>

                <form method="POST" id="formPendiente">
                    <input type="hidden" name="items" id="inputItemsPend">
                    <input type="hidden" name="subtotal" id="inputSubtotalPend">
                    <input type="hidden" name="total" id="inputTotalPend">

                    <div class="form-group" style="margin-top:14px;">
                        <label>Cliente (opcional)</label>
                        <select name="cliente_id">
                            <option value="">Sin cliente</option>
                            <?php foreach ($clientes as $c): ?>
                                <option value="<?= $c['cliente_id'] ?>"><?= htmlspecialchars($c['nombre_completo']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Dirección / Notas de entrega</label>
                        <input type="text" name="notas" placeholder="Ej. Calle Morelos #45, Col. Centro">
                    </div>

                    <button class="btn-guardar" type="submit" onclick="return prepararPendiente()">Registrar venta pendiente</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let carritoP = [];
const prodsPend = <?= json_encode(array_values(array_map(fn($p) => [
    'producto_id'  => $p['producto_id'],
    'nombre'       => $p['nombre_producto'],
    'codigo'       => $p['codigo'],
    'precio'       => floatval($p['precio_venta']),
    'texto'        => mb_strtolower($p['codigo'].' '.$p['nombre_producto']),
], $productos))) ?>;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function normalizar(s) { return String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }

function filtrarProductosPendientes(q) {
    const drop = document.getElementById('dropdownProdsPend');
    const qn = normalizar(q);
    const resultados = qn ? prodsPend.filter(p => normalizar(p.texto).includes(qn)) : prodsPend.slice(0, 40);
    if (!resultados.length) {
        drop.innerHTML = '<div style="padding:12px;text-align:center;color:#aaa;font-size:13px;">Sin resultados</div>';
    } else {
        drop.innerHTML = resultados.map(p => `
            <div onclick="seleccionarProdPend(${p.producto_id},'${p.nombre.replace(/'/g,"\\'")}',${p.precio})"
                style="padding:9px 12px;cursor:pointer;border-bottom:0.5px solid #f5f5f5;font-size:13px;"
                onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background=''">
                <strong>${p.nombre}</strong>
                <span style="color:#aaa;font-size:11px;margin-left:6px;">${p.codigo}</span>
                <span style="float:right;color:#14ace7;font-weight:600;">$${p.precio.toFixed(2)}</span>
            </div>
        `).join('');
    }
    drop.style.display = 'block';
}

function ocultarDropPend() {
    document.getElementById('dropdownProdsPend').style.display = 'none';
}

function seleccionarProdPend(id, nombre, precio) {
    const existe = carritoP.find(i => i.producto_id === id);
    if (existe) { existe.cantidad++; }
    else { carritoP.push({ producto_id: id, nombre, precio, cantidad: 1 }); }
    document.getElementById('buscarProductoPendiente').value = '';
    document.getElementById('dropdownProdsPend').style.display = 'none';
    renderCarritoMini();
}

function renderCarritoMini() {
    const div = document.getElementById('carritoMini');
    const tot = document.getElementById('totalMini');
    if (!carritoP.length) {
        div.innerHTML = '<div class="carrito-vacio-mini">Sin productos</div>';
        tot.style.display = 'none';
        return;
    }
    let total = 0;
    div.innerHTML = carritoP.map((i,idx) => {
        total += i.cantidad * i.precio;
        return `<div class="item-mini">
            <span>${i.nombre} × ${i.cantidad} = $${(i.cantidad*i.precio).toFixed(2)}</span>
            <button class="btn-quitar-mini" onclick="quitarProd(${idx})">×</button>
        </div>`;
    }).join('');
    document.getElementById('totalValor').textContent = '$'+total.toFixed(2);
    tot.style.display = 'flex';
}

function quitarProd(i) { carritoP.splice(i,1); renderCarritoMini(); }

function filtrarPendientes(q) {
    q = normalizar(q);
    document.querySelectorAll('.pendiente-item').forEach(function(item) {
        const texto = normalizar(item.dataset.pendienteTexto || item.textContent);
        item.style.display = texto.includes(q) ? '' : 'none';
    });
}

function prepararPendiente() {
    if (!carritoP.length) { alert('Agrega al menos un producto.'); return false; }
    const total = carritoP.reduce((a,i) => a+(i.cantidad*i.precio),0);
    document.getElementById('inputItemsPend').value    = JSON.stringify(carritoP.map(i=>({producto_id:i.producto_id,cantidad:i.cantidad,precio:i.precio})));
    document.getElementById('inputSubtotalPend').value = total.toFixed(2);
    document.getElementById('inputTotalPend').value    = total.toFixed(2);
    return true;
}
</script>
</body>
</html>
