<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

// Acciones sobre transferencia existente
if (isset($_GET['accion']) && isset($_GET['id'])) {
    $id         = intval($_GET['id']);
    $accion     = $_GET['accion'];
    $miSucursal = $_SESSION['sucursal_id'];

    if ($accion === 'aprobar') {
        $pdo->prepare("UPDATE transferencias SET estado='Aprobada', usuario_aprueba_id=? WHERE transferencias_id=? AND estado='Pendiente' AND sucursal_origen_id=?")
            ->execute([$_SESSION['usuario_id'], $id, $miSucursal]);
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
            $stmtOrigen = $pdo->prepare("SELECT stock_actual FROM productos WHERE producto_id = ? AND sucursal_id = ?");
            $stmtOrigen->execute([$transf['producto_id'], $transf['sucursal_origen_id']]);
            $prodOrigen = $stmtOrigen->fetch(PDO::FETCH_ASSOC);

            if ($prodOrigen && $prodOrigen['stock_actual'] >= $transf['cantidad']) {
                $stockAntOrigen   = $prodOrigen['stock_actual'];
                $stockNuevoOrigen = $stockAntOrigen - $transf['cantidad'];
                $pdo->prepare("UPDATE productos SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                    ->execute([$stockNuevoOrigen, $transf['producto_id'], $transf['sucursal_origen_id']]);
                $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,'Transferencia',?,?,?,'Transferencia enviada')")
                    ->execute([$transf['producto_id'], $_SESSION['usuario_id'], $transf['cantidad'], $stockAntOrigen, $stockNuevoOrigen]);

                $stmtDest = $pdo->prepare("SELECT stock_actual FROM productos WHERE producto_id = ? AND sucursal_id = ?");
                $stmtDest->execute([$transf['producto_id'], $transf['sucursal_destino_id']]);
                $prodDest = $stmtDest->fetch(PDO::FETCH_ASSOC);

                if ($prodDest) {
                    $stockAntDest   = $prodDest['stock_actual'];
                    $stockNuevoDest = $stockAntDest + $transf['cantidad'];
                    $pdo->prepare("UPDATE productos SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                        ->execute([$stockNuevoDest, $transf['producto_id'], $transf['sucursal_destino_id']]);
                    $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,'Transferencia',?,?,?,'Transferencia recibida')")
                        ->execute([$transf['producto_id'], $_SESSION['usuario_id'], $transf['cantidad'], $stockAntDest, $stockNuevoDest]);
                }

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
            $pdo->prepare("INSERT INTO transferencias (producto_id, sucursal_origen_id, sucursal_destino_id, usuario_solicita_id, cantidad, notas, estado) VALUES (?,?,?,?,?,?,'Pendiente')")
                ->execute([intval($item['id']), $sucursal_origen_id, $_SESSION['sucursal_id'], $_SESSION['usuario_id'], floatval($item['cantidad']), $notas]);
        }
        header('Location: transferencias.php?msg=solicitado'); exit();
    }
}

// Transferencias de esta sucursal (como origen o destino)
$stmt = $pdo->prepare("
    SELECT t.*,
        p.nombre_producto, p.codigo,
        so.nombre AS sucursal_origen,
        sd.nombre AS sucursal_destino,
        us.nombre_completo AS solicitante,
        ua.nombre_completo AS aprobador
    FROM transferencias t
    JOIN productos p ON t.producto_id = p.producto_id
    JOIN sucursales so ON t.sucursal_origen_id = so.sucursal_id
    JOIN sucursales sd ON t.sucursal_destino_id = sd.sucursal_id
    JOIN usuarios us ON t.usuario_solicita_id = us.usuario_id
    LEFT JOIN usuarios ua ON t.usuario_aprueba_id = ua.usuario_id
    WHERE t.sucursal_origen_id = ? OR t.sucursal_destino_id = ?
    ORDER BY t.created_at DESC
    LIMIT 50
");
$stmt->execute([$_SESSION['sucursal_id'], $_SESSION['sucursal_id']]);
$transferencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sucursales origen disponibles
$stmt = $pdo->prepare("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1 AND sucursal_id != ?");
$stmt->execute([$_SESSION['sucursal_id']]);
$sucursalesOrigen = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Productos de OTRAS sucursales agrupados para el buscador
$stmt = $pdo->query("SELECT producto_id, codigo, nombre_producto, stock_actual, sucursal_id FROM productos WHERE activo = 1 AND stock_actual > 0 ORDER BY nombre_producto");
$allProds = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Productos con stock bajo en MI sucursal (para sugerir primero)
$stmt = $pdo->prepare("SELECT producto_id FROM productos WHERE sucursal_id = ? AND activo = 1 AND stock_actual <= stock_minimo");
$stmt->execute([$_SESSION['sucursal_id']]);
$lowStockSet = array_flip(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'producto_id'));

$prodsBySucursal = [];
foreach ($allProds as $p) {
    if ($p['sucursal_id'] == $_SESSION['sucursal_id']) continue;
    $prodsBySucursal[$p['sucursal_id']][] = [
        'id'     => intval($p['producto_id']),
        'codigo' => $p['codigo'],
        'nombre' => $p['nombre_producto'],
        'stock'  => floatval($p['stock_actual']),
        'bajo'   => isset($lowStockSet[$p['producto_id']]),
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
    .badge-pendiente { background: #e3f2fd; color: #1565c0; }
    .badge-aprobada  { background: #e8f5e9; color: #2e7d32; }
    .badge-rechazada { background: #fdecea; color: #c0392b; }
    .badge-transito  { background: #fff8e1; color: #e65100; }
    .badge-entregada { background: #f0f0f0; color: #666; }
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
    .sug-stock { font-size: 11px; color: #555; white-space: nowrap; margin-left: 8px; }
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
        <a class="menu-item active" href="transferencias.php">Transferencias</a>
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
                    'solicitado' => 'Solicitud enviada. La sucursal origen debe aprobarla.',
                    'aprobar'    => 'Transferencia aprobada. Marca los productos como enviados cuando los despaches.',
                    'rechazar'   => 'Transferencia rechazada.',
                    'enviar'     => 'Productos marcados como enviados. La sucursal destino debe confirmar la recepcion.',
                    'recibir'    => 'Recepcion confirmada. El stock fue actualizado en ambas sucursales.',
                ]; ?>
                <div class="msg msg-exito"><?= htmlspecialchars($msgs[$_GET['msg']] ?? '') ?></div>
            <?php endif; ?>

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
                                    <?php if ($t['estado'] === 'Pendiente' && $esMiOrigen): ?>
                                        <a class="btn-accion btn-aprobar" href="transferencias.php?accion=aprobar&id=<?= $t['transferencias_id'] ?>" onclick="return confirm('Aprobar esta solicitud y comprometerse a enviar los productos?')">Aprobar</a>
                                        <a class="btn-accion btn-rechazar" href="transferencias.php?accion=rechazar&id=<?= $t['transferencias_id'] ?>" onclick="return confirm('Rechazar esta solicitud de transferencia?')">Rechazar</a>
                                    <?php elseif ($t['estado'] === 'Aprobada' && $esMiOrigen): ?>
                                        <a class="btn-accion btn-enviar" href="transferencias.php?accion=enviar&id=<?= $t['transferencias_id'] ?>" onclick="return confirm('Confirmar que ya enviaste los productos?')">Marcar enviado</a>
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
                                   placeholder="Escribe nombre o código..."
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
let itemsTransf = [];
let prodSelId = null;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function onOrigenChange(val) {
    const input = document.getElementById('busquedaProd');
    input.value = '';
    input.disabled = !val;
    document.getElementById('prodSeleccionado').style.display = 'none';
    document.getElementById('sugerencias').style.display = 'none';
    prodSelId = null;
    if (val) buscarProducto();
}

function buscarProducto() {
    const origenId = parseInt(document.getElementById('selOrigen').value) || 0;
    const q = document.getElementById('busquedaProd').value.toLowerCase().trim();
    if (!origenId || !prodsBySucursal[origenId]) { hideSug(); return; }

    let prods = prodsBySucursal[origenId];
    prods = [...prods].sort((a, b) => (b.bajo ? 1 : 0) - (a.bajo ? 1 : 0));

    let filtered = q === ''
        ? prods.slice(0, 10)
        : prods.filter(p => p.nombre.toLowerCase().includes(q) || p.codigo.toLowerCase().includes(q)).slice(0, 10);

    const hayBajo = filtered.some(p => p.bajo);
    document.getElementById('hintBajo').style.display = hayBajo ? 'block' : 'none';

    renderSug(filtered);
}

function renderSug(prods) {
    const div = document.getElementById('sugerencias');
    if (!prods.length) { hideSug(); return; }
    div.innerHTML = prods.map(p => `
        <div class="sug-item" onclick="seleccionarProd(${p.id}, '${esc(p.nombre)}', '${esc(p.codigo)}', ${p.stock}, ${p.bajo})">
            <div>
                <span class="sug-nombre">${esc(p.nombre)}</span>
                <span class="sug-codigo">${esc(p.codigo)}</span>
                ${p.bajo ? '<span class="badge-bajo">Stock bajo</span>' : ''}
            </div>
            <span class="sug-stock">Stock: ${parseFloat(p.stock).toFixed(0)}</span>
        </div>
    `).join('');
    div.style.display = 'block';
}

function hideSug() { document.getElementById('sugerencias').style.display = 'none'; }

function seleccionarProd(id, nombre, codigo, stock, bajo) {
    prodSelId = id;
    document.getElementById('busquedaProd').value = nombre;
    hideSug();
    document.getElementById('prodSelNombre').textContent = nombre + ' (' + codigo + ')';
    document.getElementById('prodSelStock').textContent = 'Stock disponible en sucursal origen: ' + parseFloat(stock).toFixed(0);
    if (bajo) document.getElementById('prodSelStock').textContent += ' — ¡tu sucursal tiene stock bajo de este producto!';
    document.getElementById('prodSeleccionado').style.display = 'block';
    document.getElementById('cantProd').value = '';
    document.getElementById('cantProd').focus();
}

function agregarItem() {
    if (!prodSelId) { alert('Selecciona un producto primero.'); return; }
    const cant = parseFloat(document.getElementById('cantProd').value) || 0;
    if (cant <= 0) { alert('Ingresa una cantidad mayor a 0.'); return; }

    const nombre = document.getElementById('prodSelNombre').textContent;
    const existe = itemsTransf.find(i => i.id === prodSelId);
    if (existe) { existe.cantidad = cant; }
    else { itemsTransf.push({ id: prodSelId, nombre, cantidad: cant }); }

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

document.addEventListener('click', function(e) {
    if (!e.target.closest('.search-wrap')) hideSug();
});
</script>
</body>
</html>
