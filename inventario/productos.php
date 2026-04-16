<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once '../vendor/autoload.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as ReaderXlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Exportar a Excel
if (isset($_GET['exportar'])) {
    $stmt = $pdo->prepare("
        SELECT p.codigo, p.nombre_producto, c.nombre as categoria, p.precio_compra,
               p.precio_venta, p.precio_mayoreo, p.stock_actual, p.stock_minimo,
               p.stock_maximo, p.tipo_venta, p.descripcion
        FROM productos p
        LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
        WHERE p.sucursal_id = ? AND p.activo = 1
        ORDER BY p.nombre_producto ASC
    ");
    $stmt->execute([$_SESSION['sucursal_id']]);
    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Productos');

    // Encabezados
    $headers = ['CÃ³digo','Nombre','CategorÃ­a','Precio compra','Precio venta','Precio mayoreo','Stock actual','Stock mÃ­nimo','Stock mÃ¡ximo','Tipo venta','DescripciÃ³n'];
    foreach ($headers as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i+1, 1, $h);
        $sheet->getStyleByColumnAndRow($i+1, 1)->getFont()->setBold(true);
    }

    // Datos
    foreach ($datos as $row => $p) {
        $sheet->setCellValueByColumnAndRow(1,  $row+2, $p['codigo']);
        $sheet->setCellValueByColumnAndRow(2,  $row+2, $p['nombre_producto']);
        $sheet->setCellValueByColumnAndRow(3,  $row+2, $p['categoria'] ?? '');
        $sheet->setCellValueByColumnAndRow(4,  $row+2, $p['precio_compra']);
        $sheet->setCellValueByColumnAndRow(5,  $row+2, $p['precio_venta']);
        $sheet->setCellValueByColumnAndRow(6,  $row+2, $p['precio_mayoreo']);
        $sheet->setCellValueByColumnAndRow(7,  $row+2, $p['stock_actual']);
        $sheet->setCellValueByColumnAndRow(8,  $row+2, $p['stock_minimo']);
        $sheet->setCellValueByColumnAndRow(9,  $row+2, $p['stock_maximo']);
        $sheet->setCellValueByColumnAndRow(10, $row+2, $p['tipo_venta']);
        $sheet->setCellValueByColumnAndRow(11, $row+2, $p['descripcion'] ?? '');
    }

    // Auto ancho
    foreach (range('A','K') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="productos_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}

// Importar desde Excel
$erroresImport = [];
$exitoImport   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_excel'])) {
    $archivo = $_FILES['archivo_excel'];
    if ($archivo['error'] === 0) {
        try {
            $reader      = IOFactory::createReaderForFile($archivo['tmp_name']);
            $spreadsheet = $reader->load($archivo['tmp_name']);
            $sheet       = $spreadsheet->getActiveSheet();
            $rows        = $sheet->toArray();

            // Saltar encabezado
            array_shift($rows);

            $importados = 0;
            $omitidos   = 0;

            foreach ($rows as $row) {
                if (empty($row[0]) || empty($row[1])) continue;

                $codigo         = trim($row[0]);
                $nombre_producto = trim($row[1]);
                $precio_compra  = floatval($row[3] ?? 0);
                $precio_venta   = floatval($row[4] ?? 0);
                $precio_mayoreo = floatval($row[5] ?? 0);
                $stock_actual   = floatval($row[6] ?? 0);
                $stock_minimo   = floatval($row[7] ?? 0);
                $stock_maximo   = floatval($row[8] ?? 0);
                $tipo_venta     = trim($row[9] ?? 'Unidad');
                $descripcion    = trim($row[10] ?? '');

                // Buscar categorÃ­a por nombre
                $categoria_id = null;
                if (!empty($row[2])) {
                    $stmtCat = $pdo->prepare("SELECT categoria_id FROM categorias WHERE nombre = ? LIMIT 1");
                    $stmtCat->execute([trim($row[2])]);
                    $cat = $stmtCat->fetchColumn();
                    if ($cat) $categoria_id = $cat;
                }

                // Verificar si el cÃ³digo ya existe
                $check = $pdo->prepare("SELECT producto_id FROM productos WHERE codigo = ? AND sucursal_id = ?");
                $check->execute([$codigo, $_SESSION['sucursal_id']]);

                if ($check->fetch()) {
                    // Actualizar
                    $pdo->prepare("UPDATE productos SET nombre_producto=?, categoria_id=?, precio_compra=?, precio_venta=?, precio_mayoreo=?, stock_minimo=?, stock_maximo=?, tipo_venta=?, descripcion=? WHERE codigo=? AND sucursal_id=?")
                        ->execute([$nombre_producto, $categoria_id, $precio_compra, $precio_venta, $precio_mayoreo, $stock_minimo, $stock_maximo, $tipo_venta, $descripcion, $codigo, $_SESSION['sucursal_id']]);
                    $omitidos++;
                } else {
                    // Insertar
                    $pdo->prepare("INSERT INTO productos (sucursal_id, categoria_id, codigo, nombre_producto, descripcion, precio_compra, precio_venta, precio_mayoreo, stock_actual, stock_minimo, stock_maximo, tipo_venta, activo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)")
                        ->execute([$_SESSION['sucursal_id'], $categoria_id, $codigo, $nombre_producto, $descripcion, $precio_compra, $precio_venta, $precio_mayoreo, $stock_actual, $stock_minimo, $stock_maximo, $tipo_venta]);
                    $importados++;
                }
            }

            $exitoImport = "$importados producto(s) importados, $omitidos actualizado(s).";
        } catch (Exception $e) {
            $erroresImport[] = 'Error al leer el archivo: ' . $e->getMessage();
        }
    } else {
        $erroresImport[] = 'Error al subir el archivo.';
    }
}

// Descargar plantilla
if (isset($_GET['plantilla'])) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Plantilla');
    $headers = ['CÃ³digo*','Nombre*','CategorÃ­a','Precio compra','Precio venta*','Precio mayoreo','Stock inicial','Stock mÃ­nimo','Stock mÃ¡ximo','Tipo venta (Unidad/Suelto)','DescripciÃ³n'];
    foreach ($headers as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i+1, 1, $h);
        $sheet->getStyleByColumnAndRow($i+1, 1)->getFont()->setBold(true);
    }
    // Fila de ejemplo
    $ejemplo = ['PROD001','Ejemplo producto','HerrerÃ­a','50','100','80','10','5','100','Unidad','DescripciÃ³n opcional'];
    foreach ($ejemplo as $i => $v) {
        $sheet->setCellValueByColumnAndRow($i+1, 2, $v);
    }
    foreach (range('A','K') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="plantilla_productos.xlsx"');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}

// Eliminar producto
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $pdo->prepare("UPDATE productos SET activo = 0 WHERE producto_id = ? AND sucursal_id = ?")->execute([$id, $_SESSION['sucursal_id']]);
    header('Location: productos.php?msg=eliminado');
    exit();
}

// Filtros
$busqueda   = trim($_GET['buscar'] ?? '');
$categoria  = intval($_GET['categoria'] ?? 0);
$stock_bajo = isset($_GET['stock_bajo']);

$where  = "WHERE p.sucursal_id = ? AND p.activo = 1";
$params = [$_SESSION['sucursal_id']];

if ($busqueda) { $where .= " AND (p.nombre_producto LIKE ? OR p.codigo LIKE ?)"; $params[] = '%'.$busqueda.'%'; $params[] = '%'.$busqueda.'%'; }
if ($categoria) { $where .= " AND p.categoria_id = ?"; $params[] = $categoria; }
if ($stock_bajo) { $where .= " AND p.stock_actual <= p.stock_minimo"; }

$stmt = $pdo->prepare("SELECT p.*, c.nombre as nombre_categoria FROM productos p LEFT JOIN categorias c ON p.categoria_id = c.categoria_id $where ORDER BY p.stock_actual <= p.stock_minimo DESC, p.nombre_producto ASC");
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categorias = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

$stmtBajo = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE sucursal_id = ? AND activo = 1 AND stock_actual <= stock_minimo");
$stmtBajo->execute([$_SESSION['sucursal_id']]);
$totalStockBajo = $stmtBajo->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos â€” FerreterÃ­a Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; }
    .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .content-header h1 { font-size: 20px; color: #222; font-weight: 600; }
    .acciones-header { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .btn-agregar { background: #14ace7; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-agregar:hover { background: #1196cb; }
    .btn-excel-export { background: #1b5e20; color: white; border: none; padding: 9px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-excel-export:hover { background: #145214; }
    .btn-excel-import { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; padding: 9px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-excel-import:hover { background: #c8e6c9; }
    .btn-plantilla { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; padding: 9px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-plantilla:hover { background: #bbdefb; }
    .import-card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 16px; margin-bottom: 16px; display: none; }
    .import-card.visible { display: block; }
    .import-card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 12px; }
    .import-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .import-form input[type=file] { flex: 1; padding: 8px; border: 1px dashed #ddd; border-radius: 6px; font-size: 13px; }
    .btn-subir { background: #14ace7; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .btn-stock-bajo { background: #fdecea; color: #c0392b; border: 1px solid #ffcdd2; padding: 9px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-stock-bajo.activo { background: #c0392b; color: white; border-color: #c0392b; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .msg-error { background: #fdecea; color: #c0392b; border-left: 3px solid #c0392b; }
    .alerta-stock { background: #fdecea; border: 1px solid #ffcdd2; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; font-size: 13px; color: #c0392b; display: flex; justify-content: space-between; align-items: center; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    tr.stock-bajo td { background: #fff8f8; }
    tr.stock-bajo:hover td { background: #fdecea; }
    .badge-tipo { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .tipo-unidad { background: #e3f2fd; color: #1565c0; }
    .tipo-suelto { background: #f3e5f5; color: #6a1b9a; }
    .stock-alerta { color: #c0392b; font-weight: 700; }
    .stock-ok { color: #2e7d32; }
    .acciones { display: flex; gap: 6px; flex-wrap: wrap; }
    .btn-accion { padding: 5px 11px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #e3f2fd; color: #1565c0; }
    .btn-editar:hover { background: #bbdefb; }
    .btn-entrada { background: #e8f5e9; color: #2e7d32; }
    .btn-entrada:hover { background: #c8e6c9; }
    .btn-eliminar { background: #fdecea; color: #c0392b; }
    .btn-eliminar:hover { background: #ffcdd2; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>FerreterÃ­a Aldrete</h3>
        <p><?= $_SESSION['rol'] ?></p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item" href="inicioInventario.php">Inicio</a>
        <a class="menu-item active" href="productos.php">Productos</a>
        <a class="menu-item" href="categorias.php">CategorÃ­as</a>
        <div class="divider"></div>
        <a class="menu-item" href="entradas.php">Entradas de productos</a>
        <a class="menu-item" href="salidas.php">Salidas y mermas</a>
        <a class="menu-item" href="historial.php">Historial de movimientos</a>
        <div class="divider"></div>
        <a class="menu-item" href="proveedores.php">Proveedores</a>
        <a class="menu-item" href="compras.php">Compras a proveedor</a>
        <div class="divider"></div>
        <a class="menu-item" href="paquetes.php">Paquetes</a>
        <a class="menu-item" href="transferencias.php">Transferencias</a>
        <a class="menu-item" href="masVendidos.php">MÃ¡s vendidos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Productos</h2>
        </div>
        <div class="topbar-right">
            <span>
                <?= htmlspecialchars($_SESSION['nombre_completo']) ?>
                <span style="opacity:0.75;font-size:12px;margin-left:6px;">â€” <?= htmlspecialchars($nombreSucursal) ?></span>
            </span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesiÃ³n</button>
            </form>
        </div>
    </div>

    <div class="content">
        <div class="content-header">
            <h1>Inventario de productos</h1>
            <div class="acciones-header">
                <a class="btn-plantilla" href="productos.php?plantilla=1">Descargar plantilla</a>
                <button class="btn-excel-import" onclick="toggleImport()">Importar Excel</button>
                <a class="btn-excel-export" href="productos.php?exportar=1">Exportar Excel</a>
                <a class="btn-agregar" href="formProducto.php">+ Agregar producto</a>
            </div>
        </div>

        <!-- Panel de importaciÃ³n -->
        <div class="import-card" id="importCard">
            <h3>Importar productos desde Excel</h3>
            <?php if (!empty($erroresImport)): ?>
                <div class="msg msg-error"><?= htmlspecialchars($erroresImport[0]) ?></div>
            <?php endif; ?>
            <?php if ($exitoImport): ?>
                <div class="msg msg-exito"><?= $exitoImport ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="import-form">
                    <input type="file" name="archivo_excel" accept=".xlsx,.xls" required>
                    <button class="btn-subir" type="submit">Importar</button>
                </div>
                <p style="font-size:12px;color:#aaa;margin-top:8px;">
                    Usa la plantilla para asegurarte del formato correcto. Los productos existentes (mismo cÃ³digo) se actualizarÃ¡n.
                </p>
            </form>
        </div>

        <?php if ($totalStockBajo > 0 && !$stock_bajo): ?>
            <div class="alerta-stock">
                <span>Hay <strong><?= $totalStockBajo ?></strong> producto(s) con stock bajo.</span>
                <a href="productos.php?stock_bajo=1" style="color:#c0392b;font-weight:700;">Ver</a>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'eliminado'): ?>
            <div class="msg msg-exito">Producto eliminado correctamente.</div>
        <?php endif; ?>

        <form method="GET" action="productos.php">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Buscar</label>
                    <input type="text" name="buscar" placeholder="Nombre o cÃ³digo..." value="<?= htmlspecialchars($busqueda) ?>" style="width:180px;">
                </div>
                <div class="filtro-group">
                    <label>CategorÃ­a</label>
                    <select name="categoria">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?= $cat['categoria_id'] ?>" <?= $categoria===$cat['categoria_id']?'selected':'' ?>>
                                <?= htmlspecialchars($cat['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($stock_bajo): ?><input type="hidden" name="stock_bajo" value="1"><?php endif; ?>
                <button class="btn-filtrar" type="submit">Filtrar</button>
                <?php if ($busqueda || $categoria || $stock_bajo): ?>
                    <a class="btn-limpiar" href="productos.php">Limpiar</a>
                <?php endif; ?>
                <a class="btn-stock-bajo <?= $stock_bajo?'activo':'' ?>" href="productos.php?stock_bajo=1">
                    Stock bajo (<?= $totalStockBajo ?>)
                </a>
            </div>
        </form>

        <div class="tabla-wrapper">
            <?php if (count($productos) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>CÃ³digo</th>
                        <th>Nombre</th>
                        <th>CategorÃ­a</th>
                        <th>Tipo</th>
                        <th>Stock</th>
                        <th>P. Venta</th>
                        <th>P. Mayoreo</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productos as $p):
                        $esStockBajo = $p['stock_actual'] <= $p['stock_minimo'];
                    ?>
                    <tr class="<?= $esStockBajo?'stock-bajo':'' ?>">
                        <td style="color:#aaa;font-size:12px;"><?= htmlspecialchars($p['codigo']) ?></td>
                        <td>
                            <strong><?= htmlspecialchars($p['nombre_producto']) ?></strong>
                            <?php if ($esStockBajo): ?>
                                <span style="font-size:11px;color:#c0392b;margin-left:5px;">âš  Stock bajo</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($p['nombre_categoria']??'â€”') ?></td>
                        <td>
                            <span class="badge-tipo <?= $p['tipo_venta']==='Unidad'?'tipo-unidad':'tipo-suelto' ?>">
                                <?= $p['tipo_venta'] ?>
                            </span>
                        </td>
                        <td class="<?= $esStockBajo?'stock-alerta':'stock-ok' ?>">
                            <?= number_format($p['stock_actual'],2) ?>
                            <span style="font-size:11px;color:#aaa;">/ mÃ­n <?= number_format($p['stock_minimo'],2) ?></span>
                        </td>
                        <td>$<?= number_format($p['precio_venta'],2) ?></td>
                        <td>$<?= number_format($p['precio_mayoreo'],2) ?></td>
                        <td>
                            <div class="acciones">
                                <a class="btn-accion btn-editar" href="formProducto.php?id=<?= $p['producto_id'] ?>">Editar</a>
                                <a class="btn-accion btn-entrada" href="entradas.php?producto_id=<?= $p['producto_id'] ?>">Entrada</a>
                                <a class="btn-accion btn-eliminar" href="productos.php?eliminar=<?= $p['producto_id'] ?>" onclick="return confirm('Â¿Eliminar este producto?')">Eliminar</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="sin-resultados">No se encontraron productos.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
function toggleImport() { document.getElementById('importCard').classList.toggle('visible'); }
</script>
</body>
</html>

