<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

$errores = [];
$exito   = false;

// Buscar venta via AJAX
if (isset($_GET['buscar_venta'])) {
    $id = intval($_GET['buscar_venta']);
    $stmt = $pdo->prepare("
        SELECT v.*, c.nombre_completo as cliente
        FROM ventas v
        LEFT JOIN clientes c ON v.cliente_id = c.cliente_id
        WHERE v.venta_id = ? AND v.estado = 'Completada'
    ");
    $stmt->execute([$id]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($venta) {
        $stmtP = $pdo->prepare("SELECT vp.*, p.nombre_producto, p.codigo FROM venta_productos vp JOIN productos p ON vp.producto_id = p.producto_id WHERE vp.venta_id = ?");
        $stmtP->execute([$id]);
        $venta['productos'] = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    }
    header('Content-Type: application/json');
    echo json_encode($venta ?: null);
    exit();
}

// Procesar devoluciÃ³n
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $venta_id      = intval($_POST['venta_id'] ?? 0);
    $productos_dev = json_decode($_POST['productos_devolver'] ?? '[]', true);
    $motivo        = trim($_POST['motivo'] ?? '');

    if (!$venta_id)              $errores[] = 'Selecciona una venta.';
    if (empty($productos_dev))   $errores[] = 'Selecciona al menos un producto a devolver.';
    if (!$motivo)                $errores[] = 'El motivo es obligatorio.';

    if (empty($errores)) {
        // Verificar si la venta era a crÃ©dito
        $stmtV = $pdo->prepare("SELECT metodo_pago, cliente_id FROM ventas WHERE venta_id = ?");
        $stmtV->execute([$venta_id]);
        $ventaInfo = $stmtV->fetch(PDO::FETCH_ASSOC);

        foreach ($productos_dev as $prod) {
            $producto_id = intval($prod['producto_id']);
            $cantidad    = floatval($prod['cantidad']);

            if ($cantidad <= 0) continue;

            // Devolver stock
            $stmtS = $pdo->prepare("SELECT stock_actual FROM productos WHERE producto_id = ?");
            $stmtS->execute([$producto_id]);
            $stockAnterior = $stmtS->fetchColumn();
            $stockNuevo = $stockAnterior + $cantidad;

            $pdo->prepare("UPDATE productos SET stock_actual = ? WHERE producto_id = ?")->execute([$stockNuevo, $producto_id]);
            $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,'Entrada',?,?,?,?)")
                ->execute([$producto_id, $_SESSION['usuario_id'], $cantidad, $stockAnterior, $stockNuevo, 'DevoluciÃ³n: '.$motivo]);
        }

        // Si era crÃ©dito, actualizar el crÃ©dito
        if ($ventaInfo['metodo_pago'] === 'Credito' && $ventaInfo['cliente_id']) {
            $totalDevuelto = array_sum(array_map(fn($p) => $p['cantidad'] * $p['precio_unitario'], $productos_dev));
            $stmtCred = $pdo->prepare("SELECT credito_id, saldo_pendiente FROM creditos WHERE venta_id = ? AND estado = 'Activo'");
            $stmtCred->execute([$venta_id]);
            $cred = $stmtCred->fetch(PDO::FETCH_ASSOC);
            if ($cred) {
                $nuevoSaldo = max(0, $cred['saldo_pendiente'] - $totalDevuelto);
                $nuevoEstado = $nuevoSaldo <= 0 ? 'Liquidado' : 'Activo';
                $pdo->prepare("UPDATE creditos SET saldo_pendiente = ?, estado = ? WHERE credito_id = ?")
                    ->execute([$nuevoSaldo, $nuevoEstado, $cred['credito_id']]);
            }
        }

        $exito = true;
        header('Location: devoluciones.php?msg=exito');
        exit();
    }
}

// Historial de devoluciones recientes
$stmt = $pdo->prepare("
    SELECT m.*, p.nombre_producto, p.codigo
    FROM movimientos_inventario m
    JOIN productos p ON m.producto_id = p.producto_id
    WHERE m.motivo LIKE 'DevoluciÃ³n:%' AND p.sucursal_id = ?
    ORDER BY m.created_at DESC LIMIT 20
");
$stmt->execute([$_SESSION['sucursal_id']]);
$historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Devoluciones â€” FerreterÃ­a Aldrete</title>
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
    .content { flex: 1; padding: 20px; overflow-y: auto; display: grid; grid-template-columns: 420px 1fr; gap: 16px; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 18px; margin-bottom: 14px; }
    .card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 14px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .form-group { margin-bottom: 13px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .buscar-row { display: flex; gap: 8px; }
    .buscar-row input { flex: 1; }
    .btn-buscar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .venta-info { background: #f9f9f9; border-radius: 6px; padding: 12px; margin-bottom: 13px; font-size: 13px; display: none; }
    .venta-info.visible { display: block; }
    .venta-info h4 { font-size: 14px; color: #333; margin: 0 0 8px; }
    .prod-devolver { border: 0.5px solid #eee; border-radius: 6px; overflow: hidden; margin-bottom: 13px; display: none; }
    .prod-devolver.visible { display: block; }
    .prod-dev-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; }
    .prod-dev-row:last-child { border-bottom: none; }
    .prod-dev-row input[type=number] { width: 80px; padding: 5px 8px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; text-align: center; }
    .btn-devolver { width: 100%; background: #c0392b; color: white; border: none; padding: 11px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-devolver:hover { background: #a93226; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 10px 13px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 10px 13px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>FerreterÃ­a Aldrete</h3>
        <p>Cajero</p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item" href="inicioCajero.php">Inicio</a>
        <a class="menu-item" href="nuevaVenta.php">Nueva venta</a>
        <a class="menu-item" href="historialVentas.php">Historial de ventas</a>
        <div class="divider"></div>
        <a class="menu-item" href="abrirCaja.php">Abrir caja</a>
        <a class="menu-item" href="corteCaja.php">Corte de caja</a>
        <a class="menu-item" href="historialCortes.php">Historial de cortes</a>
        <div class="divider"></div>
        <a class="menu-item" href="clientes.php">Clientes</a>
        <a class="menu-item" href="creditos.php">CrÃ©ditos</a>
        <a class="menu-item" href="abonos.php">Abonos</a>
        <div class="divider"></div>
        <a class="menu-item" href="ventasPendientes.php">Ventas pendientes</a>
        <a class="menu-item active" href="devoluciones.php">Devoluciones</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Devoluciones</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">â€” <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesiÃ³n</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Formulario -->
        <div>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'exito'): ?>
                <div class="msg msg-exito">DevoluciÃ³n registrada y stock actualizado.</div>
            <?php endif; ?>
            <?php if (!empty($errores)): ?>
                <div class="errores"><ul><?php foreach($errores as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
            <?php endif; ?>

            <div class="card">
                <h3>Registrar devoluciÃ³n</h3>

                <div class="form-group">
                    <label>Buscar venta por nÃºmero</label>
                    <div class="buscar-row">
                        <input type="number" id="inputVentaId" placeholder="Ej. 1042" min="1">
                        <button class="btn-buscar" onclick="buscarVenta()">Buscar</button>
                    </div>
                </div>

                <div class="venta-info" id="ventaInfo">
                    <h4 id="ventaCliente"></h4>
                    <div style="font-size:12px;color:#888;" id="ventaFecha"></div>
                    <div style="font-size:13px;margin-top:6px;" id="ventaTotal"></div>
                </div>

                <div class="prod-devolver" id="prodDevolver">
                    <div id="listaProdsDev"></div>
                </div>

                <form method="POST" id="formDevolucion">
                    <input type="hidden" name="venta_id" id="inputVentaIdHidden">
                    <input type="hidden" name="productos_devolver" id="inputProdsDev">

                    <div class="form-group" id="motivoGroup" style="display:none;">
                        <label>Motivo de devoluciÃ³n *</label>
                        <input type="text" name="motivo" placeholder="Ej. Producto defectuoso, talla incorrecta...">
                    </div>

                    <button type="submit" class="btn-devolver" id="btnDevolver" style="display:none;" onclick="return prepararDevolucion()">
                        Registrar devoluciÃ³n
                    </button>
                </form>
            </div>
        </div>

        <!-- Historial -->
        <div>
            <div class="card" style="padding:0;">
                <div style="padding:16px 20px;border-bottom:0.5px solid #eee;">
                    <h3 style="margin:0;">Devoluciones recientes</h3>
                </div>
                <?php if (count($historial) > 0): ?>
                <table>
                    <thead>
                        <tr><th>Producto</th><th>Cantidad</th><th>Motivo</th><th>Fecha</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historial as $h): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($h['nombre_producto']) ?></strong>
                                <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($h['codigo']) ?></div>
                            </td>
                            <td style="color:#2e7d32;font-weight:700;">+<?= number_format($h['cantidad'],2) ?></td>
                            <td style="font-size:12px;color:#888;"><?= htmlspecialchars(str_replace('DevoluciÃ³n: ','',$h['motivo'])) ?></td>
                            <td style="font-size:12px;color:#aaa;"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay devoluciones registradas.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
let ventaActual = null;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function buscarVenta() {
    const id = document.getElementById('inputVentaId').value;
    if (!id) return;
    fetch(`devoluciones.php?buscar_venta=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data) { alert('Venta no encontrada o no estÃ¡ completada.'); return; }
            ventaActual = data;
            document.getElementById('ventaCliente').textContent = data.cliente || 'PÃºblico general';
            document.getElementById('ventaFecha').textContent = 'Venta #'+data.venta_id;
            document.getElementById('ventaTotal').textContent = 'Total: $'+parseFloat(data.total).toFixed(2)+' Â· '+data.metodo_pago;
            document.getElementById('ventaInfo').classList.add('visible');
            document.getElementById('inputVentaIdHidden').value = data.venta_id;

            // Renderizar productos
            const lista = document.getElementById('listaProdsDev');
            lista.innerHTML = data.productos.map(p => `
                <div class="prod-dev-row">
                    <span style="flex:1;">${p.nombre_producto}</span>
                    <span style="color:#aaa;font-size:11px;">Vendido: ${p.cantidad}</span>
                    <input type="number" data-producto-id="${p.producto_id}" data-precio="${p.precio_unitario}"
                        placeholder="0" step="1" min="0" max="${p.cantidad}" value="0">
                </div>
            `).join('');
            document.getElementById('prodDevolver').classList.add('visible');
            document.getElementById('motivoGroup').style.display = 'block';
            document.getElementById('btnDevolver').style.display = 'block';
        });
}

function prepararDevolucion() {
    const inputs = document.querySelectorAll('#listaProdsDev input[type=number]');
    const prods = [];
    inputs.forEach(inp => {
        const qty = parseFloat(inp.value) || 0;
        if (qty > 0) {
            prods.push({ producto_id: inp.dataset.productoId, cantidad: qty, precio_unitario: inp.dataset.precio });
        }
    });
    if (!prods.length) { alert('Ingresa la cantidad a devolver de al menos un producto.'); return false; }
    document.getElementById('inputProdsDev').value = JSON.stringify(prods);
    return true;
}
</script>
</body>
</html>

