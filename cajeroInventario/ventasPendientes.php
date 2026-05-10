<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

// Datos de la sucursal para el ticket
$stmtSuc = $pdo->prepare("SELECT * FROM sucursales WHERE sucursal_id = ?");
$stmtSuc->execute([$_SESSION['sucursal_id']]);
$sucursalTicket = $stmtSuc->fetch(PDO::FETCH_ASSOC);

// Liquidar venta pendiente (el folio ya fue asignado al crear)
if (isset($_GET['liquidar'])) {
    $venta_id = intval($_GET['liquidar']);

    // Obtener datos de la venta antes de cambiar estado
    $stmtV = $pdo->prepare("SELECT venta_id, cliente_id, metodo_pago, total FROM ventas WHERE venta_id = ? AND estado = 'Pendiente'");
    $stmtV->execute([$venta_id]);
    $ventaLiq = $stmtV->fetch(PDO::FETCH_ASSOC);

    if ($ventaLiq) {
        $pdo->beginTransaction();
        try {
            // Marcar como completada
            $pdo->prepare("UPDATE ventas SET estado = 'Completada' WHERE venta_id = ?")->execute([$venta_id]);

            // Si es pago a crédito, crear registro en tabla creditos (igual que nuevaVenta.php)
            $metodoPagoLiq = trim($ventaLiq['metodo_pago']);
            if (($metodoPagoLiq === 'Crédito' || $metodoPagoLiq === 'Credito') && $ventaLiq['cliente_id']) {
                // Evitar duplicado por si se llama dos veces
                $stmtCheck = $pdo->prepare("SELECT credito_id FROM creditos WHERE venta_id = ? LIMIT 1");
                $stmtCheck->execute([$venta_id]);
                if (!$stmtCheck->fetchColumn()) {
                    $pdo->prepare("INSERT INTO creditos (cliente_id, venta_id, monto_total, saldo_pendiente, estado, fecha_limite) VALUES (?, ?, ?, ?, 'Activo', ?)")
                        ->execute([$ventaLiq['cliente_id'], $venta_id, $ventaLiq['total'], $ventaLiq['total'], date('Y-m-d H:i:s', strtotime('+3 minutes'))]);
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
        }
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
        $stmtS = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
        $stmtS->execute([$p['producto_id'], $_SESSION['sucursal_id']]);
        $stockAnterior = $stmtS->fetchColumn();
        $stockNuevo = $stockAnterior + $p['cantidad'];

        $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")->execute([$stockNuevo, $p['producto_id'], $_SESSION['sucursal_id']]);
        $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Entrada',?,?,?,'Cancelación venta pendiente')")
            ->execute([$p['producto_id'], $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $p['cantidad'], $stockAnterior, $stockNuevo]);
    }

    $pdo->prepare("UPDATE ventas SET estado = 'Cancelada' WHERE venta_id = ?")->execute([$venta_id]);
    header('Location: ventasPendientes.php?msg=cancelado');
    exit();
}

// Endpoint: obtener ticket JSON
if (isset($_GET['get_ticket_venta'])) {
    $venta_id = intval($_GET['get_ticket_venta']);
    $stmt = $pdo->prepare("SELECT * FROM ventas WHERE venta_id = ?");
    $stmt->execute([$venta_id]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Venta no encontrada']);
        exit();
    }

    $stmtProds = $pdo->prepare("
        SELECT vp.*, p.nombre_producto, p.codigo
        FROM venta_productos vp
        JOIN productos p ON vp.producto_id = p.producto_id
        WHERE vp.venta_id = ?
    ");
    $stmtProds->execute([$venta_id]);
    $productos = $stmtProds->fetchAll(PDO::FETCH_ASSOC);

    $stmtCaja = $pdo->prepare("SELECT numero_turno FROM cajas WHERE caja_id = ?");
    $stmtCaja->execute([$venta['caja_id']]);
    $numTurno = $stmtCaja->fetchColumn() ?: 1;

    $stmtCliente = $pdo->prepare("SELECT nombre_completo FROM clientes WHERE cliente_id = ?");
    $stmtCliente->execute([$venta['cliente_id']]);
    $cliente = $stmtCliente->fetchColumn() ?: 'Consumidor final';

    // Obtener datos de sucursal
    $stmtSucVenta = $pdo->prepare("SELECT * FROM sucursales WHERE sucursal_id = (SELECT caja_id FROM cajas WHERE caja_id = ?) OR sucursal_id = ?");
    $stmtSucVenta->execute([$venta['caja_id'], $_SESSION['sucursal_id']]);
    $sucData = $pdo->prepare("SELECT s.* FROM sucursales s JOIN cajas c ON c.sucursal_id = s.sucursal_id WHERE c.caja_id = ?");
    $sucData->execute([$venta['caja_id']]);
    $sucursal = $sucData->fetch(PDO::FETCH_ASSOC) ?: $sucursalTicket;

    header('Content-Type: application/json');
    echo json_encode([
        'venta_id'  => $venta['venta_id'],
        'folio'     => $venta['folio'],
        'fecha'     => $venta['created_at'],
        'turno'     => $numTurno,
        'cliente'   => $cliente,
        'productos' => $productos,
        'subtotal'           => $venta['subtotal'],
        'descuento'          => $venta['descuento'],
        'comision_terminal'  => $venta['comision_terminal'] ?? 0,
        'total'              => $venta['total'],
        'metodo_pago'        => $venta['metodo_pago'],
        'referencia_transferencia' => $venta['referencia_transferencia'],
        'estado'    => $venta['estado'],
        'sucursal' => [
            'nombre' => $sucursal['nombre'] ?? 'FERRETERIA ALDRETE',
            'datos_ticket' => $sucursal['datos_ticket'] ?? '',
            'logo_url' => $sucursal['logo_url'] ?? '',
            'banco' => $sucursal['banco'] ?? '',
            'titular_cuenta' => $sucursal['titular_cuenta'] ?? '',
            'numero_cuenta' => $sucursal['numero_cuenta'] ?? '',
            'clabe_interbancaria' => $sucursal['clabe_interbancaria'] ?? '',
            'alias_tarjeta' => $sucursal['alias_tarjeta'] ?? ''
        ]
    ]);
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
        $items        = json_decode($_POST['items'] ?? '[]', true);
        $cliente_id   = intval($_POST['cliente_id'] ?? 0) ?: null;
        $notas        = trim($_POST['notas'] ?? '');
        $subtotal     = floatval($_POST['subtotal'] ?? 0);
        $descuento    = floatval($_POST['descuento'] ?? 0);
        $total        = floatval($_POST['total'] ?? 0);
        $metodo_pago  = in_array($_POST['metodo_pago'] ?? '', ['Efectivo', 'Crédito', 'Terminal', 'Transferencia', 'Mixto']) ? $_POST['metodo_pago'] : 'Efectivo';
        $ref_transf      = ($metodo_pago === 'Transferencia') ? trim($_POST['referencia_transferencia'] ?? '') : null;
        $comision_terminal = floatval($_POST['comision_terminal'] ?? 0);

        // Validar referencia obligatoria para Transferencia
        if ($metodo_pago === 'Transferencia' && empty($ref_transf)) {
            $errores[] = 'El número de referencia bancaria es obligatorio para pago por Transferencia.';
        }

        if (!empty($items) && empty($errores)) {
            $pdo->beginTransaction();
            try {
                // Generar folio secuencial mensual (mismo criterio que nuevaVenta.php)
                $mesFolio  = date('m');
                $anioFolio = date('Y');
                $stmtFolio = $pdo->prepare("
                    SELECT COALESCE(MAX(CAST(folio AS UNSIGNED)), 0) + 1
                    FROM ventas
                    WHERE folio IS NOT NULL AND MONTH(created_at) = ? AND YEAR(created_at) = ?
                    FOR UPDATE
                ");
                $stmtFolio->execute([$mesFolio, $anioFolio]);
                $numFolio = intval($stmtFolio->fetchColumn());
                $folio    = str_pad($numFolio, 4, '0', STR_PAD_LEFT);

                $pdo->prepare("INSERT INTO ventas (folio, caja_id, cliente_id, usuario_id, subtotal, descuento, comision_terminal, total, metodo_pago, estado, notas, referencia_transferencia) VALUES (?,?,?,?,?,?,?,?,?,'Pendiente',?,?)")
                    ->execute([$folio, $caja, $cliente_id, $_SESSION['usuario_id'], $subtotal, $descuento, $comision_terminal, $total, $metodo_pago, $notas, $ref_transf]);
                $venta_id = $pdo->lastInsertId();

                foreach ($items as $item) {
                    // Validar stock antes de descontar
                    $stmtChk = $pdo->prepare("SELECT ss.stock_actual, p.nombre_producto FROM stock_sucursal ss JOIN productos p ON p.producto_id = ss.producto_id WHERE ss.producto_id = ? AND ss.sucursal_id = ? FOR UPDATE");
                    $stmtChk->execute([$item['producto_id'], $_SESSION['sucursal_id']]);
                    $prodChk = $stmtChk->fetch(PDO::FETCH_ASSOC);
                    if (!$prodChk || floatval($prodChk['stock_actual']) < $item['cantidad']) {
                        $disponible = $prodChk ? floatval($prodChk['stock_actual']) : 0;
                        $nombre     = $prodChk['nombre_producto'] ?? 'Producto';
                        throw new Exception("Stock insuficiente para \"$nombre\". Disponible: $disponible.");
                    }

                    $precioOrig    = floatval($item['precio_normal'] ?? $item['precio']);
                    $precioFinal   = floatval($item['precio']);
                    $subtotalItem  = $item['cantidad'] * $precioFinal;
                    $stockAnterior = floatval($prodChk['stock_actual']);
                    $stockNuevo    = $stockAnterior - $item['cantidad'];

                    $pdo->prepare("INSERT INTO venta_productos (venta_id, producto_id, cantidad, precio_unitario, precio_final, subtotal) VALUES (?,?,?,?,?,?)")
                        ->execute([$venta_id, $item['producto_id'], $item['cantidad'], $precioOrig, $precioFinal, $subtotalItem]);
                    $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")->execute([$stockNuevo, $item['producto_id'], $_SESSION['sucursal_id']]);
                    $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Salida',?,?,?,'Venta pendiente domicilio')")
                        ->execute([$item['producto_id'], $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $item['cantidad'], $stockAnterior, $stockNuevo]);
                }

                $pdo->commit();
                header('Location: ventasPendientes.php?msg=creado');
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $errores[] = $e->getMessage();
            }
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
$stmt = $pdo->prepare("
    SELECT p.producto_id, p.codigo, p.nombre_producto, p.precio_venta, ss.stock_actual, p.tipo_venta
    FROM productos p
    INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
    WHERE p.activo = 1 AND ss.activo = 1 AND ss.stock_actual > 0
    ORDER BY p.nombre_producto
");
$stmt->execute([$_SESSION['sucursal_id']]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Promociones activas para esta sucursal
$stmtPromosPend = $pdo->prepare("
    SELECT pr.producto_id, pr.precio_promocional, pr.descripcion
    FROM promociones pr
    JOIN productos p ON pr.producto_id = p.producto_id
    JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
    WHERE 1=1
      AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio <= CURDATE())
      AND (pr.fecha_fin    IS NULL OR pr.fecha_fin    >= CURDATE())
      AND pr.activo = 1
");
$stmtPromosPend->execute([$_SESSION['sucursal_id']]);
$promosPendById = [];
foreach ($stmtPromosPend->fetchAll(PDO::FETCH_ASSOC) as $promo) {
    $promosPendById[(int)$promo['producto_id']] = [
        'precio_promo' => floatval($promo['precio_promocional']),
        'descripcion'  => $promo['descripcion'] ?? '',
    ];
}

// Clientes
try {
    $clientes = $pdo->query("SELECT cliente_id, nombre_completo, COALESCE(descuento_fijo,0) as descuento, COALESCE(credito_autorizado,0) as credito FROM clientes WHERE activo = 1 ORDER BY nombre_completo")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clientes = $pdo->query("SELECT cliente_id, nombre_completo, 0 as descuento, 0 as credito FROM clientes WHERE activo = 1 ORDER BY nombre_completo")->fetchAll(PDO::FETCH_ASSOC);
}
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
    .item-mini { display: flex; align-items: center; gap: 4px; padding: 6px 0; border-bottom: 0.5px solid #f5f5f5; font-size: 12px; min-width: 0; }
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
        <div class="divider"></div>

        <div class="menu-label">Inventario</div>
        <a class="menu-item" href="productos.php">Productos</a>
        <a class="menu-item" href="categorias.php">Categorías</a>
        <a class="menu-item" href="unidades.php">Unidades de medida</a>
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
        <a class="menu-item" href="promociones.php">Promociones</a>
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
                            <button class="btn-accion" type="button" style="background:#e3f2fd;color:#1565c0;" onclick="abrirTicketVenta(<?= $p['venta_id'] ?>)">🖨️ Ticket</button>
                            <a class="btn-accion btn-liquidar" href="ventasPendientes.php?liquidar=<?= $p['venta_id'] ?>" onclick="return confirm('¿Liquidar esta venta?')">Liquidar</a>
                            <a class="btn-accion btn-cancelar" href="ventasPendientes.php?cancelar=<?= $p['venta_id'] ?>" onclick="return confirm('¿Cancelar y devolver stock?')">Cancelar</a>
                        </div>
                    </div>
                    <?php
                    $stmtProd = $pdo->prepare("SELECT vp.cantidad, vp.precio_unitario, vp.precio_final, p.nombre_producto FROM venta_productos vp JOIN productos p ON vp.producto_id = p.producto_id WHERE vp.venta_id = ?");
                    $stmtProd->execute([$p['venta_id']]);
                    $prods = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <div class="pendiente-productos">
                        <?php foreach ($prods as $prod): ?>
                        <?php
                            $precioMostrar = (!empty($prod['precio_final']) && floatval($prod['precio_final']) > 0)
                                ? floatval($prod['precio_final'])
                                : floatval($prod['precio_unitario']);
                        ?>
                        <div class="prod-row">
                            <span><?= htmlspecialchars($prod['nombre_producto']) ?> × <?= $prod['cantidad'] ?></span>
                            <span>$<?= number_format($precioMostrar * $prod['cantidad'], 2) ?></span>
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
                        <input type="text" id="buscarProductoPendiente" placeholder="Buscar por nombre o código..."
                            autocomplete="off"
                            oninput="filtrarProductosPendientes(this.value)"
                            onfocus="filtrarProductosPendientes(this.value)"
                            onblur="setTimeout(ocultarDropPend, 200)"
                            style="width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                        <div id="dropdownProdsPend"
                            style="display:none;position:absolute;top:100%;left:0;right:0;background:white;border:1px solid #e0e0e0;border-radius:6px;max-height:220px;overflow-y:auto;z-index:100;box-shadow:0 4px 12px rgba(0,0,0,0.1);margin-top:2px;"></div>
                    </div>
                    <!-- Panel cantidad tras seleccionar producto -->
                    <div id="prodSelPend" style="display:none;background:#f0f9ff;border:1px solid #dbeafe;border-radius:6px;padding:10px 12px;margin-top:8px;">
                        <div style="font-size:13px;font-weight:600;color:#1565c0;margin-bottom:4px;" id="prodSelPendNombre"></div>
                        <div style="font-size:12px;color:#555;margin-bottom:6px;">
                            Disponible: <span id="prodSelPendStock" style="font-weight:600;color:#2e7d32;"></span>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <label style="font-size:12px;color:#555;white-space:nowrap;">Cantidad:</label>
                            <input type="number" id="inputCantPend" min="1" step="1" value="1" inputmode="numeric"
                                style="width:80px;padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;"
                                oninput="sanitizarCantPend()"
                                onkeydown="if(event.key==='Enter'){event.preventDefault();confirmarAgregarProdPend();}">
                            <span id="prodSelPendPrecio" style="font-size:12px;color:#14ace7;font-weight:600;"></span>
                            <button type="button" onclick="confirmarAgregarProdPend()"
                                style="flex:1;background:#14ace7;color:white;border:none;padding:8px 10px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">Agregar</button>
                            <button type="button" onclick="cancelarSelPend()"
                                style="background:white;color:#666;border:1px solid #ddd;padding:8px 10px;border-radius:6px;cursor:pointer;font-size:13px;">✕</button>
                        </div>
                    </div>
                </div>

                <div class="carrito-mini" id="carritoMini">
                    <div class="carrito-vacio-mini">Sin productos</div>
                </div>
                <div class="total-mini" id="totalMini" style="display:none;flex-direction:column;gap:2px;">
                    <div id="descuentoLinea" style="display:none;justify-content:space-between;font-size:12px;color:#2e7d32;font-weight:500;">
                        <span>Descuento</span><span id="descuentoValor">-$0.00</span>
                    </div>
                    <div id="comisionLineaMini" style="display:none;justify-content:space-between;font-size:12px;color:#e65100;font-weight:500;">
                        <span>Comisión terminal</span><span id="comisionValorMini">+$0.00</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:14px;font-weight:700;color:#222;">
                        <span>Total</span><span id="totalValor">$0.00</span>
                    </div>
                </div>

                <form method="POST" id="formPendiente">
                    <input type="hidden" name="items"     id="inputItemsPend">
                    <input type="hidden" name="subtotal"  id="inputSubtotalPend">
                    <input type="hidden" name="descuento" id="inputDescuentoPend">
                    <input type="hidden" name="total"     id="inputTotalPend">
                    <input type="hidden" name="cliente_id" id="inputClienteIdPend">

                    <div class="form-group" style="margin-top:14px;">
                        <label>Cliente (opcional)</label>
                        <div style="position:relative;">
                            <input type="text" id="buscarClientePend" placeholder="Buscar cliente..."
                                autocomplete="off"
                                oninput="filtrarClientesPend(this.value)"
                                onfocus="filtrarClientesPend(this.value)"
                                onblur="setTimeout(ocultarDropClientes, 200)"
                                style="width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                            <div id="dropClientesPend" style="display:none;position:absolute;top:100%;left:0;right:0;background:white;border:1px solid #e0e0e0;border-radius:6px;max-height:180px;overflow-y:auto;z-index:100;box-shadow:0 4px 12px rgba(0,0,0,0.1);margin-top:2px;"></div>
                        </div>
                        <div id="clienteSelPend" style="display:none;background:#f0f9ff;border:1px solid #dbeafe;border-radius:6px;padding:8px 12px;margin-top:6px;align-items:center;gap:8px;">
                            <span id="clienteSelNombrePend" style="flex:1;font-size:13px;font-weight:600;color:#1565c0;"></span>
                            <button type="button" onclick="quitarClientePend()" style="background:none;border:none;color:#aaa;font-size:16px;cursor:pointer;margin-left:auto;line-height:1;">✕</button>
                        </div>
                    </div>

                    <div id="descClientePendGrupo" style="display:none;background:#f0faf4;border:1px solid #c8e6c9;border-radius:8px;padding:12px 14px;margin-bottom:13px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:10px;">
                            <input type="checkbox" id="aplicarDescPend" onchange="recalcularTotalPend()" style="cursor:pointer;width:16px;height:16px;accent-color:#2e7d32;">
                            <span style="font-size:13px;font-weight:600;color:#2e7d32;">Aplicar descuento al cliente</span>
                        </label>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span style="font-size:12px;color:#555;white-space:nowrap;">Porcentaje:</span>
                            <input type="number" id="inputDescClientePend" min="0" step="0.1"
                                oninput="recalcularTotalPend()"
                                onblur="clampearDescClientePend()"
                                style="width:80px;padding:6px 10px;border:1px solid #a5d6a7;border-radius:6px;font-size:13px;background:white;">
                            <span id="descMaxPendLabel" style="font-size:12px;color:#888;"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Método de pago</label>
                        <select name="metodo_pago" id="metodoPagoPend" onchange="cambiarMetodoPend(this.value)"
                            style="width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                            <option value="Efectivo">Efectivo</option>
                            <option value="Terminal">Terminal</option>
                            <option value="Transferencia">Transferencia</option>
                            <option value="Mixto">Mixto</option>
                            <option value="Crédito" id="optCreditoPend" style="display:none;">Crédito</option>
                        </select>
                    </div>

                    <!-- Comisión terminal -->
                    <div id="comisionTerminalGrupo" style="display:none;background:#fff8e1;border:1px solid #ffe082;border-radius:6px;padding:10px 12px;margin-bottom:13px;font-size:13px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                            <span style="font-weight:600;color:#795548;">Comisión terminal</span>
                            <span style="font-size:11px;color:#999;"><?= number_format(floatval($sucursalTicket['comision_terminal_pct'] ?? 0), 2) ?>%</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:#666;">Se suma al total:</span>
                            <span id="comisionValorPend" style="font-weight:700;color:#e65100;">+$0.00</span>
                        </div>
                    </div>
                    <input type="hidden" name="comision_terminal" id="inputComisionTerminalPend">

                    <div id="refTransfPendGrupo" style="display:none;">
                        <div class="form-group">
                            <label>Referencia bancaria</label>
                            <input type="text" name="referencia_transferencia" id="refTransfPend"
                                placeholder="Folio o número de referencia"
                                style="width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                        </div>
                        <?php
                            $tb = trim($sucursalTicket['banco']               ?? '');
                            $tt = trim($sucursalTicket['titular_cuenta']      ?? '');
                            $tc = trim($sucursalTicket['numero_cuenta']       ?? '');
                            $tl = trim($sucursalTicket['clabe_interbancaria'] ?? '');
                            $ta = trim($sucursalTicket['alias_tarjeta']       ?? '');
                        ?>
                        <div style="font-size:12px;line-height:1.9;background:#f0f8ff;border:1px solid #b3e0f7;border-radius:6px;padding:10px 12px;color:#333;margin-bottom:13px;">
                            <div style="font-size:11px;font-weight:700;color:#1565c0;margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px;">Datos para la transferencia</div>
                            <?php if ($tb || $tt || $tc || $tl): ?>
                                <?php if ($tb): ?><div><strong>Banco:</strong> <?= htmlspecialchars($tb) ?></div><?php endif; ?>
                                <?php if ($tt): ?><div><strong>Titular:</strong> <?= htmlspecialchars($tt) ?></div><?php endif; ?>
                                <?php if ($tc): ?><div><strong>No. cuenta:</strong> <?= htmlspecialchars($tc) ?></div><?php endif; ?>
                                <?php if ($tl): ?><div><strong>CLABE:</strong> <?= htmlspecialchars($tl) ?></div><?php endif; ?>
                                <?php if ($ta): ?><div><strong>Alias:</strong> <?= htmlspecialchars($ta) ?></div><?php endif; ?>
                            <?php else: ?>
                                <span style="color:#aaa;">Sin datos bancarios configurados.<br>Agrégalos en Configuración → Sucursal.</span>
                            <?php endif; ?>
                        </div>
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

<!-- Modal: Ticket venta pendiente -->
<div class="modal-overlay" id="modalTicketVentaPend" style="position:fixed;inset:0;background:rgba(0,0,0,0.4);display:none;align-items:center;justify-content:center;padding:20px;z-index:999;" aria-hidden="true">
    <div style="background:white;border-radius:8px;padding:24px;max-width:90mm;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #e8e8e8;">
            <h3 style="margin:0;font-size:16px;color:#333;">Ticket de venta</h3>
            <button type="button" onclick="cerrarTicketVenta()" style="background:none;border:none;font-size:24px;color:#aaa;cursor:pointer;padding:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;">×</button>
        </div>
        <div id="ticketContenidoVentaPend" style="margin-bottom:16px;"></div>
        <div style="display:flex;gap:10px;justify-content:center;border-top:1px solid #e8e8e8;padding-top:16px;">
            <button type="button" onclick="imprimirTicketPend()" style="background:#14ace7;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600;">🖨️ Imprimir</button>
            <button type="button" onclick="cerrarTicketVenta()" style="background:#f0f0f0;color:#666;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600;">Cerrar</button>
        </div>
    </div>
</div>

<!-- Ticket oculto para impresión -->
<div id="ticketImprimir"></div>

<style>
.modal-overlay.visible { display: flex !important; }

/* ── Ticket térmico 80mm ── */
@page { size: 80mm auto; margin: 0; }
@media print {
    html, body {
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
    }
    body > * { display: none !important; }
    #ticketImprimir {
        display: block !important;
        page-break-after: avoid;
        break-after: avoid;
    }
}
#ticketImprimir {
    display: none;
    font-family: 'Courier New', monospace;
    font-size: 11px;
    width: 72mm;
    margin: 0;
    padding: 3mm 4mm 2mm;
}
#ticketImprimir .t-centro { text-align: center; }
#ticketImprimir .t-linea  { border-top: 1px dashed #000; margin: 4px 0; }
#ticketImprimir .t-fila   { display: flex; justify-content: space-between; }
#ticketImprimir .t-bold   { font-weight: bold; }
#ticketImprimir .t-grande { font-size: 13px; font-weight: bold; }
/* Mismo estilo para preview en modal */
#ticketContenidoVentaPend .t-centro { text-align: center; }
#ticketContenidoVentaPend .t-linea  { border-top: 1px dashed #aaa; margin: 4px 0; }
#ticketContenidoVentaPend .t-fila   { display: flex; justify-content: space-between; }
#ticketContenidoVentaPend .t-bold   { font-weight: bold; }
#ticketContenidoVentaPend .t-grande { font-size: 13px; font-weight: bold; }
</style>

<script>
let carritoP = [];
let prodSelPendActual = null;
let clientePendActual = null;

const prodsPend = <?= json_encode(array_values(array_map(function($p) use ($promosPendById) {
    $pid          = (int)$p['producto_id'];
    $precioNormal = floatval($p['precio_venta']);
    $tienePromo   = isset($promosPendById[$pid]) && floatval($promosPendById[$pid]['precio_promo']) < $precioNormal - 0.001;
    $precioFinal  = $tienePromo ? floatval($promosPendById[$pid]['precio_promo']) : $precioNormal;
    return [
        'producto_id'   => $pid,
        'nombre'        => $p['nombre_producto'],
        'codigo'        => $p['codigo'],
        'precio'        => $precioFinal,
        'precio_normal' => $precioNormal,
        'tiene_promo'   => $tienePromo,
        'promo_desc'    => $tienePromo ? ($promosPendById[$pid]['descripcion'] ?? '') : '',
        'stock'         => floatval($p['stock_actual']),
        'tipo'          => $p['tipo_venta'],
        'texto'         => mb_strtolower($p['codigo'].' '.$p['nombre_producto']),
    ];
}, $productos))) ?>;

const clientesPend = <?= json_encode(array_values(array_map(fn($c) => [
    'id'        => (int)$c['cliente_id'],
    'nombre'    => $c['nombre_completo'],
    'descuento' => floatval($c['descuento']),
    'credito'   => intval($c['credito']),
    'texto'     => mb_strtolower($c['nombre_completo']),
], $clientes))) ?>;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
function normalizar(s) { return String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }

/* ── Búsqueda de productos ── */
function filtrarProductosPendientes(q) {
    const drop = document.getElementById('dropdownProdsPend');
    const qn = normalizar(q);
    const resultados = qn ? prodsPend.filter(p => normalizar(p.texto).includes(qn)) : prodsPend.slice(0, 40);
    if (!resultados.length) {
        drop.innerHTML = '<div style="padding:12px;text-align:center;color:#aaa;font-size:13px;">Sin resultados</div>';
    } else {
        drop.innerHTML = resultados.map(p => {
            const stockStr = p.tipo === 'Suelto'
                ? p.stock.toFixed(3).replace(/\.?0+$/, '')
                : Math.floor(p.stock);
            const stockColor = p.stock <= 0 ? '#c0392b' : (p.stock <= 5 ? '#e67e22' : '#2e7d32');
            return `<div onclick="seleccionarProdPend(${p.producto_id})"
                style="padding:9px 12px;cursor:pointer;border-bottom:0.5px solid #f5f5f5;display:flex;align-items:center;gap:8px;min-width:0;"
                onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background=''">
                <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:600;">${p.nombre}</span>
                <span style="font-size:11px;color:#aaa;white-space:nowrap;flex-shrink:0;">${p.codigo}</span>
                <span style="font-size:11px;color:${stockColor};font-weight:600;white-space:nowrap;flex-shrink:0;">Stock: ${stockStr}</span>
                <span style="font-size:13px;color:#14ace7;font-weight:600;white-space:nowrap;flex-shrink:0;">$${p.precio.toFixed(2)}</span>
            </div>`;
        }).join('');
    }
    drop.style.display = 'block';
}

function ocultarDropPend() {
    document.getElementById('dropdownProdsPend').style.display = 'none';
}

function seleccionarProdPend(id) {
    const prod = prodsPend.find(p => p.producto_id === id);
    if (!prod) return;
    prodSelPendActual = prod;
    document.getElementById('buscarProductoPendiente').value = '';
    document.getElementById('dropdownProdsPend').style.display = 'none';
    document.getElementById('prodSelPendNombre').textContent = prod.nombre + ' — ' + prod.codigo;
    document.getElementById('prodSelPendPrecio').textContent = '$' + prod.precio.toFixed(2) + ' c/u';

    // Stock disponible (descontando lo que ya está en el carrito)
    const enCarrito = carritoP.filter(i => i.producto_id === prod.producto_id).reduce((a, i) => a + i.cantidad, 0);
    const disponible = Math.max(0, prod.stock - enCarrito);
    const stockSpan = document.getElementById('prodSelPendStock');
    const stockStr = prod.tipo === 'Suelto'
        ? disponible.toFixed(3).replace(/\.?0+$/, '')
        : Math.floor(disponible);
    stockSpan.textContent = stockStr;
    stockSpan.style.color = disponible <= 0 ? '#c0392b' : (disponible <= 5 ? '#e67e22' : '#2e7d32');

    const inp = document.getElementById('inputCantPend');
    if (prod.tipo === 'Suelto') {
        inp.step = '0.001'; inp.min = '0.001'; inp.setAttribute('inputmode','decimal');
    } else {
        inp.step = '1'; inp.min = '1'; inp.setAttribute('inputmode','numeric');
    }
    inp.max = disponible > 0 ? disponible : 0;
    inp.value = disponible > 0 ? (prod.tipo === 'Suelto' ? Math.min(1, disponible) : 1) : '';
    document.getElementById('prodSelPend').style.display = 'block';
    setTimeout(() => inp.select(), 50);
}

function sanitizarCantPend() {
    if (!prodSelPendActual || prodSelPendActual.tipo === 'Suelto') return;
    const inp = document.getElementById('inputCantPend');
    if (inp.value !== '' && inp.value.includes('.')) {
        const n = parseFloat(inp.value);
        if (!isNaN(n)) inp.value = Math.floor(n) || '';
    }
}

function confirmarAgregarProdPend() {
    if (!prodSelPendActual) return;
    const cantidad = parseFloat(document.getElementById('inputCantPend').value) || 0;
    if (cantidad <= 0) { alert('Ingresa una cantidad válida.'); return; }

    // Validar que no supere el stock disponible
    const enCarrito = carritoP.filter(i => i.producto_id === prodSelPendActual.producto_id).reduce((a, i) => a + i.cantidad, 0);
    const disponible = Math.max(0, prodSelPendActual.stock - enCarrito);
    if (cantidad > disponible + 0.0001) {
        const disp = prodSelPendActual.tipo === 'Suelto'
            ? disponible.toFixed(3).replace(/\.?0+$/, '')
            : Math.floor(disponible);
        alert('Stock insuficiente. Disponible: ' + disp);
        return;
    }

    const { producto_id, nombre, precio, precio_normal, tiene_promo, promo_desc } = prodSelPendActual;
    const existe = carritoP.find(i => i.producto_id === producto_id);
    if (existe) { existe.cantidad += cantidad; }
    else { carritoP.push({ producto_id, nombre, precio, precio_normal, tiene_promo, promo_desc, cantidad }); }

    // Ocultar panel sin reabrir el dropdown
    prodSelPendActual = null;
    document.getElementById('prodSelPend').style.display = 'none';
    document.getElementById('inputCantPend').value = 1;
    document.getElementById('buscarProductoPendiente').value = '';
    renderCarritoMini();
}

function cancelarSelPend() {
    prodSelPendActual = null;
    document.getElementById('prodSelPend').style.display = 'none';
    document.getElementById('inputCantPend').value = 1;
    document.getElementById('buscarProductoPendiente').value = '';
    document.getElementById('buscarProductoPendiente').focus();
}

/* ── Carrito ── */
function renderCarritoMini() {
    const div = document.getElementById('carritoMini');
    const tot = document.getElementById('totalMini');
    if (!carritoP.length) {
        div.innerHTML = '<div class="carrito-vacio-mini">Sin productos</div>';
        tot.style.display = 'none';
        return;
    }
    div.innerHTML = carritoP.map((i,idx) => {
        const cantStr = Number.isInteger(i.cantidad) ? i.cantidad : i.cantidad.toFixed(3).replace(/\.?0+$/,'');
        const precioDisplay = i.tiene_promo
            ? `<span style="text-decoration:line-through;color:#aaa;font-size:11px;">$${i.precio_normal.toFixed(2)}</span> <span style="color:#2e7d32;font-weight:700;font-size:12px;">$${i.precio.toFixed(2)}</span>`
            : `<span style="color:#14ace7;font-weight:600;font-size:12px;">$${i.precio.toFixed(2)}</span>`;
        return `<div class="item-mini">
            <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${i.nombre}</span>
            <span style="white-space:nowrap;color:#555;font-size:12px;margin:0 6px;">×${cantStr}</span>
            ${precioDisplay}
            <button class="btn-quitar-mini" onclick="quitarProd(${idx})">×</button>
        </div>`;
    }).join('');
    tot.style.display = 'flex';
    recalcularTotalPend();
}

function quitarProd(i) { carritoP.splice(i,1); renderCarritoMini(); }

function recalcularTotalPend() {
    // Subtotal bruto: siempre precio original (antes de promos)
    const subtotalBruto = carritoP.reduce((a, i) => a + (i.cantidad * (i.precio_normal ?? i.precio)), 0);
    // Ahorro por promociones
    const ahorroPromos = carritoP.reduce((a, i) => {
        if (i.tiene_promo && i.precio_normal > i.precio) {
            return a + (i.cantidad * (i.precio_normal - i.precio));
        }
        return a;
    }, 0);
    const subtotalConPromo = subtotalBruto - ahorroPromos;

    const aplicarDesc = document.getElementById('aplicarDescPend').checked;
    const descPorc = (clientePendActual && clientePendActual.descuento > 0 && aplicarDesc)
        ? Math.min(parseFloat(document.getElementById('inputDescClientePend').value || 0), clientePendActual.descuento)
        : 0;
    const descCliente    = subtotalConPromo * (descPorc / 100);
    const descuentoTotal = ahorroPromos + descCliente;

    const metodo   = document.getElementById('metodoPagoPend').value;
    const comision = (metodo === 'Terminal') ? (subtotalBruto - descuentoTotal) * (porcComisionPend / 100) : 0;
    const total    = subtotalBruto - descuentoTotal + comision;

    document.getElementById('totalValor').textContent = '$' + total.toFixed(2);

    const lineaDesc = document.getElementById('descuentoLinea');
    lineaDesc.style.display = (descuentoTotal > 0.004 && carritoP.length) ? 'flex' : 'none';
    document.getElementById('descuentoValor').textContent = '-$' + descuentoTotal.toFixed(2);

    const lineaCom = document.getElementById('comisionLineaMini');
    lineaCom.style.display = (comision > 0 && carritoP.length) ? 'flex' : 'none';
    document.getElementById('comisionValorMini').textContent = '+$' + comision.toFixed(2);
    document.getElementById('comisionValorPend').textContent  = '+$' + comision.toFixed(2);
}

/* ── Búsqueda de clientes ── */
function filtrarClientesPend(q) {
    const qn = normalizar(q);
    const drop = document.getElementById('dropClientesPend');
    const resultados = qn ? clientesPend.filter(c => normalizar(c.texto).includes(qn)) : clientesPend.slice(0, 30);
    if (!resultados.length) {
        drop.innerHTML = '<div style="padding:10px;text-align:center;color:#aaa;font-size:13px;">Sin resultados</div>';
    } else {
        drop.innerHTML = resultados.map(c => `
            <div onclick="seleccionarClientePend(${c.id})"
                style="padding:9px 12px;cursor:pointer;border-bottom:0.5px solid #f5f5f5;font-size:13px;"
                onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background=''">
                ${c.nombre}
                ${c.descuento > 0 ? `<span style="font-size:11px;color:#2e7d32;font-weight:600;margin-left:4px;">${c.descuento}% desc.</span>` : ''}
                ${c.credito  > 0 ? `<span style="font-size:11px;color:#1565c0;font-weight:600;margin-left:4px;">Crédito</span>` : ''}
            </div>
        `).join('');
    }
    drop.style.display = 'block';
}

function ocultarDropClientes() {
    document.getElementById('dropClientesPend').style.display = 'none';
}

function seleccionarClientePend(id) {
    clientePendActual = clientesPend.find(c => c.id === id);
    if (!clientePendActual) return;
    ocultarDropClientes();
    document.getElementById('buscarClientePend').value = '';
    document.getElementById('inputClienteIdPend').value = clientePendActual.id;
    document.getElementById('clienteSelNombrePend').textContent = clientePendActual.nombre;
    document.getElementById('clienteSelPend').style.display = 'flex';

    // Descuento
    const descGrupo = document.getElementById('descClientePendGrupo');
    if (clientePendActual.descuento > 0) {
        document.getElementById('aplicarDescPend').checked = true;
        document.getElementById('inputDescClientePend').value = clientePendActual.descuento.toFixed(1);
        document.getElementById('inputDescClientePend').max   = clientePendActual.descuento;
        document.getElementById('descMaxPendLabel').textContent = 'Máximo: ' + parseFloat(clientePendActual.descuento).toFixed(1) + '%';
        descGrupo.style.display = 'block';
    } else {
        descGrupo.style.display = 'none';
    }

    // Crédito: solo habilitar si el cliente tiene crédito autorizado
    const optCredito = document.getElementById('optCreditoPend');
    if (clientePendActual.credito > 0) {
        optCredito.style.display = '';
        optCredito.disabled = false;
    } else {
        optCredito.style.display = 'none';
        optCredito.disabled = true;
        // Si ya estaba seleccionado Crédito, resetear
        const sel = document.getElementById('metodoPagoPend');
        if (sel.value === 'Crédito') {
            sel.value = 'Efectivo';
            cambiarMetodoPend('Efectivo');
        }
    }

    recalcularTotalPend();
}

function quitarClientePend() {
    // Si método es Crédito, resetear
    const sel = document.getElementById('metodoPagoPend');
    if (sel.value === 'Crédito') {
        sel.value = 'Efectivo';
        cambiarMetodoPend('Efectivo');
    }
    // Ocultar opción Crédito
    const optCredito = document.getElementById('optCreditoPend');
    optCredito.style.display = 'none';
    optCredito.disabled = true;

    clientePendActual = null;
    document.getElementById('inputClienteIdPend').value = '';
    document.getElementById('buscarClientePend').value  = '';
    document.getElementById('clienteSelPend').style.display = 'none';
    document.getElementById('descClientePendGrupo').style.display = 'none';
    document.getElementById('aplicarDescPend').checked = false;
    document.getElementById('inputDescClientePend').value = 0;
    recalcularTotalPend();
}

function clampearDescClientePend() {
    if (!clientePendActual) return;
    const input  = document.getElementById('inputDescClientePend');
    const maximo = parseFloat(clientePendActual.descuento || 0);
    let valor = parseFloat(input.value || 0);
    if (isNaN(valor) || valor < 0) valor = 0;
    if (valor > maximo) valor = maximo;
    input.value = valor.toFixed(1);
    recalcularTotalPend();
}

/* ── Filtro lista pendientes ── */
function filtrarPendientes(q) {
    q = normalizar(q);
    document.querySelectorAll('.pendiente-item').forEach(function(item) {
        const texto = normalizar(item.dataset.pendienteTexto || item.textContent);
        item.style.display = texto.includes(q) ? '' : 'none';
    });
}

/* ── Método de pago ── */
const porcComisionPend = <?= floatval($sucursalTicket['comision_terminal_pct'] ?? 0) ?>;
const datosTicket = <?= json_encode([
    'nombre'       => $sucursalTicket['nombre']       ?? 'Ferretería Aldrete',
    'rfc'          => $sucursalTicket['rfc']           ?? '',
    'direccion'    => $sucursalTicket['direccion']     ?? '',
    'telefono'     => $sucursalTicket['telefono']      ?? '',
    'datos_ticket' => $sucursalTicket['datos_ticket']  ?? '',
]) ?>;

function cambiarMetodoPend(valor) {
    if (valor === 'Crédito') {
        if (!clientePendActual || !clientePendActual.credito) {
            alert('Selecciona un cliente con crédito autorizado para usar este método de pago.');
            document.getElementById('metodoPagoPend').value = 'Efectivo';
            valor = 'Efectivo';
        }
    }
    document.getElementById('refTransfPendGrupo').style.display =
        valor === 'Transferencia' ? 'block' : 'none';
    document.getElementById('comisionTerminalGrupo').style.display =
        valor === 'Terminal' ? 'block' : 'none';
    recalcularTotalPend();
}

function prepararPendiente() {
    if (!carritoP.length) { alert('Agrega al menos un producto.'); return false; }

    const metodo = document.getElementById('metodoPagoPend').value;

    // Validar referencia obligatoria
    if (metodo === 'Transferencia') {
        const ref = document.getElementById('refTransfPend').value.trim();
        if (!ref) {
            alert('El número de referencia bancaria es obligatorio para pago por Transferencia.');
            document.getElementById('refTransfPend').focus();
            return false;
        }
    }

    const subtotalBruto = carritoP.reduce((a, i) => a + (i.cantidad * (i.precio_normal ?? i.precio)), 0);
    const ahorroPromos  = carritoP.reduce((a, i) => {
        if (i.tiene_promo && i.precio_normal > i.precio) return a + (i.cantidad * (i.precio_normal - i.precio));
        return a;
    }, 0);
    const subtotalConPromo = subtotalBruto - ahorroPromos;
    const aplicarDesc = document.getElementById('aplicarDescPend').checked;
    const descPorc = (clientePendActual && clientePendActual.descuento > 0 && aplicarDesc)
        ? Math.min(parseFloat(document.getElementById('inputDescClientePend').value || 0), clientePendActual.descuento)
        : 0;
    const descCliente    = subtotalConPromo * (descPorc / 100);
    const descuentoTotal = ahorroPromos + descCliente;
    const comision       = (metodo === 'Terminal') ? (subtotalBruto - descuentoTotal) * (porcComisionPend / 100) : 0;
    const total          = subtotalBruto - descuentoTotal + comision;

    document.getElementById('inputItemsPend').value            = JSON.stringify(carritoP.map(i => ({
        producto_id:   i.producto_id,
        cantidad:      i.cantidad,
        precio:        i.precio,
        precio_normal: i.precio_normal ?? i.precio,
    })));
    document.getElementById('inputSubtotalPend').value          = subtotalBruto.toFixed(2);
    document.getElementById('inputDescuentoPend').value         = descuentoTotal.toFixed(2);
    document.getElementById('inputComisionTerminalPend').value  = comision.toFixed(2);
    document.getElementById('inputTotalPend').value             = total.toFixed(2);
    return true;
}

/* ── Ticket de venta pendiente ── */
function fmt(n) { return parseFloat(n||0).toFixed(2); }
function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function generarTicketHTML(venta) {
    const fecha = venta.fecha_formateada || venta.created_at;
    let html = `<div class="t-centro t-bold t-grande">${esc(datosTicket.nombre)}</div>`;

    if (datosTicket.datos_ticket) {
        html += `<div class="t-centro" style="white-space:pre-line;font-size:11px;">${esc(datosTicket.datos_ticket)}</div>`;
    } else {
        if (datosTicket.rfc)       html += `<div class="t-centro">RFC: ${esc(datosTicket.rfc)}</div>`;
        if (datosTicket.direccion) html += `<div class="t-centro">${esc(datosTicket.direccion)}</div>`;
        if (datosTicket.telefono)  html += `<div class="t-centro">Tel: ${esc(datosTicket.telefono)}</div>`;
    }

    html += `
        <div class="t-linea"></div>
        <div class="t-fila"><span>Folio:</span><span>${venta.folio || ('#'+venta.venta_id)}</span></div>
        <div class="t-fila"><span>Fecha:</span><span>${esc(fecha)}</span></div>
        <div class="t-fila"><span>Cajero:</span><span>${esc(venta.cajero || '—')}</span></div>`;

    if (venta.cliente) {
        html += `<div class="t-fila"><span>Cliente:</span><span>${esc(venta.cliente)}</span></div>`;
    }

    html += `
        <div class="t-linea"></div>
        <div class="t-fila t-bold"><span>Producto</span><span>Importe</span></div>
        <div class="t-linea"></div>`;

    let ahorroPromoTicket = 0;
    (venta.productos || []).forEach(p => {
        const tieneAjuste = p.nota_ajuste && p.nota_ajuste.trim() !== '';
        const precioOrig  = parseFloat(p.precio_unitario);
        const precioFinal = parseFloat(p.precio_final || p.precio_unitario);
        const tienePromo  = !tieneAjuste && precioFinal < precioOrig - 0.001;

        if (tienePromo) ahorroPromoTicket += (precioOrig - precioFinal) * parseFloat(p.cantidad);

        html += `<div>${esc(p.nombre_producto)}</div>`;
        if (tienePromo) {
            html += `<div style="font-size:10px;text-decoration:line-through;color:#888;">$${fmt(precioOrig)}/u (precio normal)</div>`;
            html += `<div class="t-fila"><span>${parseFloat(p.cantidad).toFixed(2)} x $${fmt(precioFinal)}</span><span>$${fmt(p.subtotal)}</span></div>`;
        } else {
            const precioUsado = tieneAjuste ? precioFinal : precioOrig;
            html += `<div class="t-fila"><span>${parseFloat(p.cantidad).toFixed(2)} x $${fmt(precioUsado)}${tieneAjuste ? ' *' : ''}</span><span>$${fmt(p.subtotal)}</span></div>`;
            if (tieneAjuste) html += `<div style="font-size:10px;color:#666;">* Ajuste daño: ${esc(p.nota_ajuste)}</div>`;
        }
    });

    html += `<div class="t-linea"></div>`;

    if (parseFloat(venta.descuento) > 0) {
        html += `<div class="t-fila"><span>Subtotal</span><span>$${fmt(venta.subtotal)}</span></div>`;
    }
    if (parseFloat(venta.comision_terminal) > 0) {
        html += `<div class="t-fila"><span>Comisión terminal</span><span>$${fmt(venta.comision_terminal)}</span></div>`;
    }
    if (parseFloat(venta.descuento) > 0) {
        html += `<div class="t-fila" style="font-size:11px;"><span>Ahorraste</span><span>-$${fmt(venta.descuento)}</span></div>`;
    }

    html += `
        <div class="t-fila t-bold t-grande">
            <span>TOTAL</span><span>$${fmt(venta.total)}</span>
        </div>
        <div class="t-linea"></div>
        <div class="t-fila"><span>Método de pago</span><span>${esc(venta.metodo_pago)}</span></div>`;

    if (venta.metodo_pago === 'Efectivo' && parseFloat(venta.cambio) > 0) {
        html += `
        <div class="t-fila"><span>Recibido</span><span>$${fmt(venta.monto_efectivo)}</span></div>
        <div class="t-fila"><span>Cambio</span><span>$${fmt(venta.cambio)}</span></div>`;
    }
    if (venta.metodo_pago === 'Mixto') {
        html += `
        <div class="t-fila"><span>Efectivo</span><span>$${fmt(venta.monto_efectivo)}</span></div>
        <div class="t-fila"><span>Terminal</span><span>$${fmt(venta.monto_terminal)}</span></div>`;
    }
    if (venta.metodo_pago === 'Credito') {
        html += `<div class="t-centro" style="margin-top:6px;font-weight:bold;">*** VENTA A CRÉDITO ***</div>`;
    }

    html += `
        <div class="t-linea"></div>
        <div class="t-centro">¡Gracias por su compra!</div>
        <div class="t-centro" style="font-size:10px;margin-top:4px;">Conserve su ticket</div>`;

    document.getElementById('ticketImprimir').innerHTML = html;
}

function abrirTicketVenta(ventaId) {
    fetch(`historialVentas.php?detalle_venta=${ventaId}`)
        .then(r => r.json())
        .then(function(venta) {
            if (!venta) { alert('No se pudo cargar el ticket.'); return; }
            generarTicketHTML(venta);
            document.getElementById('ticketContenidoVentaPend').innerHTML =
                document.getElementById('ticketImprimir').innerHTML;
            document.getElementById('modalTicketVentaPend').classList.add('visible');
            document.getElementById('modalTicketVentaPend').setAttribute('aria-hidden', 'false');
        })
        .catch(e => alert('Error al cargar el ticket: ' + e.message));
}

function cerrarTicketVenta() {
    document.getElementById('modalTicketVentaPend').classList.remove('visible');
    document.getElementById('modalTicketVentaPend').setAttribute('aria-hidden', 'true');
}

function imprimirTicketPend() {
    cerrarTicketVenta();
    requestAnimationFrame(() => {
        const ticket = document.getElementById('ticketImprimir');
        ticket.style.display = 'block';
        const altoMm = Math.ceil(ticket.scrollHeight * 0.2646) + 4;
        ticket.style.display = '';
        let estilo = document.getElementById('__ticketPageStyle');
        if (!estilo) {
            estilo = document.createElement('style');
            estilo.id = '__ticketPageStyle';
            document.head.appendChild(estilo);
        }
        estilo.textContent = `@page { size: 80mm ${altoMm}mm; margin: 0; }`;
        setTimeout(() => window.print(), 150);
    });
}

</script>
</body>
</html>
