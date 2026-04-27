<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
require_once '../vendor/autoload.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);
require_once __DIR__ . '/_admin_sucursal_filtro.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as ReaderXlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Exportar PDF
if (isset($_GET['exportar']) && $_GET['exportar'] === 'pdf') {
    require_once __DIR__ . '/export_helper.php';
    $esAdmin   = $_SESSION['rol'] === 'Administrador';
    $vistaGlobal = $esAdmin && $sucursalVista === 0;

    $sqlWhere  = $vistaGlobal ? "WHERE p.activo = 1"                        : "WHERE p.sucursal_id = ? AND p.activo = 1";
    $sqlJoinS  = $vistaGlobal ? "JOIN sucursales s ON s.sucursal_id = p.sucursal_id" : "";
    $sqlSelS   = $vistaGlobal ? ", s.nombre AS sucursal_nombre"              : "";
    $sqlSelC   = $esAdmin     ? ", p.precio_compra"                          : "";
    $paramsPdf = $vistaGlobal ? [] : [$sucursalVista];

    $stmt = $pdo->prepare("
        SELECT p.codigo, p.nombre_producto, c.nombre as categoria{$sqlSelC},
               p.precio_venta, p.precio_mayoreo, p.stock_actual, p.stock_minimo, p.tipo_venta,
               p.unidad_medida{$sqlSelS}
        FROM productos p
        LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
        {$sqlJoinS}
        {$sqlWhere}
        ORDER BY " . ($vistaGlobal ? "s.nombre ASC, " : "") . "p.nombre_producto ASC
    ");
    $stmt->execute($paramsPdf);
    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($esAdmin) {
        $columnas = ['Código','Nombre','Categoría','P. Compra','P. Venta','P. Mayoreo','Stock','Mín.','Tipo','Unidad'];
        if ($vistaGlobal) $columnas[] = 'Sucursal';
        $filas = array_map(function($p) use ($vistaGlobal) {
            $row = [
                $p['codigo'], $p['nombre_producto'], $p['categoria'] ?? '—',
                '$' . number_format($p['precio_compra'], 2),
                '$' . number_format($p['precio_venta'], 2),
                '$' . number_format($p['precio_mayoreo'], 2),
                $p['stock_actual'], $p['stock_minimo'], $p['tipo_venta'], $p['unidad_medida'] ?? '—',
            ];
            if ($vistaGlobal) $row[] = $p['sucursal_nombre'] ?? '—';
            return $row;
        }, $datos);
    } else {
        $columnas = ['Código','Nombre','Categoría','P. Venta','P. Mayoreo','Stock','Mín.','Tipo','Unidad'];
        $filas = array_map(fn($p) => [
            $p['codigo'], $p['nombre_producto'], $p['categoria'] ?? '—',
            '$' . number_format($p['precio_venta'], 2),
            '$' . number_format($p['precio_mayoreo'], 2),
            $p['stock_actual'], $p['stock_minimo'], $p['tipo_venta'], $p['unidad_medida'] ?? '—',
        ], $datos);
    }

    $titulo    = $vistaGlobal ? 'Inventario Global de Productos' : 'Inventario de Productos';
    $subtitulo = $vistaGlobal ? 'Todas las sucursales' : 'Productos activos — ' . $nombreSucursalVista;
    $resumen   = [['label' => 'Total Productos', 'valor' => count($datos)]];
    exportarPDF($titulo, $subtitulo, $columnas, $filas, $resumen, 'L');
}

// Exportar a Excel (formato limpio para reimportación)
if (isset($_GET['exportar']) && $_GET['exportar'] === 'excel') {
    $esAdmin     = $_SESSION['rol'] === 'Administrador';
    $vistaGlobal = $esAdmin && $sucursalVista === 0;

    $sqlWhere   = $vistaGlobal ? "WHERE p.activo = 1"                          : "WHERE p.sucursal_id = ? AND p.activo = 1";
    $sqlJoinS   = $vistaGlobal ? "JOIN sucursales s ON s.sucursal_id = p.sucursal_id" : "";
    $sqlSelS    = $vistaGlobal ? ", s.nombre AS sucursal_nombre"                : "";
    $sqlSelC    = $esAdmin     ? ", p.precio_compra"                            : "";
    $paramsXls  = $vistaGlobal ? [] : [$sucursalVista];

    $stmt = $pdo->prepare("
        SELECT p.codigo, p.nombre_producto, c.nombre as categoria{$sqlSelC},
               p.precio_venta, p.precio_mayoreo, p.stock_actual, p.stock_minimo,
               p.stock_maximo, p.tipo_venta, p.descripcion, p.unidad_medida{$sqlSelS}
        FROM productos p
        LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
        {$sqlJoinS}
        {$sqlWhere}
        ORDER BY " . ($vistaGlobal ? "s.nombre ASC, " : "") . "p.nombre_producto ASC
    ");
    $stmt->execute($paramsXls);
    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Productos');

    // Cabeceras según rol y vista
    if ($esAdmin) {
        $headers = ['Código','Nombre','Categoría','Precio compra','Precio venta','Precio mayoreo','Stock actual','Stock mínimo','Stock máximo','Tipo venta','Descripción','Unidad de medida'];
    } else {
        $headers = ['Código','Nombre','Categoría','Precio venta','Precio mayoreo','Stock actual','Stock mínimo','Stock máximo','Tipo venta','Descripción','Unidad de medida'];
    }
    if ($vistaGlobal) $headers[] = 'Sucursal';

    foreach ($headers as $i => $h) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue("{$col}1", $h);
        $sheet->getStyle("{$col}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF14ace7']],
        ]);
    }

    foreach ($datos as $r => $p) {
        $fila = $r + 2;
        if ($esAdmin) {
            $vals = [$p['codigo'], $p['nombre_producto'], $p['categoria'] ?? '', $p['precio_compra'], $p['precio_venta'], $p['precio_mayoreo'], $p['stock_actual'], $p['stock_minimo'], $p['stock_maximo'], $p['tipo_venta'], $p['descripcion'] ?? '', $p['unidad_medida'] ?? ''];
        } else {
            $vals = [$p['codigo'], $p['nombre_producto'], $p['categoria'] ?? '', $p['precio_venta'], $p['precio_mayoreo'], $p['stock_actual'], $p['stock_minimo'], $p['stock_maximo'], $p['tipo_venta'], $p['descripcion'] ?? '', $p['unidad_medida'] ?? ''];
        }
        if ($vistaGlobal) $vals[] = $p['sucursal_nombre'] ?? '';
        foreach ($vals as $i => $v) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}{$fila}", $v);
        }
    }

    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="productos_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit();
}

// Importar desde Excel
$erroresImport = [];
$exitoImport   = false;

// Normaliza un texto de encabezado para comparación
function normalizarEncabezado(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]/', '', $s);
    return $s;
}

// Detecta a qué campo interno corresponde un encabezado (acepta plantilla nueva y formato del sistema anterior)
function detectarCampo(string $header): ?string {
    $h = normalizarEncabezado($header);
    // Orden importante: patrones más específicos primero
    $mapa = [
        'codigo'        => 'codigo',
        'preciocosto'   => 'precio_compra',
        'preciocompra'  => 'precio_compra',
        'precioventa'   => 'precio_venta',
        'preciomayoreo' => 'precio_mayoreo',
        'departamento'  => 'categoria',
        'categoria'     => 'categoria',
        'stockactual'   => 'stock_actual',
        'existencia'    => 'stock_actual',
        'stockinicial'  => 'stock_actual',
        'invminimo'     => 'stock_minimo',
        'stockminimo'   => 'stock_minimo',
        'invmaximo'     => 'stock_maximo',
        'stockmaximo'   => 'stock_maximo',
        'tipoventa'     => 'tipo_venta',
        'tipodevent'    => 'tipo_venta',
        'descripcion'   => 'descripcion',
        'nombre'        => 'nombre',
        'unidadmedida'  => 'unidad_medida',
        'unidad'        => 'unidad_medida',
    ];
    foreach ($mapa as $patron => $campo) {
        if (str_contains($h, $patron)) return $campo;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_excel'])) {
    set_time_limit(300); // 5 minutos para importaciones grandes
    $archivo = $_FILES['archivo_excel'];
    if ($archivo['error'] === 0) {
        try {
            $reader      = IOFactory::createReaderForFile($archivo['tmp_name']);
            $spreadsheet = $reader->load($archivo['tmp_name']);
            $sheet       = $spreadsheet->getActiveSheet();
            $rows        = $sheet->toArray();

            if (empty($rows)) throw new Exception('El archivo está vacío.');

            // Construir mapa de columna → campo a partir del encabezado
            $headerRow = $rows[0];
            $colMap = [];
            foreach ($headerRow as $idx => $header) {
                if ($header === null || $header === '') continue;
                $campo = detectarCampo((string)$header);
                if ($campo && !isset($colMap[$campo])) {
                    $colMap[$campo] = $idx;
                }
            }

            // Compatibilidad sistema anterior: "Descripcion" (col B) era el nombre del producto
            if (!isset($colMap['nombre']) && isset($colMap['descripcion'])) {
                $colMap['nombre'] = $colMap['descripcion'];
                unset($colMap['descripcion']);
            }

            if (!isset($colMap['codigo']) || !isset($colMap['nombre'])) {
                throw new Exception('No se encontraron las columnas Código y Nombre en el archivo. Revisa que la primera fila sea el encabezado.');
            }

            // Desactivar modo estricto solo para esta sesión para evitar errores en valores ENUM no exactos
            $pdo->exec("SET SESSION sql_mode = ''");
            $pdo->beginTransaction();

            $importados = 0;
            $omitidos   = 0;
            $categoriasCache  = []; // evita SELECT repetido para misma categoría
            $categoriasCreadas = [];
            $unidadesCache    = []; // evita INSERT repetido para misma unidad
            $unidadesCreadas  = [];

            $filasConError = [];

            // Iterar desde fila 2 (índice 1)
            for ($i = 1; $i < count($rows); $i++) {
                $row    = $rows[$i];
                $fila   = $i + 1; // número de fila real en Excel (base 1, +1 por encabezado)

                $codigo = trim($row[$colMap['codigo']] ?? '');
                $nombre = trim($row[$colMap['nombre']] ?? '');
                if ($codigo === '' || $nombre === '') continue;

                $precio_compra  = floatval($row[$colMap['precio_compra']  ?? -1] ?? 0);
                $precio_venta   = floatval($row[$colMap['precio_venta']   ?? -1] ?? 0);
                $precio_mayoreo = floatval($row[$colMap['precio_mayoreo'] ?? -1] ?? 0);
                $stock_actual   = floatval($row[$colMap['stock_actual']   ?? -1] ?? 0);
                $stock_minimo   = floatval($row[$colMap['stock_minimo']   ?? -1] ?? 0);
                $stock_maximo   = floatval($row[$colMap['stock_maximo']   ?? -1] ?? 0);
                $tv = strtolower(trim($row[$colMap['tipo_venta'] ?? -1] ?? ''));
                $tipo_venta = str_contains($tv, 'suelto') || str_contains($tv, 'granel') || str_contains($tv, 'kg') || str_contains($tv, 'gr') ? 'Suelto' : 'Unidad';
                $descripcion    = trim($row[$colMap['descripcion']        ?? -1] ?? '');
                $unidad_medida  = trim($row[$colMap['unidad_medida']      ?? -1] ?? '');

                // Importar siempre a la sucursal que el admin tiene seleccionada
                // (si está en vista global, se usa la sucursal propia del usuario)
                $sucursalImport = ($sucursalVista > 0) ? $sucursalVista : intval($_SESSION['sucursal_id']);

                try {
                    // Auto-crear unidad de medida si no existe en la sucursal (con caché para no repetir INSERT)
                    if ($unidad_medida !== '' && !isset($unidadesCache[$unidad_medida])) {
                        $checkUnd = $pdo->prepare("SELECT unidad_id FROM unidades_medida WHERE nombre = ? AND sucursal_id = ? LIMIT 1");
                        $checkUnd->execute([$unidad_medida, $sucursalImport]);
                        if (!$checkUnd->fetchColumn()) {
                            $pdo->prepare("INSERT INTO unidades_medida (nombre, sucursal_id) VALUES (?, ?)")
                                ->execute([$unidad_medida, $sucursalImport]);
                            $unidadesCreadas[] = $unidad_medida;
                        }
                        $unidadesCache[$unidad_medida] = true;
                    }

                    // Categoría: buscar en caché, luego en BD, luego crear si no existe
                    $categoria_id = null;
                    $nombreCat = isset($colMap['categoria']) ? trim($row[$colMap['categoria']] ?? '') : '';
                    if ($nombreCat !== '') {
                        if (isset($categoriasCache[$nombreCat])) {
                            $categoria_id = $categoriasCache[$nombreCat];
                        } else {
                            $stmtCat = $pdo->prepare("SELECT categoria_id FROM categorias WHERE nombre = ? LIMIT 1");
                            $stmtCat->execute([$nombreCat]);
                            $cat = $stmtCat->fetchColumn();
                            if ($cat) {
                                $categoria_id = (int)$cat;
                            } else {
                                // Crear categoría nueva automáticamente
                                $pdo->prepare("INSERT INTO categorias (nombre) VALUES (?)")->execute([$nombreCat]);
                                $categoria_id = (int)$pdo->lastInsertId();
                                if ($categoria_id) $categoriasCreadas[] = $nombreCat;
                            }
                            if ($categoria_id) $categoriasCache[$nombreCat] = $categoria_id;
                        }
                    }

                    $check = $pdo->prepare("SELECT producto_id FROM productos WHERE codigo = ? AND sucursal_id = ?");
                    $check->execute([$codigo, $sucursalImport]);

                    if ($check->fetch()) {
                        if ($categoria_id !== null) {
                            $pdo->prepare("UPDATE productos SET nombre_producto=?, categoria_id=?, precio_compra=?, precio_venta=?, precio_mayoreo=?, stock_minimo=?, stock_maximo=?, tipo_venta=?, descripcion=?, unidad_medida=? WHERE codigo=? AND sucursal_id=?")
                                ->execute([$nombre, $categoria_id, $precio_compra, $precio_venta, $precio_mayoreo, $stock_minimo, $stock_maximo, $tipo_venta, $descripcion, $unidad_medida ?: null, $codigo, $sucursalImport]);
                        } else {
                            $pdo->prepare("UPDATE productos SET nombre_producto=?, precio_compra=?, precio_venta=?, precio_mayoreo=?, stock_minimo=?, stock_maximo=?, tipo_venta=?, descripcion=?, unidad_medida=? WHERE codigo=? AND sucursal_id=?")
                                ->execute([$nombre, $precio_compra, $precio_venta, $precio_mayoreo, $stock_minimo, $stock_maximo, $tipo_venta, $descripcion, $unidad_medida ?: null, $codigo, $sucursalImport]);
                        }
                        $omitidos++;
                    } else {
                        $pdo->prepare("INSERT INTO productos (sucursal_id, categoria_id, codigo, nombre_producto, descripcion, precio_compra, precio_venta, precio_mayoreo, stock_actual, stock_minimo, stock_maximo, tipo_venta, unidad_medida, activo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)")
                            ->execute([$sucursalImport, $categoria_id, $codigo, $nombre, $descripcion, $precio_compra, $precio_venta, $precio_mayoreo, $stock_actual, $stock_minimo, $stock_maximo, $tipo_venta, $unidad_medida ?: null]);
                        $importados++;
                    }
                } catch (Exception $eRow) {
                    $filasConError[] = "Fila $fila (\"$codigo\" — $nombre): " . $eRow->getMessage();
                }
            }

            $pdo->commit();
            $exitoImport = "$importados producto(s) importados, $omitidos actualizado(s).";
            if ($categoriasCreadas) $exitoImport .= ' Categorías nuevas: ' . implode(', ', array_unique($categoriasCreadas)) . '.';
            if ($unidadesCreadas)   $exitoImport .= ' Unidades nuevas: ' . implode(', ', array_unique($unidadesCreadas)) . '.';
            if (!isset($colMap['categoria'])) $exitoImport .= ' (El archivo no tiene columna de Categoría)';
            if ($filasConError) $erroresImport = array_merge($erroresImport, $filasConError);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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
    foreach ($headers as $i => $h) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue("{$col}1", $h);
        $sheet->getStyle("{$col}1")->getFont()->setBold(true);
    }
    // Fila de ejemplo
    $ejemplo = ['PROD001','Ejemplo producto','Herrería','50','100','80','10','5','100','Unidad','Descripción opcional','pieza'];
    foreach ($ejemplo as $i => $v) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue("{$col}2", $v);
    }
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
        $stmtProd = $pdo->prepare("SELECT producto_id, stock_actual FROM productos WHERE producto_id = ? AND sucursal_id = ?");
        $stmtProd->execute([$id, $sucursalVista]);
        $productoEliminar = $stmtProd->fetch(PDO::FETCH_ASSOC);

        if ($productoEliminar) {
            $pdo->prepare("UPDATE productos SET activo = 0 WHERE producto_id = ? AND sucursal_id = ?")->execute([$id, $sucursalVista]);
            $pdo->prepare("
                INSERT INTO movimientos_inventario
                (producto_id, usuario_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo)
                VALUES (?, ?, 'Ajuste', 0, ?, ?, ?)
            ")->execute([
                $id,
                $_SESSION['usuario_id'],
                $productoEliminar['stock_actual'],
                $productoEliminar['stock_actual'],
                'Producto eliminado: ' . $motivo
            ]);
            header('Location: inventario_productos.php?msg=eliminado');
            exit();
        }
    }

    header('Location: inventario_productos.php?msg=error_eliminar');
    exit();
}

// Filtros
$busqueda    = trim($_GET['buscar'] ?? '');
$categoria   = intval($_GET['categoria'] ?? 0);
$stock_bajo  = isset($_GET['stock_bajo']);
$esAdmin     = $_SESSION['rol'] === 'Administrador';
$vistaGlobal = $esAdmin && $sucursalVista === 0;

if ($vistaGlobal) {
    $where  = "WHERE p.activo = 1";
    $params = [];
} else {
    $where  = "WHERE p.sucursal_id = ? AND p.activo = 1";
    $params = [$sucursalVista];
}

if ($busqueda) { $where .= " AND (p.nombre_producto LIKE ? OR p.codigo LIKE ?)"; $params[] = '%'.$busqueda.'%'; $params[] = '%'.$busqueda.'%'; }
if ($categoria) { $where .= " AND p.categoria_id = ?"; $params[] = $categoria; }
if ($stock_bajo) { $where .= " AND p.stock_actual <= p.stock_minimo"; }

$joinSucursal = $vistaGlobal ? "JOIN sucursales s ON s.sucursal_id = p.sucursal_id" : "";
$selectSuc    = $vistaGlobal ? ", s.nombre AS nombre_sucursal" : "";
$orderBy      = $vistaGlobal ? "p.stock_actual <= p.stock_minimo DESC, s.nombre ASC, p.nombre_producto ASC"
                              : "p.stock_actual <= p.stock_minimo DESC, p.nombre_producto ASC";

$stmt = $pdo->prepare("SELECT p.*, c.nombre as nombre_categoria{$selectSuc} FROM productos p LEFT JOIN categorias c ON p.categoria_id = c.categoria_id {$joinSucursal} {$where} ORDER BY {$orderBy}");
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categorias = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($vistaGlobal) {
    $totalStockBajo = $pdo->query("SELECT COUNT(*) FROM productos WHERE activo = 1 AND stock_actual <= stock_minimo")->fetchColumn();
} else {
    $stmtBajo = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE sucursal_id = ? AND activo = 1 AND stock_actual <= stock_minimo");
    $stmtBajo->execute([$sucursalVista]);
    $totalStockBajo = $stmtBajo->fetchColumn();
}
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

<?php renderAdminSidebar('inventario_productos'); ?>

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
                <a class="btn-plantilla" href="inventario_productos.php?plantilla=1">Descargar plantilla</a>
                <button class="btn-excel-import" onclick="toggleImport()">Importar Excel</button>
                <a style="background:#c0392b;color:white;border:none;padding:9px 14px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;" href="inventario_productos.php?exportar=pdf">⬇ PDF</a>
                <a class="btn-excel-export" href="inventario_productos.php?exportar=excel">⬇ Excel</a>
                <a class="btn-agregar" href="inventario_formProducto.php">+ Agregar producto</a>
            </div>
        </div>

        <!-- Panel de importación -->
        <div class="import-card" id="importCard">
            <h3>Importar productos desde Excel</h3>
            <?php if ($vistaGlobal): ?>
                <div class="msg" style="background:#fff8e1;color:#e65100;border-left:3px solid #e65100;margin-bottom:10px;">
                    Estás en vista global. La importación se realizará a tu sucursal propia. Selecciona una sucursal específica para importar a otra.
                </div>
            <?php endif; ?>
            <?php if (!empty($erroresImport)): ?>
                <div class="msg msg-error">
                    <?php if (count($erroresImport) === 1): ?>
                        <?= htmlspecialchars($erroresImport[0]) ?>
                    <?php else: ?>
                        <strong><?= count($erroresImport) ?> error(es) durante la importación:</strong>
                        <ul style="margin:8px 0 0 16px;padding:0;">
                            <?php foreach ($erroresImport as $err): ?>
                                <li style="margin-bottom:4px;"><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
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
                    Usa la plantilla para asegurarte del formato correcto. Los productos existentes (mismo código) se actualizarán. Si la categoría no existe, se creará automáticamente.
                </p>
            </form>
        </div>

        <?php if ($totalStockBajo > 0 && !$stock_bajo): ?>
            <div class="alerta-stock">
                <span>Hay <strong><?= $totalStockBajo ?></strong> producto(s) con stock bajo.</span>
                <a href="inventario_productos.php?stock_bajo=1" style="color:#c0392b;font-weight:700;">Ver</a>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'eliminado'): ?>
            <div class="msg msg-exito">Producto eliminado correctamente.</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'error_eliminar'): ?>
            <div class="msg msg-error">No se pudo eliminar el producto. Captura un motivo para dejarlo en historial.</div>
        <?php endif; ?>

        <form method="GET" action="inventario_productos.php">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Buscar</label>
                    <input type="text" name="buscar" placeholder="Nombre o código..." value="<?= htmlspecialchars($busqueda) ?>" style="width:180px;" oninput="filtrarTabla(this.value)">
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
                <?php if ($stock_bajo): ?><input type="hidden" name="stock_bajo" value="1"><?php endif; ?>
                <?php renderSucursalSwitcher(); ?>
                <button class="btn-filtrar" type="submit">Filtrar</button>
                <?php if ($busqueda || $categoria || $stock_bajo): ?>
                    <a class="btn-limpiar" href="inventario_productos.php">Limpiar</a>
                <?php endif; ?>
                <a class="btn-stock-bajo <?= $stock_bajo?'activo':'' ?>" href="inventario_productos.php?stock_bajo=1">
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
                        <?php if ($vistaGlobal): ?><th>Sucursal</th><?php endif; ?>
                        <th>Tipo</th>
                        <th>Stock</th>
                        <th>P. Venta</th>
                        <th>P. Mayoreo</th>
                        <th>Acciones</th>
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
                        <?php if ($vistaGlobal): ?>
                        <td style="font-size:12px;color:#1565c0;"><?= htmlspecialchars($p['nombre_sucursal']??'—') ?></td>
                        <?php endif; ?>
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
                        <td>
                            <div class="acciones">
                                <a class="btn-accion btn-editar" href="inventario_formProducto.php?id=<?= $p['producto_id'] ?>">Editar</a>
                                <a class="btn-accion btn-entrada" href="inventario_entradas.php?producto_id=<?= $p['producto_id'] ?>">Entrada</a>
                                <button class="btn-accion btn-eliminar" type="button" onclick="confirmarEliminacion(<?= $p['producto_id'] ?>, <?= json_encode($p['nombre_producto']) ?>)">Eliminar</button>
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
function confirmarEliminacion(id, nombre) {
    const seguro = confirm('Se va a desactivar "' + nombre + '". Este movimiento se guardara en historial. ¿Deseas continuar?');
    if (!seguro) return;
    const motivo = prompt('Escribe el motivo de la eliminacion del producto:');
    if (motivo === null) return;
    if (!motivo.trim()) {
        alert('Necesitas capturar un motivo para eliminar el producto.');
        return;
    }
    document.getElementById('inputEliminarProductoId').value = id;
    document.getElementById('inputEliminarProductoMotivo').value = motivo.trim();
    document.getElementById('formEliminarProducto').submit();
}
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
function toggleImport() { document.getElementById('importCard').classList.toggle('visible'); }
<?php if ($exitoImport || !empty($erroresImport)): ?>
document.getElementById('importCard').classList.add('visible');
<?php endif; ?>
let productoEliminarActual = null;
confirmarEliminacion = function(id, nombre) {
    productoEliminarActual = { id, nombre };
    document.getElementById('textoEliminarProducto').textContent = 'Se desactivará "' + nombre + '" y se registrará el motivo en el historial de movimientos.';
    document.getElementById('textareaEliminarProducto').value = '';
    document.getElementById('errorEliminarProducto').style.display = 'none';
    document.getElementById('modalEliminarProducto').classList.add('visible');
    document.getElementById('modalEliminarProducto').setAttribute('aria-hidden', 'false');
    setTimeout(() => document.getElementById('textareaEliminarProducto').focus(), 0);
};
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
    if (e.key === 'Escape') cerrarModalEliminacion();
});
</script>
</body>
</html>


