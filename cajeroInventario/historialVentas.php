<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

// ── AJAX: detalle de venta + productos ──────────────────────────────────────
if (isset($_GET['detalle_venta'])) {
    $venta_id = intval($_GET['detalle_venta']);
    $stmtV = $pdo->prepare("
        SELECT v.*, c.nombre_completo AS cliente, c.telefono AS tel_cliente,
               u.nombre_completo AS cajero
        FROM ventas v
        JOIN cajas ca ON v.caja_id = ca.caja_id AND ca.sucursal_id = ?
        LEFT JOIN clientes c ON v.cliente_id = c.cliente_id
        LEFT JOIN usuarios u ON v.usuario_id = u.usuario_id
        WHERE v.venta_id = ?
    ");
    $stmtV->execute([$_SESSION['sucursal_id'], $venta_id]);
    $venta = $stmtV->fetch(PDO::FETCH_ASSOC);
    if ($venta) {
        $venta['fecha_formateada'] = date('d/m/Y H:i', strtotime($venta['created_at']));
        $stmtP = $pdo->prepare("
            SELECT vp.producto_id, vp.cantidad, vp.precio_unitario, vp.precio_final, vp.descuento, vp.subtotal, vp.nota_ajuste,
                   p.nombre_producto, p.codigo
            FROM venta_productos vp
            JOIN productos p ON vp.producto_id = p.producto_id
            WHERE vp.venta_id = ?
        ");
        $stmtP->execute([$venta_id]);
        $venta['productos'] = $stmtP->fetchAll(PDO::FETCH_ASSOC);

        // Cantidades devueltas activas (no canceladas) por producto
        $stmtDev = $pdo->prepare("
            SELECT mi.producto_id, SUM(mi.cantidad) AS cantidad_devuelta
            FROM movimientos_inventario mi
            JOIN devoluciones d ON mi.devolucion_id = d.devolucion_id
            WHERE d.venta_id = ? AND mi.tipo = 'Entrada' AND d.cancelada_en IS NULL
            GROUP BY mi.producto_id
        ");
        $stmtDev->execute([$venta_id]);
        $devueltos = [];
        foreach ($stmtDev->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $devueltos[$row['producto_id']] = floatval($row['cantidad_devuelta']);
        }
        foreach ($venta['productos'] as &$prod) {
            $prod['cantidad_devuelta'] = $devueltos[$prod['producto_id']] ?? 0;
        }
        unset($prod);

        // Descuento proporcional: descuento × (subtotal_actual / subtotal_original)
        $subtotalOriginal = array_sum(array_column($venta['productos'], 'subtotal'));
        $venta['descuento_display'] = ($subtotalOriginal > 0.001)
            ? round(floatval($venta['descuento']) * floatval($venta['subtotal']) / $subtotalOriginal, 2)
            : 0;
    }
    header('Content-Type: application/json');
    echo json_encode($venta);
    exit();
}

// ── Filtros ──────────────────────────────────────────────────────────────────
// Tomar valores crudos (pueden venir vacíos si el usuario borró el campo)
$fechaDesde = trim($_GET['desde'] ?? date('Y-m-d'));
$fechaHasta = trim($_GET['hasta'] ?? date('Y-m-d'));
$metodo     = $_GET['metodo'] ?? '';
$estado     = $_GET['estado'] ?? '';
$buscar     = trim($_GET['buscar'] ?? '');

// Validar que las fechas tengan formato correcto; si no, ignorarlas
$dDesde = $fechaDesde ? DateTime::createFromFormat('Y-m-d', $fechaDesde) : false;
$dHasta = $fechaHasta ? DateTime::createFromFormat('Y-m-d', $fechaHasta) : false;
if (!$dDesde) $fechaDesde = '';
if (!$dHasta) $fechaHasta = '';

$hayFiltroFecha = ($fechaDesde !== '' || $fechaHasta !== '');

$where  = "WHERE ca.sucursal_id = ?";
$params = [$_SESSION['sucursal_id']];

if ($fechaDesde !== '' && $fechaHasta !== '') {
    // Rango completo
    $where .= " AND DATE(v.created_at) BETWEEN ? AND ?";
    $params[] = $fechaDesde;
    $params[] = $fechaHasta;
} elseif ($fechaDesde !== '') {
    // Solo desde: todo a partir de esa fecha
    $where .= " AND DATE(v.created_at) >= ?";
    $params[] = $fechaDesde;
} elseif ($fechaHasta !== '') {
    // Solo hasta: todo anterior o igual a esa fecha
    $where .= " AND DATE(v.created_at) <= ?";
    $params[] = $fechaHasta;
}
if ($metodo) { $where .= " AND v.metodo_pago = ?"; $params[] = $metodo; }
if ($estado) { $where .= " AND v.estado = ?";       $params[] = $estado; }
if ($buscar) {
    $where .= " AND (v.folio LIKE ? OR c.nombre_completo LIKE ?)";
    $params[] = '%'.$buscar.'%';
    $params[] = '%'.$buscar.'%';
}

// Sin filtro de fecha ni búsqueda: mostrar las últimas 150 ventas recientes
$limit = (!$hayFiltroFecha && !$buscar) ? 'LIMIT 150' : 'LIMIT 1000';

$stmt = $pdo->prepare("
    SELECT v.*, c.nombre_completo AS cliente, u.nombre_completo AS cajero,
        ROUND(v.descuento * v.subtotal / NULLIF(
            (SELECT SUM(vp2.subtotal) FROM venta_productos vp2 WHERE vp2.venta_id = v.venta_id), 0
        ), 2) AS descuento_display
    FROM ventas v
    JOIN cajas ca ON v.caja_id = ca.caja_id
    LEFT JOIN clientes c ON v.cliente_id = c.cliente_id
    LEFT JOIN usuarios u ON v.usuario_id = u.usuario_id
    $where
    ORDER BY v.created_at DESC
    $limit
");
$stmt->execute($params);
$ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Totales del período ──────────────────────────────────────────────────────
$stmtTot = $pdo->prepare("
    SELECT
        COUNT(*) as total_ventas,
        COALESCE(SUM(CASE WHEN v.estado IN ('Completada','Modificado') THEN v.total ELSE 0 END),0) as total_cobrado,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Efectivo'  AND v.estado IN ('Completada','Modificado') THEN v.total ELSE 0 END),0) as ef,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Terminal'  AND v.estado IN ('Completada','Modificado') THEN v.total ELSE 0 END),0) as term,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Credito'   AND v.estado IN ('Completada','Modificado') THEN v.total ELSE 0 END),0) as cred,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Mixto'     AND v.estado IN ('Completada','Modificado') THEN v.total ELSE 0 END),0) as mixto,
        COUNT(CASE WHEN v.estado='Cancelada' THEN 1 END) as canceladas
    FROM ventas v
    JOIN cajas ca ON v.caja_id = ca.caja_id
    LEFT JOIN clientes c ON v.cliente_id = c.cliente_id
    $where
");
$stmtTot->execute($params);
$totales = $stmtTot->fetch(PDO::FETCH_ASSOC);

// ── Movimientos de caja del mismo período ────────────────────────────────────
$whereM  = "WHERE m.sucursal_id = ?";
$paramsM = [$_SESSION['sucursal_id']];
if ($fechaDesde !== '' && $fechaHasta !== '') {
    $whereM .= " AND DATE(m.created_at) BETWEEN ? AND ?";
    $paramsM[] = $fechaDesde; $paramsM[] = $fechaHasta;
} elseif ($fechaDesde !== '') {
    $whereM .= " AND DATE(m.created_at) >= ?";
    $paramsM[] = $fechaDesde;
} elseif ($fechaHasta !== '') {
    $whereM .= " AND DATE(m.created_at) <= ?";
    $paramsM[] = $fechaHasta;
}
$stmtMov = $pdo->prepare("
    SELECT m.movimiento_id, m.tipo, m.monto, m.nota, m.created_at,
           u.nombre_completo AS cajero
    FROM movimientos_caja m
    LEFT JOIN usuarios u ON m.usuario_id = u.usuario_id
    $whereM
    ORDER BY m.created_at DESC
    LIMIT 500
");
$stmtMov->execute($paramsM);
$movimientos = $stmtMov->fetchAll(PDO::FETCH_ASSOC);

// Separar: abonos de crédito vs movimientos regulares
// str_starts_with evita problemas de multibyte con regex sin /u
$esAbono = fn($m) => str_starts_with($m['nota'], 'Pago crédito') || str_starts_with($m['nota'], 'Abono de crédito');
$movAbonos    = array_values(array_filter($movimientos,  $esAbono));
$movRegulares = array_values(array_filter($movimientos, fn($m) => !$esAbono($m)));

// Totales regulares (retiros/ingresos manuales, sin abonos)
$totalIngresos = array_sum(array_column(array_filter($movRegulares, fn($m) => $m['tipo']==='Ingreso'), 'monto'));
$totalRetiros  = array_sum(array_column(array_filter($movRegulares, fn($m) => $m['tipo']==='Retiro'),  'monto'));

// Desglose de abonos de crédito por método
$totalAbonosEf    = array_sum(array_column(array_filter($movAbonos, fn($m) =>  str_ends_with($m['nota'], '[Efectivo]')),      'monto'));
$totalAbonosTerm  = array_sum(array_column(array_filter($movAbonos, fn($m) =>  str_ends_with($m['nota'], '[Terminal]')),      'monto'));
$totalAbonosTrans = array_sum(array_column(array_filter($movAbonos, fn($m) =>  str_ends_with($m['nota'], '[Transferencia]')), 'monto'));
$totalAbonosOtros = array_sum(array_column(array_filter($movAbonos, fn($m) =>
    !str_ends_with($m['nota'], '[Efectivo]') &&
    !str_ends_with($m['nota'], '[Terminal]') &&
    !str_ends_with($m['nota'], '[Transferencia]')
), 'monto'));
$totalAbonos = $totalAbonosEf + $totalAbonosTerm + $totalAbonosTrans + $totalAbonosOtros;

// ── Datos de sucursal para el ticket ────────────────────────────────────────
$stmtSuc = $pdo->prepare("SELECT * FROM sucursales WHERE sucursal_id = ?");
$stmtSuc->execute([$_SESSION['sucursal_id']]);
$sucursalTicket = $stmtSuc->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Ventas — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 20px 24px; overflow-y: auto; }
    .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .content-header h1 { font-size: 20px; color: #222; font-weight: 600; }

    /* Filtros */
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px 16px; margin-bottom: 14px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 4px; }
    .filtro-group label { font-size: 10px; color: #888; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
    .filtro-group input, .filtro-group select { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .filtro-group input[type=text] { width: 180px; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }

    /* Stats */
    .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap: 10px; margin-bottom: 14px; }
    .stat { background: white; border-radius: 8px; padding: 12px 14px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 10px; color: #999; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 0.4px; }
    .stat h3 { font-size: 17px; font-weight: 700; color: #222; margin: 0; }
    .stat.canceladas { border-top-color: #e74c3c; }
    .stat.canceladas h3 { color: #e74c3c; }

    /* Tabla */
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    .tabla-info { padding: 10px 14px; font-size: 12px; color: #aaa; border-bottom: 1px solid #f0f0f0; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; position: sticky; top: 0; }
    th { padding: 10px 14px; text-align: left; font-size: 11px; color: #888; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; white-space: nowrap; }
    td { padding: 10px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    tr.cancelada td { opacity: .65; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-efectivo  { background: #e8f5e9; color: #2e7d32; }
    .badge-terminal  { background: #e3f2fd; color: #1565c0; }
    .badge-mixto     { background: #f3e5f5; color: #6a1b9a; }
    .badge-credito   { background: #fff8e1; color: #f57f17; }
    .badge-completada { background: #e8f5e9; color: #2e7d32; }
    .badge-cancelada  { background: #fdecea; color: #c0392b; }
    .badge-pendiente  { background: #e3f2fd; color: #1565c0; }
    .badge-devuelto   { background: #f3e5f5; color: #6a1b9a; }
    .badge-modificado { background: #fff8e1; color: #e65100; }
    .sin-resultados { padding: 48px; text-align: center; color: #aaa; font-size: 14px; }
    .badge-ingreso { background: #e8f5e9; color: #2e7d32; }
    .badge-retiro  { background: #fdecea; color: #c0392b; }
    .mov-nota { font-size: 11px; color: #888; margin-top: 2px; max-width: 320px; word-break: break-word; }
    .seccion-mov { margin-top: 20px; }
    .seccion-mov-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
    .seccion-mov-header h2 { font-size: 15px; font-weight: 600; color: #333; }
    .seccion-mov-stats { display: flex; gap: 10px; }
    .mov-stat { background: white; border-radius: 8px; padding: 10px 16px; border: 0.5px solid #e8e8e8; font-size: 12px; }
    .mov-stat span { display: block; color: #999; font-size: 10px; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 2px; }
    .mov-stat.ing strong { color: #2e7d32; font-size: 15px; font-weight: 700; }
    .mov-stat.ret strong { color: #c0392b; font-size: 15px; font-weight: 700; }
    .sin-mov { padding: 32px; text-align: center; color: #bbb; font-size: 13px; }
    .abonos-resumen { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; border-top: 3px solid #2e7d32; padding: 14px 16px; margin-bottom: 10px; }
    .abonos-resumen-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .abonos-resumen-titulo { font-size: 13px; font-weight: 700; color: #2e7d32; text-transform: uppercase; letter-spacing: 0.5px; }
    .abonos-resumen-total { font-size: 16px; font-weight: 700; color: #2e7d32; }
    .abonos-stats { display: flex; gap: 8px; flex-wrap: wrap; }
    .abono-stat { background: #f9f9f9; border-radius: 6px; padding: 8px 14px; border: 0.5px solid #eee; min-width: 110px; }
    .abono-stat span { display: block; font-size: 10px; color: #999; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 3px; }
    .abono-stat strong { font-size: 14px; font-weight: 700; }
    .btn-accion { border: none; padding: 4px 10px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; }
    .btn-detalle { background: #e3f2fd; color: #1565c0; }
    .btn-detalle:hover { background: #bbdefb; }
    .btn-ticket { background: #e8f5e9; color: #2e7d32; }
    .btn-ticket:hover { background: #c8e6c9; }
    .acciones-td { display: flex; gap: 5px; }

    /* Modal detalle */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 300; align-items: center; justify-content: center; }
    .modal-overlay.visible { display: flex; }
    .modal { background: white; border-radius: 10px; width: 92%; max-width: 680px; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
    .modal-header { padding: 16px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: flex-start; flex-shrink: 0; }
    .modal-header-info h3 { font-size: 16px; font-weight: 700; color: #222; margin: 0 0 2px; }
    .modal-header-info p { font-size: 12px; color: #999; margin: 0; }
    .modal-close { background: none; border: none; font-size: 22px; cursor: pointer; color: #aaa; line-height: 1; padding: 0 0 0 10px; }
    .modal-close:hover { color: #333; }
    .modal-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
    .det-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 16px; }
    .det-campo { background: #f9f9f9; border-radius: 6px; padding: 8px 12px; }
    .det-campo span { display: block; font-size: 10px; color: #999; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 2px; }
    .det-campo strong { font-size: 13px; color: #333; }
    .det-titulo { font-size: 12px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 8px; }
    .det-tabla { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .det-tabla th { font-size: 11px; color: #888; text-transform: uppercase; padding: 7px 10px; text-align: left; border-bottom: 1px solid #eee; }
    .det-tabla td { padding: 8px 10px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    .det-tabla tr:last-child td { border-bottom: none; }
    .det-totales { border-top: 1px solid #eee; padding-top: 12px; }
    .det-fila { display: flex; justify-content: space-between; font-size: 13px; color: #555; margin-bottom: 5px; }
    .det-fila.total { font-size: 16px; font-weight: 700; color: #222; border-top: 1px solid #eee; margin-top: 8px; padding-top: 8px; }
    .modal-footer { padding: 12px 20px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 8px; flex-shrink: 0; }
    .btn-reimprimir { background: #14ace7; color: white; border: none; padding: 9px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
    .btn-reimprimir:hover { background: #1196cb; }
    .btn-cerrar-modal { background: white; color: #666; border: 1px solid #ddd; padding: 9px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; }

    /* Ticket impresión */
    @page {
        size: 80mm auto;
        margin: 0;
    }
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
        <a class="menu-item" href="nuevaVenta.php">Nueva venta</a>
        <a class="menu-item active" href="historialVentas.php">Historial de ventas</a>
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
            <h2>Historial de Ventas</h2>
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
        <div class="content-header">
            <h1>Todas las ventas</h1>
        </div>

        <!-- Filtros -->
        <form method="GET">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Desde</label>
                    <input type="date" name="desde" value="<?= htmlspecialchars($fechaDesde) ?>"
                        placeholder="dd/mm/aaaa">
                </div>
                <div class="filtro-group">
                    <label>Hasta</label>
                    <input type="date" name="hasta" value="<?= htmlspecialchars($fechaHasta) ?>"
                        placeholder="dd/mm/aaaa">
                </div>
                <div class="filtro-group">
                    <label>Método de pago</label>
                    <select name="metodo">
                        <option value="">Todos</option>
                        <option value="Efectivo" <?= $metodo==='Efectivo'?'selected':'' ?>>Efectivo</option>
                        <option value="Terminal" <?= $metodo==='Terminal'?'selected':'' ?>>Terminal</option>
                        <option value="Mixto"    <?= $metodo==='Mixto'   ?'selected':'' ?>>Mixto</option>
                        <option value="Credito"  <?= $metodo==='Credito' ?'selected':'' ?>>Crédito</option>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="">Todos</option>
                        <option value="Completada" <?= $estado==='Completada'?'selected':'' ?>>Completada</option>
                        <option value="Cancelada"  <?= $estado==='Cancelada' ?'selected':'' ?>>Cancelada</option>
                        <option value="Pendiente"  <?= $estado==='Pendiente' ?'selected':'' ?>>Pendiente</option>
                        <option value="Devuelto"   <?= $estado==='Devuelto'  ?'selected':'' ?>>Devuelto</option>
                        <option value="Modificado" <?= $estado==='Modificado'?'selected':'' ?>>Modificado</option>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Buscar</label>
                    <input type="text" name="buscar" placeholder="Folio o cliente..." value="<?= htmlspecialchars($buscar) ?>">
                </div>
                <button class="btn-filtrar" type="submit">Filtrar</button>
                <?php if ($metodo || $estado || $buscar || $fechaDesde || $fechaHasta): ?>
                    <a class="btn-limpiar" href="historialVentas.php">Limpiar</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Stats -->
        <div class="stats">
            <div class="stat">
                <p>Total ventas</p>
                <h3><?= $totales['total_ventas'] ?></h3>
            </div>
            <div class="stat">
                <p>Total cobrado</p>
                <h3>$<?= number_format($totales['total_cobrado'],2) ?></h3>
            </div>
            <div class="stat">
                <p>Efectivo</p>
                <h3>$<?= number_format($totales['ef'],2) ?></h3>
            </div>
            <div class="stat">
                <p>Terminal</p>
                <h3>$<?= number_format($totales['term'],2) ?></h3>
            </div>
            <div class="stat">
                <p>Crédito</p>
                <h3>$<?= number_format($totales['cred'],2) ?></h3>
            </div>
            <div class="stat">
                <p>Mixto</p>
                <h3>$<?= number_format($totales['mixto'],2) ?></h3>
            </div>
            <div class="stat canceladas">
                <p>Canceladas</p>
                <h3><?= $totales['canceladas'] ?></h3>
            </div>
        </div>

        <!-- Tabla -->
        <div class="tabla-wrapper">
            <div class="tabla-info">
                <?php if ($fechaDesde !== '' && $fechaHasta !== ''): ?>
                    Mostrando <?= count($ventas) ?> venta(s) del
                    <?= date('d/m/Y', strtotime($fechaDesde)) ?> al
                    <?= date('d/m/Y', strtotime($fechaHasta)) ?>
                <?php elseif ($fechaDesde !== ''): ?>
                    Mostrando <?= count($ventas) ?> venta(s) desde el <?= date('d/m/Y', strtotime($fechaDesde)) ?>
                <?php elseif ($fechaHasta !== ''): ?>
                    Mostrando <?= count($ventas) ?> venta(s) hasta el <?= date('d/m/Y', strtotime($fechaHasta)) ?>
                <?php else: ?>
                    Últimas <?= count($ventas) ?> ventas recientes<?= ($metodo || $estado || $buscar) ? ' con filtros aplicados' : '' ?>
                <?php endif; ?>
            </div>
            <?php if (count($ventas) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Cajero</th>
                        <th>Cliente</th>
                        <th>Método</th>
                        <th>Subtotal</th>
                        <th>Desc.</th>
                        <th>Total</th>
                        <th>Estado</th>
                        <th>Fecha/Hora</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ventas as $v): ?>
                    <tr class="<?= in_array($v['estado'], ['Cancelada','Devuelto']) ? 'cancelada' : '' ?>">
                        <td style="color:#aaa;font-size:12px;font-family:monospace;">
                            <?= htmlspecialchars($v['folio'] ?? ('#'.$v['venta_id'])) ?>
                        </td>
                        <td style="font-size:12px;">
                            <?= htmlspecialchars($v['cajero'] ?? '—') ?>
                        </td>
                        <td><?= htmlspecialchars($v['cliente'] ?? 'Público general') ?></td>
                        <!-- [AUTOFIX] H-03: htmlspecialchars en badges de metodo_pago y estado -->
                        <td>
                            <span class="badge badge-<?= htmlspecialchars(strtolower($v['metodo_pago'])) ?>">
                                <?= htmlspecialchars($v['metodo_pago']) ?>
                            </span>
                        </td>
                        <td>$<?= number_format($v['subtotal'],2) ?></td>
                        <td style="color:#2e7d32;">
                            <?php
                                $sinDesc = in_array($v['estado'], ['Devuelto','Cancelada']);
                                $descShow = floatval($v['descuento_display'] ?? $v['descuento']);
                                echo (!$sinDesc && $descShow > 0)
                                    ? '-$'.number_format($descShow,2)
                                    : '—';
                            ?>
                        </td>
                        <td style="font-weight:700;">$<?= number_format($v['total'],2) ?></td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars(strtolower($v['estado'])) ?>">
                                <?= htmlspecialchars($v['estado']) ?>
                            </span>
                        </td>
                        <td style="color:#aaa;font-size:12px;white-space:nowrap;">
                            <?= $v['created_at'] ? date('d/m/Y H:i', strtotime($v['created_at'])) : '—' ?>
                        </td>
                        <td>
                            <div class="acciones-td">
                                <button class="btn-accion btn-detalle"
                                    onclick="verDetalle(<?= $v['venta_id'] ?>)">
                                    Ver detalle
                                </button>
                                <button class="btn-accion btn-ticket"
                                    onclick="reimprimirTicket(<?= $v['venta_id'] ?>)">
                                    🖨 Ticket
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="sin-resultados">No hay ventas en el período seleccionado.</div>
            <?php endif; ?>
        </div>

        <!-- ── Movimientos de caja ─────────────────────────────────────────── -->
        <div class="seccion-mov">
            <div class="seccion-mov-header">
                <h2>Movimientos de caja</h2>
            </div>

            <!-- Desglose abonos de crédito -->
            <?php if ($totalAbonos > 0): ?>
            <div class="abonos-resumen">
                <div class="abonos-resumen-header">
                    <div class="abonos-resumen-titulo">Abonos de crédito</div>
                    <div class="abonos-resumen-total">+$<?= number_format($totalAbonos, 2) ?></div>
                </div>
                <div class="abonos-stats">
                    <?php if ($totalAbonosEf > 0): ?>
                    <div class="abono-stat">
                        <span>Efectivo</span>
                        <strong style="color:#2e7d32;">$<?= number_format($totalAbonosEf, 2) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($totalAbonosTerm > 0): ?>
                    <div class="abono-stat">
                        <span>Terminal</span>
                        <strong style="color:#1565c0;">$<?= number_format($totalAbonosTerm, 2) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($totalAbonosTrans > 0): ?>
                    <div class="abono-stat">
                        <span>Transferencia</span>
                        <strong style="color:#e65100;">$<?= number_format($totalAbonosTrans, 2) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tabla: abonos de crédito -->
            <?php if (count($movAbonos) > 0): ?>
            <div class="tabla-wrapper" style="margin-bottom:12px;">
                <div class="tabla-info" style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:11px;font-weight:700;color:#2e7d32;text-transform:uppercase;letter-spacing:.4px;">Abonos de crédito</span>
                    <span style="color:#ccc;">·</span>
                    <span><?= count($movAbonos) ?> movimiento(s)</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Método</th>
                            <th>Monto</th>
                            <th>Nota / Cliente</th>
                            <th>Cajero</th>
                            <th>Fecha/Hora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movAbonos as $m):
                            if      (str_ends_with($m['nota'], '[Efectivo]'))      { $metTag = 'Efectivo';      $metColor = '#2e7d32'; }
                            elseif  (str_ends_with($m['nota'], '[Terminal]'))      { $metTag = 'Terminal';      $metColor = '#1565c0'; }
                            elseif  (str_ends_with($m['nota'], '[Transferencia]')) { $metTag = 'Transferencia'; $metColor = '#e65100'; }
                            else                                                   { $metTag = '—';             $metColor = '#aaa'; }
                            $notaLimpia = rtrim(preg_replace('/\s*\[(Efectivo|Terminal|Transferencia)\]$/', '', $m['nota']));
                        ?>
                        <tr>
                            <td>
                                <span class="badge" style="background:<?= $metColor ?>22;color:<?= $metColor ?>;"><?= $metTag ?></span>
                            </td>
                            <td style="font-weight:700;color:#2e7d32;">+$<?= number_format($m['monto'], 2) ?></td>
                            <td>
                                <div class="mov-nota"><?= htmlspecialchars($notaLimpia) ?></div>
                            </td>
                            <td style="font-size:12px;"><?= htmlspecialchars($m['cajero'] ?? '—') ?></td>
                            <td style="color:#aaa;font-size:12px;white-space:nowrap;">
                                <?= date('d/m/Y H:i', strtotime($m['created_at'])) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Tabla: movimientos regulares (ingresos/retiros manuales) -->
            <div class="tabla-wrapper">
                <?php if (count($movRegulares) > 0): ?>
                <div class="tabla-info" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <span style="font-size:11px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px;">Retiros e ingresos manuales</span>
                    <div style="display:flex;gap:8px;">
                        <?php if ($totalIngresos > 0): ?>
                        <span style="background:#e8f5e9;color:#2e7d32;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;">Ingresos +$<?= number_format($totalIngresos,2) ?></span>
                        <?php endif; ?>
                        <?php if ($totalRetiros > 0): ?>
                        <span style="background:#fdecea;color:#c0392b;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;">Retiros -$<?= number_format($totalRetiros,2) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Monto</th>
                            <th>Nota / Motivo</th>
                            <th>Cajero</th>
                            <th>Fecha/Hora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movRegulares as $m): ?>
                        <tr>
                            <td>
                                <span class="badge badge-<?= strtolower($m['tipo']) ?>">
                                    <?= $m['tipo'] === 'Ingreso' ? '&#8593; Ingreso' : '&#8595; Retiro' ?>
                                </span>
                            </td>
                            <td style="font-weight:700;color:<?= $m['tipo']==='Ingreso' ? '#2e7d32' : '#c0392b' ?>;">
                                <?= $m['tipo']==='Ingreso' ? '+' : '-' ?>$<?= number_format($m['monto'], 2) ?>
                            </td>
                            <td>
                                <div class="mov-nota"><?= htmlspecialchars($m['nota']) ?></div>
                            </td>
                            <td style="font-size:12px;"><?= htmlspecialchars($m['cajero'] ?? '—') ?></td>
                            <td style="color:#aaa;font-size:12px;white-space:nowrap;">
                                <?= date('d/m/Y H:i', strtotime($m['created_at'])) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php elseif (count($movAbonos) === 0): ?>
                    <div class="sin-mov">No hay movimientos de caja en este período.</div>
                <?php else: ?>
                    <div class="sin-mov" style="padding:16px;">No hay retiros ni ingresos manuales en este período.</div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- Modal detalle de venta -->
<div class="modal-overlay" id="modalDetalle">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-info">
                <h3 id="detFolio">Detalle de venta</h3>
                <p id="detFecha"></p>
            </div>
            <button class="modal-close" onclick="cerrarDetalle()">&#x2715;</button>
        </div>
        <div class="modal-body">
            <!-- Metadatos -->
            <div class="det-meta" id="detMeta"></div>

            <!-- Productos -->
            <p class="det-titulo">Productos</p>
            <table class="det-tabla">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Producto</th>
                        <th style="text-align:right;">Cant.</th>
                        <th style="text-align:right;">P. Unit.</th>
                        <th style="text-align:right;">Subtotal</th>
                    </tr>
                </thead>
                <tbody id="detProductos"></tbody>
            </table>

            <!-- Totales -->
            <div class="det-totales" id="detTotales"></div>
        </div>
        <div class="modal-footer">
            <button class="btn-cerrar-modal" onclick="cerrarDetalle()">Cerrar</button>
            <button class="btn-reimprimir" id="btnReimprimir" onclick="reimprimirDesdeModal()">
                🖨 Reimprimir ticket
            </button>
        </div>
    </div>
</div>

<script>
// ── Config datos sucursal para ticket ────────────────────────────────────────
const datosTicket = <?= json_encode([
    'nombre'       => $sucursalTicket['nombre']       ?? 'Ferretería Aldrete',
    'rfc'          => $sucursalTicket['rfc']           ?? '',
    'direccion'    => $sucursalTicket['direccion']     ?? '',
    'telefono'     => $sucursalTicket['telefono']      ?? '',
    'datos_ticket' => $sucursalTicket['datos_ticket']  ?? '',
]) ?>;

let ventaActualId = null;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

// ── Ver detalle ──────────────────────────────────────────────────────────────
function verDetalle(ventaId) {
    ventaActualId = ventaId;
    fetch(`historialVentas.php?detalle_venta=${ventaId}`)
        .then(r => r.json())
        // [AUTOFIX] H-02: Verificar error del servidor antes de renderizar detalle
        .then(venta => {
            if (!venta || venta.error) { alert('No se pudo cargar el detalle de la venta.'); return; }
            renderDetalle(venta);
            document.getElementById('modalDetalle').classList.add('visible');
        })
        .catch(() => alert('Error de conexión al cargar el detalle.'));
}

function renderDetalle(v) {
    document.getElementById('detFolio').textContent = v.folio ? ('Folio: ' + v.folio) : ('Venta #' + v.venta_id);
    document.getElementById('detFecha').textContent = v.fecha_formateada || v.created_at;

    // Metadatos
    document.getElementById('detMeta').innerHTML = `
        <div class="det-campo"><span>Cajero</span><strong>${esc(v.cajero || '—')}</strong></div>
        <div class="det-campo"><span>Cliente</span><strong>${esc(v.cliente || 'Público general')}</strong></div>
        <div class="det-campo"><span>Método de pago</span><strong>${esc(v.metodo_pago)}</strong></div>
        <div class="det-campo"><span>Estado</span><strong>${esc(v.estado)}</strong></div>
        ${v.metodo_pago === 'Efectivo' ? `
            <div class="det-campo"><span>Recibido</span><strong>$${fmt(v.monto_efectivo)}</strong></div>
            <div class="det-campo"><span>Cambio</span><strong>$${fmt(v.cambio)}</strong></div>
        ` : ''}
        ${v.metodo_pago === 'Terminal' ? `
            <div class="det-campo"><span>Monto terminal</span><strong>$${fmt(v.monto_terminal)}</strong></div>
            <div class="det-campo"><span>Comisión</span><strong>$${fmt(v.comision_terminal)}</strong></div>
        ` : ''}
        ${v.metodo_pago === 'Mixto' ? `
            <div class="det-campo"><span>Efectivo</span><strong>$${fmt(v.monto_efectivo)}</strong></div>
            <div class="det-campo"><span>Terminal</span><strong>$${fmt(v.monto_terminal)}</strong></div>
            <div class="det-campo"><span>Comisión</span><strong>$${fmt(v.comision_terminal)}</strong></div>
            <div class="det-campo"><span>Cambio</span><strong>$${fmt(v.cambio)}</strong></div>
        ` : ''}
    `;

    // Productos
    document.getElementById('detProductos').innerHTML = v.productos.map(p => {
        const tieneAjuste  = p.nota_ajuste && p.nota_ajuste.trim() !== '';
        const precioOrig   = parseFloat(p.precio_unitario);
        const precioFinal  = parseFloat(p.precio_final || p.precio_unitario);
        const tienePromo   = !tieneAjuste && precioFinal < precioOrig - 0.001;
        const devuelta     = parseFloat(p.cantidad_devuelta || 0);
        const cantTotal    = parseFloat(p.cantidad);
        const todoDev      = devuelta >= cantTotal - 0.001;
        const parcialDev   = devuelta > 0.001 && !todoDev;
        const rowStyle     = todoDev ? 'opacity:0.55;' : '';
        const tdLineThru   = todoDev ? 'text-decoration:line-through;color:#aaa;' : '';

        let precioHTML;
        if (tieneAjuste) {
            precioHTML = `<span style="text-decoration:line-through;color:#aaa;font-size:11px;">$${fmt(precioOrig)}</span> <span style="color:#c0392b;font-weight:700;">$${fmt(precioFinal)}</span>`;
        } else if (tienePromo) {
            precioHTML = `<span style="text-decoration:line-through;color:#aaa;font-size:11px;">$${fmt(precioOrig)}</span> <span style="color:#2e7d32;font-weight:700;">$${fmt(precioFinal)}</span>`;
        } else {
            precioHTML = `$${fmt(precioOrig)}`;
        }

        return `
        <tr style="${rowStyle}">
            <td style="color:#aaa;font-size:12px;font-family:monospace;${tdLineThru}">${esc(p.codigo)}</td>
            <td>
                <span style="${tdLineThru}">${esc(p.nombre_producto)}</span>
                ${todoDev   ? '<span style="background:#fdecea;color:#c0392b;border-radius:99px;padding:1px 8px;font-size:10px;font-weight:700;margin-left:5px;">Devuelto</span>' : ''}
                ${parcialDev ? `<span style="background:#fff3e0;color:#e65100;border-radius:99px;padding:1px 8px;font-size:10px;font-weight:700;margin-left:5px;">Dev. parcial (${devuelta % 1 === 0 ? devuelta : devuelta.toFixed(2)})</span>` : ''}
                ${tieneAjuste ? `<div style="font-size:11px;color:#e65100;margin-top:2px;">⚠ Ajuste por daño: ${esc(p.nota_ajuste)}</div>` : ''}
                ${tienePromo  ? `<div style="font-size:11px;color:#2e7d32;margin-top:2px;">Precio de promoción</div>` : ''}
            </td>
            <td style="text-align:right;${tdLineThru}">${parseFloat(p.cantidad).toFixed(2)}</td>
            <td style="text-align:right;${todoDev?'text-decoration:line-through;color:#aaa;':''}">${precioHTML}</td>
            <td style="text-align:right;font-weight:600;${tdLineThru}">$${fmt(p.subtotal)}</td>
        </tr>`;
    }).join('') || '<tr><td colspan="5" style="text-align:center;color:#aaa;padding:16px;">Sin productos registrados</td></tr>';

    // Totales
    let html = '';
    if (parseFloat(v.descuento_display) > 0) {
        html += `<div class="det-fila"><span>Subtotal</span><span>$${fmt(v.subtotal)}</span></div>`;
        html += `<div class="det-fila" style="color:#2e7d32;"><span>Ahorraste</span><span>-$${fmt(v.descuento_display)}</span></div>`;
    }
    if (parseFloat(v.comision_terminal) > 0) {
        html += `<div class="det-fila"><span>Comisión terminal</span><span>$${fmt(v.comision_terminal)}</span></div>`;
    }
    html += `<div class="det-fila total"><span>TOTAL</span><span>$${fmt(v.total)}</span></div>`;
    document.getElementById('detTotales').innerHTML = html;
}

function cerrarDetalle() {
    document.getElementById('modalDetalle').classList.remove('visible');
    ventaActualId = null;
}

// Cerrar modal al click en el overlay
document.getElementById('modalDetalle').addEventListener('click', function(e) {
    if (e.target === this) cerrarDetalle();
});

// ── Ticket ───────────────────────────────────────────────────────────────────
function reimprimirTicket(ventaId) {
    fetch(`historialVentas.php?detalle_venta=${ventaId}`)
        .then(r => r.json())
        // [AUTOFIX] H-01: Verificar error del servidor antes de generar ticket
        .then(venta => {
            if (!venta || venta.error) { alert('No se pudo cargar el ticket.'); return; }
            generarTicketHTML(venta);
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
        })
        .catch(() => alert('Error de conexión.'));
}

function reimprimirDesdeModal() {
    if (!ventaActualId) return;
    reimprimirTicket(ventaActualId);
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
        const devuelta    = parseFloat(p.cantidad_devuelta || 0);
        const cantTotal   = parseFloat(p.cantidad);
        const todoDev     = devuelta >= cantTotal - 0.001;
        const parcialDev  = devuelta > 0.001 && !todoDev;
        const ltStyle     = todoDev ? 'text-decoration:line-through;color:#aaa;' : '';

        if (tienePromo) ahorroPromoTicket += (precioOrig - precioFinal) * parseFloat(p.cantidad);

        html += `<div style="${ltStyle}">${esc(p.nombre_producto)}${todoDev ? ' [DEVUELTO]' : ''}${parcialDev ? ` [Dev. ${devuelta % 1 === 0 ? devuelta : devuelta.toFixed(2)}]` : ''}</div>`;
        if (tienePromo) {
            html += `<div style="font-size:10px;text-decoration:line-through;color:#888;">$${fmt(precioOrig)}/u (precio normal)</div>`;
            html += `<div class="t-fila" style="${ltStyle}"><span>${parseFloat(p.cantidad).toFixed(2)} x $${fmt(precioFinal)}</span><span>$${fmt(p.subtotal)}</span></div>`;
        } else {
            const precioUsado = tieneAjuste ? precioFinal : precioOrig;
            html += `<div class="t-fila" style="${ltStyle}"><span>${parseFloat(p.cantidad).toFixed(2)} x $${fmt(precioUsado)}${tieneAjuste ? ' *' : ''}</span><span>$${fmt(p.subtotal)}</span></div>`;
            if (tieneAjuste) html += `<div style="font-size:10px;color:#666;">* Ajuste daño: ${esc(p.nota_ajuste)}</div>`;
        }
    });

    html += `<div class="t-linea"></div>`;

    if (parseFloat(venta.descuento_display) > 0) {
        html += `<div class="t-fila"><span>Subtotal</span><span>$${fmt(venta.subtotal)}</span></div>`;
    }
    if (parseFloat(venta.comision_terminal) > 0) {
        html += `<div class="t-fila"><span>Comisión terminal</span><span>$${fmt(venta.comision_terminal)}</span></div>`;
    }
    if (parseFloat(venta.descuento_display) > 0) {
        html += `<div class="t-fila" style="font-size:11px;"><span>Ahorraste</span><span>-$${fmt(venta.descuento_display)}</span></div>`;
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

// ── Utilidades ───────────────────────────────────────────────────────────────
function fmt(n) { return parseFloat(n||0).toFixed(2); }
function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

</script>
<script src="../includes/auto_filter.js"></script>
</body>
</html>
