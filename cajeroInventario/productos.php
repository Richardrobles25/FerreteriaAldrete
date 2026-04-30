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
               p.precio_venta, p.precio_mayoreo, ss.stock_actual, ss.stock_minimo,
               ss.stock_maximo, p.tipo_venta, p.descripcion, p.unidad_medida
        FROM productos p
        INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
        LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
        WHERE p.activo = 1 AND ss.activo = 1
        ORDER BY p.nombre_producto ASC
    ");
    $stmt->execute([$_SESSION['sucursal_id']]);
    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Productos');

    // Encabezados
    $headers = ['Código','Nombre','Categoría','Precio compra','Precio venta','Precio mayoreo','Stock actual','Stock mínimo','Stock máximo','Tipo venta','Descripción','Unidad de medida'];
    $sheet->fromArray($headers, null, 'A1');
    $sheet->getStyle('A1:L1')->getFont()->setBold(true);

    // Datos
    $rowIndex = 2;
    foreach ($datos as $p) {
        $sheet->fromArray([
            $p['codigo'],
            $p['nombre_producto'],
            $p['categoria'] ?? '',
            $p['precio_compra'],
            $p['precio_venta'],
            $p['precio_mayoreo'],
            $p['stock_actual'],
            $p['stock_minimo'],
            $p['stock_maximo'],
            $p['tipo_venta'],
            $p['descripcion'] ?? '',
            $p['unidad_medida'] ?? '',
        ], null, 'A' . $rowIndex);
        $rowIndex++;
    }

    // Auto ancho
    foreach (range('A','L') as $col) {
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

// Bloquear acciones de escritura si se está consultando otra sucursal
$sucursalPostConsulta = intval($_GET['sucursal_consulta'] ?? $_SESSION['sucursal_id']);
if ($sucursalPostConsulta !== intval($_SESSION['sucursal_id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: productos.php?sucursal_consulta=' . $sucursalPostConsulta);
    exit();
}

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
                $unidad_medida  = trim($row[11] ?? '');

                // Auto-crear unidad de medida si no existe en la sucursal
                if ($unidad_medida !== '') {
                    $pdo->prepare("INSERT IGNORE INTO unidades_medida (nombre, sucursal_id) VALUES (?, ?)")
                        ->execute([$unidad_medida, $_SESSION['sucursal_id']]);
                }

                // Buscar o crear categoría por nombre (sin duplicados)
                $categoria_id = null;
                if (!empty($row[2])) {
                    $nombreCat = trim($row[2]);
                    $stmtCat = $pdo->prepare("SELECT categoria_id FROM categorias WHERE nombre = ? LIMIT 1");
                    $stmtCat->execute([$nombreCat]);
                    $categoria_id = $stmtCat->fetchColumn() ?: null;
                    if (!$categoria_id) {
                        $pdo->prepare("INSERT INTO categorias (nombre) VALUES (?)")->execute([$nombreCat]);
                        $categoria_id = $pdo->lastInsertId();
                    }
                }

                // Verificar si el código ya existe en el catálogo
                $check = $pdo->prepare("SELECT producto_id FROM productos WHERE codigo = ?");
                $check->execute([$codigo]);
                $existente = $check->fetch();

                if ($existente) {
                    $producto_id = $existente['producto_id'];
                    // Actualizar catálogo compartido
                    $pdo->prepare("UPDATE productos SET nombre_producto=?, categoria_id=?, precio_compra=?, precio_venta=?, precio_mayoreo=?, tipo_venta=?, descripcion=?, unidad_medida=? WHERE producto_id=?")
                        ->execute([$nombre_producto, $categoria_id, $precio_compra, $precio_venta, $precio_mayoreo, $tipo_venta, $descripcion, $unidad_medida ?: null, $producto_id]);
                    // Crear o actualizar stock de esta sucursal
                    // Si ya existe el registro → solo actualiza mínimo/máximo (preserva stock_actual)
                    // Si no existe → lo crea con el stock_actual del Excel
                    $pdo->prepare("
                        INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo)
                        VALUES (?, ?, ?, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE
                            stock_minimo = VALUES(stock_minimo),
                            stock_maximo = VALUES(stock_maximo),
                            activo       = 1
                    ")->execute([$producto_id, $_SESSION['sucursal_id'], $stock_actual, $stock_minimo, $stock_maximo]);
                    $omitidos++;
                } else {
                    // Insertar en catálogo
                    $pdo->prepare("INSERT INTO productos (categoria_id, codigo, nombre_producto, descripcion, precio_compra, precio_venta, precio_mayoreo, tipo_venta, unidad_medida, activo) VALUES (?,?,?,?,?,?,?,?,?,1)")
                        ->execute([$categoria_id, $codigo, $nombre_producto, $descripcion, $precio_compra, $precio_venta, $precio_mayoreo, $tipo_venta, $unidad_medida ?: null]);
                    $producto_id = $pdo->lastInsertId();
                    // Insertar stock en esta sucursal
                    $pdo->prepare("INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo) VALUES (?,?,?,?,?,1)")
                        ->execute([$producto_id, $_SESSION['sucursal_id'], $stock_actual, $stock_minimo, $stock_maximo]);
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
    $headers = ['Código*','Nombre*','Categoría','Precio compra','Precio venta*','Precio mayoreo','Stock inicial','Stock mínimo','Stock máximo','Tipo venta (Unidad/Suelto)','Descripción','Unidad de medida'];
    $sheet->fromArray($headers, null, 'A1');
    $sheet->getStyle('A1:L1')->getFont()->setBold(true);
    // Fila de ejemplo
    $ejemplo = ['PROD001','Ejemplo producto','Herrería','50','100','80','10','5','100','Unidad','Descripción opcional','pieza'];
    $sheet->fromArray($ejemplo, null, 'A2');
    foreach (range('A','L') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="plantilla_productos.xlsx"');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}

// Eliminar producto con motivo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_producto'])) {
    $id     = intval($_POST['producto_id'] ?? 0);
    $motivo = trim($_POST['motivo_eliminacion'] ?? '');

    if ($id && $motivo !== '') {
        $stmtProd = $pdo->prepare("SELECT p.producto_id, ss.stock_actual FROM productos p INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ? WHERE p.producto_id = ?");
        $stmtProd->execute([$_SESSION['sucursal_id'], $id]);
        $productoEliminar = $stmtProd->fetch(PDO::FETCH_ASSOC);

        if ($productoEliminar) {
            $pdo->prepare("UPDATE stock_sucursal SET activo = 0 WHERE producto_id = ? AND sucursal_id = ?")->execute([$id, $_SESSION['sucursal_id']]);
            $pdo->prepare("
                INSERT INTO movimientos_inventario
                (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo)
                VALUES (?, ?, 'Ajuste', ?, ?, 0, ?)
            ")->execute([
                $id,
                $_SESSION['usuario_id'],
                $productoEliminar['stock_actual'],
                $productoEliminar['stock_actual'],
                'Producto eliminado: ' . $motivo
            ]);
            header('Location: productos.php?msg=eliminado');
            exit();
        }
    }

    header('Location: productos.php?msg=error_eliminar');
    exit();
}

// AJAX: productos del catálogo global que esta sucursal aún no tiene
if (isset($_GET['catalogo_disponible'])) {
    header('Content-Type: application/json');
    $busq = trim($_GET['q'] ?? '');
    $where = "WHERE p.activo = 1 AND (ss.producto_id IS NULL OR ss.activo = 0)";
    $params = [$_SESSION['sucursal_id']];
    if ($busq) { $where .= " AND (p.nombre_producto LIKE ? OR p.codigo LIKE ?)"; $params[] = '%'.$busq.'%'; $params[] = '%'.$busq.'%'; }
    $stmt = $pdo->prepare("
        SELECT p.producto_id, p.codigo, p.nombre_producto, p.precio_venta, p.precio_mayoreo,
               c.nombre AS categoria
        FROM productos p
        LEFT JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
        LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
        $where
        ORDER BY p.nombre_producto ASC
        LIMIT 80
    ");
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// POST: agregar producto del catálogo a esta sucursal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_catalogo'])) {
    $producto_id  = intval($_POST['producto_id'] ?? 0);
    $stock_actual = floatval($_POST['stock_actual'] ?? 0);
    $stock_minimo = floatval($_POST['stock_minimo'] ?? 0);
    $stock_maximo = floatval($_POST['stock_maximo'] ?? 0);

    if ($producto_id) {
        $pdo->prepare("
            INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo)
            VALUES (?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE activo = 1, stock_minimo = VALUES(stock_minimo), stock_maximo = VALUES(stock_maximo)
        ")->execute([$producto_id, $_SESSION['sucursal_id'], $stock_actual, $stock_minimo, $stock_maximo]);

        if ($stock_actual > 0) {
            $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,'Entrada',?,0,?,'Alta de producto en sucursal')")
                ->execute([$producto_id, $_SESSION['usuario_id'], $stock_actual, $stock_actual]);
        }
    }
    header('Location: productos.php?msg=agregado_catalogo');
    exit();
}

// Sucursales para consulta
$sucursalesConsulta = $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$sucursal_consulta = intval($_GET['sucursal_consulta'] ?? $_SESSION['sucursal_id']);
$idsSucursales = array_map(fn($s) => intval($s['sucursal_id']), $sucursalesConsulta);
if (!in_array($sucursal_consulta, $idsSucursales, true)) {
    $sucursal_consulta = intval($_SESSION['sucursal_id']);
}

// Filtros
$busqueda   = trim($_GET['buscar'] ?? '');
$categoria  = intval($_GET['categoria'] ?? 0);
$stock_bajo = isset($_GET['stock_bajo']);

$where  = "WHERE ss.sucursal_id = ? AND p.activo = 1 AND ss.activo = 1";
$params = [$sucursal_consulta];

if ($busqueda) { $where .= " AND (p.nombre_producto LIKE ? OR p.codigo LIKE ?)"; $params[] = '%'.$busqueda.'%'; $params[] = '%'.$busqueda.'%'; }
if ($categoria) { $where .= " AND p.categoria_id = ?"; $params[] = $categoria; }
if ($stock_bajo) { $where .= " AND ss.stock_actual <= ss.stock_minimo"; }

$stmt = $pdo->prepare("
    SELECT p.*, ss.stock_actual, ss.stock_minimo, ss.stock_maximo,
           c.nombre as nombre_categoria
    FROM productos p
    INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id
    LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
    $where
    ORDER BY ss.stock_actual <= ss.stock_minimo DESC, p.nombre_producto ASC
");
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categorias = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

$stmtBajo = $pdo->prepare("
    SELECT COUNT(*) FROM productos p
    INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
    WHERE p.activo = 1 AND ss.activo = 1 AND ss.stock_actual <= ss.stock_minimo
");
$stmtBajo->execute([$sucursal_consulta]);
$totalStockBajo = $stmtBajo->fetchColumn();

// Modo solo lectura: cuando se consulta una sucursal diferente a la del usuario
$soloLectura = ($sucursal_consulta !== intval($_SESSION['sucursal_id']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos — Ferretería Aldrete</title>
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
    /* Modal catálogo */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1000; align-items:center; justify-content:center; }
    .modal-overlay.visible { display:flex; }
    .modal { background:white; border-radius:10px; width:640px; max-width:95vw; max-height:85vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 8px 32px rgba(0,0,0,0.18); }
    .modal-header { padding:18px 20px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center; }
    .modal-header h3 { font-size:15px; font-weight:700; color:#222; margin:0; }
    .modal-close { background:none; border:none; font-size:22px; color:#aaa; cursor:pointer; line-height:1; }
    .modal-close:hover { color:#333; }
    .modal-search { padding:14px 20px; border-bottom:1px solid #f0f0f0; }
    .modal-search input { width:100%; padding:9px 12px; border:1px solid #ddd; border-radius:6px; font-size:13px; }
    .modal-search input:focus { outline:none; border-color:#14ace7; }
    .modal-lista { flex:1; overflow-y:auto; padding:8px 0; min-height:200px; }
    .cat-item { padding:12px 20px; display:flex; justify-content:space-between; align-items:center; border-bottom:0.5px solid #f5f5f5; cursor:pointer; transition:background 0.1s; }
    .cat-item:hover { background:#eef8ff; }
    .cat-item-info { flex:1; }
    .cat-item-nombre { font-size:13px; font-weight:600; color:#333; }
    .cat-item-sub { font-size:11px; color:#999; margin-top:2px; }
    .cat-item-precio { font-size:13px; font-weight:700; color:#14ace7; }
    .cat-empty { text-align:center; padding:40px; color:#aaa; font-size:13px; }
    .modal-form { padding:16px 20px; border-top:1px solid #eee; background:#f9f9f9; display:none; }
    .modal-form.visible { display:block; }
    .modal-form h4 { font-size:13px; font-weight:700; color:#333; margin:0 0 12px; }
    .stock-inputs { display:flex; gap:10px; margin-bottom:12px; }
    .stock-inputs label { display:flex; flex-direction:column; gap:4px; font-size:11px; color:#888; font-weight:600; flex:1; }
    .stock-inputs input { padding:8px 10px; border:1px solid #ddd; border-radius:6px; font-size:13px; }
    .modal-btns { display:flex; gap:8px; justify-content:flex-end; }
    .btn-cancelar-modal { background:white; color:#666; border:1px solid #ddd; padding:8px 16px; border-radius:6px; cursor:pointer; font-size:13px; }
    .btn-confirmar-modal { background:#14ace7; color:white; border:none; padding:8px 18px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:700; }
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
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.35); display: none; align-items: center; justify-content: center; padding: 20px; z-index: 999; }
    .modal-overlay.visible { display: flex; }
    .modal-card { width: 100%; max-width: 520px; background: white; border-radius: 10px; border: 1px solid #e8e8e8; box-shadow: 0 20px 45px rgba(0,0,0,0.18); padding: 22px; }
    .modal-card h3 { margin: 0 0 10px; font-size: 18px; color: #222; }
    .modal-card p { margin: 0 0 14px; font-size: 13px; color: #666; line-height: 1.45; }
    .modal-card textarea { width: 100%; min-height: 110px; resize: vertical; border: 1px solid #ddd; border-radius: 8px; padding: 12px; font-size: 13px; font-family: Arial, sans-serif; }
    .modal-card textarea:focus { outline: none; border-color: #14ace7; }
    .modal-acciones { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
    .btn-modal-cancelar { background: white; color: #666; border: 1px solid #ddd; padding: 10px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
    .btn-modal-confirmar { background: #c0392b; color: white; border: none; padding: 10px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .modal-error { color: #c0392b; font-size: 12px; margin-top: 8px; display: none; }
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
        <a class="menu-item active" href="productos.php">Productos</a>
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
            <h2>Productos</h2>
        </div>
        <div class="topbar-right">
            <span>
                <?= htmlspecialchars($_SESSION['nombre_completo']) ?>
                <span style="opacity:0.75;font-size:12px;margin-left:6px;">— <?= htmlspecialchars($nombreSucursal) ?></span>
            </span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="content">
        <div class="content-header">
            <h1>Inventario de productos</h1>
            <div class="acciones-header">
                <?php if (!$soloLectura): ?>
                <a class="btn-plantilla" href="productos.php?plantilla=1">Descargar plantilla</a>
                <button class="btn-excel-import" onclick="toggleImport()">Importar Excel</button>
                <a class="btn-excel-export" href="productos.php?exportar=1">Exportar Excel</a>
                <button class="btn-agregar" onclick="abrirModalCatalogo()">+ Agregar del catálogo</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($soloLectura): ?>
        <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;gap:10px;font-size:13px;color:#795548;">
            <span style="font-size:16px;">👁</span>
            <span>Estás viendo el inventario de <strong><?php
                foreach ($sucursalesConsulta as $s) {
                    if (intval($s['sucursal_id']) === $sucursal_consulta) { echo htmlspecialchars($s['nombre']); break; }
                }
            ?></strong>. Solo lectura — no puedes editar, agregar ni eliminar productos de esta sucursal.</span>
        </div>
        <?php endif; ?>

        <!-- Panel de importación -->
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
                    Usa la plantilla para asegurarte del formato correcto. Los productos existentes (mismo código) se actualizarán.
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
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'error_eliminar'): ?>
            <div class="msg msg-error">No se pudo eliminar el producto. Captura un motivo para dejarlo en historial.</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'agregado_catalogo'): ?>
            <div class="msg msg-exito">Producto agregado a tu sucursal correctamente.</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'solo_catalogo'): ?>
            <div class="msg msg-error" style="background:#fff8e1;color:#795548;border-left-color:#f9a825;">
                Para agregar un producto nuevo al catálogo global, contacta al administrador. Usa el botón <strong>"+ Agregar del catálogo"</strong> para activar productos ya existentes en tu sucursal.
            </div>
        <?php endif; ?>

        <form method="GET" action="productos.php">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Buscar</label>
                    <input type="text" name="buscar" placeholder="Nombre o código..." value="<?= htmlspecialchars($busqueda) ?>" oninput="filtrarTabla(this.value)" style="width:180px;">
                </div>
                <div class="filtro-group">
                    <label>Categoría</label>
                    <select name="categoria">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?= $cat['categoria_id'] ?>" <?= $categoria===$cat['categoria_id']?'selected':'' ?>>
                                <?= htmlspecialchars($cat['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Sucursal</label>
                    <select name="sucursal_consulta">
                        <?php foreach ($sucursalesConsulta as $sucursalItem): ?>
                            <option value="<?= $sucursalItem['sucursal_id'] ?>" <?= $sucursal_consulta === intval($sucursalItem['sucursal_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sucursalItem['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($stock_bajo): ?><input type="hidden" name="stock_bajo" value="1"><?php endif; ?>
                <button class="btn-filtrar" type="submit">Filtrar</button>
                <?php if ($busqueda || $categoria || $stock_bajo || $sucursal_consulta !== intval($_SESSION['sucursal_id'])): ?>
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
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Categoría</th>
                        <th>Tipo</th>
                        <th>Stock</th>
                        <th>P. Venta</th>
                        <th>P. Mayoreo</th>
                        <?php if (!$soloLectura): ?><th>Acciones</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="tablaFiltrable">
                    <?php foreach ($productos as $p):
                        $esStockBajo = $p['stock_actual'] <= $p['stock_minimo'];
                    ?>
                    <tr class="<?= $esStockBajo?'stock-bajo':'' ?>">
                        <td style="color:#aaa;font-size:12px;"><?= htmlspecialchars($p['codigo']) ?></td>
                        <td>
                            <strong><?= htmlspecialchars($p['nombre_producto']) ?></strong>
                            <?php if ($esStockBajo): ?>
                                <span style="font-size:11px;color:#c0392b;margin-left:5px;">⚠ Stock bajo</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($p['nombre_categoria']??'—') ?></td>
                        <td>
                            <span class="badge-tipo <?= $p['tipo_venta']==='Unidad'?'tipo-unidad':'tipo-suelto' ?>">
                                <?= $p['tipo_venta'] ?>
                            </span>
                        </td>
                        <td class="<?= $esStockBajo?'stock-alerta':'stock-ok' ?>">
                            <?= number_format($p['stock_actual'],2) ?>
                            <span style="font-size:11px;color:#aaa;">/ mín <?= number_format($p['stock_minimo'],2) ?></span>
                        </td>
                        <td>$<?= number_format($p['precio_venta'],2) ?></td>
                        <td>$<?= number_format($p['precio_mayoreo'],2) ?></td>
                        <?php if (!$soloLectura): ?>
                        <td>
                            <div class="acciones">
                                <a class="btn-accion btn-editar" href="formProducto.php?id=<?= $p['producto_id'] ?>">Editar</a>
                                <a class="btn-accion btn-entrada" href="entradas.php?producto_id=<?= $p['producto_id'] ?>">Entrada</a>
                                <button class="btn-accion btn-eliminar" type="button"
                                    data-id="<?= $p['producto_id'] ?>"
                                    data-nombre="<?= htmlspecialchars($p['nombre_producto'], ENT_QUOTES) ?>"
                                    onclick="confirmarEliminacion(parseInt(this.dataset.id), this.dataset.nombre)">Eliminar</button>
                            </div>
                        </td>
                        <?php endif; ?>
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

<!-- Modal: Agregar del catálogo global -->
<div class="modal-overlay" id="modalCatalogo">
    <div class="modal">
        <div class="modal-header">
            <h3>Agregar producto del catálogo</h3>
            <button class="modal-close" onclick="cerrarModalCatalogo()">&times;</button>
        </div>
        <div class="modal-search">
            <input type="text" id="searchCatalogo" placeholder="Buscar por nombre o código..." oninput="buscarCatalogo(this.value)">
        </div>
        <div class="modal-lista" id="listaCatalogo">
            <div class="cat-empty">Cargando productos...</div>
        </div>
        <div class="modal-form" id="formCatalogo">
            <h4 id="nombreProductoSeleccionado"></h4>
            <div class="stock-inputs">
                <label>Stock inicial<input type="number" id="inputStockActual" value="0" min="0" step="0.01"></label>
                <label>Stock mínimo<input type="number" id="inputStockMinimo" value="0" min="0" step="0.01"></label>
                <label>Stock máximo<input type="number" id="inputStockMaximo" value="0" min="0" step="0.01"></label>
            </div>
            <div class="modal-btns">
                <button class="btn-cancelar-modal" onclick="cancelarSeleccion()">Cancelar</button>
                <button class="btn-confirmar-modal" onclick="confirmarAgregarCatalogo()">Agregar a mi sucursal</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="formAgregarCatalogo" style="display:none;">
    <input type="hidden" name="agregar_catalogo" value="1">
    <input type="hidden" name="producto_id" id="inputCatalogoProductoId">
    <input type="hidden" name="stock_actual" id="inputCatalogoStockActual">
    <input type="hidden" name="stock_minimo" id="inputCatalogoStockMinimo">
    <input type="hidden" name="stock_maximo" id="inputCatalogoStockMaximo">
</form>

<form method="POST" id="formEliminarProducto" style="display:none;">
    <input type="hidden" name="eliminar_producto" value="1">
    <input type="hidden" name="producto_id" id="inputEliminarProductoId">
    <input type="hidden" name="motivo_eliminacion" id="inputEliminarProductoMotivo">
</form>
<div class="modal-overlay" id="modalEliminarProducto" aria-hidden="true">
    <div class="modal-card">
        <h3>Eliminar producto</h3>
        <p id="textoEliminarProducto">Se desactivará el producto seleccionado y el motivo se guardará en el historial de movimientos.</p>
        <textarea id="textareaEliminarProducto" placeholder="Escribe el motivo de la eliminación"></textarea>
        <div class="modal-error" id="errorEliminarProducto">Necesitas capturar un motivo para continuar.</div>
        <div class="modal-acciones">
            <button type="button" class="btn-modal-cancelar" onclick="cerrarModalEliminacion()">Cancelar</button>
            <button type="button" class="btn-modal-confirmar" onclick="enviarEliminacionProducto()">Eliminar producto</button>
        </div>
    </div>
</div>

<script>
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
function toggleImport() { document.getElementById('importCard').classList.toggle('visible'); }
let productoEliminarActual = null;
function confirmarEliminacion(id, nombre) {
    productoEliminarActual = { id, nombre };
    document.getElementById('textoEliminarProducto').textContent = 'Se desactivará "' + nombre + '" y se registrará el motivo en el historial de movimientos.';
    document.getElementById('textareaEliminarProducto').value = '';
    document.getElementById('errorEliminarProducto').style.display = 'none';
    document.getElementById('modalEliminarProducto').classList.add('visible');
    document.getElementById('modalEliminarProducto').setAttribute('aria-hidden', 'false');
    setTimeout(() => document.getElementById('textareaEliminarProducto').focus(), 0);
}
function cerrarModalEliminacion() {
    productoEliminarActual = null;
    document.getElementById('modalEliminarProducto').classList.remove('visible');
    document.getElementById('modalEliminarProducto').setAttribute('aria-hidden', 'true');
}
function enviarEliminacionProducto() {
    if (!productoEliminarActual) return;
    const motivo = document.getElementById('textareaEliminarProducto').value.trim();
    if (!motivo) {
        document.getElementById('errorEliminarProducto').style.display = 'block';
        document.getElementById('textareaEliminarProducto').focus();
        return;
    }
    document.getElementById('inputEliminarProductoId').value = productoEliminarActual.id;
    document.getElementById('inputEliminarProductoMotivo').value = motivo;
    cerrarModalEliminacion();
    document.getElementById('formEliminarProducto').submit();
}
document.getElementById('modalEliminarProducto').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalEliminacion();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { cerrarModalEliminacion(); cerrarModalCatalogo(); }
});

// ---- Modal catálogo global ----
let catalogoTimeout = null;
let productoSeleccionado = null;

function abrirModalCatalogo() {
    document.getElementById('modalCatalogo').classList.add('visible');
    document.getElementById('searchCatalogo').value = '';
    document.getElementById('formCatalogo').classList.remove('visible');
    productoSeleccionado = null;
    cargarCatalogo('');
    setTimeout(() => document.getElementById('searchCatalogo').focus(), 100);
}

function cerrarModalCatalogo() {
    document.getElementById('modalCatalogo').classList.remove('visible');
    productoSeleccionado = null;
}

function buscarCatalogo(q) {
    clearTimeout(catalogoTimeout);
    catalogoTimeout = setTimeout(() => cargarCatalogo(q), 300);
}

function cargarCatalogo(q) {
    const lista = document.getElementById('listaCatalogo');
    lista.innerHTML = '<div class="cat-empty">Cargando...</div>';
    fetch('productos.php?catalogo_disponible&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            if (data.length === 0) {
                lista.innerHTML = '<div class="cat-empty">No hay productos disponibles en el catálogo para agregar.<br><small>Todos los productos del catálogo ya están en tu sucursal.</small></div>';
                return;
            }
            lista.innerHTML = data.map(p => {
                const cat = p.categoria ? ' &middot; ' + p.categoria : '';
                const precio = parseFloat(p.precio_venta || 0).toFixed(2);
                return `<div class="cat-item" onclick="seleccionarProducto(${p.producto_id}, ${JSON.stringify(p.nombre_producto)})">
                    <div class="cat-item-info">
                        <div class="cat-item-nombre">${p.nombre_producto}</div>
                        <div class="cat-item-sub">${p.codigo}${cat}</div>
                    </div>
                    <div class="cat-item-precio">$${precio}</div>
                </div>`;
            }).join('');
        })
        .catch(() => {
            lista.innerHTML = '<div class="cat-empty">Error al cargar el catálogo. Intenta de nuevo.</div>';
        });
}

function seleccionarProducto(id, nombre) {
    productoSeleccionado = id;
    document.getElementById('nombreProductoSeleccionado').textContent = 'Configurar stock para: ' + nombre;
    document.getElementById('inputStockActual').value = '0';
    document.getElementById('inputStockMinimo').value = '0';
    document.getElementById('inputStockMaximo').value = '0';
    document.getElementById('formCatalogo').classList.add('visible');
    // Resaltar el item seleccionado
    document.querySelectorAll('.cat-item').forEach(el => el.style.background = '');
    event.currentTarget.style.background = '#e3f2fd';
    setTimeout(() => document.getElementById('inputStockActual').focus(), 50);
}

function cancelarSeleccion() {
    productoSeleccionado = null;
    document.getElementById('formCatalogo').classList.remove('visible');
    document.querySelectorAll('.cat-item').forEach(el => el.style.background = '');
}

function confirmarAgregarCatalogo() {
    if (!productoSeleccionado) return;
    document.getElementById('inputCatalogoProductoId').value = productoSeleccionado;
    document.getElementById('inputCatalogoStockActual').value = document.getElementById('inputStockActual').value;
    document.getElementById('inputCatalogoStockMinimo').value = document.getElementById('inputStockMinimo').value;
    document.getElementById('inputCatalogoStockMaximo').value = document.getElementById('inputStockMaximo').value;
    document.getElementById('formAgregarCatalogo').submit();
}

document.getElementById('modalCatalogo').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalCatalogo();
});
</script>
</body>
</html>
