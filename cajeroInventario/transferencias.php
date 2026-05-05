<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

// Acciones sobre transferencia existente
$_accionData = $_POST + $_GET;
if (isset($_accionData['accion']) && (isset($_accionData['id']) || isset($_GET['id']))) {
    $id         = intval($_accionData['id'] ?? $_GET['id'] ?? 0);
    $accion     = $_accionData['accion'];
    $miSucursal = $_SESSION['sucursal_id'];

    if ($accion === 'editar_cantidad') {
        $nuevaCantidad = floatval($_POST['nueva_cantidad'] ?? 0);
        if ($nuevaCantidad > 0) {
            // Validar tipo_venta y obtener stock actual del origen en un solo query
            $stmtTV = $pdo->prepare("
                SELECT p.tipo_venta, ss.stock_actual AS stock_origen
                FROM transferencias t
                JOIN productos p ON t.producto_id = p.producto_id
                LEFT JOIN stock_sucursal ss ON ss.producto_id = t.producto_id AND ss.sucursal_id = t.sucursal_origen_id
                WHERE t.transferencias_id = ?
            ");
            $stmtTV->execute([$id]);
            $tvRow = $stmtTV->fetch(PDO::FETCH_ASSOC);
            if ($tvRow && $tvRow['tipo_venta'] !== 'Suelto') {
                $nuevaCantidad = floor($nuevaCantidad);
            }
            if ($nuevaCantidad <= 0) { header('Location: transferencias.php?msg=cantidad_editada'); exit(); }
            // Validar que la nueva cantidad no supere el stock disponible en origen
            if ($tvRow && $tvRow['stock_origen'] !== null && $nuevaCantidad > floatval($tvRow['stock_origen'])) {
                header('Location: transferencias.php?msg=error_stock_editar'); exit();
            }

            // Obtener cantidad anterior para la nota
            $stmtOld = $pdo->prepare("SELECT cantidad FROM transferencias WHERE transferencias_id = ? AND estado IN ('Pendiente','Aprobada') AND sucursal_origen_id = ?");
            $stmtOld->execute([$id, $miSucursal]);
            $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                $notaEdicion = 'Cantidad modificada por origen de ' . number_format($old['cantidad'], 2) . ' a ' . number_format($nuevaCantidad, 2) . ' el ' . date('d/m/Y H:i') . ' por ' . $_SESSION['nombre_completo'] . '. Pendiente de confirmacion por destino.';
                $pdo->prepare("UPDATE transferencias SET cantidad = ?, estado = 'Modificada', notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?)) WHERE transferencias_id = ? AND estado IN ('Pendiente','Aprobada') AND sucursal_origen_id = ?")
                    ->execute([$nuevaCantidad, $notaEdicion, $id, $miSucursal]);
            }
        }
        header('Location: transferencias.php?msg=cantidad_editada'); exit();

    } elseif ($accion === 'aceptar_modificacion') {
        $pdo->prepare("UPDATE transferencias SET estado = 'Aprobada' WHERE transferencias_id = ? AND estado = 'Modificada' AND sucursal_destino_id = ?")
            ->execute([$id, $miSucursal]);
        header('Location: transferencias.php?msg=aceptar_modificacion'); exit();

    } elseif ($accion === 'rechazar_modificacion') {
        $notaRechazo = 'Modificacion de cantidad rechazada por destino el ' . date('d/m/Y H:i') . ' por ' . $_SESSION['nombre_completo'] . '.';
        $pdo->prepare("UPDATE transferencias SET estado = 'Pendiente', notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?)) WHERE transferencias_id = ? AND estado = 'Modificada' AND sucursal_destino_id = ?")
            ->execute([$notaRechazo, $id, $miSucursal]);
        header('Location: transferencias.php?msg=rechazar_modificacion'); exit();

    } elseif ($accion === 'aprobar') {
        $notaAprobacion = 'Aceptada el ' . date('d/m/Y H:i') . ' por ' . $_SESSION['nombre_completo'] . '. Preparar envio a sucursal destino.';
        $pdo->prepare("
            UPDATE transferencias
            SET estado='Aprobada',
                usuario_aprueba_id=?,
                notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?))
            WHERE transferencias_id=? AND estado='Pendiente' AND sucursal_origen_id=?
        ")->execute([$_SESSION['usuario_id'], $notaAprobacion, $id, $miSucursal]);
    } elseif ($accion === 'rechazar') {
        $pdo->prepare("UPDATE transferencias SET estado='Rechazada', usuario_aprueba_id=? WHERE transferencias_id=? AND estado='Pendiente' AND sucursal_origen_id=?")
            ->execute([$_SESSION['usuario_id'], $id, $miSucursal]);
    } elseif ($accion === 'enviar') {
        $pdo->prepare("UPDATE transferencias SET estado='En tránsito' WHERE transferencias_id=? AND estado='Aprobada' AND sucursal_origen_id=?")
            ->execute([$id, $miSucursal]);
    } elseif ($accion === 'recibir') {
        $stmt = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ?");
        $stmt->execute([$id]);
        $transf = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($transf && $transf['estado'] === 'En tránsito' && $transf['sucursal_destino_id'] == $miSucursal) {
            $stmtOrigen = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
            $stmtOrigen->execute([$transf['producto_id'], $transf['sucursal_origen_id']]);
            $prodOrigen = $stmtOrigen->fetch(PDO::FETCH_ASSOC);

            if ($prodOrigen && $prodOrigen['stock_actual'] >= $transf['cantidad']) {
                // Descontar stock del origen
                $stockAntOrigen   = $prodOrigen['stock_actual'];
                $stockNuevoOrigen = $stockAntOrigen - $transf['cantidad'];
                $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                    ->execute([$stockNuevoOrigen, $transf['producto_id'], $transf['sucursal_origen_id']]);
                $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,'Transferencia',?,?,?,'Transferencia enviada')")
                    ->execute([$transf['producto_id'], $_SESSION['usuario_id'], $transf['cantidad'], $stockAntOrigen, $stockNuevoOrigen]);

                // Sumar stock al destino — mismo producto_id (catálogo compartido)
                $stmtDest = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
                $stmtDest->execute([$transf['producto_id'], $transf['sucursal_destino_id']]);
                $prodDest = $stmtDest->fetch(PDO::FETCH_ASSOC);

                if ($prodDest) {
                    $stockAntDest   = $prodDest['stock_actual'];
                    $stockNuevoDest = $stockAntDest + $transf['cantidad'];
                    $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                        ->execute([$stockNuevoDest, $transf['producto_id'], $transf['sucursal_destino_id']]);
                } else {
                    // Primera vez que este producto llega a la sucursal destino
                    $stockNuevoDest = $transf['cantidad'];
                    $pdo->prepare("INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo) VALUES (?,?,?,0,0,1)")
                        ->execute([$transf['producto_id'], $transf['sucursal_destino_id'], $stockNuevoDest]);
                    $stockAntDest = 0;
                }
                $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,'Transferencia',?,?,?,'Transferencia recibida')")
                    ->execute([$transf['producto_id'], $_SESSION['usuario_id'], $transf['cantidad'], $stockAntDest, $stockNuevoDest]);

                $pdo->prepare("UPDATE transferencias SET estado='Entregada', usuario_aprueba_id=? WHERE transferencias_id=?")
                    ->execute([$_SESSION['usuario_id'], $id]);
            }
        }
    }
    header('Location: transferencias.php?msg='.$accion); exit();
}

// Nueva solicitud — multi-producto, YO soy el DESTINO (quien pide), ORIGEN = otra sucursal
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sucursal_origen_id = intval($_POST['sucursal_origen_id'] ?? 0);
    $notas              = trim($_POST['notas'] ?? '');
    $items              = json_decode($_POST['items_transf'] ?? '[]', true);

    if (!$sucursal_origen_id)                              $errores[] = 'Selecciona la sucursal de origen.';
    if ($sucursal_origen_id == $_SESSION['sucursal_id'])   $errores[] = 'La sucursal origen no puede ser la misma que la tuya.';
    if (empty($items))                                     $errores[] = 'Agrega al menos un producto.';

    if (empty($errores)) {
        foreach ($items as $item) {
            $prodId   = intval($item['id']);
            $cantidad = floatval($item['cantidad']);
            // Validar tipo_venta en backend
            $stmtTV2 = $pdo->prepare("SELECT p.tipo_venta, p.nombre_producto, ss.stock_actual FROM productos p LEFT JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ? WHERE p.producto_id = ?");
            $stmtTV2->execute([$sucursal_origen_id, $prodId]);
            $tvRow2 = $stmtTV2->fetch(PDO::FETCH_ASSOC);
            if ($tvRow2 && $tvRow2['tipo_venta'] !== 'Suelto') {
                $cantidad = floor($cantidad);
            }
            if ($cantidad <= 0) continue;
            // Validar que la cantidad no supere el stock real en la sucursal origen
            $stockOrigen = $tvRow2 ? floatval($tvRow2['stock_actual']) : 0;
            if ($cantidad > $stockOrigen) {
                $nombreProd  = $tvRow2['nombre_producto'] ?? "Producto #$prodId";
                $errores[] = "\"$nombreProd\": se solicitaron " . number_format($cantidad, 2) . " pero la sucursal origen solo tiene " . number_format($stockOrigen, 2) . " disponibles.";
            }
        }
    }

    if (empty($errores)) {
        foreach ($items as $item) {
            $prodId   = intval($item['id']);
            $cantidad = floatval($item['cantidad']);
            $stmtTV3  = $pdo->prepare("SELECT tipo_venta FROM productos WHERE producto_id = ?");
            $stmtTV3->execute([$prodId]);
            $tvRow3 = $stmtTV3->fetch(PDO::FETCH_ASSOC);
            if ($tvRow3 && $tvRow3['tipo_venta'] !== 'Suelto') { $cantidad = floor($cantidad); }
            if ($cantidad <= 0) continue;
            $pdo->prepare("INSERT INTO transferencias (producto_id, sucursal_origen_id, sucursal_destino_id, usuario_solicita_id, cantidad, notas, estado) VALUES (?,?,?,?,?,?,'Pendiente')")
                ->execute([$prodId, $sucursal_origen_id, $_SESSION['sucursal_id'], $_SESSION['usuario_id'], $cantidad, $notas]);
        }
        header('Location: transferencias.php?msg=solicitado'); exit();
    }
}

// Filtro de fechas para el historial
$filtroDesde = $_GET['desde'] ?? date('Y-m-01');
$filtroHasta = $_GET['hasta'] ?? date('Y-m-d');
$filtroEstado = trim($_GET['estado_f'] ?? '');

$whereExtra = '';
$paramsExtra = [];
if ($filtroDesde) { $whereExtra .= ' AND DATE(t.created_at) >= ?'; $paramsExtra[] = $filtroDesde; }
if ($filtroHasta) { $whereExtra .= ' AND DATE(t.created_at) <= ?'; $paramsExtra[] = $filtroHasta; }
if ($filtroEstado) { $whereExtra .= ' AND t.estado = ?'; $paramsExtra[] = $filtroEstado; }

// Transferencias de esta sucursal (como origen o destino)
$stmt = $pdo->prepare("
    SELECT t.*,
        p.nombre_producto, p.codigo, p.tipo_venta,
        so.nombre AS sucursal_origen,
        sd.nombre AS sucursal_destino,
        us.nombre_completo AS solicitante,
        ua.nombre_completo AS aprobador,
        (SELECT ss.stock_actual FROM stock_sucursal ss WHERE ss.producto_id = t.producto_id AND ss.sucursal_id = t.sucursal_origen_id LIMIT 1) AS stock_origen
    FROM transferencias t
    JOIN productos p ON t.producto_id = p.producto_id
    JOIN sucursales so ON t.sucursal_origen_id = so.sucursal_id
    JOIN sucursales sd ON t.sucursal_destino_id = sd.sucursal_id
    JOIN usuarios us ON t.usuario_solicita_id = us.usuario_id
    LEFT JOIN usuarios ua ON t.usuario_aprueba_id = ua.usuario_id
    WHERE (t.sucursal_origen_id = ? OR t.sucursal_destino_id = ?)
    $whereExtra
    ORDER BY t.created_at DESC
    LIMIT 100
");
$stmt->execute(array_merge([$_SESSION['sucursal_id'], $_SESSION['sucursal_id']], $paramsExtra));
$transferencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sucursales origen disponibles
$stmt = $pdo->prepare("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1 AND sucursal_id != ?");
$stmt->execute([$_SESSION['sucursal_id']]);
$sucursalesOrigen = $stmt->fetchAll(PDO::FETCH_ASSOC);

// INNER JOIN por codigo: solo productos que existen en AMBAS sucursales
// "bajo" = mi sucursal tiene ese producto con stock < minimo (y minimo > 0)
$stmt = $pdo->prepare("
    SELECT
        p.producto_id,
        p.codigo,
        p.nombre_producto,
        p.tipo_venta,
        ss_origen.sucursal_id,
        ss_origen.stock_actual,
        ss_mia.stock_actual AS mi_stock,
        (ss_mia.stock_actual < ss_mia.stock_minimo AND ss_mia.stock_minimo > 0) AS bajo
    FROM productos p
    INNER JOIN stock_sucursal ss_origen ON ss_origen.producto_id = p.producto_id
        AND ss_origen.sucursal_id != ? AND ss_origen.activo = 1 AND ss_origen.stock_actual > 0
    INNER JOIN stock_sucursal ss_mia ON ss_mia.producto_id = p.producto_id
        AND ss_mia.sucursal_id = ?
    WHERE p.activo = 1
    ORDER BY bajo DESC, p.nombre_producto ASC
");
$stmt->execute([$_SESSION['sucursal_id'], $_SESSION['sucursal_id']]);

$prodsBySucursal = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $prodsBySucursal[$p['sucursal_id']][] = [
        'id'         => intval($p['producto_id']),
        'codigo'     => $p['codigo'],
        'nombre'     => $p['nombre_producto'],
        'stock'      => floatval($p['stock_actual']),
        'mi_stock'   => floatval($p['mi_stock']),
        'bajo'       => (bool)$p['bajo'],
        'tipo_venta' => $p['tipo_venta'],
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transferencias — Ferretería Aldrete</title>
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
    .menu-label { padding: 8px 16px 4px; font-size: 10px; font-weight: 700; color: #14ace7; text-transform: uppercase; letter-spacing: 0.5px; }
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
    .content { flex: 1; padding: 20px; overflow-y: auto; display: grid; grid-template-columns: 1fr 370px; gap: 16px; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 18px; margin-bottom: 14px; }
    .card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 14px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 10px 12px; text-align: left; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 10px 12px; font-size: 12px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-pendiente  { background: #e3f2fd; color: #1565c0; }
    .badge-aprobada   { background: #e8f5e9; color: #2e7d32; }
    .badge-rechazada  { background: #fdecea; color: #c0392b; }
    .badge-transito   { background: #fff8e1; color: #e65100; }
    .badge-entregada  { background: #f0f0f0; color: #666; }
    .badge-modificada { background: #e8eaf6; color: #283593; }
    .btn-aceptar-mod  { background: #e8f5e9; color: #2e7d32; }
    .btn-rechazar-mod { background: #fdecea; color: #c0392b; }
    .direction-badge { font-size: 10px; padding: 2px 6px; border-radius: 99px; font-weight: 600; }
    .dir-enviada { background: #fdecea; color: #c0392b; }
    .dir-recibida { background: #e8f5e9; color: #2e7d32; }
    .acciones { display: flex; gap: 5px; flex-wrap: wrap; }
    .btn-accion { padding: 4px 10px; border-radius: 5px; font-size: 11px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-aprobar { background: #e8f5e9; color: #2e7d32; }
    .btn-rechazar { background: #fdecea; color: #c0392b; }
    .btn-enviar   { background: #e3f2fd; color: #1565c0; }
    .btn-recibir  { background: #f3e5f5; color: #6a1b9a; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    .form-group { margin-bottom: 13px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 600; }
    .form-group select, .form-group input, .form-group textarea { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group select:focus, .form-group input:focus { outline: none; border-color: #14ace7; }
    .search-wrap { position: relative; }
    .search-input { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .search-input:focus { outline: none; border-color: #14ace7; }
    .sugerencias { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ddd; border-top: none; border-radius: 0 0 6px 6px; z-index: 100; max-height: 220px; overflow-y: auto; display: none; box-shadow: 0 4px 12px rgba(0,0,0,.08); }
    .sug-item { padding: 9px 12px; cursor: pointer; font-size: 13px; display: flex; justify-content: space-between; align-items: center; border-bottom: 0.5px solid #f5f5f5; }
    .sug-item:last-child { border-bottom: none; }
    .sug-item:hover { background: #eef8ff; }
    .sug-nombre { color: #333; font-weight: 500; }
    .sug-codigo { font-size: 11px; color: #aaa; margin-left: 6px; }
    .sug-stocks { display: flex; flex-direction: column; align-items: flex-end; gap: 1px; white-space: nowrap; margin-left: 8px; }
    .sug-stock-row { font-size: 11px; }
    .sug-stock-orig { color: #1565c0; }
    .sug-stock-dest { color: #2e7d32; }
    .badge-bajo { background: #fdecea; color: #c0392b; font-size: 10px; padding: 1px 6px; border-radius: 99px; font-weight: 700; margin-left: 6px; }
    .prod-seleccionado { background: #eef8ff; border: 1px solid #bbdefb; border-radius: 6px; padding: 10px 12px; margin-bottom: 10px; display: none; }
    .prod-sel-nombre { font-size: 13px; font-weight: 600; color: #1565c0; }
    .prod-sel-stock { font-size: 12px; color: #555; margin-top: 2px; }
    .cant-row { display: flex; gap: 8px; margin-top: 8px; }
    .cant-row input { flex: 1; padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .cant-row input:focus { outline: none; border-color: #14ace7; }
    .btn-add { background: #14ace7; color: white; border: none; padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
    .lista-items { border: 0.5px solid #eee; border-radius: 6px; min-height: 46px; overflow: hidden; margin-bottom: 13px; }
    .transf-item { display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; }
    .transf-item:last-child { border-bottom: none; }
    .transf-item-nombre { font-weight: 500; color: #333; }
    .transf-item-cant { font-size: 12px; color: #14ace7; font-weight: 700; }
    .btn-quitar { background: none; border: none; color: #c0392b; cursor: pointer; font-size: 18px; line-height: 1; padding: 0 4px; }
    .items-vacio { text-align: center; color: #aaa; font-size: 12px; padding: 14px; }
    .btn-guardar { width: 100%; background: #14ace7; color: white; border: none; padding: 11px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; margin-top: 4px; }
    .btn-guardar:hover { background: #1196cb; }
    .hint-bajo { font-size: 11px; color: #c0392b; margin-top: 4px; }
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
        <a class="menu-item" href="salidas.php">Salidas y mermas</a>
        <a class="menu-item" href="historial.php">Movimientos</a>
        <div class="divider"></div>

        <div class="menu-label">Proveedores</div>
        <a class="menu-item" href="proveedores.php">Proveedores</a>
        <a class="menu-item" href="compras.php">Compras</a>
        <div class="divider"></div>

        <div class="menu-label">Más</div>
        <a class="menu-item" href="paquetes.php">Paquetes</a>
        <a class="menu-item active" href="transferencias.php">Transferencias</a>
        <a class="menu-item" href="promociones.php">Promociones</a>
        <a class="menu-item" href="masVendidos.php">Más vendidos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Transferencias entre sucursales</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Lista de transferencias -->
        <div>
            <?php if (isset($_GET['msg'])): ?>
                <?php $msgs = [
                    'solicitado'      => 'Solicitud enviada. La sucursal origen debe aprobarla.',
                    'aprobar'         => 'Transferencia aprobada. Se agrego una nota para la sucursal que recibira el pedido.',
                    'rechazar'        => 'Transferencia rechazada.',
                    'enviar'          => 'Productos marcados como enviados. La sucursal destino debe confirmar la recepcion.',
                    'recibir'         => 'Recepcion confirmada. El stock fue actualizado en ambas sucursales.',
                    'cantidad_editada'       => 'Cantidad modificada. La sucursal destino debe confirmar el cambio.',
                    'aceptar_modificacion'  => 'Cambio de cantidad aceptado. La transferencia continua como Aprobada.',
                    'rechazar_modificacion' => 'Cambio de cantidad rechazado. La transferencia volvio a estado Pendiente.',
                    'error_stock_editar'    => 'No se puede guardar: la cantidad ingresada supera el stock disponible en la sucursal origen.',
                ]; ?>
                <div class="msg msg-exito"><?= htmlspecialchars($msgs[$_GET['msg']] ?? '') ?></div>
            <?php endif; ?>

            <!-- Filtro de fechas -->
            <div class="card" style="margin-bottom:10px;">
                <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                    <div>
                        <label style="display:block;font-size:11px;color:#888;margin-bottom:3px;font-weight:600;">Desde</label>
                        <input type="date" name="desde" value="<?= htmlspecialchars($filtroDesde) ?>"
                               style="padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:#888;margin-bottom:3px;font-weight:600;">Hasta</label>
                        <input type="date" name="hasta" value="<?= htmlspecialchars($filtroHasta) ?>"
                               style="padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:#888;margin-bottom:3px;font-weight:600;">Estado</label>
                        <select name="estado_f" style="padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                            <option value="">Todos</option>
                            <?php foreach (['Pendiente','Aprobada','Modificada','En tránsito','Entregada','Rechazada'] as $est): ?>
                            <option value="<?= $est ?>" <?= $filtroEstado === $est ? 'selected' : '' ?>><?= $est ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" style="background:#14ace7;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">Filtrar</button>
                    <a href="transferencias.php" style="font-size:12px;color:#aaa;align-self:center;text-decoration:none;">Limpiar</a>
                </form>
            </div>

            <div class="card" style="padding:0;">
                <?php if (count($transferencias) > 0): ?>
                <table>
                    <thead>
                        <tr><th>Producto</th><th>Cant.</th><th>Origen → Destino</th><th>Estado</th><th>Solicitante</th><th>Fecha</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transferencias as $t):
                            $esMiOrigen = $t['sucursal_origen_id'] == $_SESSION['sucursal_id'];
                            $badgeMap = [
                                'Pendiente'   => 'badge-pendiente',
                                'Aprobada'    => 'badge-aprobada',
                                'Modificada'  => 'badge-modificada',
                                'En tránsito' => 'badge-transito',
                                'Entregada'   => 'badge-entregada',
                                'Rechazada'   => 'badge-rechazada',
                            ];
                            $bc = $badgeMap[$t['estado']] ?? 'badge-pendiente';
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($t['nombre_producto']) ?></strong>
                                <div style="font-size:10px;color:#aaa;"><?= htmlspecialchars($t['codigo']) ?></div>
                                <span class="direction-badge <?= $esMiOrigen ? 'dir-enviada' : 'dir-recibida' ?>">
                                    <?= $esMiOrigen ? 'Enviada' : 'Recibida' ?>
                                </span>
                            </td>
                            <td><?= number_format($t['cantidad'], 2) ?></td>
                            <td style="font-size:11px;">
                                <?= htmlspecialchars($t['sucursal_origen']) ?> → <?= htmlspecialchars($t['sucursal_destino']) ?>
                            </td>
                            <td><span class="badge <?= $bc ?>"><?= htmlspecialchars($t['estado']) ?></span></td>
                            <td style="font-size:11px;"><?= htmlspecialchars($t['solicitante']) ?></td>
                            <td style="color:#aaa;font-size:11px;"><?= date('d/m/Y', strtotime($t['created_at'])) ?></td>
                            <td>
                                <div class="acciones">
                                    <?php
                                    $tvJs = $t['tipo_venta'] === 'Suelto' ? 'Suelto' : 'Unidad';
                                ?>
                                <?php
                                    $stockOrigenJs = floatval($t['stock_origen'] ?? 0);
                                ?>
                                <?php if ($t['estado'] === 'Pendiente' && $esMiOrigen): ?>
                                        <a class="btn-accion btn-aprobar" href="transferencias.php?accion=aprobar&id=<?= $t['transferencias_id'] ?>" onclick="return confirm('Aprobar esta solicitud y comprometerse a enviar los productos?')">Aprobar</a>
                                        <a class="btn-accion btn-rechazar" href="transferencias.php?accion=rechazar&id=<?= $t['transferencias_id'] ?>" onclick="return confirm('Rechazar esta solicitud de transferencia?')">Rechazar</a>
                                        <button class="btn-accion" type="button" style="background:#fff8e1;color:#e65100;border:none;cursor:pointer;" onclick="abrirModalEditarCantidad(<?= $t['transferencias_id'] ?>, <?= $t['cantidad'] ?>, '<?= $tvJs ?>', <?= $stockOrigenJs ?>)">Editar cantidad</button>
                                    <?php elseif ($t['estado'] === 'Aprobada' && $esMiOrigen): ?>
                                        <button class="btn-accion" type="button" style="background:#fff8e1;color:#e65100;border:none;cursor:pointer;" onclick="abrirModalEditarCantidad(<?= $t['transferencias_id'] ?>, <?= $t['cantidad'] ?>, '<?= $tvJs ?>', <?= $stockOrigenJs ?>)">Editar cantidad</button>
                                        <a class="btn-accion btn-enviar" href="transferencias.php?accion=enviar&id=<?= $t['transferencias_id'] ?>" onclick="return confirm('Confirmar que ya enviaste los productos?')">Marcar enviado</a>
                                    <?php elseif ($t['estado'] === 'Modificada' && !$esMiOrigen): ?>
                                        <a class="btn-accion btn-aceptar-mod"
                                           href="transferencias.php?accion=aceptar_modificacion&id=<?= $t['transferencias_id'] ?>"
                                           onclick="return confirm('Aceptar la nueva cantidad de <?= number_format($t['cantidad'], 2) ?>? La transferencia continuara como Aprobada.')">
                                            ✓ Aceptar cantidad
                                        </a>
                                        <a class="btn-accion btn-rechazar-mod"
                                           href="transferencias.php?accion=rechazar_modificacion&id=<?= $t['transferencias_id'] ?>"
                                           onclick="return confirm('Rechazar el cambio de cantidad? La transferencia volvera a Pendiente.')">
                                            ✕ Rechazar cambio
                                        </a>
                                    <?php elseif ($t['estado'] === 'Modificada' && $esMiOrigen): ?>
                                        <span style="color:#283593;font-size:11px;font-style:italic;">Esperando confirmacion del destino</span>
                                    <?php elseif ($t['estado'] === 'En tránsito' && !$esMiOrigen): ?>
                                        <a class="btn-accion btn-recibir" href="transferencias.php?accion=recibir&id=<?= $t['transferencias_id'] ?>" onclick="return confirm('Confirmar recepcion? Esto movera el stock en ambas sucursales.')">Confirmar recepcion</a>
                                    <?php else: ?>
                                        <span style="color:#aaa;font-size:11px;">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay transferencias registradas.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario: solicitar productos de otra sucursal -->
        <div>
            <div class="card">
                <h3>Solicitar productos</h3>
                <p style="font-size:12px;color:#aaa;margin:-8px 0 14px;">Pide productos de otra sucursal a la tuya.</p>

                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach ($errores as $e):?><li><?= htmlspecialchars($e) ?></li><?php endforeach;?></ul></div>
                <?php endif; ?>

                <form method="POST" id="formTransf">
                    <input type="hidden" name="items_transf" id="inputItemsTransf">

                    <div class="form-group">
                        <label>Sucursal origen *</label>
                        <select name="sucursal_origen_id" id="selOrigen" onchange="onOrigenChange(this.value)">
                            <option value="">-- Selecciona sucursal --</option>
                            <?php foreach ($sucursalesOrigen as $s): ?>
                                <option value="<?= $s['sucursal_id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Buscar producto *</label>
                        <div class="search-wrap">
                            <input type="text" id="busquedaProd" class="search-input"
                                   placeholder="Selecciona primero la sucursal origen..."
                                   autocomplete="off"
                                   oninput="buscarProducto()"
                                   onfocus="buscarProducto()"
                                   disabled>
                            <div class="sugerencias" id="sugerencias"></div>
                        </div>
                        <div class="hint-bajo" id="hintBajo" style="display:none;">&#9650; Productos marcados en rojo tienen stock bajo en tu sucursal.</div>
                    </div>

                    <div class="prod-seleccionado" id="prodSeleccionado">
                        <div class="prod-sel-nombre" id="prodSelNombre"></div>
                        <div class="prod-sel-stock" id="prodSelStock"></div>
                        <div class="cant-row">
                            <input type="number" id="cantProd" placeholder="Cantidad a pedir" step="1" min="1">
                            <button type="button" class="btn-add" onclick="agregarItem()">+ Agregar</button>
                        </div>
                    </div>

                    <div class="lista-items" id="listaItems">
                        <div class="items-vacio">Sin productos agregados</div>
                    </div>

                    <div class="form-group">
                        <label>Notas (opcional)</label>
                        <input type="text" name="notas" placeholder="Motivo o indicaciones...">
                    </div>

                    <button class="btn-guardar" type="submit" onclick="return prepararEnvio()">
                        Enviar solicitud
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const prodsBySucursal = <?= json_encode($prodsBySucursal) ?>;
let itemsTransf  = [];
let prodSelId    = null;
let prodSelTipo  = 'Unidad'; // 'Suelto' o 'Unidad'
let prodSelStock = 0;        // stock disponible en sucursal origen del producto seleccionado

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function onOrigenChange(val) {
    const input = document.getElementById('busquedaProd');
    input.value = '';
    input.disabled = !val;
    input.placeholder = val ? 'Escribe nombre o código...' : 'Selecciona primero la sucursal origen...';
    document.getElementById('prodSeleccionado').style.display = 'none';
    prodSelId = null;
    hideSug();
    if (val) { setTimeout(() => { input.focus(); }, 50); }
}

function buscarProducto() {
    const origenId = parseInt(document.getElementById('selOrigen').value) || 0;
    const q = document.getElementById('busquedaProd').value.toLowerCase().trim();
    if (!origenId || !prodsBySucursal[origenId]) { hideSug(); return; }

    let prods = [...prodsBySucursal[origenId]].sort((a, b) => {
        if (a.bajo !== b.bajo) return b.bajo ? 1 : -1;
        return a.nombre.localeCompare(b.nombre);
    });

    const filtered = q === ''
        ? prods
        : prods.filter(p => p.nombre.toLowerCase().includes(q) || p.codigo.toLowerCase().includes(q));

    document.getElementById('hintBajo').style.display = filtered.some(p => p.bajo) ? 'block' : 'none';
    renderSug(filtered);
}

function renderSug(prods) {
    const div = document.getElementById('sugerencias');
    if (!prods.length) { hideSug(); return; }
    div.innerHTML = prods.map(p => {
        const esSuelto = p.tipo_venta === 'Suelto';
        const stockFmt = esSuelto ? parseFloat(p.stock).toFixed(3).replace(/\.?0+$/,'') : Math.floor(p.stock);
        const miStockFmt = esSuelto ? parseFloat(p.mi_stock).toFixed(3).replace(/\.?0+$/,'') : Math.floor(p.mi_stock);
        return `
        <div class="sug-item" onclick="seleccionarProd(${p.id}, '${esc(p.nombre)}', '${esc(p.codigo)}', ${p.stock}, ${p.mi_stock}, ${p.bajo}, '${p.tipo_venta||'Unidad'}')">
            <div>
                <span class="sug-nombre">${esc(p.nombre)}</span>
                <span class="sug-codigo">${esc(p.codigo)}</span>
                ${p.bajo ? '<span class="badge-bajo">Stock bajo</span>' : ''}
                ${esSuelto ? '<span style="font-size:10px;color:#888;margin-left:4px;">granel</span>' : ''}
            </div>
            <div class="sug-stocks">
                <span class="sug-stock-row sug-stock-orig">Origen: ${stockFmt}</span>
                <span class="sug-stock-row sug-stock-dest">Tu sucursal: ${miStockFmt}</span>
            </div>
        </div>`;
    }).join('');
    div.style.display = 'block';
}

function hideSug() { document.getElementById('sugerencias').style.display = 'none'; }

function seleccionarProd(id, nombre, codigo, stock, mi_stock, bajo, tipoVenta) {
    prodSelId    = id;
    prodSelTipo  = tipoVenta || 'Unidad';
    prodSelStock = parseFloat(stock) || 0;
    const esSuelto = prodSelTipo === 'Suelto';

    document.getElementById('busquedaProd').value = nombre;
    hideSug();
    document.getElementById('prodSelNombre').textContent = nombre + ' (' + codigo + ')';

    const stockFmt   = esSuelto ? parseFloat(stock).toFixed(3).replace(/\.?0+$/,'')    : Math.floor(stock);
    const miStockFmt = esSuelto ? parseFloat(mi_stock).toFixed(3).replace(/\.?0+$/,'') : Math.floor(mi_stock);
    document.getElementById('prodSelStock').innerHTML =
        'Origen: <strong style="color:#1565c0;">' + stockFmt + '</strong>' +
        ' &nbsp;|&nbsp; Destino (tu sucursal): <strong style="color:#2e7d32;">' + miStockFmt + '</strong>' +
        (bajo ? ' <span style="color:#c0392b;font-size:11px;">(stock bajo)</span>' : '') +
        (esSuelto ? ' <span style="font-size:11px;color:#888;">&nbsp;— granel (acepta decimales)</span>' : '');

    const inpCant = document.getElementById('cantProd');
    inpCant.step        = esSuelto ? '0.001' : '1';
    inpCant.min         = esSuelto ? '0.001' : '1';
    inpCant.inputMode   = esSuelto ? 'decimal' : 'numeric';
    inpCant.placeholder = esSuelto ? 'Cantidad (ej. 2.5)' : 'Cantidad (número entero)';
    inpCant.value = '';

    document.getElementById('prodSeleccionado').style.display = 'block';
    inpCant.focus();
}

function agregarItem() {
    if (!prodSelId) { alert('Selecciona un producto primero.'); return; }
    const esSuelto = prodSelTipo === 'Suelto';
    let cant = parseFloat(document.getElementById('cantProd').value) || 0;
    if (cant <= 0) { alert('Ingresa una cantidad mayor a 0.'); return; }
    if (!esSuelto) {
        if (!Number.isInteger(cant) || cant !== Math.floor(cant)) {
            alert('Este producto no es granel. La cantidad debe ser un número entero (sin decimales).');
            return;
        }
        cant = Math.floor(cant);
    }
    // Validar que la cantidad no supere el stock disponible en la sucursal origen
    if (cant > prodSelStock) {
        const stockFmt = esSuelto
            ? parseFloat(prodSelStock).toFixed(3).replace(/\.?0+$/, '')
            : Math.floor(prodSelStock);
        alert('No se puede pedir ' + cant + ' unidades. La sucursal origen solo tiene ' + stockFmt + ' disponibles.');
        return;
    }

    const nombre = document.getElementById('prodSelNombre').textContent;
    const existe = itemsTransf.find(i => i.id === prodSelId);
    if (existe) { existe.cantidad = cant; existe.tipo_venta = prodSelTipo; }
    else { itemsTransf.push({ id: prodSelId, nombre, cantidad: cant, tipo_venta: prodSelTipo }); }

    prodSelId = null;
    document.getElementById('busquedaProd').value = '';
    document.getElementById('prodSeleccionado').style.display = 'none';
    document.getElementById('hintBajo').style.display = 'none';
    renderItems();
}

function renderItems() {
    const div = document.getElementById('listaItems');
    if (!itemsTransf.length) {
        div.innerHTML = '<div class="items-vacio">Sin productos agregados</div>';
        return;
    }
    div.innerHTML = itemsTransf.map((item, idx) => `
        <div class="transf-item">
            <span class="transf-item-nombre">${esc(item.nombre)}</span>
            <div style="display:flex;align-items:center;gap:8px;">
                <span class="transf-item-cant">x${item.cantidad}</span>
                <button class="btn-quitar" type="button" onclick="quitarItem(${idx})">x</button>
            </div>
        </div>
    `).join('');
}

function quitarItem(idx) {
    itemsTransf.splice(idx, 1);
    renderItems();
}

function prepararEnvio() {
    if (!itemsTransf.length) { alert('Agrega al menos un producto.'); return false; }
    document.getElementById('inputItemsTransf').value = JSON.stringify(
        itemsTransf.map(i => ({ id: i.id, cantidad: i.cantidad }))
    );
    return true;
}

// Cerrar sugerencias al perder foco (delay para permitir click en una sugerencia)
document.getElementById('busquedaProd').addEventListener('blur', function() {
    setTimeout(hideSug, 200);
});

// ── Editar cantidad transferencia ────────────────────────────────────────────
let _modalEditTipoVenta  = 'Unidad';
let _modalEditStockOrigen = 0;

function abrirModalEditarCantidad(id, cantActual, tipoVenta, stockOrigen) {
    _modalEditTipoVenta   = tipoVenta   || 'Unidad';
    _modalEditStockOrigen = parseFloat(stockOrigen) || 0;
    const esSuelto = _modalEditTipoVenta === 'Suelto';

    const cantFmt = esSuelto
        ? parseFloat(cantActual).toFixed(3).replace(/\.?0+$/,'')
        : Math.floor(cantActual);
    document.getElementById('modalEditCantidadActual').textContent = cantFmt;

    // Mostrar stock disponible en el modal
    const stockFmt = esSuelto
        ? _modalEditStockOrigen.toFixed(3).replace(/\.?0+$/, '')
        : Math.floor(_modalEditStockOrigen);
    const stockDiv = document.getElementById('modalEditStockDisponible');
    if (stockDiv) {
        stockDiv.textContent = 'Stock disponible en origen: ' + stockFmt;
        stockDiv.style.display = 'block';
    }

    const inp = document.getElementById('inputNuevaCantidadModal');
    inp.step        = esSuelto ? '0.001' : '1';
    inp.min         = esSuelto ? '0.001' : '1';
    inp.max         = _modalEditStockOrigen > 0 ? _modalEditStockOrigen : '';
    inp.inputMode   = esSuelto ? 'decimal' : 'numeric';
    inp.placeholder = esSuelto ? 'Ej. 2.5' : 'Ej. 5';
    inp.value       = '';

    // Mostrar/ocultar aviso granel
    document.getElementById('modalEditGranelHint').style.display = esSuelto ? 'block' : 'none';

    document.getElementById('inputEditarTransfId').value      = id;
    document.getElementById('inputEditarNuevaCantidad').value  = '';
    document.getElementById('modalEditCantidad').style.display = 'flex';
    setTimeout(() => inp.focus(), 80);
}

function cerrarModalEditarCantidad() {
    document.getElementById('modalEditCantidad').style.display = 'none';
}

function confirmarEditarCantidad() {
    const esSuelto = _modalEditTipoVenta === 'Suelto';
    let val = parseFloat(document.getElementById('inputNuevaCantidadModal').value);
    if (!val || val <= 0) { alert('Ingresa una cantidad válida mayor a 0.'); return; }
    if (!esSuelto) {
        if (val !== Math.floor(val)) {
            alert('Este producto no es granel. La cantidad debe ser un número entero (sin decimales).');
            return;
        }
        val = Math.floor(val);
    }
    // Validar contra stock disponible en origen
    if (_modalEditStockOrigen > 0 && val > _modalEditStockOrigen) {
        const stockFmt = esSuelto
            ? _modalEditStockOrigen.toFixed(3).replace(/\.?0+$/, '')
            : Math.floor(_modalEditStockOrigen);
        alert('No se puede guardar: la cantidad (' + val + ') supera el stock disponible en la sucursal origen (' + stockFmt + ').');
        return;
    }
    document.getElementById('inputEditarNuevaCantidad').value = val;
    document.getElementById('formEditarCantidadTransf').submit();
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalEditarCantidad();
    }
});
</script>

<!-- Form editar cantidad -->
<form id="formEditarCantidadTransf" method="POST" action="transferencias.php" style="display:none;">
    <input type="hidden" name="accion" value="editar_cantidad">
    <input type="hidden" id="inputEditarTransfId" name="id">
    <input type="hidden" id="inputEditarNuevaCantidad" name="nueva_cantidad">
</form>

<!-- Modal editar cantidad — event listener aquí porque el script principal corre antes del HTML -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('modalEditCantidad').addEventListener('click', function(e) {
        if (e.target === this) cerrarModalEditarCantidad();
    });
});
</script>
<div id="modalEditCantidad" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;padding:22px;width:320px;box-shadow:0 8px 32px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <span style="font-size:14px;font-weight:700;color:#333;">Modificar cantidad a enviar</span>
            <button onclick="cerrarModalEditarCantidad()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888;line-height:1;">×</button>
        </div>
        <div style="font-size:13px;color:#555;margin-bottom:8px;">
            Cantidad actual: <strong id="modalEditCantidadActual" style="color:#1565c0;"></strong>
        </div>
        <div id="modalEditStockDisponible" style="display:none;font-size:12px;color:#2e7d32;font-weight:600;margin-bottom:12px;"></div>
        <div style="font-size:12px;color:#888;background:#fff8e1;border:1px solid #ffe082;border-radius:6px;padding:10px 12px;margin-bottom:14px;">
            ⚠ Al guardar, la transferencia pasará a estado <strong>Modificada</strong> y la sucursal destino deberá aceptar o rechazar el cambio.
        </div>
        <div id="modalEditGranelHint" style="display:none;font-size:12px;color:#555;background:#e8f5e9;border:1px solid #c8e6c9;border-radius:6px;padding:8px 12px;margin-bottom:12px;">
            🌾 Producto a granel — acepta decimales (ej. 2.5 kg)
        </div>
        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:12px;color:#555;font-weight:600;margin-bottom:6px;">Nueva cantidad *</label>
            <input type="number" id="inputNuevaCantidadModal" step="1" min="1"
                   placeholder="Ej. 5"
                   style="width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;"
                   onkeydown="if(event.key==='Enter') confirmarEditarCantidad()">
        </div>
        <div style="display:flex;gap:8px;">
            <button onclick="confirmarEditarCantidad()"
                    style="flex:1;background:#14ace7;color:#fff;border:none;padding:10px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">
                Guardar cambio
            </button>
            <button onclick="cerrarModalEditarCantidad()"
                    style="background:#f0f0f0;color:#555;border:none;padding:10px 16px;border-radius:6px;font-size:13px;cursor:pointer;">
                Cancelar
            </button>
        </div>
    </div>
</div>


</body>
</html>
