<?php
ob_start();
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';
require_once '../vendor/autoload.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sucursal_filtro.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as ReaderXlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

// [FIX-IMPORT-02] La importación por Excel (crear/actualizar el catálogo global en bloque,
// incluye cambiar precios) es SOLO para Administrador — ni Inventario (puro) ni
// Inventario/Cajero pueden crear/editar el catálogo global. Este archivo también acepta
// esos roles en verificarRol() (por si algún día los alcanza), así que el botón y el
// backend deben bloquearlos aquí.
$puedeImportarExcel = ($_SESSION['rol'] ?? '') === 'Administrador';

// Exportar PDF
if (isset($_GET['exportar']) && $_GET['exportar'] === 'pdf') {
    require_once __DIR__ . '/export_helper.php';
    $esAdmin     = $_SESSION['rol'] === 'Administrador';
    $vistaGlobal = $esAdmin && $sucursalVista === 0;

    if ($vistaGlobal) {
        // Catálogo global: sin stock ni sucursal
        $sqlSelC = $esAdmin ? ", p.precio_compra" : "";
        $stmt = $pdo->prepare("
            SELECT p.codigo, p.nombre_producto, c.nombre as categoria{$sqlSelC},
                   p.precio_venta, p.precio_mayoreo, p.tipo_venta, p.unidad_medida
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
            WHERE p.activo = 1
            ORDER BY p.nombre_producto ASC
        ");
        $stmt->execute([]);
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($esAdmin) {
            $columnas = ['Código','Nombre','Categoría','P. Compra','P. Venta','P. Mayoreo','Tipo','Unidad'];
            $filas = array_map(fn($p) => [
                $p['codigo'], $p['nombre_producto'], $p['categoria'] ?? '—',
                '$' . number_format($p['precio_compra'], 2),
                '$' . number_format($p['precio_venta'], 2),
                '$' . number_format($p['precio_mayoreo'], 2),
                $p['tipo_venta'], $p['unidad_medida'] ?? '—',
            ], $datos);
        } else {
            $columnas = ['Código','Nombre','Categoría','P. Venta','P. Mayoreo','Tipo','Unidad'];
            $filas = array_map(fn($p) => [
                $p['codigo'], $p['nombre_producto'], $p['categoria'] ?? '—',
                '$' . number_format($p['precio_venta'], 2),
                '$' . number_format($p['precio_mayoreo'], 2),
                $p['tipo_venta'], $p['unidad_medida'] ?? '—',
            ], $datos);
        }
        $titulo    = 'Catálogo Global de Productos';
        $subtitulo = 'Todos los productos del catálogo';
    } else {
        $sqlSelC   = $esAdmin ? ", p.precio_compra" : "";
        $stmt = $pdo->prepare("
            SELECT p.codigo, p.nombre_producto, c.nombre as categoria{$sqlSelC},
                   p.precio_venta, p.precio_mayoreo, ss.stock_actual, ss.stock_minimo, p.tipo_venta, p.unidad_medida
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
            INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ? AND ss.activo = 1
            WHERE p.activo = 1
            ORDER BY p.nombre_producto ASC
        ");
        $stmt->execute([$sucursalVista]);
        $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($esAdmin) {
            $columnas = ['Código','Nombre','Categoría','P. Compra','P. Venta','P. Mayoreo','Stock','Mín.','Tipo','Unidad'];
            $filas = array_map(fn($p) => [
                $p['codigo'], $p['nombre_producto'], $p['categoria'] ?? '—',
                '$' . number_format($p['precio_compra'], 2),
                '$' . number_format($p['precio_venta'], 2),
                '$' . number_format($p['precio_mayoreo'], 2),
                $p['stock_actual'], $p['stock_minimo'], $p['tipo_venta'], $p['unidad_medida'] ?? '—',
            ], $datos);
        } else {
            $columnas = ['Código','Nombre','Categoría','P. Venta','P. Mayoreo','Stock','Mín.','Tipo','Unidad'];
            $filas = array_map(fn($p) => [
                $p['codigo'], $p['nombre_producto'], $p['categoria'] ?? '—',
                '$' . number_format($p['precio_venta'], 2),
                '$' . number_format($p['precio_mayoreo'], 2),
                $p['stock_actual'], $p['stock_minimo'], $p['tipo_venta'], $p['unidad_medida'] ?? '—',
            ], $datos);
        }
        $titulo    = 'Inventario de Productos';
        $subtitulo = 'Productos activos — ' . $nombreSucursalVista;
    }

    $resumen = [['label' => 'Total Productos', 'valor' => count($datos)]];
    while (ob_get_level() > 0) ob_end_clean();
    exportarPDF($titulo, $subtitulo, $columnas, $filas, $resumen, 'L');
}

// Exportar a Excel
if (isset($_GET['exportar']) && $_GET['exportar'] === 'excel') {
    $esAdmin     = $_SESSION['rol'] === 'Administrador';
    $vistaGlobal = $esAdmin && $sucursalVista === 0;
    $sqlSelC     = $esAdmin ? ", p.precio_compra" : "";

    if ($vistaGlobal) {
        // Catálogo global: sin stock ni sucursal
        $stmt = $pdo->prepare("
            SELECT p.codigo, p.nombre_producto, c.nombre as categoria{$sqlSelC},
                   p.precio_venta, p.precio_mayoreo, p.tipo_venta, p.descripcion, p.unidad_medida
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
            WHERE p.activo = 1
            ORDER BY p.nombre_producto ASC
        ");
        $stmt->execute([]);
        $datos   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = $esAdmin
            ? ['Código','Nombre','Categoría','Precio compra','Precio venta','Precio mayoreo','Tipo venta','Descripción','Unidad de medida']
            : ['Código','Nombre','Categoría','Precio venta','Precio mayoreo','Tipo venta','Descripción','Unidad de medida'];
        $filaFn  = $esAdmin
            ? fn($p) => [$p['codigo'],$p['nombre_producto'],$p['categoria']??'',$p['precio_compra'],$p['precio_venta'],$p['precio_mayoreo'],$p['tipo_venta'],$p['descripcion']??'',$p['unidad_medida']??'']
            : fn($p) => [$p['codigo'],$p['nombre_producto'],$p['categoria']??'',$p['precio_venta'],$p['precio_mayoreo'],$p['tipo_venta'],$p['descripcion']??'',$p['unidad_medida']??''];
        $filename = 'catalogo_global_' . date('Y-m-d') . '.xlsx';
    } else {
        $stmt = $pdo->prepare("
            SELECT p.codigo, p.nombre_producto, c.nombre as categoria{$sqlSelC},
                   p.precio_venta, p.precio_mayoreo, ss.stock_actual, ss.stock_minimo,
                   ss.stock_maximo, p.tipo_venta, p.descripcion, p.unidad_medida
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
            INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ? AND ss.activo = 1
            WHERE p.activo = 1
            ORDER BY p.nombre_producto ASC
        ");
        $stmt->execute([$sucursalVista]);
        $datos   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = $esAdmin
            ? ['Código','Nombre','Categoría','Precio compra','Precio venta','Precio mayoreo','Stock actual','Stock mínimo','Stock máximo','Tipo venta','Descripción','Unidad de medida']
            : ['Código','Nombre','Categoría','Precio venta','Precio mayoreo','Stock actual','Stock mínimo','Stock máximo','Tipo venta','Descripción','Unidad de medida'];
        $filaFn  = $esAdmin
            ? fn($p) => [$p['codigo'],$p['nombre_producto'],$p['categoria']??'',$p['precio_compra'],$p['precio_venta'],$p['precio_mayoreo'],$p['stock_actual'],$p['stock_minimo'],$p['stock_maximo'],$p['tipo_venta'],$p['descripcion']??'',$p['unidad_medida']??'']
            : fn($p) => [$p['codigo'],$p['nombre_producto'],$p['categoria']??'',$p['precio_venta'],$p['precio_mayoreo'],$p['stock_actual'],$p['stock_minimo'],$p['stock_maximo'],$p['tipo_venta'],$p['descripcion']??'',$p['unidad_medida']??''];
        $filename = 'productos_' . date('Y-m-d') . '.xlsx';
    }

    try {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($vistaGlobal ? 'Catálogo Global' : 'Productos');

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
            foreach ($filaFn($p) as $i => $v) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue("{$col}{$fila}", $v);
            }
        }
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tmpExcel = sys_get_temp_dir() . '/inv_' . uniqid() . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmpExcel);

        while (ob_get_level() > 0) ob_end_clean();
        @ini_set('zlib.output_compression', '0');
        if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tmpExcel));
        readfile($tmpExcel);
        @unlink($tmpExcel);
        exit();
    } catch (\Throwable $e) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        die('Error al generar Excel: ' . htmlspecialchars($e->getMessage()));
    }
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
    // [FIX-CRIT-B-03] CSRF ausente antes — es el vector de mayor impacto de este archivo:
    // permitía reescribir precio_compra/precio_venta/precio_mayoreo de productos existentes
    // (la importación actualiza por código) desde un POST sin ningún token.
    requerirCSRF($_POST['_token'] ?? '', 'inventario_productos.php');
    // [FIX-IMPORT-02] Bloquear la importación completa para Inventario/Cajero.
    if (!$puedeImportarExcel) {
        header('Location: inventario_productos.php?msg=no_autorizado_import');
        exit();
    }
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
            $bloqueados = 0;
            $categoriasCache  = []; // evita SELECT repetido para misma categoría
            $categoriasCreadas = [];
            $unidadesCache    = []; // evita INSERT repetido para misma unidad
            $unidadesCreadas  = [];

            // Solo Administrador puede dar de alta catálogo global nuevo; ya se validó arriba
            // que solo Administrador llega a este bloque ($puedeImportarExcel).
            $puedeCrearCatalogo = $puedeImportarExcel;

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

                // [FIX-MEDIO-B-17] Antes, en "vista global" (sucursalVista === 0), se caía a
                // $_SESSION['sucursal_id'] del admin — un valor real y arbitrario (la
                // sucursal a la que quedó asignada su cuenta al crearla, no necesariamente
                // ninguna con sentido de negocio), y el stock del Excel se metía ahí en
                // silencio, contradiciendo el aviso en pantalla ("la importación se
                // realizará al catálogo global"). Ahora, en vista global, solo se actualiza
                // el catálogo (nombre/precio/categoría — ya de por sí global) y se OMITE
                // por completo el stock del Excel, en vez de adivinar una sucursal.
                $sucursalImport = ($sucursalVista > 0) ? $sucursalVista : null;

                try {
                    // [FIX-ALTO-B-11] Antes no se validaba el signo de precios/stock: un
                    // Excel con una celda negativa (typo o manipulado) se importaba tal cual,
                    // dejando precios negativos o stock negativo en el catálogo/sucursal.
                    if ($precio_compra < 0 || $precio_venta < 0 || $precio_mayoreo < 0
                        || $stock_actual < 0 || $stock_minimo < 0 || $stock_maximo < 0) {
                        throw new Exception('Precios y cantidades de stock no pueden ser negativos.');
                    }
                    // [FIX-PRECIO-MAX-01] precio_compra/venta/mayoreo son DECIMAL(10,2) (tope
                    // tecnico 99,999,999.99), pero ningun producto real cuesta eso — un tope
                    // de $500,000 (igual al que ya usan abrirCaja/corteCaja) atrapa un error
                    // de dedo en el Excel mucho antes.
                    if ($precio_compra > 500000 || $precio_venta > 500000 || $precio_mayoreo > 500000) {
                        throw new Exception('Los precios no pueden ser mayores a $500,000.00.');
                    }

                    // [FIX-UNIDAD-GLOBAL-01] unidades_medida.sucursal_id es NOT NULL — en "Todas
                    // las sucursales" ($sucursalImport === null) este INSERT tronaba con
                    // "Column 'sucursal_id' cannot be null" en cuanto el Excel traía una unidad
                    // que aun no existiera en NINGUNA sucursal. El campo unidad_medida del
                    // producto (un varchar libre, no una FK) igual se guarda bien mas abajo; solo
                    // se omite la auto-creacion de la unidad como sugerencia cuando no hay una
                    // sucursal especifica a la cual asociarla.
                    if ($unidad_medida !== '' && !isset($unidadesCache[$unidad_medida]) && $puedeCrearCatalogo && $sucursalImport !== null) {
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
                            } elseif ($puedeCrearCatalogo) {
                                // Crear categoría nueva automáticamente
                                $pdo->prepare("INSERT INTO categorias (nombre) VALUES (?)")->execute([$nombreCat]);
                                $categoria_id = (int)$pdo->lastInsertId();
                                if ($categoria_id) $categoriasCreadas[] = $nombreCat;
                            }
                            if ($categoria_id) $categoriasCache[$nombreCat] = $categoria_id;
                        }
                    }

                    // Buscar el producto globalmente por código (catálogo compartido)
                    $check = $pdo->prepare("SELECT producto_id FROM productos WHERE codigo = ?");
                    $check->execute([$codigo]);
                    $productoExistente = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$productoExistente && !$puedeCrearCatalogo) {
                        // Solo el administrador puede agregar productos nuevos al catálogo global
                        $bloqueados++;
                        continue;
                    }

                    if ($productoExistente) {
                        $prodId = $productoExistente['producto_id'];
                        // [FIX-CONSISTENCIA] Igual que en inventario_formProducto.php: Inventario/Cajero
                        // no puede editar el catalogo global (nombre, precios, categoria) de un
                        // producto que ya existe — solo puede actualizar el stock de su sucursal.
                        if ($puedeCrearCatalogo) {
                            // Actualizar catálogo global (sin stock ni sucursal_id)
                            if ($categoria_id !== null) {
                                $pdo->prepare("UPDATE productos SET nombre_producto=?, categoria_id=?, precio_compra=?, precio_venta=?, precio_mayoreo=?, tipo_venta=?, descripcion=?, unidad_medida=? WHERE producto_id=?")
                                    ->execute([$nombre, $categoria_id, $precio_compra, $precio_venta, $precio_mayoreo, $tipo_venta, $descripcion, $unidad_medida ?: null, $prodId]);
                            } else {
                                $pdo->prepare("UPDATE productos SET nombre_producto=?, precio_compra=?, precio_venta=?, precio_mayoreo=?, tipo_venta=?, descripcion=?, unidad_medida=? WHERE producto_id=?")
                                    ->execute([$nombre, $precio_compra, $precio_venta, $precio_mayoreo, $tipo_venta, $descripcion, $unidad_medida ?: null, $prodId]);
                            }
                        }
                        // Upsert stock de la sucursal (solo si hay una sucursal especifica elegida)
                        if ($sucursalImport !== null) {
                            // [FIX-CONSISTENCIA] (portado de cajeroInventario/productos.php): faltaba
                            // "activo = 1" en el UPDATE — si el producto ya habia sido dado de baja
                            // en esta sucursal, reimportarlo por Excel actualizaba minimo/maximo pero
                            // lo dejaba invisible/inactivo en vez de reactivarlo.
                            $pdo->prepare("INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo)
                                VALUES (?,?,?,?,?,1)
                                ON DUPLICATE KEY UPDATE stock_minimo=VALUES(stock_minimo), stock_maximo=VALUES(stock_maximo), activo=1")
                                ->execute([$prodId, $sucursalImport, $stock_actual, $stock_minimo, $stock_maximo]);
                        }
                        $omitidos++;
                    } else {
                        // Insertar en catálogo global (sin sucursal_id, sin stock)
                        $pdo->prepare("INSERT INTO productos (categoria_id, codigo, nombre_producto, descripcion, precio_compra, precio_venta, precio_mayoreo, tipo_venta, unidad_medida, activo) VALUES (?,?,?,?,?,?,?,?,?,1)")
                            ->execute([$categoria_id, $codigo, $nombre, $descripcion, $precio_compra, $precio_venta, $precio_mayoreo, $tipo_venta, $unidad_medida ?: null]);
                        $prodId = (int)$pdo->lastInsertId();
                        // Insertar stock para la sucursal (solo si hay una sucursal especifica elegida)
                        if ($sucursalImport !== null) {
                            $pdo->prepare("INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo) VALUES (?,?,?,?,?,1)")
                                ->execute([$prodId, $sucursalImport, $stock_actual, $stock_minimo, $stock_maximo]);
                        }
                        $importados++;
                    }
                } catch (Exception $eRow) {
                    $filasConError[] = "Fila $fila (\"$codigo\" — $nombre): " . $eRow->getMessage();
                }
            }

            $pdo->commit();
            $exitoImport = "$importados producto(s) importados, $omitidos actualizado(s).";
            if ($bloqueados > 0) $exitoImport .= " $bloqueados fila(s) omitida(s): tu rol no puede dar de alta productos nuevos en el catálogo, solo actualizar los existentes.";
            if ($sucursalVista === 0) $exitoImport .= ' Solo se actualizó el catálogo global (nombre, precios, categoría) — el stock del Excel se omitió porque no hay una sucursal específica elegida.';
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
    // [FIX-IMPORT-02] Sin sentido ofrecer la plantilla de importación a quien no puede
    // importar — mismo candado que el POST del Excel.
    if (!$puedeImportarExcel) {
        header('Location: inventario_productos.php?msg=no_autorizado_import');
        exit();
    }
    try {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Plantilla');
        $headers = ['Código*','Nombre*','Categoría','Precio compra','Precio venta*','Precio mayoreo','Stock inicial','Stock mínimo','Stock máximo','Tipo venta (Unidad/Suelto)','Descripción','Unidad de medida'];
        foreach ($headers as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $h);
            $sheet->getStyle("{$col}1")->getFont()->setBold(true);
        }
        // Varios ejemplos representativos de una ferretería: por unidad y por granel/kg,
        // con y sin mayoreo, con y sin categoría/descripción — para que quede claro cómo
        // llenar cada tipo de producto real, no solo un caso genérico.
        $ejemplos = [
            ['TOR-001', 'Tornillo Phillips 1/4" x 1"', 'Tornillería', '0.50', '1.00', '0.80', '500', '100', '2000', 'Unidad', 'Caja con 500 piezas', 'pieza'],
            ['CEM-050', 'Cemento gris 50kg', 'Materiales', '145.00', '185.00', '170.00', '80', '10', '200', 'Unidad', 'Saco de 50kg', 'saco'],
            ['ARE-001', 'Arena de río', 'Materiales', '', '25.00', '', '500', '50', '0', 'Suelto', 'Se vende por kg', 'kg'],
            ['PINT-BL1', 'Pintura vinílica blanca 1L', 'Pinturas', '60.00', '95.00', '', '30', '5', '80', 'Unidad', '', 'pieza'],
            ['CABLE-12', 'Cable eléctrico calibre 12', '', '8.50', '13.00', '11.50', '200', '20', '0', 'Suelto', 'Se vende por metro', 'metro'],
        ];
        foreach ($ejemplos as $fila => $ejemplo) {
            foreach ($ejemplo as $i => $v) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue("{$col}" . ($fila + 2), $v);
            }
        }
        foreach (range('A','L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $tmpPlantilla = sys_get_temp_dir() . '/plt_' . uniqid() . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPlantilla);
        while (ob_get_level() > 0) ob_end_clean();
        @ini_set('zlib.output_compression', '0');
        if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="plantilla_productos.xlsx"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tmpPlantilla));
        readfile($tmpPlantilla);
        @unlink($tmpPlantilla);
        exit();
    } catch (\Throwable $e) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        die('Error al generar plantilla: ' . htmlspecialchars($e->getMessage()));
    }
}

// Eliminar producto con motivo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_producto'])) {
    // [FIX-CRIT-B-03] CSRF ausente antes.
    requerirCSRF($_POST['_token'] ?? '', 'inventario_productos.php');
    $id     = intval($_POST['producto_id'] ?? 0);
    $motivo = trim($_POST['motivo_eliminacion'] ?? '');

    // [FIX-MEDIO-B-14] En "Todas las sucursales" (sucursalVista === 0) el INNER JOIN de
    // abajo exige ss.sucursal_id = 0, que ninguna fila real cumple nunca — la accion
    // fallaba SIEMPRE en vista global y caia al mensaje generico "captura un motivo",
    // aunque el motivo si se hubiera escrito. Ahora se explica la causa real.
    if ($sucursalVista === 0) {
        header('Location: inventario_productos.php?msg=error_sin_sucursal');
        exit();
    }

    if ($id && $motivo !== '') {
        $stmtProd = $pdo->prepare("SELECT p.producto_id, ss.stock_actual FROM productos p INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ? WHERE p.producto_id = ?");
        $stmtProd->execute([$sucursalVista, $id]);
        $productoEliminar = $stmtProd->fetch(PDO::FETCH_ASSOC);

        if ($productoEliminar) {
            // [FIX-MEDIO-H-07] La baja de stock y el registro del movimiento (su unica
            // explicacion en el historial) eran dos escrituras sueltas: si la segunda fallaba
            // despues de que la primera ya tuviera exito, el producto desaparecia de la
            // sucursal sin dejar ningun rastro de motivo ni de quien/cuando lo dio de baja.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE stock_sucursal SET activo = 0 WHERE producto_id = ? AND sucursal_id = ?")->execute([$id, $sucursalVista]);
                // [FIX-MEDIO-B-16] Antes se guardaba sin sucursal_id: la baja quedaba invisible
                // en cualquier historial de movimientos filtrado por sucursal.
                $pdo->prepare("
                    INSERT INTO movimientos_inventario
                    (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo)
                    VALUES (?, ?, ?, 'Ajuste', 0, ?, ?, ?)
                ")->execute([
                    $id,
                    $_SESSION['usuario_id'],
                    $sucursalVista,
                    $productoEliminar['stock_actual'],
                    $productoEliminar['stock_actual'],
                    'Producto eliminado: ' . $motivo
                ]);
                $pdo->commit();
                header('Location: inventario_productos.php?msg=eliminado');
                exit();
            } catch (\PDOException $e) {
                $pdo->rollBack();
                header('Location: inventario_productos.php?msg=error_eliminar');
                exit();
            }
        }
    }

    header('Location: inventario_productos.php?msg=error_eliminar');
    exit();
}

// AJAX: catálogo global — productos que la sucursal seleccionada aún no tiene
if (isset($_GET['catalogo_disponible'])) {
    header('Content-Type: application/json');
    $sucursalFiltro = intval($_GET['sucursal'] ?? 0);
    if (!$sucursalFiltro) { echo json_encode([]); exit(); }
    $busq   = trim($_GET['q'] ?? '');
    $where  = "WHERE p.activo = 1 AND (ss.producto_id IS NULL OR ss.activo = 0)";
    $params = [$sucursalFiltro];
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

// POST: agregar producto del catálogo a una sucursal específica
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_catalogo_admin'])) {
    // [FIX-CRIT-B-03] CSRF ausente antes.
    requerirCSRF($_POST['_token'] ?? '', 'inventario_productos.php');
    $sucursalDestino = intval($_POST['sucursal_destino'] ?? 0);
    $productos_ids   = $_POST['producto_id']   ?? [];
    $stocks_actual   = $_POST['stock_actual']  ?? [];
    $stocks_minimo   = $_POST['stock_minimo']  ?? [];
    $stocks_maximo   = $_POST['stock_maximo']  ?? [];

    if ($sucursalDestino && is_array($productos_ids)) {
        // [FIX-MEDIO-H-07] El lote entero de productos se agregaba fuera de una transaccion:
        // una falla a mitad del foreach (o entre el upsert de stock y su movimiento de
        // "Entrada") dejaba un lote parcial -- algunos productos ya en la sucursal con su
        // entrada registrada, otros con stock pero sin movimiento, y el resto sin agregar --
        // sin ninguna forma de saber cuales quedaron a medias.
        $pdo->beginTransaction();
        try {
            $stmtUpsert = $pdo->prepare("
                INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo)
                VALUES (?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE activo=1, stock_actual=VALUES(stock_actual), stock_minimo=VALUES(stock_minimo), stock_maximo=VALUES(stock_maximo)
            ");
            $stmtMov = $pdo->prepare("
                INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo)
                VALUES (?, ?, ?, 'Entrada', ?, 0, ?, 'Alta de producto en sucursal')
            ");
            foreach ($productos_ids as $i => $pid) {
                $pid    = intval($pid);
                $actual = floatval($stocks_actual[$i] ?? 0);
                $minimo = floatval($stocks_minimo[$i] ?? 0);
                $maximo = floatval($stocks_maximo[$i] ?? 0);
                if (!$pid) continue;
                $stmtUpsert->execute([$pid, $sucursalDestino, $actual, $minimo, $maximo]);
                if ($actual > 0) $stmtMov->execute([$pid, $_SESSION['usuario_id'], $sucursalDestino, $actual, $actual]);
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            header('Location: inventario_productos.php?sucursal='.$sucursalDestino.'&msg=error_agregar_catalogo');
            exit();
        }
    }
    header('Location: inventario_productos.php?sucursal='.$sucursalDestino.'&msg=agregado_catalogo');
    exit();
}

// Filtros
$busqueda    = trim($_GET['buscar'] ?? '');
$categoria   = intval($_GET['categoria'] ?? 0);
$stock_bajo  = isset($_GET['stock_bajo']);
$esAdmin     = $_SESSION['rol'] === 'Administrador';
$vistaGlobal = $esAdmin && $sucursalVista === 0;

if ($vistaGlobal) {
    // Vista global: catálogo puro, sin JOIN a stock_sucursal
    $where   = "WHERE p.activo = 1";
    $params  = [];
    $orderBy = "p.nombre_producto ASC";
    if ($busqueda) { $where .= " AND (p.nombre_producto LIKE ? OR p.codigo LIKE ?)"; $params[] = '%'.$busqueda.'%'; $params[] = '%'.$busqueda.'%'; }
    if ($categoria) { $where .= " AND p.categoria_id = ?"; $params[] = $categoria; }
    $stmt = $pdo->prepare("
        SELECT p.*, c.nombre as nombre_categoria
        FROM productos p
        LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
        {$where}
        ORDER BY {$orderBy}
    ");
    $stmt->execute($params);
    $totalStockBajo = 0;
} else {
    $where   = "WHERE p.activo = 1";
    $params  = [$sucursalVista];
    $orderBy = "ss.stock_actual <= ss.stock_minimo DESC, p.nombre_producto ASC";
    if ($busqueda) { $where .= " AND (p.nombre_producto LIKE ? OR p.codigo LIKE ?)"; $params[] = '%'.$busqueda.'%'; $params[] = '%'.$busqueda.'%'; }
    if ($categoria) { $where .= " AND p.categoria_id = ?"; $params[] = $categoria; }
    if ($stock_bajo) { $where .= " AND ss.stock_actual <= ss.stock_minimo"; }
    $stmt = $pdo->prepare("
        SELECT p.*, c.nombre as nombre_categoria, ss.stock_actual, ss.stock_minimo, ss.stock_maximo
        FROM productos p
        LEFT JOIN categorias c ON p.categoria_id = c.categoria_id
        INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ? AND ss.activo = 1
        {$where}
        ORDER BY {$orderBy}
    ");
    $stmtBajo = $pdo->prepare("
        SELECT COUNT(*) FROM productos p
        INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ? AND ss.activo = 1
        WHERE p.activo = 1 AND ss.stock_actual <= ss.stock_minimo
    ");
    $stmtBajo->execute([$sucursalVista]);
    $totalStockBajo = $stmtBajo->fetchColumn();
}
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categorias = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
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
                <?php if ($puedeImportarExcel): ?>
                <!-- [FIX-IMPORT-02] La importación/plantilla es solo para Administrador. -->
                <a class="btn-plantilla" href="inventario_productos.php?plantilla=1">Descargar plantilla</a>
                <button class="btn-excel-import" onclick="toggleImport()">Importar Excel</button>
                <?php endif; ?>
                <a style="background:#c0392b;color:white;border:none;padding:9px 14px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;" href="inventario_productos.php?exportar=pdf">⬇ PDF</a>
                <a class="btn-excel-export" href="inventario_productos.php?exportar=excel">⬇ Excel</a>
                <?php if ($vistaGlobal): ?>
                    <a class="btn-agregar" href="inventario_formProducto.php?todas=1">+ Nuevo producto (todas las sucursales)</a>
                <?php else: ?>
                    <button class="btn-agregar" type="button" onclick="abrirModalCatalogo()">+ Agregar del catálogo</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Panel de importación (FIX-IMPORT-02: solo Administrador) -->
        <?php if ($puedeImportarExcel): ?>
        <div class="import-card" id="importCard">
            <h3>Importar productos desde Excel</h3>
            <?php if ($vistaGlobal): ?>
                <div class="msg" style="background:#fff8e1;color:#e65100;border-left:3px solid #e65100;margin-bottom:10px;">
                    Estás en el inventario global. La importación se realizará a el catalogo global.
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
                <?php /* [FIX-CRIT-B-05] Faltaba htmlspecialchars aquí (los mensajes de error
                contiguos sí lo tienen) — el texto incluye nombres de categoría/unidad tomados
                literalmente del archivo Excel importado, así que un nombre con <script> se
                ejecutaba en la sesión de quien ve la pantalla después de importar. */ ?>
                <div class="msg msg-exito"><?= htmlspecialchars($exitoImport, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="import-form">
                    <input type="file" name="archivo_excel" accept=".xlsx,.xls" required>
                    <button class="btn-subir" type="submit">Importar</button>
                </div>
                <p style="font-size:12px;color:#aaa;margin-top:8px;">
                    Usa la plantilla para asegurarte del formato correcto. Los productos existentes (mismo código) se actualizarán. Si la categoría no existe, se creará automáticamente.
                </p>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($totalStockBajo > 0 && !$stock_bajo): ?>
            <div class="alerta-stock">
                <span>Hay <strong><?= $totalStockBajo ?></strong> producto(s) con stock bajo.</span>
                <a href="inventario_productos.php?stock_bajo=1" style="color:#c0392b;font-weight:700;">Ver</a>
            </div>
        <?php endif; ?>

        <?php
        $msgTextos = [
            'creado'           => '✅ Producto registrado correctamente en el catálogo.',
            'editado'          => '✅ Producto actualizado correctamente.',
            'eliminado'        => '✅ Producto eliminado correctamente.',
            'agregado_catalogo'=> '✅ Producto(s) agregado(s) a la sucursal correctamente.',
            'error_agregar_catalogo' => '❌ No se pudo completar el alta de productos. No se guardó ningún cambio, intenta de nuevo.',
            'error_eliminar'   => '❌ No se pudo eliminar el producto. Captura un motivo para dejarlo en historial.',
            'error_sin_sucursal' => '❌ Selecciona una sucursal específica (no "Todas las sucursales") para eliminar un producto de su stock.',
            'error_token'      => '❌ La sesión expiró o el formulario no es válido. Recarga la página e intenta de nuevo.',
            'no_autorizado_import' => '❌ Tu rol no puede importar productos por Excel. Usa "+ Agregar del catálogo" para activar productos ya existentes en tu sucursal.',
        ];
        $msgActual = $_GET['msg'] ?? '';
        if (isset($msgTextos[$msgActual])):
            $esMsgError = str_starts_with($msgActual, 'error');
        ?>
        <div class="msg <?= $esMsgError ? 'msg-error' : 'msg-exito' ?>" id="msgNotificacion" style="display:flex;justify-content:space-between;align-items:center;">
            <span><?= $msgTextos[$msgActual] ?></span>
            <button onclick="this.parentElement.style.display='none'" style="background:none;border:none;font-size:18px;color:inherit;cursor:pointer;padding:0 4px;line-height:1;">&times;</button>
        </div>
        <script>setTimeout(()=>{const m=document.getElementById('msgNotificacion');if(m)m.style.display='none';},10000);</script>
        <?php endif; ?>

        <form method="GET" action="inventario_productos.php">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Buscar</label>
                    <input type="text" name="buscar" placeholder="Nombre o código..." value="<?= htmlspecialchars($busqueda) ?>" style="width:180px;" oninput="filtrarTabla(this.value)">
                </div>
                <div class="filtro-group">
                    <label>Categoría</label>
                    <select name="categoria" onchange="this.form.submit()">
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
                <a class="btn-limpiar" href="inventario_productos.php">Limpiar</a>
                <?php if (!$vistaGlobal): ?>
                <a class="btn-stock-bajo <?= $stock_bajo?'activo':'' ?>" href="inventario_productos.php?stock_bajo=1">
                    Stock bajo (<?= $totalStockBajo ?>)
                </a>
                <?php endif; ?>
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
                        <?php if (!$vistaGlobal): ?>
                        <th>Stock</th>
                        <?php endif; ?>
                        <th>P. Venta</th>
                        <th>P. Mayoreo</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody id="tablaFiltrable">
                    <?php foreach ($productos as $p):
                        $esStockBajo = !$vistaGlobal && isset($p['stock_actual']) && $p['stock_actual'] <= $p['stock_minimo'];
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
                        <?php if (!$vistaGlobal): ?>
                        <td class="<?= $esStockBajo?'stock-alerta':'stock-ok' ?>">
                            <?= number_format($p['stock_actual'],2) ?>
                            <span style="font-size:11px;color:#aaa;">/ mín <?= number_format($p['stock_minimo'],2) ?></span>
                        </td>
                        <?php endif; ?>
                        <td>$<?= number_format($p['precio_venta'],2) ?></td>
                        <td>$<?= number_format($p['precio_mayoreo'],2) ?></td>
                        <td>
                            <div class="acciones">
                                <a class="btn-accion btn-editar" href="inventario_formProducto.php?id=<?= $p['producto_id'] ?><?= $vistaGlobal ? '' : '&sucursal='.$sucursalVista ?>">Editar</a>
                                <?php if (!$vistaGlobal): ?>
                                <a class="btn-accion btn-entrada" href="inventario_entradas.php?producto_id=<?= $p['producto_id'] ?>">Entrada</a>
                                <?php endif; ?>
                                <?php /* [FIX-CRIT-B-01] json_encode() no es un escape de HTML: al interpolarse
                                dentro de un atributo onclick="...", sus comillas dobles cerraban el atributo
                                de inmediato y el resto del nombre se parseaba como atributos HTML nuevos —
                                XSS almacenado ejecutable, y de paso el onclick quedaba roto para TODO
                                producto (SyntaxError de JS), dejando "Eliminar" sin funcionar nunca. Ahora
                                el id/nombre van en data-* correctamente escapados con htmlspecialchars, y
                                un listener delegado (ver el <script> de abajo) llama a la misma función
                                confirmarEliminacion() de siempre. */ ?>
                                <button class="btn-accion btn-eliminar" type="button" data-producto-id="<?= (int)$p['producto_id'] ?>" data-producto-nombre="<?= htmlspecialchars($p['nombre_producto'], ENT_QUOTES, 'UTF-8') ?>">Eliminar</button>
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

<!-- Modal: Agregar del catálogo global (solo en vista de sucursal específica) -->
<?php if (!$vistaGlobal): ?>
<div class="modal-overlay" id="modalCatalogo" style="z-index:1001;">
    <div style="background:white;border-radius:10px;width:660px;max-width:95vw;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.18);">
        <div style="padding:18px 20px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h3 style="font-size:15px;font-weight:700;color:#222;margin:0;">Agregar del catálogo global</h3>
                <p style="font-size:12px;color:#888;margin:3px 0 0;">Sucursal: <strong><?= htmlspecialchars($nombreSucursalVista) ?></strong> — Solo productos que aún no están en esta sucursal</p>
            </div>
            <button onclick="cerrarModalCatalogo()" style="background:none;border:none;font-size:22px;color:#aaa;cursor:pointer;">&times;</button>
        </div>
        <div style="padding:14px 20px;border-bottom:1px solid #f0f0f0;">
            <input type="text" id="searchCatalogo" placeholder="Buscar por nombre o código..." oninput="buscarCatalogo(this.value)" style="width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
        </div>
        <div id="listaCatalogo" style="flex:1;overflow-y:auto;padding:8px 0;min-height:160px;">
            <div style="text-align:center;padding:40px;color:#aaa;font-size:13px;">Cargando productos...</div>
        </div>
        <!-- Panel de productos seleccionados -->
        <div id="seccionSeleccionados" style="display:none;border-top:2px solid #14ace7;background:#f0f9ff;flex-shrink:0;">
            <div style="padding:10px 20px 4px;">
                <span style="font-size:12px;font-weight:700;color:#0277bd;text-transform:uppercase;letter-spacing:.5px;">
                    Productos a agregar (<span id="conteoSeleccionados">0</span>)
                </span>
            </div>
            <!-- Encabezados de columnas alineados con los inputs -->
            <div style="display:flex;align-items:center;gap:8px;padding:2px 20px 4px;">
                <span style="flex:1;"></span>
                <span style="width:68px;text-align:center;font-size:10px;font-weight:700;color:#0277bd;text-transform:uppercase;">Inicial</span>
                <span style="width:68px;text-align:center;font-size:10px;font-weight:700;color:#0277bd;text-transform:uppercase;">Mínimo</span>
                <span style="width:68px;text-align:center;font-size:10px;font-weight:700;color:#0277bd;text-transform:uppercase;">Máximo</span>
                <span style="width:24px;"></span>
            </div>
            <div id="listaSeleccionados" style="max-height:220px;overflow-y:auto;padding:0 20px 6px;"></div>
            <div style="padding:10px 20px 14px;display:flex;justify-content:flex-end;gap:8px;">
                <button onclick="cerrarModalCatalogo()" style="background:white;color:#666;border:1px solid #ddd;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:13px;">Cancelar</button>
                <button onclick="confirmarAgregarCatalogo()" id="btnConfirmarAgregar" style="background:#14ace7;color:white;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:700;">Agregar a esta sucursal</button>
            </div>
        </div>
    </div>
</div>
<form method="POST" id="formAgregarCatalogoAdmin" style="display:none;">
    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="agregar_catalogo_admin" value="1">
    <input type="hidden" name="sucursal_destino" value="<?= $sucursalVista ?>">
</form>
<?php endif; ?>

<form method="POST" id="formEliminarProducto" style="display:none;">
    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="eliminar_producto" value="1">
    <input type="hidden" name="producto_id" id="inputEliminarProductoId">
    <input type="hidden" name="motivo_eliminacion" id="inputEliminarProductoMotivo">
</form>
<div class="modal-overlay" id="modalEliminarProducto" aria-hidden="true">
    <div class="modal-card">
        <h3>Eliminar producto</h3>
        <p id="textoEliminarProducto">Se desactivará el stock de este producto <strong>solo en la sucursal que estás viendo</strong> (no se borra del catálogo global ni se desactiva en otras sucursales) y el motivo se guardará en el historial de movimientos.</p>
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
    const seguro = confirm('Se va a desactivar el stock de "' + nombre + '" solo en esta sucursal (no en el catalogo global ni en otras sucursales). Este movimiento se guardara en historial. ¿Deseas continuar?');
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
// [FIX-CRIT-B-01] Listener delegado: ya no se interpola el nombre del producto dentro de un
// atributo onclick (ver comentario junto al botón "Eliminar" más arriba).
document.querySelectorAll('.btn-eliminar[data-producto-id]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        confirmarEliminacion(this.dataset.productoId, this.dataset.productoNombre);
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalEliminacion();
        cerrarModalCatalogo();
    }
});

// ---- Modal catálogo global (admin vista-sucursal) ----
let catalogoTimeout = null;

function abrirModalCatalogo() {
    document.getElementById('modalCatalogo').classList.add('visible');
    document.getElementById('searchCatalogo').value = '';
    document.getElementById('listaSeleccionados').innerHTML = '';
    document.getElementById('seccionSeleccionados').style.display = 'none';
    document.getElementById('conteoSeleccionados').textContent = '0';
    cargarCatalogo('');
    setTimeout(() => document.getElementById('searchCatalogo').focus(), 100);
}

function cerrarModalCatalogo() {
    const modal = document.getElementById('modalCatalogo');
    if (modal) modal.classList.remove('visible');
}

function buscarCatalogo(q) {
    clearTimeout(catalogoTimeout);
    catalogoTimeout = setTimeout(() => cargarCatalogo(q), 300);
}

function cargarCatalogo(q) {
    const lista = document.getElementById('listaCatalogo');
    lista.innerHTML = '<div style="text-align:center;padding:40px;color:#aaa;font-size:13px;">Cargando...</div>';
    const sucursal = <?= intval($sucursalVista) ?>;
    fetch('inventario_productos.php?catalogo_disponible&sucursal=' + sucursal + '&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            if (data.length === 0) {
                lista.innerHTML = '<div style="text-align:center;padding:40px;color:#aaa;font-size:13px;">No hay productos disponibles para agregar.<br><small>Todos los del catálogo ya están en esta sucursal.</small></div>';
                return;
            }
            // Mantener marcados los que ya están en la lista de seleccionados
            const yaSeleccionados = new Set([...document.querySelectorAll('.sel-item')].map(e => e.dataset.id));
            // [FIX-C6] (portado de cajeroInventario/productos.php): esc() cubre & < > " ' — antes
            // "nombre" solo escapaba & y " (para el atributo data-nombre) pero p.nombre_producto/
            // p.categoria/p.codigo se insertaban CRUDOS en el innerHTML de abajo, un nombre de
            // producto con <script> se ejecutaba en la sesión de quien viera esta pantalla.
            const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
            lista.innerHTML = data.map(p => {
                const cat    = p.categoria ? ' · ' + esc(p.categoria) : '';
                const precio = parseFloat(p.precio_venta || 0).toFixed(2);
                const nombre = esc(p.nombre_producto);
                const enLista = yaSeleccionados.has(String(p.producto_id));
                const btnStyle = enLista
                    ? 'background:#388e3c;color:white;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;white-space:nowrap;'
                    : 'background:#14ace7;color:white;border:none;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;white-space:nowrap;';
                const btnText = enLista ? '✓ En lista' : '+ Seleccionar';
                return `<div class="cat-item" data-id="${p.producto_id}" data-nombre="${nombre}" style="display:flex;justify-content:space-between;align-items:center;padding:10px 20px;border-bottom:1px solid #f5f5f5;">
                    <div style="flex:1;">
                        <div style="font-weight:600;font-size:13px;">${nombre}</div>
                        <div style="font-size:11px;color:#888;">${esc(p.codigo)}${cat}</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:14px;">
                        <span style="font-size:13px;color:#1565c0;font-weight:600;">$${precio}</span>
                        <button class="btn-cat-sel" onclick="seleccionarProductoCat(this)" style="${btnStyle}">${btnText}</button>
                    </div>
                </div>`;
            }).join('');
        })
        .catch(() => {
            lista.innerHTML = '<div style="text-align:center;padding:40px;color:#c00;font-size:13px;">Error al cargar el catálogo. Intenta de nuevo.</div>';
        });
}

function seleccionarProductoCat(btn) {
    const catItem = btn.closest('.cat-item');
    const id      = catItem.dataset.id;
    const nombre  = catItem.dataset.nombre;

    // Si ya está en la lista, quitarlo
    const existente = document.querySelector(`.sel-item[data-id="${id}"]`);
    if (existente) {
        existente.remove();
        btn.textContent = '+ Seleccionar';
        btn.style.background = '#14ace7';
        actualizarSeccionSeleccionados();
        return;
    }

    // Agregar a la lista de seleccionados
    const inpStyle = 'width:68px;padding:5px 6px;border:1px solid #b0d8f0;border-radius:5px;font-size:12px;text-align:center;';
    const fila = document.createElement('div');
    fila.className = 'sel-item';
    fila.dataset.id = id;
    fila.style.cssText = 'display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid #d0eaf8;';
    // [FIX-C6] Escapar nombre antes de insertarlo: viene de dataset.nombre (el navegador lo
    // devuelve ya decodificado), asi que hay que volver a limpiarlo aqui antes de meterlo
    // en innerHTML, sin depender del esc() de cargarCatalogo() (funcion distinta).
    const nombreSeguro = nombre.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    fila.innerHTML = `
        <span style="flex:1;font-size:12px;font-weight:600;color:#222;">${nombreSeguro}</span>
        <input type="number" class="inp-stock-actual" min="0" step="0.01" placeholder="Inicial" style="${inpStyle}" title="Stock inicial">
        <input type="number" class="inp-stock-min"    min="0" step="0.01" placeholder="Mín"     style="${inpStyle}" title="Stock mínimo">
        <input type="number" class="inp-stock-max"    min="0" step="0.01" placeholder="Máx"     style="${inpStyle}" title="Stock máximo">
        <button onclick="quitarSeleccionado(this)" title="Quitar" style="background:none;border:none;color:#e53935;font-size:18px;line-height:1;cursor:pointer;padding:0 2px;">✕</button>`;
    document.getElementById('listaSeleccionados').appendChild(fila);

    btn.textContent = '✓ En lista';
    btn.style.background = '#388e3c';

    actualizarSeccionSeleccionados();

    // Enfocar primer input del nuevo elemento
    setTimeout(() => fila.querySelector('.inp-stock-actual').focus(), 50);
}

function quitarSeleccionado(removeBtn) {
    const selItem = removeBtn.closest('.sel-item');
    const id = selItem.dataset.id;
    selItem.remove();
    // Resetear botón en la lista del catálogo
    const catBtn = document.querySelector(`.cat-item[data-id="${id}"] .btn-cat-sel`);
    if (catBtn) { catBtn.textContent = '+ Seleccionar'; catBtn.style.background = '#14ace7'; }
    actualizarSeccionSeleccionados();
}

function actualizarSeccionSeleccionados() {
    const items = document.querySelectorAll('.sel-item');
    const n = items.length;
    document.getElementById('seccionSeleccionados').style.display = n > 0 ? 'block' : 'none';
    document.getElementById('conteoSeleccionados').textContent = n;
    document.getElementById('btnConfirmarAgregar').textContent =
        'Agregar ' + n + ' producto' + (n !== 1 ? 's' : '') + ' a esta sucursal';
    if (n > 0) {
        setTimeout(() => document.getElementById('seccionSeleccionados')
            .scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 80);
    }
}

function confirmarAgregarCatalogo() {
    const items = document.querySelectorAll('.sel-item');
    if (!items.length) return;
    const form = document.getElementById('formAgregarCatalogoAdmin');
    // Limpiar inputs dinámicos previos
    form.querySelectorAll('.inp-din').forEach(e => e.remove());
    const add = (name, val) => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = name; i.value = val; i.className = 'inp-din';
        form.appendChild(i);
    };
    items.forEach(item => {
        add('producto_id[]',  item.dataset.id);
        add('stock_actual[]', item.querySelector('.inp-stock-actual').value);
        add('stock_minimo[]', item.querySelector('.inp-stock-min').value);
        add('stock_maximo[]', item.querySelector('.inp-stock-max').value);
    });
    form.submit();
}

// Cerrar al hacer clic fuera del modal
(function() {
    const m = document.getElementById('modalCatalogo');
    if (m) m.addEventListener('click', function(e) { if (e.target === this) cerrarModalCatalogo(); });
})();
</script>
<script src="../includes/auto_filter.js"></script>
</body>
</html>


