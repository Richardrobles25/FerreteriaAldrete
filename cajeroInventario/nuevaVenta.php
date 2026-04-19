<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

$stmt = $pdo->prepare("SELECT * FROM cajas WHERE usuario_id = ? AND estado = 'Abierta' ORDER BY abierta_en DESC LIMIT 1");
$stmt->execute([$_SESSION['usuario_id']]);
$caja = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$caja) {
    header('Location: abrirCaja.php?msg=sinCaja');
    exit();
}

$sucursales = $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);

// Datos de la sucursal para el ticket
$stmtSuc = $pdo->prepare("SELECT * FROM sucursales WHERE sucursal_id = ?");
$stmtSuc->execute([$_SESSION['sucursal_id']]);
$sucursalTicket = $stmtSuc->fetch(PDO::FETCH_ASSOC);

// ── AJAX: paquetes activos ───────────────────────────────────────────────────
// ── AJAX: todos los productos de la sucursal (para búsqueda local en JS) ─────
if (isset($_GET['get_productos_all'])) {
    $stmt = $pdo->prepare("
        SELECT producto_id, codigo, nombre_producto, precio_venta,
               precio_mayoreo, stock_actual, tipo_venta
        FROM productos
        WHERE sucursal_id = ? AND activo = 1
        ORDER BY nombre_producto ASC
    ");
    $stmt->execute([$_SESSION['sucursal_id']]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

if (isset($_GET['get_paquetes'])) {
    $stmtPaq = $pdo->prepare("
        SELECT pk.paquete_id, pk.codigo, pk.nombre, pk.precio_paquete,
               pp.producto_id, pp.cantidad AS cantidad_requerida,
               pr.nombre_producto, pr.stock_actual
        FROM paquetes pk
        JOIN paquete_productos pp ON pk.paquete_id = pp.paquete_id
        JOIN productos pr ON pp.producto_id = pr.producto_id
        WHERE pk.activo = 1 AND pr.sucursal_id = ?
    ");
    $stmtPaq->execute([$_SESSION['sucursal_id']]);
    $filas = $stmtPaq->fetchAll(PDO::FETCH_ASSOC);
    $agrupados = [];
    foreach ($filas as $f) {
        $pid = intval($f['paquete_id']);
        if (!isset($agrupados[$pid])) {
            $agrupados[$pid] = [
                'paquete_id'     => $pid,
                'codigo'         => $f['codigo'],
                'nombre'         => $f['nombre'],
                'precio_paquete' => floatval($f['precio_paquete']),
                'productos'      => []
            ];
        }
        $agrupados[$pid]['productos'][] = [
            'producto_id'        => intval($f['producto_id']),
            'nombre_producto'    => $f['nombre_producto'],
            'cantidad_requerida' => floatval($f['cantidad_requerida']),
            'stock_actual'       => floatval($f['stock_actual'])
        ];
    }
    header('Content-Type: application/json');
    echo json_encode(array_values($agrupados));
    exit();
}

// ── AJAX: buscar paquete ─────────────────────────────────────────────────────
if (isset($_GET['buscar_paquete'])) {
    $termino = trim($_GET['buscar_paquete']);
    $stmt = $pdo->prepare("
        SELECT pk.paquete_id, pk.codigo, pk.nombre, pk.precio_paquete,
               pp.producto_id, pp.cantidad AS cantidad_req,
               pr.stock_actual, pr.nombre_producto
        FROM paquetes pk
        JOIN paquete_productos pp ON pk.paquete_id = pp.paquete_id
        JOIN productos pr ON pp.producto_id = pr.producto_id
        WHERE pk.activo = 1 AND pr.sucursal_id = ?
          AND (pk.codigo LIKE ? OR pk.nombre LIKE ?)
    ");
    $stmt->execute([$_SESSION['sucursal_id'], '%'.$termino.'%', '%'.$termino.'%']);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $resultado = [];
    foreach ($filas as $f) {
        $pid = intval($f['paquete_id']);
        if (!isset($resultado[$pid])) {
            $resultado[$pid] = [
                'paquete_id'    => $pid,
                'codigo'        => $f['codigo'],
                'nombre'        => $f['nombre'],
                'precio_paquete'=> floatval($f['precio_paquete']),
                'disponible'    => true,
                'productos'     => []
            ];
        }
        $resultado[$pid]['productos'][] = [
            'producto_id'     => intval($f['producto_id']),
            'nombre_producto' => $f['nombre_producto'],
            'cantidad_req'    => floatval($f['cantidad_req']),
            'stock_actual'    => floatval($f['stock_actual'])
        ];
        if (floatval($f['stock_actual']) < floatval($f['cantidad_req'])) {
            $resultado[$pid]['disponible'] = false;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(array_values($resultado));
    exit();
}

// ── AJAX: búsqueda combinada (producto + paquete en un solo request) ─────────
if (isset($_GET['buscar_combo'])) {
    $termino     = trim($_GET['buscar_combo']);
    $sucursal_id = intval($_GET['sucursal_id'] ?? $_SESSION['sucursal_id']);
    $like        = '%' . $termino . '%';

    // Productos
    $stmtP = $pdo->prepare("
        SELECT producto_id, codigo, nombre_producto, precio_venta,
               precio_mayoreo, stock_actual, tipo_venta
        FROM productos
        WHERE sucursal_id = ? AND activo = 1
          AND (codigo LIKE ? OR nombre_producto LIKE ?)
        LIMIT 10
    ");
    $stmtP->execute([$sucursal_id, $like, $like]);
    $productos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // Paquetes
    $stmtPaq = $pdo->prepare("
        SELECT pk.paquete_id, pk.codigo, pk.nombre, pk.precio_paquete,
               pp.producto_id, pp.cantidad AS cantidad_req,
               pr.stock_actual, pr.nombre_producto
        FROM paquetes pk
        JOIN paquete_productos pp ON pk.paquete_id = pp.paquete_id
        JOIN productos pr ON pp.producto_id = pr.producto_id
        WHERE pk.activo = 1 AND pr.sucursal_id = ?
          AND (pk.codigo LIKE ? OR pk.nombre LIKE ?)
    ");
    $stmtPaq->execute([$sucursal_id, $like, $like]);
    $filasPaq = $stmtPaq->fetchAll(PDO::FETCH_ASSOC);

    $paquetes = [];
    foreach ($filasPaq as $f) {
        $pid = intval($f['paquete_id']);
        if (!isset($paquetes[$pid])) {
            $paquetes[$pid] = [
                'paquete_id'     => $pid,
                'codigo'         => $f['codigo'],
                'nombre'         => $f['nombre'],
                'precio_paquete' => floatval($f['precio_paquete']),
                'disponible'     => true,
                'productos'      => []
            ];
        }
        $paquetes[$pid]['productos'][] = [
            'producto_id'     => intval($f['producto_id']),
            'nombre_producto' => $f['nombre_producto'],
            'cantidad_req'    => floatval($f['cantidad_req']),
            'stock_actual'    => floatval($f['stock_actual'])
        ];
        if (floatval($f['stock_actual']) < floatval($f['cantidad_req'])) {
            $paquetes[$pid]['disponible'] = false;
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['productos' => $productos, 'paquetes' => array_values($paquetes)]);
    exit();
}

// ── AJAX: buscar producto ────────────────────────────────────────────────────
if (isset($_GET['buscar_producto'])) {
    $termino     = trim($_GET['buscar_producto']);
    $sucursal_id = intval($_GET['sucursal_id'] ?? $_SESSION['sucursal_id']);
    $stmt = $pdo->prepare("
        SELECT producto_id, codigo, nombre_producto, precio_venta,
               precio_mayoreo, stock_actual, tipo_venta
        FROM productos
        WHERE sucursal_id = ? AND activo = 1
          AND (codigo LIKE ? OR nombre_producto LIKE ?)
        LIMIT 10
    ");
    $stmt->execute([$sucursal_id, '%'.$termino.'%', '%'.$termino.'%']);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// ── AJAX: buscar producto por código exacto (scanner) ───────────────────────
if (isset($_GET['scan_codigo'])) {
    $codigo      = trim($_GET['scan_codigo']);
    $sucursal_id = $_SESSION['sucursal_id'];
    $stmt = $pdo->prepare("
        SELECT producto_id, codigo, nombre_producto, precio_venta,
               stock_actual, tipo_venta
        FROM productos
        WHERE sucursal_id = ? AND activo = 1 AND codigo = ?
        LIMIT 1
    ");
    $stmt->execute([$sucursal_id, $codigo]);
    $prod = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($prod ?: null);
    exit();
}

// ── AJAX: inventario por sucursal ────────────────────────────────────────────
if (isset($_GET['inventario_sucursal'])) {
    $sucursal_id = intval($_GET['inventario_sucursal']);
    $buscar      = trim($_GET['buscar_inv'] ?? '');
    $where       = "WHERE sucursal_id = ? AND activo = 1";
    $params      = [$sucursal_id];
    if ($buscar) { $where .= " AND (nombre_producto LIKE ? OR codigo LIKE ?)"; $params[] = '%'.$buscar.'%'; $params[] = '%'.$buscar.'%'; }
    $stmt = $pdo->prepare("SELECT producto_id, codigo, nombre_producto, stock_actual, precio_venta, tipo_venta FROM productos $where ORDER BY nombre_producto ASC LIMIT 50");
    $stmt->execute($params);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// ── AJAX: todos los clientes (para búsqueda local en JS) ────────────────────
if (isset($_GET['get_clientes_all'])) {
    $stmt = $pdo->query("
        SELECT cliente_id, nombre_completo, telefono,
               descuento_fijo, credito_autorizado
        FROM clientes WHERE activo = 1
        ORDER BY nombre_completo ASC
    ");
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// ── AJAX: buscar cliente (mantenido por compatibilidad) ──────────────────────
if (isset($_GET['buscar_cliente'])) {
    $termino = trim($_GET['buscar_cliente']);
    $stmt = $pdo->prepare("
        SELECT cliente_id, nombre_completo, telefono,
               descuento_fijo, credito_autorizado
        FROM clientes
        WHERE activo = 1 AND nombre_completo LIKE ?
        LIMIT 8
    ");
    $stmt->execute(['%'.$termino.'%']);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// ── AJAX: obtener detalle de venta para ticket ───────────────────────────────
if (isset($_GET['ticket_venta'])) {
    $venta_id = intval($_GET['ticket_venta']);
    $stmtV = $pdo->prepare("
        SELECT v.*, c.nombre_completo AS cliente, c.telefono AS tel_cliente
        FROM ventas v
        LEFT JOIN clientes c ON v.cliente_id = c.cliente_id
        WHERE v.venta_id = ?
    ");
    $stmtV->execute([$venta_id]);
    $venta = $stmtV->fetch(PDO::FETCH_ASSOC);
    if ($venta) {
        // Formatear fecha en servidor (evita problemas de zona horaria en JS)
        $venta['fecha_formateada'] = date('d/m/Y H:i', strtotime($venta['created_at']));
    }

    $stmtP = $pdo->prepare("
        SELECT vp.cantidad, vp.precio_unitario, vp.subtotal, p.nombre_producto, p.codigo
        FROM venta_productos vp
        JOIN productos p ON vp.producto_id = p.producto_id
        WHERE vp.venta_id = ?
    ");
    $stmtP->execute([$venta_id]);
    $venta['productos'] = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($venta);
    exit();
}

// ── Procesar venta ───────────────────────────────────────────────────────────
$errorVenta = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_venta'])) {
    $items             = json_decode($_POST['items'], true);
    $cliente_id        = intval($_POST['cliente_id'] ?? 0) ?: null;
    $metodo_pago       = $_POST['metodo_pago'] ?? '';
    $monto_efectivo    = floatval($_POST['monto_efectivo'] ?? 0);
    $monto_terminal    = floatval($_POST['monto_terminal'] ?? 0);
    $comision_terminal = floatval($_POST['comision_terminal'] ?? 0);
    $descuento         = floatval($_POST['descuento'] ?? 0);
    $subtotal          = floatval($_POST['subtotal'] ?? 0);
    $total             = floatval($_POST['total'] ?? 0);
    $cambio            = floatval($_POST['cambio'] ?? 0);

    if (!empty($items) && $metodo_pago) {
        $pdo->beginTransaction();
        try {
            // Generar folio mensual: NNNN-MM-YYYY (se reinicia cada 1ro de mes)
            $mesFolio  = date('m');
            $anioFolio = date('Y');
            // SELECT con FOR UPDATE para evitar folios duplicados en ventas simultáneas
            $stmtFolio = $pdo->prepare("
                SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(folio,'-',1) AS UNSIGNED)),0)+1
                FROM ventas
                WHERE folio IS NOT NULL
                  AND MONTH(created_at) = ? AND YEAR(created_at) = ?
                FOR UPDATE
            ");
            $stmtFolio->execute([$mesFolio, $anioFolio]);
            $numFolio = intval($stmtFolio->fetchColumn());
            $folio = str_pad($numFolio, 4, '0', STR_PAD_LEFT) . '-' . $mesFolio . '-' . $anioFolio;

            $stmt = $pdo->prepare("
                INSERT INTO ventas
                (folio,caja_id,cliente_id,usuario_id,subtotal,descuento,comision_terminal,
                 total,metodo_pago,monto_efectivo,monto_terminal,cambio,estado)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'Completada')
            ");
            $stmt->execute([$folio,$caja['caja_id'],$cliente_id,$_SESSION['usuario_id'],
                            $subtotal,$descuento,$comision_terminal,$total,
                            $metodo_pago,$monto_efectivo,$monto_terminal,$cambio]);
            $venta_id = $pdo->lastInsertId();

            foreach ($items as $item) {
                $subtotalItem = $item['cantidad'] * $item['precio'];
                $pdo->prepare("INSERT INTO venta_productos (venta_id,producto_id,cantidad,precio_unitario,descuento,subtotal) VALUES (?,?,?,?,0,?)")
                    ->execute([$venta_id,$item['producto_id'],$item['cantidad'],$item['precio'],$subtotalItem]);

                // Bloquear la fila del producto para evitar condición de carrera
                $stmtS = $pdo->prepare("SELECT stock_actual FROM productos WHERE producto_id = ? FOR UPDATE");
                $stmtS->execute([$item['producto_id']]);
                $stockAnterior = floatval($stmtS->fetchColumn());

                // Validar stock en servidor antes de descontar
                if ($stockAnterior < $item['cantidad']) {
                    throw new Exception("Stock insuficiente para uno de los productos. Verifica el carrito e intenta de nuevo.");
                }

                $stockNuevo = $stockAnterior - $item['cantidad'];
                $pdo->prepare("UPDATE productos SET stock_actual = ? WHERE producto_id = ?")->execute([$stockNuevo,$item['producto_id']]);
                $pdo->prepare("INSERT INTO movimientos_inventario (producto_id,usuario_id,tipo,cantidad,stock_anterior,stock_nuevo,motivo) VALUES (?,?,'Salida',?,?,?,'Venta')")
                    ->execute([$item['producto_id'],$_SESSION['usuario_id'],$item['cantidad'],$stockAnterior,$stockNuevo]);
            }

            if ($metodo_pago === 'Credito' && $cliente_id) {
                $pdo->prepare("INSERT INTO creditos (cliente_id,venta_id,monto_total,saldo_pendiente,estado) VALUES (?,?,?,?,'Activo')")
                    ->execute([$cliente_id,$venta_id,$total,$total]);
            }

            $pdo->commit();
            header('Location: nuevaVenta.php?msg=exito&venta_id='.$venta_id);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $errorVenta = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Venta — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 14px; overflow-y: auto; display: grid; grid-template-columns: 1fr 360px; gap: 12px; }
    .panel-izq { display: flex; flex-direction: column; gap: 10px; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; }
    .card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 10px; }
    .busqueda-wrap { position: relative; }
    .busqueda-wrap input { width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
    .busqueda-wrap input:focus { outline: none; border-color: #14ace7; }
    .dropdown-resultados { display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #e8e8e8; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 100; max-height: 280px; overflow-y: auto; margin-top: 2px; }
    .dropdown-resultados.visible { display: block; }
    .resultado-item { padding: 10px 14px; cursor: pointer; border-bottom: 0.5px solid #f5f5f5; display: flex; justify-content: space-between; align-items: center; }
    .resultado-item:hover { background: #eef8ff; }
    .resultado-item:last-child { border-bottom: none; }
    .resultado-nombre { font-size: 13px; color: #333; font-weight: 600; }
    .resultado-codigo { font-size: 11px; color: #999; }
    .resultado-precio { font-size: 13px; color: #14ace7; font-weight: 700; }
    .stock-badge { font-size: 11px; padding: 2px 7px; border-radius: 99px; font-weight: 600; }
    .stock-ok { background: #e8f5e9; color: #2e7d32; }
    .stock-bajo { background: #fdecea; color: #c0392b; }
    .scanner-row { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
    .scanner-row input { flex: 1; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: monospace; }
    .scanner-row input:focus { outline: none; border-color: #2e7d32; border-width: 2px; }
    .btn-scan-mode { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; white-space: nowrap; }
    .btn-scan-mode.activo { background: #2e7d32; color: white; border-color: #2e7d32; }
    .scan-feedback { font-size: 12px; padding: 4px 10px; border-radius: 99px; display: none; }
    .scan-ok { background: #e8f5e9; color: #2e7d32; }
    .scan-err { background: #fdecea; color: #c0392b; }
    .carrito-tabla { width: 100%; border-collapse: collapse; }
    .carrito-tabla th { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; padding: 7px 8px; text-align: left; border-bottom: 1px solid #eee; }
    .carrito-tabla td { padding: 8px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; color: #444; vertical-align: middle; }
    .carrito-tabla tr:last-child td { border-bottom: none; }
    .qty-input { width: 65px; padding: 5px 8px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; text-align: center; }
    .qty-input:focus { outline: none; border-color: #14ace7; }
    .btn-eliminar-item { background: #fdecea; color: #c0392b; border: none; padding: 4px 9px; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 600; }
    .carrito-vacio { text-align: center; color: #aaa; padding: 24px; font-size: 13px; }
    .paq-row td { background: #fffde7 !important; }
    .rec-paquetes { display: none; background: #fff8e1; border: 1px solid #90caf9; border-radius: 8px; padding: 12px 14px; }
    .rec-titulo { font-size: 12px; font-weight: 700; color: #1565c0; margin-bottom: 8px; }
    .rec-item { background: white; border-radius: 6px; padding: 10px; margin-bottom: 8px; border: 0.5px solid #90caf9; }
    .rec-item:last-child { margin-bottom: 0; }
    .rec-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
    .rec-nombre { font-size: 13px; font-weight: 600; color: #333; }
    .rec-precio { color: #14ace7; font-weight: 700; font-size: 13px; }
    .rec-barra-bg { height: 4px; background: #f0f0f0; border-radius: 99px; margin-bottom: 4px; }
    .rec-barra { height: 4px; border-radius: 99px; }
    .rec-desc { font-size: 11px; color: #888; margin-bottom: 7px; }
    .rec-btn { width: 100%; border: none; padding: 7px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; color: white; }
    .cliente-seleccionado { display: none; background: #eef8ff; border: 1px solid #bbdefb; border-radius: 6px; padding: 8px 12px; font-size: 13px; margin-top: 8px; justify-content: space-between; align-items: center; }
    .btn-quitar-cliente { background: none; border: none; color: #c0392b; cursor: pointer; font-size: 12px; font-weight: 600; }
    .panel-der { display: flex; flex-direction: column; gap: 10px; }
    .resumen { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 16px; }
    .resumen h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 12px; }
    .resumen-fila { display: flex; justify-content: space-between; font-size: 13px; color: #555; margin-bottom: 8px; }
    .resumen-fila.total { border-top: 1px solid #eee; margin-top: 8px; padding-top: 10px; font-size: 18px; font-weight: 700; color: #222; }
    .resumen-fila.cambio-fila { color: #2e7d32; font-weight: 600; }
    .form-group-sm { margin-bottom: 10px; }
    .form-group-sm label { display: block; font-size: 11px; color: #888; margin-bottom: 4px; font-weight: 600; text-transform: uppercase; }
    .form-group-sm input { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .form-group-sm input:focus { outline: none; border-color: #14ace7; }
    .metodos { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-bottom: 10px; }
    .metodo-btn { padding: 8px; border: 2px solid #e8e8e8; border-radius: 6px; text-align: center; cursor: pointer; font-size: 13px; color: #555; background: white; transition: all 0.15s; }
    .metodo-btn:hover { border-color: #14ace7; color: #14ace7; }
    .metodo-btn.selected { border-color: #14ace7; background: #eef8ff; color: #14ace7; font-weight: 600; }
    .campos-pago { display: none; }
    .campos-pago.visible { display: block; }
    .btn-cobrar { width: 100%; background: #14ace7; color: white; border: none; padding: 13px; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; margin-top: 8px; }
    .btn-cobrar:hover { background: #1196cb; }
    .btn-cobrar:disabled { background: #ddd; cursor: not-allowed; }
    .btn-cancelar-venta { width: 100%; background: white; color: #888; border: 1px solid #ddd; padding: 9px; border-radius: 8px; font-size: 13px; cursor: pointer; margin-top: 6px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; padding: 12px 16px; border-radius: 6px; font-size: 13px; border-left: 3px solid #2e7d32; grid-column: span 2; display: flex; justify-content: space-between; align-items: center; }
    .btn-print-ticket { background: #2e7d32; color: white; border: none; padding: 7px 16px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 200; align-items: center; justify-content: center; }
    .modal-overlay.visible { display: flex; }
    .modal { background: white; border-radius: 10px; width: 90%; max-width: 700px; max-height: 80vh; display: flex; flex-direction: column; overflow: hidden; }
    .modal-header { padding: 16px 20px; border-bottom: 0.5px solid #eee; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h3 { font-size: 16px; font-weight: 600; color: #222; margin: 0; }
    .modal-header button { background: none; border: none; font-size: 20px; cursor: pointer; color: #888; }
    .modal-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
    .modal-filtros { display: flex; gap: 10px; margin-bottom: 14px; }
    .modal-filtros input { flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .modal-filtros input:focus { outline: none; border-color: #14ace7; }
    .modal-filtros select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .inv-tabla { width: 100%; border-collapse: collapse; }
    .inv-tabla th { font-size: 11px; color: #888; text-transform: uppercase; padding: 8px 10px; text-align: left; border-bottom: 1px solid #eee; }
    .inv-tabla td { padding: 9px 10px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    .inv-tabla tr:last-child td { border-bottom: none; }
    .btn-agregar-inv { background: #e3f2fd; color: #1565c0; border: none; padding: 4px 10px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; }
    .btn-agregar-inv:disabled { background: #f5f5f5; color: #aaa; cursor: not-allowed; }
    .sucursal-diferente { font-size: 11px; color: #1565c0; background: #e3f2fd; padding: 2px 7px; border-radius: 99px; margin-left: 5px; }

    /* ── Ticket de impresión ── */
    @media print {
        body > * { display: none !important; }
        #ticketImprimir { display: block !important; }
    }
    #ticketImprimir {
        display: none;
        font-family: 'Courier New', monospace;
        font-size: 12px;
        width: 280px;
        margin: 0 auto;
        padding: 10px;
    }
    #ticketImprimir .t-centro { text-align: center; }
    #ticketImprimir .t-linea { border-top: 1px dashed #000; margin: 6px 0; }
    #ticketImprimir .t-fila { display: flex; justify-content: space-between; }
    #ticketImprimir .t-bold { font-weight: bold; }
    #ticketImprimir .t-grande { font-size: 15px; font-weight: bold; }
</style>

<!-- Ticket oculto para impresión -->
<div id="ticketImprimir"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>Ferretería Aldrete</h3>
        <p>Cajero / Inventario</p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item" href="inicioCajeroInventario.php">Inicio</a>
        <div class="divider"></div>

        <div class="menu-label">Ventas</div>
        <a class="menu-item active" href="nuevaVenta.php">Nueva venta</a>
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
            <h2>Nueva Venta — Turno #<?= $caja['numero_turno'] ?></h2>
        </div>
        <div class="topbar-right">
            <span>
                <?= htmlspecialchars($_SESSION['nombre_completo']) ?>
                <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span>
            </span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="content">
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'exito'): ?>
            <div class="msg-exito">
                <span>Venta registrada correctamente. <a href="nuevaVenta.php" style="color:#2e7d32;font-weight:600;">Nueva venta</a></span>
                <button class="btn-print-ticket" onclick="imprimirTicket(<?= intval($_GET['venta_id'] ?? 0) ?>)">
                    🖨 Imprimir ticket
                </button>
            </div>
        <?php endif; ?>
        <?php if ($errorVenta): ?>
            <div style="background:#fdecea;color:#c0392b;padding:12px 16px;border-radius:6px;font-size:13px;border-left:3px solid #c0392b;grid-column:span 2;margin-bottom:4px;">
                ⚠ <?= htmlspecialchars($errorVenta) ?>
            </div>
        <?php endif; ?>

        <div class="panel-izq">
            <!-- Buscar producto / scanner -->
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <h3 style="margin:0;">Agregar producto</h3>
                    <div style="display:flex;gap:6px;">
                        <button type="button" id="btnModoScanner"
                            class="btn-scan-mode"
                            onclick="toggleModoScanner()">
                            📷 Scanner
                        </button>
                        <button type="button" onclick="abrirInventario()"
                            style="background:#e3f2fd;color:#1565c0;border:none;padding:5px 12px;border-radius:5px;font-size:12px;font-weight:600;cursor:pointer;">
                            Ver inventario
                        </button>
                    </div>
                </div>

                <!-- Búsqueda normal -->
                <div id="modoNormal">
                    <div class="busqueda-wrap">
                        <input type="text" id="inputProducto"
                            placeholder="Escribe código o nombre del producto..."
                            autocomplete="off">
                        <div class="dropdown-resultados" id="dropdownProductos"></div>
                    </div>
                </div>

                <!-- Modo scanner -->
                <div id="modoScanner" style="display:none;">
                    <div class="scanner-row">
                        <input type="text" id="inputScanner"
                            placeholder="Escanea el código de barras..."
                            autocomplete="off"
                            autofocus>
                        <span class="scan-feedback" id="scanFeedback"></span>
                    </div>
                    <div style="font-size:11px;color:#aaa;">
                        El campo se limpia automáticamente después de cada scan. Presiona Enter o escanea para agregar.
                    </div>
                </div>
            </div>

            <!-- Carrito -->
            <div class="card" style="flex:1;">
                <h3>Carrito</h3>
                <div class="carrito-vacio" id="carritoVacio">El carrito está vacío</div>
                <table class="carrito-tabla" id="carritoTabla" style="display:none;">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Precio</th>
                            <th>Cant.</th>
                            <th>Disp.</th>
                            <th>Subtotal</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="carritoBody"></tbody>
                </table>

                <!-- Recomendación paquetes -->
                <div class="rec-paquetes" id="recPaquetes" style="margin-top:10px;">
                    <div class="rec-titulo">📦 Paquetes disponibles con estos productos</div>
                    <div id="recLista"></div>
                </div>
            </div>

            <!-- Cliente -->
            <div class="card">
                <h3>Cliente (opcional)</h3>
                <div class="busqueda-wrap">
                    <input type="text" id="inputCliente"
                        placeholder="Buscar por nombre..."
                        autocomplete="off">
                    <div class="dropdown-resultados" id="dropdownClientes"></div>
                </div>
                <div class="cliente-seleccionado" id="clienteSeleccionado">
                    <div>
                        <strong id="clienteNombre"></strong>
                        <span id="clienteTelefono" style="font-size:12px;color:#999;margin-left:8px;"></span>
                        <span id="clienteDescuento" style="font-size:12px;color:#14ace7;margin-left:8px;"></span>
                    </div>
                    <button class="btn-quitar-cliente" onclick="quitarCliente()">Quitar</button>
                </div>
            </div>
        </div>

        <!-- Panel derecho: resumen y pago -->
        <div class="panel-der">
            <div class="resumen">
                <h3>Resumen</h3>
                <div class="resumen-fila"><span>Subtotal</span><span id="resSubtotal">$0.00</span></div>
                <div class="resumen-fila"><span>Descuento</span><span id="resDescuento" style="color:#2e7d32;">-$0.00</span></div>
                <div class="resumen-fila"><span>Comisión terminal</span><span id="resComision">$0.00</span></div>
                <div class="resumen-fila total"><span>Total</span><span id="resTotal">$0.00</span></div>

                <div style="margin-top:14px;">
                    <div class="form-group-sm">
                        <label>Método de pago</label>
                        <div class="metodos">
                            <button type="button" class="metodo-btn" onclick="seleccionarMetodo('Efectivo',this)">Efectivo</button>
                            <button type="button" class="metodo-btn" onclick="seleccionarMetodo('Terminal',this)">Terminal</button>
                            <button type="button" class="metodo-btn" onclick="seleccionarMetodo('Mixto',this)">Mixto</button>
                            <button type="button" class="metodo-btn" id="btnCredito" onclick="seleccionarMetodo('Credito',this)" style="display:none;">Crédito</button>
                        </div>
                    </div>

                    <div class="campos-pago" id="camposEfectivo">
                        <div class="form-group-sm">
                            <label>Cantidad recibida</label>
                            <input type="number" id="montoEfectivo" placeholder="0.00" step="0.01" oninput="calcularCambio()">
                        </div>
                        <div class="resumen-fila cambio-fila"><span>Cambio</span><span id="resCambio">$0.00</span></div>
                    </div>

                    <div class="campos-pago" id="camposTerminal">
                        <div class="form-group-sm">
                            <label>Comisión terminal (%)</label>
                            <input type="number" id="porcComision" placeholder="0" step="0.1" value="0" oninput="recalcularTodo()">
                        </div>
                    </div>

                    <div class="campos-pago" id="camposMixto">
                        <div class="form-group-sm">
                            <label>Monto efectivo</label>
                            <input type="number" id="mixtoEfectivo" placeholder="0.00" step="0.01" oninput="calcularMixto()">
                        </div>
                        <div class="form-group-sm">
                            <label>Monto terminal</label>
                            <input type="number" id="mixtoTerminal" placeholder="0.00" step="0.01" oninput="calcularMixto()">
                        </div>
                        <div class="form-group-sm">
                            <label>Comisión terminal (%)</label>
                            <input type="number" id="mixtoComision" placeholder="0" step="0.1" value="0" oninput="calcularMixto()">
                        </div>
                    </div>
                </div>

                <form method="POST" id="formVenta">
                    <input type="hidden" name="confirmar_venta" value="1">
                    <input type="hidden" name="items" id="inputItems">
                    <input type="hidden" name="cliente_id" id="inputClienteId">
                    <input type="hidden" name="metodo_pago" id="inputMetodoPago">
                    <input type="hidden" name="monto_efectivo" id="inputMontoEfectivo">
                    <input type="hidden" name="monto_terminal" id="inputMontoTerminal">
                    <input type="hidden" name="comision_terminal" id="inputComisionTerminal">
                    <input type="hidden" name="descuento" id="inputDescuento">
                    <input type="hidden" name="subtotal" id="inputSubtotal">
                    <input type="hidden" name="total" id="inputTotal">
                    <input type="hidden" name="cambio" id="inputCambio">
                    <button type="submit" class="btn-cobrar" id="btnCobrar" disabled onclick="return prepararVenta()">
                        Cobrar
                    </button>
                </form>
                <button class="btn-cancelar-venta" onclick="limpiarVenta()">Cancelar venta</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal inventario -->
<div class="modal-overlay" id="modalInventario">
    <div class="modal">
        <div class="modal-header">
            <h3>Consultar inventario</h3>
            <button onclick="cerrarInventario()">&#x2715;</button>
        </div>
        <div class="modal-body">
            <div class="modal-filtros">
                <input type="text" id="invBuscar" placeholder="Buscar producto..." oninput="buscarInventario()">
                <select id="invSucursal" onchange="buscarInventario()">
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?= $s['sucursal_id'] ?>" <?= $s['sucursal_id']==$_SESSION['sucursal_id']?'selected':'' ?>>
                            <?= htmlspecialchars($s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="invResultados">
                <p style="text-align:center;color:#aaa;font-size:13px;padding:20px;">Busca un producto para ver el inventario.</p>
            </div>
        </div>
    </div>
</div>

<script>
// ── Estado global ────────────────────────────────────────────────────────────
let carrito           = [];
let clienteActual     = null;
let metodoPago        = null;
let paquetesGlobales  = [];
let productosGlobales = [];
let clientesGlobales  = [];
let modoScannerActivo = false;
let scannerTimer      = null;

const miSucursalId = <?= intval($_SESSION['sucursal_id']) ?>;
const datosTicket  = <?= json_encode([
    'nombre'       => $sucursalTicket['nombre'] ?? 'Ferretería Aldrete',
    'rfc'          => $sucursalTicket['rfc'] ?? '',
    'direccion'    => $sucursalTicket['direccion'] ?? '',
    'telefono'     => $sucursalTicket['telefono'] ?? '',
    'datos_ticket' => $sucursalTicket['datos_ticket'] ?? '',
]) ?>;
const cajeroNombre = <?= json_encode($_SESSION['nombre_completo']) ?>;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

// ── Normalizar texto: minúsculas + sin acentos (ñ→n, é→e, etc.) ─────────────
function normalizar(str) {
    return String(str || '').toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

// ── Cargar productos, paquetes y clientes en paralelo al iniciar ─────────────
Promise.all([
    fetch('nuevaVenta.php?get_productos_all=1').then(r => r.json()),
    fetch('nuevaVenta.php?get_paquetes=1').then(r => r.json()),
    fetch('nuevaVenta.php?get_clientes_all=1').then(r => r.json())
]).then(([prods, paqs, clis]) => {
    productosGlobales = prods.map(p => ({
        ...p,
        producto_id:  parseInt(p.producto_id),
        precio_venta: parseFloat(p.precio_venta),
        stock_actual: parseFloat(p.stock_actual)
    }));
    paquetesGlobales = paqs.map(paq => ({
        ...paq,
        paquete_id:     parseInt(paq.paquete_id),
        precio_paquete: parseFloat(paq.precio_paquete),
        productos: paq.productos.map(p => ({
            ...p,
            producto_id:        parseInt(p.producto_id),
            cantidad_requerida: parseFloat(p.cantidad_requerida),
            stock_actual:       parseFloat(p.stock_actual)
        }))
    }));
    clientesGlobales = clis.map(c => ({
        ...c,
        cliente_id:         parseInt(c.cliente_id),
        descuento_fijo:     parseFloat(c.descuento_fijo),
        credito_autorizado: parseInt(c.credito_autorizado)
    }));
}).catch(() => {});

// ── Modo scanner ─────────────────────────────────────────────────────────────
function toggleModoScanner() {
    modoScannerActivo = !modoScannerActivo;
    document.getElementById('modoNormal').style.display  = modoScannerActivo ? 'none' : 'block';
    document.getElementById('modoScanner').style.display = modoScannerActivo ? 'block' : 'none';
    document.getElementById('btnModoScanner').classList.toggle('activo', modoScannerActivo);
    if (modoScannerActivo) {
        document.getElementById('inputScanner').focus();
    }
}

// Scanner: detecta Enter (mayoría de lectores envían Enter al final)
document.getElementById('inputScanner').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const codigo = this.value.trim();
        if (!codigo) return;
        procesarScan(codigo);
        this.value = '';
    }
});

// Scanner: también detecta cuando deja de recibir input (lectura rápida del scanner)
document.getElementById('inputScanner').addEventListener('input', function() {
    clearTimeout(scannerTimer);
    const val = this.value.trim();
    if (!val) return;
    // Si el input recibe muchos caracteres muy rápido (scanner físico), procesar automáticamente
    scannerTimer = setTimeout(() => {
        if (document.getElementById('inputScanner').value.trim().length > 3) {
            procesarScan(document.getElementById('inputScanner').value.trim());
            document.getElementById('inputScanner').value = '';
        }
    }, 450);
});

function procesarScan(codigo) {
    const feedback = document.getElementById('scanFeedback');
    feedback.style.display = 'inline';
    feedback.className     = 'scan-feedback';
    feedback.textContent   = '⏳ Buscando...';

    fetch(`nuevaVenta.php?scan_codigo=${encodeURIComponent(codigo)}`)
        .then(r => r.json())
        .then(prod => {
            if (!prod) {
                feedback.className   = 'scan-feedback scan-err';
                feedback.textContent = `❌ No encontrado: ${codigo}`;
                setTimeout(() => { feedback.style.display = 'none'; }, 2000);
                return;
            }
            if (prod.stock_actual <= 0) {
                feedback.className   = 'scan-feedback scan-err';
                feedback.textContent = `❌ Sin stock: ${prod.nombre_producto}`;
                setTimeout(() => { feedback.style.display = 'none'; }, 2000);
                return;
            }
            agregarProducto(prod.producto_id, prod.nombre_producto, prod.precio_venta, prod.stock_actual, prod.tipo_venta);
            feedback.className   = 'scan-feedback scan-ok';
            feedback.textContent = `✅ ${prod.nombre_producto}`;
            setTimeout(() => { feedback.style.display = 'none'; }, 1500);
        })
        .catch(() => {
            feedback.className   = 'scan-feedback scan-err';
            feedback.textContent = '❌ Error de conexión';
            setTimeout(() => { feedback.style.display = 'none'; }, 2000);
        });
}

// ── Búsqueda local: filtra productosGlobales y paquetesGlobales en JS ────────
let toProd;
document.getElementById('inputProducto').addEventListener('input', function() {
    clearTimeout(toProd);
    const val = this.value.trim();
    if (!val) { document.getElementById('dropdownProductos').classList.remove('visible'); return; }

    // Sin debounce pesado: 60ms solo para no disparar en cada tecla de un paste
    toProd = setTimeout(() => {
        const q = normalizar(val);

        // Filtrar productos localmente
        const productos = productosGlobales
            .filter(p => normalizar(p.codigo).includes(q) || normalizar(p.nombre_producto).includes(q))
            .slice(0, 10);

        // Filtrar paquetes localmente y calcular disponibilidad
        const paquetes = paquetesGlobales
            .filter(paq => normalizar(paq.codigo).includes(q) || normalizar(paq.nombre).includes(q))
            .map(paq => {
                const disponible = paq.productos.every(pp => pp.stock_actual >= pp.cantidad_requerida);
                return {
                    ...paq,
                    disponible,
                    productos: paq.productos.map(pp => ({ ...pp, cantidad_req: pp.cantidad_requerida }))
                };
            });

        mostrarResultadosCombinados(productos, paquetes);
    }, 60);
});

function mostrarResultadosCombinados(productos, paquetes) {
    const drop = document.getElementById('dropdownProductos');
    let html   = '';

    paquetes.forEach(paq => {
        const cls   = paq.disponible ? 'stock-ok' : 'stock-bajo';
        const label = paq.disponible ? 'Disponible' : 'Stock insuf.';
        html += `<div class="resultado-item" style="background:#fffde7;"
            onclick="${paq.disponible
                ? `agregarPaquete(${JSON.stringify(paq).replace(/"/g,"'")})`
                : 'alert(\"Stock insuficiente\")'}" >
            <div>
                <div class="resultado-nombre">📦 ${esc(paq.nombre)}</div>
                <div class="resultado-codigo">${esc(paq.codigo)} · ${paq.productos.length} productos</div>
            </div>
            <div style="text-align:right;">
                <div class="resultado-precio">$${parseFloat(paq.precio_paquete).toFixed(2)}</div>
                <span class="stock-badge ${cls}">${label}</span>
            </div>
        </div>`;
    });

    productos.forEach(p => {
        const cls   = p.stock_actual > 0 ? 'stock-ok' : 'stock-bajo';
        const label = p.stock_actual > 0 ? `Stock: ${parseFloat(p.stock_actual).toFixed(p.tipo_venta==='Suelto'?3:0)}` : 'Sin stock';
        html += `<div class="resultado-item"
            onclick="agregarProducto(${p.producto_id},'${esc(p.nombre_producto)}',${p.precio_venta},${p.stock_actual},'${p.tipo_venta}')">
            <div>
                <div class="resultado-nombre">${esc(p.nombre_producto)}</div>
                <div class="resultado-codigo">${esc(p.codigo)}</div>
            </div>
            <div style="text-align:right;">
                <div class="resultado-precio">$${parseFloat(p.precio_venta).toFixed(2)}</div>
                <span class="stock-badge ${cls}">${label}</span>
            </div>
        </div>`;
    });

    if (!html) html = '<div style="padding:14px;text-align:center;color:#aaa;font-size:13px;">No se encontraron resultados</div>';
    drop.innerHTML = html;
    drop.classList.add('visible');
}

// ── Agregar producto ─────────────────────────────────────────────────────────
function agregarProducto(id, nombre, precio, stock, tipo) {
    id    = parseInt(id);
    stock = parseFloat(stock);
    if (stock <= 0) { alert('Sin stock disponible.'); return; }
    const existe = carrito.find(i => i.producto_id === id);
    if (existe) {
        if (existe.cantidad < stock) existe.cantidad++;
        else { alert(`Stock máximo: ${stock}`); return; }
    } else {
        carrito.push({ producto_id: id, nombre, precio: parseFloat(precio), cantidad: 1, stock, tipo });
    }
    document.getElementById('inputProducto').value = '';
    document.getElementById('dropdownProductos').classList.remove('visible');
    renderCarrito();
    recalcularTodo();
    verificarRecomendaciones();
}

// ── Agregar paquete ──────────────────────────────────────────────────────────
function agregarPaquete(paq) {
    if (typeof paq === 'string') {
        try { paq = JSON.parse(paq.replace(/'/g, '"')); } catch(e) { return; }
    }
    const ids = paq.productos.map(p => parseInt(p.producto_id));
    carrito   = carrito.filter(i => i.tipo === 'paquete' || !ids.includes(parseInt(i.producto_id)));
    carrito.push({
        producto_id:       null,
        paquete_id:        parseInt(paq.paquete_id),
        nombre:            '📦 ' + paq.nombre,
        precio:            parseFloat(paq.precio_paquete),
        cantidad:          1,
        stock:             99,
        tipo:              'paquete',
        productos_paquete: paq.productos.map(p => ({
            producto_id:        parseInt(p.producto_id),
            nombre_producto:    p.nombre_producto,
            cantidad_requerida: parseFloat(p.cantidad_requerida || p.cantidad_req || 1)
        }))
    });
    document.getElementById('inputProducto').value = '';
    document.getElementById('dropdownProductos').classList.remove('visible');
    renderCarrito();
    recalcularTodo();
    verificarRecomendaciones();
}

// ── Render carrito ───────────────────────────────────────────────────────────
function renderCarrito() {
    const body  = document.getElementById('carritoBody');
    const tabla = document.getElementById('carritoTabla');
    const vacio = document.getElementById('carritoVacio');

    if (!carrito.length) { tabla.style.display='none'; vacio.style.display='block'; return; }
    tabla.style.display = 'table';
    vacio.style.display = 'none';

    body.innerHTML = carrito.map((item, i) => {
        if (item.tipo === 'paquete') {
            const subNames = item.productos_paquete.map(p => `${p.nombre_producto}×${p.cantidad_requerida}`).join(', ');
            return `<tr class="paq-row">
                <td>
                    <strong>${esc(item.nombre)}</strong>
                    <div style="font-size:11px;color:#aaa;">${esc(subNames)}</div>
                </td>
                <td>$${item.precio.toFixed(2)}</td>
                <td><input class="qty-input" type="number" value="1" min="1" max="1" disabled style="width:55px;"></td>
                <td>—</td>
                <td>$${item.precio.toFixed(2)}</td>
                <td><button class="btn-eliminar-item" onclick="eliminarItem(${i})">Quitar</button></td>
            </tr>`;
        }
        return `<tr>
            <td>${esc(item.nombre)}</td>
            <td>$${parseFloat(item.precio).toFixed(2)}</td>
            <td>
                <input class="qty-input" type="number" value="${item.cantidad}"
                    min="1" step="1" max="${item.stock}"
                    onchange="cambiarCantidad(${i},this.value)">
            </td>
            <td><span class="stock-badge ${item.cantidad>=item.stock?'stock-bajo':'stock-ok'}">${item.stock}</span></td>
            <td>$${(item.cantidad*item.precio).toFixed(2)}</td>
            <td><button class="btn-eliminar-item" onclick="eliminarItem(${i})">Quitar</button></td>
        </tr>`;
    }).join('');
}

function cambiarCantidad(i, val) {
    const qty = parseInt(val);
    if (qty < 1) carrito[i].cantidad = 1;
    else if (qty > carrito[i].stock) { alert(`Stock máximo: ${carrito[i].stock}`); carrito[i].cantidad = carrito[i].stock; }
    else carrito[i].cantidad = qty;
    renderCarrito(); recalcularTodo();
}

function eliminarItem(i) {
    carrito.splice(i, 1);
    renderCarrito(); recalcularTodo();
    verificarRecomendaciones();
}

function limpiarVenta() {
    if (carrito.length > 0 && !confirm('¿Cancelar la venta?')) return;
    carrito = []; clienteActual = null; metodoPago = null;
    renderCarrito(); recalcularTodo(); quitarCliente();
    document.querySelectorAll('.metodo-btn').forEach(b => b.classList.remove('selected'));
    document.querySelectorAll('.campos-pago').forEach(c => c.classList.remove('visible'));
    document.getElementById('recPaquetes').style.display = 'none';
}

// ── Motor de recomendación de paquetes ───────────────────────────────────────
function verificarRecomendaciones() {
    const divRec  = document.getElementById('recPaquetes');
    const divList = document.getElementById('recLista');

    const enCarrito = {};
    carrito.forEach(item => {
        if (item.producto_id !== null && item.tipo !== 'paquete') {
            const pid = parseInt(item.producto_id);
            enCarrito[pid] = (enCarrito[pid] || 0) + parseFloat(item.cantidad);
        }
    });

    const recs = [];
    paquetesGlobales.forEach(paq => {
        if (carrito.some(i => i.paquete_id === parseInt(paq.paquete_id))) return;

        let cubiertos = 0;
        const faltantes = [];
        let hayAlguno   = false;

        paq.productos.forEach(prod => {
            const pid       = parseInt(prod.producto_id);
            const enCar     = enCarrito[pid] || 0;
            const necesaria = parseFloat(prod.cantidad_requerida);
            if (enCar > 0) hayAlguno = true;
            if (enCar >= necesaria) { cubiertos++; }
            else { faltantes.push({ nombre: prod.nombre_producto, falta: +(necesaria - enCar).toFixed(3) }); }
        });

        if (!hayAlguno) return;
        const total      = paq.productos.length;
        const porcentaje = Math.round((cubiertos / total) * 100);
        recs.push({ paq, cubiertos, total, faltantes, porcentaje });
    });

    if (!recs.length) { divRec.style.display = 'none'; return; }
    recs.sort((a, b) => b.porcentaje - a.porcentaje);

    divList.innerHTML = recs.map(rec => {
        const completo = rec.cubiertos === rec.total;
        const color    = completo ? '#2e7d32' : '#14ace7';
        const paqData  = JSON.stringify(rec.paq).replace(/"/g, '&quot;');
        return `<div class="rec-item">
            <div class="rec-header">
                <span class="rec-nombre">${esc(rec.paq.nombre)}</span>
                <span class="rec-precio">$${rec.paq.precio_paquete.toFixed(2)}</span>
            </div>
            <div class="rec-barra-bg">
                <div class="rec-barra" style="width:${rec.porcentaje}%;background:${color};"></div>
            </div>
            <div class="rec-desc">
                ${completo
                    ? `✅ Tienes todos los productos (${rec.total}/${rec.total})`
                    : `${rec.cubiertos}/${rec.total} productos · Falta: ${rec.faltantes.map(f=>f.nombre+' (×'+f.falta+')').join(', ')}`}
            </div>
            <button class="rec-btn" style="background:${color};" onclick='agregarPaquete(${paqData})'>
                ${completo ? '✅ Aplicar paquete al carrito' : `➕ Completar paquete (${rec.porcentaje}%)`}
            </button>
        </div>`;
    }).join('');

    divRec.style.display = 'block';
}

// ── Clientes (búsqueda local) ─────────────────────────────────────────────────
let toCli;
document.getElementById('inputCliente').addEventListener('input', function() {
    clearTimeout(toCli);
    const val = this.value.trim();
    if (!val) { document.getElementById('dropdownClientes').classList.remove('visible'); return; }
    toCli = setTimeout(() => {
        const q = normalizar(val);
        const resultados = clientesGlobales
            .filter(c => normalizar(c.nombre_completo).includes(q) ||
                         normalizar(c.telefono || '').includes(q))
            .slice(0, 8);
        mostrarClientes(resultados);
    }, 60);
});

function mostrarClientes(lista) {
    const drop = document.getElementById('dropdownClientes');
    if (!lista.length) {
        drop.innerHTML = '<div style="padding:14px;text-align:center;color:#aaa;font-size:13px;">Sin resultados</div>';
    } else {
        drop.innerHTML = lista.map(c => `
            <div class="resultado-item"
                onclick="seleccionarCliente(${c.cliente_id},'${esc(c.nombre_completo)}','${esc(c.telefono??'')}',${c.descuento_fijo},${c.credito_autorizado})">
                <div>
                    <div class="resultado-nombre">${esc(c.nombre_completo)}</div>
                    <div class="resultado-codigo">${esc(c.telefono??'')} ${c.descuento_fijo>0?'· Desc: '+c.descuento_fijo+'%':''}</div>
                </div>
            </div>`).join('');
    }
    drop.classList.add('visible');
}

function seleccionarCliente(id, nombre, telefono, descuento, credito) {
    clienteActual = { id, nombre, telefono, descuento: parseFloat(descuento), credito: parseInt(credito) };
    document.getElementById('inputCliente').value = '';
    document.getElementById('dropdownClientes').classList.remove('visible');
    document.getElementById('clienteNombre').textContent    = nombre;
    document.getElementById('clienteTelefono').textContent  = telefono;
    document.getElementById('clienteDescuento').textContent = descuento > 0 ? 'Desc: '+descuento+'%' : '';
    document.getElementById('clienteSeleccionado').style.display = 'flex';
    document.getElementById('inputClienteId').value = id;
    document.getElementById('btnCredito').style.display = credito ? 'block' : 'none';
    recalcularTodo();
}

function quitarCliente() {
    clienteActual = null;
    document.getElementById('clienteSeleccionado').style.display = 'none';
    document.getElementById('inputClienteId').value = '';
    document.getElementById('btnCredito').style.display = 'none';
    if (metodoPago === 'Credito') {
        metodoPago = null;
        document.querySelectorAll('.metodo-btn').forEach(b => b.classList.remove('selected'));
        document.querySelectorAll('.campos-pago').forEach(c => c.classList.remove('visible'));
    }
    recalcularTodo();
}

// ── Métodos de pago ──────────────────────────────────────────────────────────
function seleccionarMetodo(metodo, btn) {
    if (metodo === 'Credito' && !clienteActual) { alert('Selecciona un cliente para pago a crédito.'); return; }
    metodoPago = metodo;
    document.getElementById('inputMetodoPago').value = metodo;
    document.querySelectorAll('.metodo-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    document.querySelectorAll('.campos-pago').forEach(c => c.classList.remove('visible'));
    if (metodo==='Efectivo') document.getElementById('camposEfectivo').classList.add('visible');
    if (metodo==='Terminal') document.getElementById('camposTerminal').classList.add('visible');
    if (metodo==='Mixto')    document.getElementById('camposMixto').classList.add('visible');
    recalcularTodo(); verificarCobrar();
}

// ── Cálculos ─────────────────────────────────────────────────────────────────
function recalcularTodo() {
    let subtotal  = carrito.reduce((a,i) => a + (i.cantidad * i.precio), 0);
    let descuento = (clienteActual && clienteActual.descuento > 0) ? subtotal*(clienteActual.descuento/100) : 0;
    let comision  = 0;
    if (metodoPago === 'Terminal') {
        comision = (subtotal-descuento) * ((parseFloat(document.getElementById('porcComision').value)||0)/100);
    }
    const total = subtotal - descuento + comision;
    document.getElementById('resSubtotal').textContent  = '$'+subtotal.toFixed(2);
    document.getElementById('resDescuento').textContent = '-$'+descuento.toFixed(2);
    document.getElementById('resComision').textContent  = '$'+comision.toFixed(2);
    document.getElementById('resTotal').textContent     = '$'+total.toFixed(2);
    document.getElementById('inputSubtotal').value         = subtotal.toFixed(2);
    document.getElementById('inputDescuento').value        = descuento.toFixed(2);
    document.getElementById('inputComisionTerminal').value = comision.toFixed(2);
    document.getElementById('inputTotal').value            = total.toFixed(2);
    // Para pago Terminal: guardar el monto completo en monto_terminal (campo hidden)
    // Esto es necesario para que el corte de caja pueda sumar correctamente
    if (metodoPago === 'Terminal') {
        document.getElementById('inputMontoTerminal').value = total.toFixed(2);
        document.getElementById('inputMontoEfectivo').value = '0.00';
    }
    verificarCobrar();
}

function calcularCambio() {
    const total    = parseFloat(document.getElementById('inputTotal').value)||0;
    const recibido = parseFloat(document.getElementById('montoEfectivo').value)||0;
    const cambio   = Math.max(0, recibido-total);
    document.getElementById('resCambio').textContent     = '$'+cambio.toFixed(2);
    document.getElementById('inputMontoEfectivo').value  = recibido.toFixed(2);
    document.getElementById('inputCambio').value         = cambio.toFixed(2);
    verificarCobrar();
}

function calcularMixto() {
    const ef   = parseFloat(document.getElementById('mixtoEfectivo').value)||0;
    const term = parseFloat(document.getElementById('mixtoTerminal').value)||0;
    const porc = parseFloat(document.getElementById('mixtoComision').value)||0;
    const com  = term*(porc/100);
    const sub  = parseFloat(document.getElementById('inputSubtotal').value)||0;
    const desc = parseFloat(document.getElementById('inputDescuento').value)||0;
    const total= sub - desc + com;
    document.getElementById('resComision').textContent    = '$'+com.toFixed(2);
    document.getElementById('resTotal').textContent       = '$'+total.toFixed(2);
    document.getElementById('inputComisionTerminal').value = com.toFixed(2);
    document.getElementById('inputMontoEfectivo').value   = ef.toFixed(2);
    document.getElementById('inputMontoTerminal').value   = term.toFixed(2);
    document.getElementById('inputTotal').value           = total.toFixed(2);
    verificarCobrar();
}

function verificarCobrar() {
    document.getElementById('btnCobrar').disabled = !(carrito.length > 0 && metodoPago);
}

// ── Preparar y enviar venta ──────────────────────────────────────────────────
function prepararVenta() {
    const itemsExpandidos = [];
    carrito.forEach(item => {
        if (item.tipo === 'paquete') {
            const totalCant = item.productos_paquete.reduce((s,p) => s+p.cantidad_requerida, 0);
            item.productos_paquete.forEach(prod => {
                const precioProp = totalCant > 0 ? (item.precio * prod.cantidad_requerida) / totalCant : 0;
                itemsExpandidos.push({
                    producto_id: prod.producto_id,
                    cantidad:    prod.cantidad_requerida,
                    precio:      parseFloat((precioProp / prod.cantidad_requerida).toFixed(4))
                });
            });
        } else {
            itemsExpandidos.push({
                producto_id: item.producto_id,
                cantidad:    item.cantidad,
                precio:      item.precio
            });
        }
    });
    document.getElementById('inputItems').value = JSON.stringify(itemsExpandidos);
    return true;
}

// ── Ticket de impresión ──────────────────────────────────────────────────────
function imprimirTicket(ventaId) {
    if (!ventaId) return;
    fetch(`nuevaVenta.php?ticket_venta=${ventaId}`)
        .then(r => r.json())
        .then(venta => {
            if (!venta) { alert('No se pudo cargar el ticket.'); return; }
            generarTicketHTML(venta);
            setTimeout(() => window.print(), 300);
        });
}

function generarTicketHTML(venta) {
    const linea = '--------------------------------';
    // Usar fecha ya formateada en servidor (evita desfase de zona horaria en el navegador)
    const fecha = venta.fecha_formateada || venta.created_at;

    let html = `
        <div class="t-centro t-bold t-grande">${datosTicket.nombre}</div>`;

    if (datosTicket.datos_ticket) {
        html += `<div class="t-centro" style="white-space:pre-line;font-size:11px;">${esc(datosTicket.datos_ticket)}</div>`;
    } else {
        if (datosTicket.rfc)       html += `<div class="t-centro">RFC: ${esc(datosTicket.rfc)}</div>`;
        if (datosTicket.direccion) html += `<div class="t-centro">${esc(datosTicket.direccion)}</div>`;
        if (datosTicket.telefono)  html += `<div class="t-centro">Tel: ${esc(datosTicket.telefono)}</div>`;
    }

    html += `
        <div class="t-linea"></div>
        <div class="t-fila"><span>Folio:</span><span>${venta.folio ? parseInt(venta.folio.split('-')[0]) : ('#'+venta.venta_id)}</span></div>
        <div class="t-fila"><span>Fecha:</span><span>${fecha}</span></div>
        <div class="t-fila"><span>Cajero:</span><span>${esc(cajeroNombre)}</span></div>`;

    if (venta.cliente) {
        html += `<div class="t-fila"><span>Cliente:</span><span>${esc(venta.cliente)}</span></div>`;
    }

    html += `<div class="t-linea"></div>
        <div class="t-fila t-bold"><span>Producto</span><span>Importe</span></div>
        <div class="t-linea"></div>`;

    venta.productos.forEach(p => {
        html += `
            <div>${esc(p.nombre_producto)}</div>
            <div class="t-fila">
                <span>${p.cantidad} x $${parseFloat(p.precio_unitario).toFixed(2)}</span>
                <span>$${parseFloat(p.subtotal).toFixed(2)}</span>
            </div>`;
    });

    html += `<div class="t-linea"></div>`;

    if (parseFloat(venta.descuento) > 0) {
        html += `<div class="t-fila"><span>Subtotal</span><span>$${parseFloat(venta.subtotal).toFixed(2)}</span></div>
                 <div class="t-fila"><span>Descuento</span><span>-$${parseFloat(venta.descuento).toFixed(2)}</span></div>`;
    }
    if (parseFloat(venta.comision_terminal) > 0) {
        html += `<div class="t-fila"><span>Comisión terminal</span><span>$${parseFloat(venta.comision_terminal).toFixed(2)}</span></div>`;
    }

    html += `
        <div class="t-fila t-bold t-grande">
            <span>TOTAL</span><span>$${parseFloat(venta.total).toFixed(2)}</span>
        </div>
        <div class="t-linea"></div>
        <div class="t-fila"><span>Método de pago</span><span>${esc(venta.metodo_pago)}</span></div>`;

    if (venta.metodo_pago === 'Efectivo' && parseFloat(venta.cambio) > 0) {
        html += `
        <div class="t-fila"><span>Recibido</span><span>$${parseFloat(venta.monto_efectivo).toFixed(2)}</span></div>
        <div class="t-fila"><span>Cambio</span><span>$${parseFloat(venta.cambio).toFixed(2)}</span></div>`;
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

// ── Modal inventario ─────────────────────────────────────────────────────────
function abrirInventario() {
    document.getElementById('modalInventario').classList.add('visible');
    buscarInventario();
}
function cerrarInventario() {
    document.getElementById('modalInventario').classList.remove('visible');
}

let toInv;
function buscarInventario() {
    clearTimeout(toInv);
    toInv = setTimeout(() => {
        const buscar   = document.getElementById('invBuscar').value;
        const sucursal = document.getElementById('invSucursal').value;
        fetch(`nuevaVenta.php?inventario_sucursal=${sucursal}&buscar_inv=${encodeURIComponent(buscar)}`)
            .then(r => r.json()).then(renderInventario);
    }, 300);
}

function renderInventario(productos) {
    const div = document.getElementById('invResultados');
    if (!productos.length) {
        div.innerHTML = '<p style="text-align:center;color:#aaa;font-size:13px;padding:20px;">No se encontraron productos.</p>';
        return;
    }
    const sucursalSel = parseInt(document.getElementById('invSucursal').value);
    const esDif       = sucursalSel !== miSucursalId;

    div.innerHTML = `<table class="inv-tabla">
        <thead><tr><th>Código</th><th>Producto</th><th>Stock</th><th>Precio</th><th></th></tr></thead>
        <tbody>${productos.map(p => {
            const sinStock = p.stock_actual <= 0;
            return `<tr>
                <td style="color:#aaa;font-size:12px;">${esc(p.codigo)}</td>
                <td>${esc(p.nombre_producto)}${esDif?'<span class="sucursal-diferente">Otra sucursal</span>':''}</td>
                <td><span class="stock-badge ${sinStock?'stock-bajo':'stock-ok'}">${parseFloat(p.stock_actual).toFixed(p.tipo_venta==='Suelto'?3:0)}</span></td>
                <td>$${parseFloat(p.precio_venta).toFixed(2)}</td>
                <td>${!esDif && !sinStock
                    ? `<button class="btn-agregar-inv" onclick="agregarProducto(${p.producto_id},'${esc(p.nombre_producto)}',${p.precio_venta},${p.stock_actual},'${p.tipo_venta}');cerrarInventario()">Agregar</button>`
                    : `<button class="btn-agregar-inv" disabled>${esDif?'Otra suc.':'Sin stock'}</button>`
                }</td>
            </tr>`;
        }).join('')}</tbody>
    </table>`;
}

// ── Cerrar dropdowns ─────────────────────────────────────────────────────────
document.getElementById('modalInventario').addEventListener('click', function(e) {
    if (e.target === this) cerrarInventario();
});
document.addEventListener('click', function(e) {
    if (!e.target.closest('.busqueda-wrap') && !e.target.closest('.dropdown-resultados')) {
        document.querySelectorAll('.dropdown-resultados').forEach(d => d.classList.remove('visible'));
    }
});

// ── Auto-imprimir si viene de venta exitosa ──────────────────────────────────
<?php if (isset($_GET['msg']) && $_GET['msg'] === 'exito' && isset($_GET['venta_id'])): ?>
    window.addEventListener('load', () => {
        imprimirTicket(<?= intval($_GET['venta_id']) ?>);
    });
<?php endif; ?>

// ── Utilidad ─────────────────────────────────────────────────────────────────
function esc(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>
</html>
