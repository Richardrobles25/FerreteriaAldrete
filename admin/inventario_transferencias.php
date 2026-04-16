<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

// Aprobar/rechazar/entregar transferencia
if (isset($_GET['accion']) && isset($_GET['id'])) {
    $id     = intval($_GET['id']);
    $accion = $_GET['accion'];

    if ($accion === 'aprobar') {
        $pdo->prepare("UPDATE transferencias SET estado='Aprobada', usuario_aprueba_id=? WHERE transferencias_id=?")->execute([$_SESSION['usuario_id'], $id]);
    } elseif ($accion === 'rechazar') {
        $pdo->prepare("UPDATE transferencias SET estado='Rechazada', usuario_aprueba_id=? WHERE transferencias_id=?")->execute([$_SESSION['usuario_id'], $id]);
    } elseif ($accion === 'entregar') {
        // Mover stock: restar de origen, sumar en destino
        $stmt = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ?");
        $stmt->execute([$id]);
        $transf = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($transf && $transf['estado'] === 'Aprobada') {
            // Restar stock en sucursal origen
            $stmtOrigen = $pdo->prepare("SELECT stock_actual, producto_id FROM productos WHERE producto_id = ? AND sucursal_id = ?");
            $stmtOrigen->execute([$transf['producto_id'], $transf['sucursal_origen_id']]);
            $prodOrigen = $stmtOrigen->fetch(PDO::FETCH_ASSOC);

            if ($prodOrigen && $prodOrigen['stock_actual'] >= $transf['cantidad']) {
                $stockAntOrigen = $prodOrigen['stock_actual'];
                $stockNuevoOrigen = $stockAntOrigen - $transf['cantidad'];
                $pdo->prepare("UPDATE productos SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                    ->execute([$stockNuevoOrigen, $transf['producto_id'], $transf['sucursal_origen_id']]);
                $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,'Transferencia',?,?,?,'Transferencia enviada')")
                    ->execute([$transf['producto_id'], $_SESSION['usuario_id'], $transf['cantidad'], $stockAntOrigen, $stockNuevoOrigen]);

                // Sumar stock en sucursal destino
                $stmtDest = $pdo->prepare("SELECT stock_actual FROM productos WHERE producto_id = ? AND sucursal_id = ?");
                $stmtDest->execute([$transf['producto_id'], $transf['sucursal_destino_id']]);
                $prodDest = $stmtDest->fetch(PDO::FETCH_ASSOC);

                if ($prodDest) {
                    $stockAntDest = $prodDest['stock_actual'];
                    $stockNuevoDest = $stockAntDest + $transf['cantidad'];
                    $pdo->prepare("UPDATE productos SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                        ->execute([$stockNuevoDest, $transf['producto_id'], $transf['sucursal_destino_id']]);
                    $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,'Transferencia',?,?,?,'Transferencia recibida')")
                        ->execute([$transf['producto_id'], $_SESSION['usuario_id'], $transf['cantidad'], $stockAntDest, $stockNuevoDest]);
                }

                $pdo->prepare("UPDATE transferencias SET estado='Entregada' WHERE transferencias_id=?")->execute([$id]);
            }
        }
    }
    header('Location: inventario_transferencias.php?msg='.$accion); exit();
}

// Nueva solicitud de transferencia
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $producto_id         = intval($_POST['producto_id'] ?? 0);
    $sucursal_destino_id = intval($_POST['sucursal_destino_id'] ?? 0);
    $cantidad            = floatval($_POST['cantidad'] ?? 0);
    $notas               = trim($_POST['notas'] ?? '');

    if (!$producto_id)         $errores[] = 'Selecciona un producto.';
    if (!$sucursal_destino_id) $errores[] = 'Selecciona la sucursal destino.';
    if ($cantidad <= 0)        $errores[] = 'La cantidad debe ser mayor a 0.';
    if ($sucursal_destino_id == $_SESSION['sucursal_id']) $errores[] = 'La sucursal destino no puede ser la misma.';

    if (empty($errores)) {
        $pdo->prepare("INSERT INTO transferencias (producto_id, sucursal_origen_id, sucursal_destino_id, usuario_solicita_id, cantidad, notas, estado) VALUES (?,?,?,?,?,?,'Pendiente')")
            ->execute([$producto_id, $_SESSION['sucursal_id'], $sucursal_destino_id, $_SESSION['usuario_id'], $cantidad, $notas]);
        header('Location: inventario_transferencias.php?msg=solicitado'); exit();
    }
}

// Transferencias relevantes para esta sucursal
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

// Datos para el formulario
$stmt = $pdo->prepare("SELECT producto_id, codigo, nombre_producto, stock_actual FROM productos WHERE sucursal_id = ? AND activo = 1 AND stock_actual > 0 ORDER BY nombre_producto ASC");
$stmt->execute([$_SESSION['sucursal_id']]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1 AND sucursal_id != ?");
$stmt->execute([$_SESSION['sucursal_id']]);
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transferencias â€” FerreterÃ­a Aldrete</title>
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
    .content { flex: 1; padding: 20px; overflow-y: auto; display: grid; grid-template-columns: 1fr 360px; gap: 16px; }
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
    .badge-aprobada { background: #e8f5e9; color: #2e7d32; }
    .badge-rechazada { background: #fdecea; color: #c0392b; }
    .badge-entregada { background: #e3f2fd; color: #1565c0; }
    .direction-badge { font-size: 10px; padding: 2px 6px; border-radius: 99px; font-weight: 600; }
    .dir-enviada { background: #fdecea; color: #c0392b; }
    .dir-recibida { background: #e8f5e9; color: #2e7d32; }
    .acciones { display: flex; gap: 5px; flex-wrap: wrap; }
    .btn-accion { padding: 4px 10px; border-radius: 5px; font-size: 11px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-aprobar { background: #e8f5e9; color: #2e7d32; }
    .btn-rechazar { background: #fdecea; color: #c0392b; }
    .btn-entregar { background: #e3f2fd; color: #1565c0; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    .form-group { margin-bottom: 13px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 600; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .stock-disp { background: #eef8ff; border-radius: 6px; padding: 8px 12px; font-size: 12px; color: #1565c0; margin-bottom: 12px; display: none; }
    .btn-guardar { width: 100%; background: #14ace7; color: white; border: none; padding: 11px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-guardar:hover { background: #1196cb; }
</style>

<?php renderAdminSidebar('inventario_transferencias'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Transferencias entre sucursales</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">â€” <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesiÃ³n</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Lista -->
        <div>
            <?php if (isset($_GET['msg'])): ?>
                <?php $msgs = ['solicitado'=>'Transferencia solicitada.','aprobar'=>'Transferencia aprobada.','rechazar'=>'Transferencia rechazada.','entregar'=>'Stock transferido correctamente.']; ?>
                <div class="msg msg-exito"><?= $msgs[$_GET['msg']] ?? '' ?></div>
            <?php endif; ?>

            <div class="card" style="padding:0;">
                <?php if (count($transferencias) > 0): ?>
                <table>
                    <thead>
                        <tr><th>Producto</th><th>Cant.</th><th>Origen â†’ Destino</th><th>Estado</th><th>Solicitante</th><th>Fecha</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transferencias as $t):
                            $esMiSucursal = $t['sucursal_origen_id'] == $_SESSION['sucursal_id'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($t['nombre_producto']) ?></strong>
                                <div style="font-size:10px;color:#aaa;"><?= htmlspecialchars($t['codigo']) ?></div>
                                <span class="direction-badge <?= $esMiSucursal?'dir-enviada':'dir-recibida' ?>">
                                    <?= $esMiSucursal?'Enviada':'Recibida' ?>
                                </span>
                            </td>
                            <td><?= number_format($t['cantidad'],2) ?></td>
                            <td style="font-size:11px;">
                                <?= htmlspecialchars($t['sucursal_origen']) ?> â†’ <?= htmlspecialchars($t['sucursal_destino']) ?>
                            </td>
                            <td><span class="badge badge-<?= strtolower($t['estado']) ?>"><?= $t['estado'] ?></span></td>
                            <td style="font-size:11px;"><?= htmlspecialchars($t['solicitante']) ?></td>
                            <td style="color:#aaa;font-size:11px;"><?= date('d/m/Y', strtotime($t['created_at'])) ?></td>
                            <td>
                                <div class="acciones">
                                    <?php if ($t['estado'] === 'Pendiente' && !$esMiSucursal): ?>
                                        <a class="btn-accion btn-aprobar" href="inventario_transferencias.php?accion=aprobar&id=<?= $t['transferencias_id'] ?>" onclick="return confirm('Â¿Aprobar?')">Aprobar</a>
                                        <a class="btn-accion btn-rechazar" href="inventario_transferencias.php?accion=rechazar&id=<?= $t['transferencias_id'] ?>" onclick="return confirm('Â¿Rechazar?')">Rechazar</a>
                                    <?php elseif ($t['estado'] === 'Aprobada' && $esMiSucursal): ?>
                                        <a class="btn-accion btn-entregar" href="inventario_transferencias.php?accion=entregar&id=<?= $t['transferencias_id'] ?>" onclick="return confirm('Â¿Confirmar entrega y mover stock?')">Entregar</a>
                                    <?php else: ?>
                                        <span style="color:#aaa;font-size:11px;">â€”</span>
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

        <!-- Formulario nueva transferencia -->
        <div>
            <div class="card">
                <h3>Solicitar transferencia</h3>
                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach($errores as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label>Producto *</label>
                        <select name="producto_id" onchange="mostrarStockDisp(this)">
                            <option value="">-- Selecciona --</option>
                            <?php foreach ($productos as $p): ?>
                                <option value="<?= $p['producto_id'] ?>" data-stock="<?= $p['stock_actual'] ?>">
                                    <?= htmlspecialchars($p['nombre_producto']) ?> (Stock: <?= number_format($p['stock_actual'],2) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="stock-disp" id="stockDisp">
                        Disponible en tu sucursal: <strong id="stockDispVal"></strong>
                    </div>

                    <div class="form-group">
                        <label>Sucursal destino *</label>
                        <select name="sucursal_destino_id">
                            <option value="">-- Selecciona --</option>
                            <?php foreach ($sucursales as $s): ?>
                                <option value="<?= $s['sucursal_id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Cantidad *</label>
                        <input type="number" name="cantidad" placeholder="0" step="1" min="1">
                    </div>

                    <div class="form-group">
                        <label>Notas</label>
                        <input type="text" name="notas" placeholder="Motivo de la transferencia...">
                    </div>

                    <button class="btn-guardar" type="submit">Solicitar transferencia</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
function mostrarStockDisp(sel) {
    const opt = sel.options[sel.selectedIndex];
    const div = document.getElementById('stockDisp');
    if (sel.value) {
        document.getElementById('stockDispVal').textContent = parseFloat(opt.dataset.stock).toFixed(2);
        div.style.display = 'block';
    } else { div.style.display = 'none'; }
}
</script>
</body>
</html>


