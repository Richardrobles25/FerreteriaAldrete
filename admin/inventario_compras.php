<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);
require_once __DIR__ . '/_admin_sucursal_filtro.php';

// Ver detalle de compra
$verDetalle = intval($_GET['ver'] ?? 0);
$detalle    = null;
$detalleProductos = [];

if ($verDetalle) {
    $stmt = $pdo->prepare("
        SELECT cp.*, p.nombre AS nombre_proveedor, s.nombre AS nombre_sucursal, u.nombre_completo AS usuario
        FROM compras_proveedor cp
        JOIN proveedores p ON cp.proveedor_id = p.proveedor_id
        JOIN sucursales s ON cp.sucursal_id = s.sucursal_id
        JOIN usuarios u ON cp.usuario_id = u.usuario_id
        WHERE cp.compras_proveedor_id = ?
    ");
    $stmt->execute([$verDetalle]);
    $detalle = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($detalle) {
        $stmtP = $pdo->prepare("
            SELECT cpd.*, pr.nombre_producto, pr.codigo
            FROM compra_productos cpd
            JOIN productos pr ON cpd.producto_id = pr.producto_id
            WHERE cpd.compra_id = ?
        ");
        $stmtP->execute([$verDetalle]);
        $detalleProductos = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Migración: asegurar columna proveedor_id en movimientos_inventario ────────
$colExiste = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'movimientos_inventario'
      AND COLUMN_NAME  = 'proveedor_id'")->fetchColumn();
if (!$colExiste) {
    $pdo->exec("ALTER TABLE movimientos_inventario ADD COLUMN proveedor_id INT NULL DEFAULT NULL");
}

// Procesar nueva compra
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$verDetalle) {
    $proveedor_id = intval($_POST['proveedor_id'] ?? 0);
    $notas        = trim($_POST['notas'] ?? '');
    $items        = json_decode($_POST['items'] ?? '[]', true);

    if (!$proveedor_id)    $errores[] = 'Selecciona un proveedor.';
    if (empty($items))     $errores[] = 'Agrega al menos un producto.';

    if (empty($errores)) {
        $total = array_sum(array_map(fn($i) => $i['cantidad'] * $i['precio_unitario'], $items));

        $stmt = $pdo->prepare("INSERT INTO compras_proveedor (proveedor_id, usuario_id, sucursal_id, total, notas) VALUES (?,?,?,?,?)");
        $stmt->execute([$proveedor_id, $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $total, $notas]);
        $compra_id = $pdo->lastInsertId();

        foreach ($items as $item) {
            $subtotal = $item['cantidad'] * $item['precio_unitario'];
            $pdo->prepare("INSERT INTO compra_productos (compra_id, producto_id, cantidad, precio_unitario, subtotal) VALUES (?,?,?,?,?)")
                ->execute([$compra_id, $item['producto_id'], $item['cantidad'], $item['precio_unitario'], $subtotal]);

            $stmtS = $pdo->prepare("SELECT stock_actual FROM productos WHERE producto_id = ?");
            $stmtS->execute([$item['producto_id']]);
            $stockAnterior = $stmtS->fetchColumn();
            $stockNuevo    = $stockAnterior + $item['cantidad'];

            $pdo->prepare("UPDATE productos SET stock_actual = ? WHERE producto_id = ?")->execute([$stockNuevo, $item['producto_id']]);
            $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo, proveedor_id) VALUES (?,?,'Entrada',?,?,?,?,?)")
                ->execute([$item['producto_id'], $_SESSION['usuario_id'], $item['cantidad'], $stockAnterior, $stockNuevo, 'Compra a proveedor #'.$compra_id, $proveedor_id]);
        }

        header('Location: inventario_compras.php?msg=creado');
        exit();
    }
}

// Listado de compras
$busqueda = trim($_GET['buscar'] ?? '');
$fecha    = $_GET['fecha'] ?? '';

$where  = "WHERE cp.sucursal_id = ?";
$params = [$sucursalVista];
if ($busqueda) { $where .= " AND p.nombre LIKE ?"; $params[] = '%'.$busqueda.'%'; }
if ($fecha)    { $where .= " AND DATE(cp.created_at) = ?"; $params[] = $fecha; }

$stmt = $pdo->prepare("
    SELECT cp.*, p.nombre AS nombre_proveedor, u.nombre_completo AS usuario
    FROM compras_proveedor cp
    JOIN proveedores p ON cp.proveedor_id = p.proveedor_id
    JOIN usuarios u ON cp.usuario_id = u.usuario_id
    $where
    ORDER BY cp.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Datos para el formulario
$proveedores = $pdo->query("SELECT proveedor_id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("SELECT producto_id, codigo, nombre_producto, stock_actual, precio_compra FROM productos WHERE sucursal_id = ? AND activo = 1 ORDER BY nombre_producto ASC");
$stmt->execute([$sucursalVista]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['exportar']) && in_array($_GET['exportar'], ['pdf','excel'])) {
    require_once __DIR__ . '/export_helper.php';

    $expData = $pdo->query("
        SELECT cp.compras_proveedor_id, p.nombre AS proveedor, s.nombre AS sucursal,
               u.nombre_completo AS usuario, cp.total, cp.notas, cp.created_at
        FROM compras_proveedor cp
        JOIN proveedores p ON cp.proveedor_id = p.proveedor_id
        JOIN sucursales s ON cp.sucursal_id = s.sucursal_id
        JOIN usuarios u ON cp.usuario_id = u.usuario_id
        ORDER BY cp.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $titulo = 'Historial de Compras a Proveedores';
    $subtitulo = 'Generado: ' . date('d/m/Y H:i');
    $columnas = ['# Compra','Proveedor','Sucursal','Usuario','Total','Notas','Fecha'];
    $filas = array_map(fn($r) => [
        '#' . $r['compras_proveedor_id'],
        $r['proveedor'],
        $r['sucursal'],
        $r['usuario'],
        '$' . number_format($r['total'], 2),
        $r['notas'] ?? '',
        date('d/m/Y H:i', strtotime($r['created_at'])),
    ], $expData);
    $resumen = [
        ['label' => 'Total Compras', 'valor' => count($expData)],
        ['label' => 'Total Gastado', 'valor' => '$' . number_format(array_sum(array_column($expData,'total')), 2)],
    ];

    if ($_GET['exportar'] === 'pdf') exportarPDF($titulo, $subtitulo, $columnas, $filas, $resumen, 'L');
    else exportarExcel($titulo, $subtitulo, $columnas, $filas, $resumen);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compras a Proveedor — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 20px; overflow-y: auto; display: grid; grid-template-columns: 1fr 400px; gap: 16px; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 18px; margin-bottom: 14px; }
    .card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 14px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 14px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 10px 13px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 10px 13px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .btn-accion { padding: 5px 11px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-ver { background: #e3f2fd; color: #1565c0; }
    .btn-ver:hover { background: #bbdefb; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    .form-group { margin-bottom: 13px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 600; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .agregar-row { display: flex; gap: 8px; margin-bottom: 13px; }
    .agregar-row > div { flex: 2; }
    .agregar-row input[type="text"] { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 12px; }
    .agregar-row input[type="number"] { flex: 1; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 12px; }
    .agregar-row input:focus { outline: none; border-color: #14ace7; }
    .prod-search-wrap { position: relative; }
    .prod-drop { display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #e0e0e0; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,.1); z-index: 200; max-height: 200px; overflow-y: auto; margin-top: 2px; }
    .prod-drop-item { padding: 9px 13px; cursor: pointer; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; display: flex; justify-content: space-between; align-items: center; }
    .prod-drop-item:hover { background: #eef8ff; }
    .prod-drop-item:last-child { border-bottom: none; }
    .prod-chip { display: none; margin-top: 6px; background: #eef8ff; border: 1px solid #bbdefb; border-radius: 6px; padding: 7px 12px; font-size: 13px; justify-content: space-between; align-items: center; }
    .prod-chip button { background: none; border: none; color: #c0392b; cursor: pointer; font-size: 12px; font-weight: 700; }
    .btn-agregar-prod { background: #14ace7; color: white; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; white-space: nowrap; }
    .lista-compra { border: 0.5px solid #eee; border-radius: 6px; min-height: 60px; margin-bottom: 13px; overflow: hidden; }
    .compra-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; }
    .compra-item:last-child { border-bottom: none; }
    .compra-vacio { text-align: center; color: #aaa; font-size: 12px; padding: 20px; }
    .btn-quitar { background: none; border: none; color: #c0392b; cursor: pointer; font-size: 16px; }
    .total-compra { display: flex; justify-content: space-between; font-size: 15px; font-weight: 700; color: #222; padding: 10px 0; border-top: 1px solid #eee; }
    .btn-guardar { width: 100%; background: #14ace7; color: white; border: none; padding: 11px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-guardar:hover { background: #1196cb; }
    .detalle-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 200; display: flex; align-items: center; justify-content: center; }
    .detalle-modal { background: white; border-radius: 10px; width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto; padding: 24px; }
    .detalle-modal h3 { font-size: 16px; font-weight: 600; margin: 0 0 16px; }
    .detalle-fila { display: flex; justify-content: space-between; font-size: 13px; color: #555; margin-bottom: 8px; }
    .btn-cerrar-modal { background: #f0f0f0; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; margin-top: 16px; }
</style>

<?php renderAdminSidebar('inventario_compras'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Compras a proveedor</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Lista de compras -->
        <div>
            <div class="content-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                <h1 style="font-size:20px;color:#222;font-weight:600;">Compras a proveedor</h1>
                <div style="display:flex;gap:8px;">
                    <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'pdf'])) ?>" style="background:#c0392b;color:white;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">⬇ PDF</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'excel'])) ?>" style="background:#1b5e20;color:white;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">⬇ Excel</a>
                </div>
            </div>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'creado'): ?>
                <div class="msg msg-exito">Compra registrada y stock actualizado.</div>
            <?php endif; ?>

            <form method="GET">
                <div class="filtros">
                    <div class="filtro-group">
                        <label>Proveedor</label>
                        <input type="text" name="buscar" placeholder="Nombre..." value="<?= htmlspecialchars($busqueda) ?>" oninput="filtrarTabla(this.value)">
                    </div>
                    <div class="filtro-group">
                        <label>Fecha</label>
                        <input type="date" name="fecha" value="<?= htmlspecialchars($fecha) ?>">
                    </div>
                    <?php renderSucursalSwitcher(); ?>
                    <button class="btn-filtrar" type="submit">Filtrar</button>
                    <?php if ($busqueda || $fecha): ?><a class="btn-limpiar" href="inventario_compras.php">Limpiar</a><?php endif; ?>
                </div>
            </form>

            <div class="card" style="padding:0;">
                <?php if (count($compras) > 0): ?>
                <table>
                    <thead>
                        <tr><th>#</th><th>Proveedor</th><th>Total</th><th>Usuario</th><th>Fecha</th><th>Notas</th><th></th></tr>
                    </thead>
                    <tbody id="tablaFiltrable">
                        <?php foreach ($compras as $c): ?>
                        <tr>
                            <td style="color:#aaa;"><?= $c['compras_proveedor_id'] ?></td>
                            <td><strong><?= htmlspecialchars($c['nombre_proveedor']) ?></strong></td>
                            <td style="font-weight:700;color:#2e7d32;">$<?= number_format($c['total'],2) ?></td>
                            <td style="font-size:12px;"><?= htmlspecialchars($c['usuario']) ?></td>
                            <td style="font-size:12px;color:#aaa;"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></td>
                            <td style="font-size:12px;color:#888;"><?= htmlspecialchars($c['notas']??'—') ?></td>
                            <td><a class="btn-accion btn-ver" href="inventario_compras.php?ver=<?= $c['compras_proveedor_id'] ?>">Ver</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay compras registradas.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Nueva compra -->
        <div>
            <div class="card">
                <h3>Registrar compra</h3>
                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach($errores as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Proveedor *</label>
                    <input type="hidden" id="proveedorIdCompra" value="">
                    <div class="prod-search-wrap">
                        <input type="text" id="buscarProveedorCompra" placeholder="Buscar proveedor..." autocomplete="off"
                            oninput="filtrarProveedoresCompra(this.value)"
                            style="width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                        <div class="prod-drop" id="dropProveedoresCompra"></div>
                    </div>
                    <div class="prod-chip" id="proveedorChipCompra">
                        <span id="proveedorChipNombreCompra"></span>
                        <button type="button" onclick="limpiarProveedorCompra()">✕ Cambiar</button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Agregar producto</label>
                    <div class="agregar-row">
                        <div style="position:relative;">
                            <input type="text" id="buscarProdCompra" placeholder="Buscar producto..." autocomplete="off"
                                oninput="filtrarProductosCompra(this.value)">
                            <input type="hidden" id="prodCompraId" value="">
                            <input type="hidden" id="prodCompraNombre" value="">
                            <div class="prod-drop" id="dropProdCompra"></div>
                        </div>
                        <input type="number" id="cantCompra" placeholder="Cant." step="1" min="1" style="width:70px;flex:none;">
                        <input type="number" id="precioCompra" placeholder="Precio" step="0.01" min="0" style="width:80px;flex:none;">
                        <button class="btn-agregar-prod" onclick="agregarProdCompra()">+</button>
                    </div>
                </div>

                <div class="lista-compra" id="listaCompra">
                    <div class="compra-vacio" id="compraVacio">Sin productos</div>
                </div>

                <div class="total-compra" id="totalCompra" style="display:none;">
                    <span>Total</span><span id="totalCompraValor">$0.00</span>
                </div>

                <form method="POST" id="formCompra">
                    <input type="hidden" name="proveedor_id" id="inputProveedorId">
                    <input type="hidden" name="items" id="inputItemsCompra">
                    <div class="form-group" style="margin-top:12px;">
                        <label>Notas (opcional)</label>
                        <input type="text" name="notas" placeholder="Observaciones de la compra...">
                    </div>
                    <button class="btn-guardar" type="submit" onclick="return prepararCompra()">Registrar compra</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($detalle): ?>
<div class="detalle-overlay" onclick="if(event.target===this)window.location='inventario_compras.php'">
    <div class="detalle-modal">
        <h3>Compra #<?= $detalle['compras_proveedor_id'] ?></h3>
        <div class="detalle-fila"><span>Proveedor:</span><strong><?= htmlspecialchars($detalle['nombre_proveedor']) ?></strong></div>
        <div class="detalle-fila"><span>Sucursal:</span><span><?= htmlspecialchars($detalle['nombre_sucursal']) ?></span></div>
        <div class="detalle-fila"><span>Usuario:</span><span><?= htmlspecialchars($detalle['usuario']) ?></span></div>
        <div class="detalle-fila"><span>Fecha:</span><span><?= date('d/m/Y H:i', strtotime($detalle['created_at'])) ?></span></div>
        <?php if ($detalle['notas']): ?>
            <div class="detalle-fila"><span>Notas:</span><span><?= htmlspecialchars($detalle['notas']) ?></span></div>
        <?php endif; ?>
        <table style="margin-top:16px;">
            <thead><tr><th>Producto</th><th>Cant.</th><th>Precio unit.</th><th>Subtotal</th></tr></thead>
            <tbody>
                <?php foreach ($detalleProductos as $dp): ?>
                <tr>
                    <td><?= htmlspecialchars($dp['nombre_producto']) ?><div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($dp['codigo']) ?></div></td>
                    <td><?= number_format($dp['cantidad'],2) ?></td>
                    <td>$<?= number_format($dp['precio_unitario'],2) ?></td>
                    <td>$<?= number_format($dp['subtotal'],2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="text-align:right;font-size:16px;font-weight:700;margin-top:12px;">Total: $<?= number_format($detalle['total'],2) ?></div>
        <button class="btn-cerrar-modal" onclick="window.location='inventario_compras.php'">Cerrar</button>
    </div>
</div>
<?php endif; ?>

<script>
const productosData   = <?= json_encode(array_values($productos)) ?>;
const proveedoresData = <?= json_encode(array_values($proveedores)) ?>;
let itemsCompra = [];

function normalizar(str) {
    return String(str || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}
function filtrarTabla(q) {
    q = normalizar(q);
    document.querySelectorAll('#tablaFiltrable tr').forEach(function(tr) {
        tr.style.display = normalizar(tr.textContent).includes(q) ? '' : 'none';
    });
}
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

// ── Autocomplete de proveedor ────────────────────────────────────────────────
function filtrarProveedoresCompra(q) {
    const drop = document.getElementById('dropProveedoresCompra');
    if (!q.trim()) { drop.style.display = 'none'; return; }
    const norm = normalizar(q);
    const matches = proveedoresData.filter(p => normalizar(p.nombre).includes(norm)).slice(0, 20);
    drop.innerHTML = matches.length
        ? matches.map(p => '<div class="prod-drop-item" onclick="seleccionarProveedorCompra(' + p.proveedor_id + ')"><strong>' + p.nombre + '</strong></div>').join('')
        : '<div style="padding:10px 14px;color:#aaa;font-size:13px;">Sin resultados</div>';
    drop.style.display = 'block';
}
function seleccionarProveedorCompra(id) {
    const p = proveedoresData.find(x => x.proveedor_id == id);
    if (!p) return;
    document.getElementById('proveedorIdCompra').value = id;
    document.getElementById('proveedorChipNombreCompra').textContent = p.nombre;
    document.getElementById('proveedorChipCompra').style.display = 'flex';
    document.getElementById('buscarProveedorCompra').value = '';
    document.getElementById('dropProveedoresCompra').style.display = 'none';
}
function limpiarProveedorCompra() {
    document.getElementById('proveedorIdCompra').value = '';
    document.getElementById('proveedorChipCompra').style.display = 'none';
    document.getElementById('buscarProveedorCompra').value = '';
}

// ── Autocomplete de producto en compra ──────────────────────────────────────
function filtrarProductosCompra(q) {
    const drop = document.getElementById('dropProdCompra');
    if (!q.trim()) { drop.style.display = 'none'; return; }
    const norm = normalizar(q);
    const matches = productosData.filter(p =>
        normalizar(p.nombre_producto).includes(norm) || normalizar(p.codigo).includes(norm)
    ).slice(0, 25);
    drop.innerHTML = matches.length
        ? matches.map(p =>
            '<div class="prod-drop-item" onclick="seleccionarProdCompra(' + p.producto_id + ')">'
            + '<div><strong>' + p.nombre_producto + '</strong><span style="color:#aaa;font-size:11px;"> · ' + p.codigo + '</span></div>'
            + '<span style="font-size:12px;color:#888;">$' + parseFloat(p.precio_compra || 0).toFixed(2) + '</span>'
            + '</div>').join('')
        : '<div style="padding:10px 14px;color:#aaa;font-size:13px;">Sin resultados</div>';
    drop.style.display = 'block';
}
function seleccionarProdCompra(id) {
    const p = productosData.find(x => x.producto_id == id);
    if (!p) return;
    document.getElementById('prodCompraId').value     = id;
    document.getElementById('prodCompraNombre').value = p.nombre_producto;
    document.getElementById('buscarProdCompra').value = p.nombre_producto;
    document.getElementById('precioCompra').value     = p.precio_compra || '';
    document.getElementById('dropProdCompra').style.display = 'none';
    document.getElementById('cantCompra').focus();
}

function agregarProdCompra() {
    const id     = document.getElementById('prodCompraId').value;
    const nombre = document.getElementById('prodCompraNombre').value;
    const cant   = parseFloat(document.getElementById('cantCompra').value) || 0;
    const precio = parseFloat(document.getElementById('precioCompra').value) || 0;

    if (!id || cant <= 0 || precio <= 0) { alert('Completa producto, cantidad y precio.'); return; }

    const existe = itemsCompra.find(i => i.producto_id == id);
    if (existe) { existe.cantidad += cant; }
    else { itemsCompra.push({ producto_id: parseInt(id), nombre, cantidad: cant, precio_unitario: precio }); }

    document.getElementById('prodCompraId').value     = '';
    document.getElementById('prodCompraNombre').value = '';
    document.getElementById('buscarProdCompra').value = '';
    document.getElementById('cantCompra').value       = '';
    document.getElementById('precioCompra').value     = '';
    renderListaCompra();
}

function renderListaCompra() {
    const div = document.getElementById('listaCompra');
    const tot = document.getElementById('totalCompra');
    if (!itemsCompra.length) {
        div.innerHTML = '<div class="compra-vacio">Sin productos</div>';
        tot.style.display = 'none';
        return;
    }
    let total = 0;
    div.innerHTML = itemsCompra.map(function(i, idx) {
        const sub = i.cantidad * i.precio_unitario;
        total += sub;
        return '<div class="compra-item">'
            + '<div><div style="font-size:13px;">' + i.nombre + '</div>'
            + '<div style="font-size:11px;color:#aaa;">' + i.cantidad + ' × $' + i.precio_unitario.toFixed(2) + '</div></div>'
            + '<div style="display:flex;align-items:center;gap:10px;">'
            + '<span style="font-weight:700;">$' + sub.toFixed(2) + '</span>'
            + '<button class="btn-quitar" onclick="quitarProdCompra(' + idx + ')">×</button>'
            + '</div></div>';
    }).join('');
    document.getElementById('totalCompraValor').textContent = '$' + total.toFixed(2);
    tot.style.display = 'flex';
}

function quitarProdCompra(i) { itemsCompra.splice(i, 1); renderListaCompra(); }

function prepararCompra() {
    const prov = document.getElementById('proveedorIdCompra').value;
    if (!prov) { alert('Selecciona un proveedor.'); return false; }
    if (!itemsCompra.length) { alert('Agrega al menos un producto.'); return false; }
    document.getElementById('inputProveedorId').value  = prov;
    document.getElementById('inputItemsCompra').value  = JSON.stringify(itemsCompra);
    return true;
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.prod-search-wrap')) {
        document.getElementById('dropProveedoresCompra').style.display = 'none';
        document.getElementById('dropProdCompra').style.display = 'none';
    }
});
</script>
</body>
</html>


