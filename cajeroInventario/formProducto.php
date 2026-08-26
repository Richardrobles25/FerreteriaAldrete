<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

$editando  = null;
$errores   = [];
$esEdicion = isset($_GET['id']);

// En cajeroInventario no se pueden crear productos nuevos al catálogo global.
// Solo el administrador puede crear productos; aquí solo se editan los existentes.
if (!$esEdicion) {
    header('Location: productos.php?msg=solo_catalogo');
    exit();
}
$proveedoresProducto = [];

function esValorEnteroValido($valor): bool {
    if ($valor === null || $valor === '') {
        return true;
    }
    return preg_match('/^\d+$/', trim((string) $valor)) === 1;
}

function normalizarNumeroFormulario($valor): string {
    $valor = trim((string) $valor);
    if ($valor === '') {
        return '0';
    }
    return str_replace(',', '.', $valor);
}

if ($esEdicion) {
    $stmt = $pdo->prepare("
        SELECT p.*, ss.stock_actual, ss.stock_minimo, ss.stock_maximo
        FROM productos p
        INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
        WHERE p.producto_id = ?
    ");
    $stmt->execute([$_SESSION['sucursal_id'], intval($_GET['id'])]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editando) { header('Location: productos.php'); exit(); }

    $stmtPP = $pdo->prepare("
        SELECT pp.proveedor_id, pp.codigo_proveedor, p.nombre AS nombre_proveedor
        FROM producto_proveedor pp
        JOIN proveedores p ON pp.proveedor_id = p.proveedor_id
        WHERE pp.producto_id = ?
    ");
    $stmtPP->execute([$editando['producto_id']]);
    $proveedoresProducto = $stmtPP->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [FIX-NC3] Verificar CSRF: este formulario no tenia ninguna validacion de token,
    // a diferencia de proveedores.php/categorias.php/unidades.php que ya la tienen.
    requerirCSRF($_POST['_token'] ?? '', 'formProducto.php');
    $codigo           = strtoupper(trim($_POST['codigo'] ?? ''));
    $nombre_producto  = trim($_POST['nombre_producto'] ?? '');
    $descripcion      = trim($_POST['descripcion'] ?? '');
    $categoria_id     = intval($_POST['categoria_id'] ?? 0) ?: null;
    $precio_compra    = floatval(normalizarNumeroFormulario($_POST['precio_compra'] ?? 0));
    $precio_venta     = floatval(normalizarNumeroFormulario($_POST['precio_venta'] ?? 0));
    $precio_mayoreo   = floatval(normalizarNumeroFormulario($_POST['precio_mayoreo'] ?? 0));
    $stock_minimo_raw = $_POST['stock_minimo'] ?? 0;
    $stock_maximo_raw = $_POST['stock_maximo'] ?? 0;
    $stock_minimo     = floatval(normalizarNumeroFormulario($stock_minimo_raw));
    $stock_maximo     = floatval(normalizarNumeroFormulario($stock_maximo_raw));
    $tipo_venta       = $_POST['tipo_venta'] ?? 'Unidad';
    $unidad_medida    = trim($_POST['unidad_medida'] ?? '');
    $cantidad_inicial_raw = $_POST['cantidad_inicial'] ?? 0;
    $cantidad_inicial = floatval(normalizarNumeroFormulario($cantidad_inicial_raw));
    $proveedores_sel  = $_POST['proveedores'] ?? [];
    $codigos_prov     = $_POST['codigos_prov'] ?? [];
    $producto_id      = intval($_POST['producto_id'] ?? 0);

    if (!$codigo)           $errores[] = 'El código es obligatorio.';
    if (!$nombre_producto)  $errores[] = 'El nombre del producto es obligatorio.';
    if ($precio_venta <= 0) $errores[] = 'El precio de venta debe ser mayor a 0.';
    if ($tipo_venta !== 'Suelto') {
        if (!esValorEnteroValido($stock_minimo_raw)) $errores[] = 'El stock minimo solo acepta valores enteros cuando el tipo de venta es por unidad.';
        if (!esValorEnteroValido($stock_maximo_raw)) $errores[] = 'El stock maximo solo acepta valores enteros cuando el tipo de venta es por unidad.';
        if (!$producto_id && !esValorEnteroValido($cantidad_inicial_raw)) {
            $errores[] = 'La cantidad inicial solo acepta valores enteros cuando el tipo de venta es por unidad.';
        }
    }

    if ($codigo) {
        $stmtCheck = $pdo->prepare("SELECT producto_id FROM productos WHERE codigo = ? AND producto_id != ?");
        $stmtCheck->execute([$codigo, $producto_id]);
        if ($stmtCheck->fetch()) $errores[] = 'Ya existe un producto con ese código en el catálogo.';
    }

    if (empty($errores)) {
        // Auto-insertar unidad en la tabla si no existe aún
        if ($unidad_medida !== '') {
            $pdo->prepare("INSERT IGNORE INTO unidades_medida (nombre, sucursal_id) VALUES (?, ?)")
                ->execute([$unidad_medida, intval($_SESSION['sucursal_id'])]);
        }

        if ($producto_id) {
            // Actualizar catálogo (datos compartidos)
            $pdo->prepare("
                UPDATE productos SET codigo=?,nombre_producto=?,descripcion=?,categoria_id=?,
                precio_compra=?,precio_venta=?,precio_mayoreo=?,tipo_venta=?,unidad_medida=?
                WHERE producto_id=?
            ")->execute([$codigo,$nombre_producto,$descripcion,$categoria_id,
                         $precio_compra,$precio_venta,$precio_mayoreo,
                         $tipo_venta,$unidad_medida ?: null,$producto_id]);
            // Actualizar stock de esta sucursal
            $pdo->prepare("
                UPDATE stock_sucursal SET stock_minimo=?,stock_maximo=?
                WHERE producto_id=? AND sucursal_id=?
            ")->execute([$stock_minimo,$stock_maximo,$producto_id,$_SESSION['sucursal_id']]);
        } else {
            // Insertar en catálogo (sin stock ni sucursal)
            $pdo->prepare("
                INSERT INTO productos
                (categoria_id,codigo,nombre_producto,descripcion,
                 precio_compra,precio_venta,precio_mayoreo,tipo_venta,unidad_medida,activo)
                VALUES (?,?,?,?,?,?,?,?,?,1)
            ")->execute([$categoria_id,$codigo,$nombre_producto,$descripcion,
                         $precio_compra,$precio_venta,$precio_mayoreo,
                         $tipo_venta,$unidad_medida ?: null]);
            $producto_id = $pdo->lastInsertId();

            // Insertar stock para esta sucursal
            $pdo->prepare("
                INSERT INTO stock_sucursal (producto_id,sucursal_id,stock_actual,stock_minimo,stock_maximo,activo)
                VALUES (?,?,?,?,?,1)
            ")->execute([$producto_id,$_SESSION['sucursal_id'],$cantidad_inicial,$stock_minimo,$stock_maximo]);

            if ($cantidad_inicial > 0) {
                // [FIX] Se agrega sucursal_id: antes quedaba NULL y el movimiento
                // no aparecía en el historial de ninguna sucursal
                $pdo->prepare("
                    INSERT INTO movimientos_inventario
                    (producto_id,usuario_id,sucursal_id,tipo,cantidad,stock_anterior,stock_nuevo,motivo)
                    VALUES (?,?,?,'Entrada',?,0,?,'Inventario inicial')
                ")->execute([$producto_id,$_SESSION['usuario_id'],$_SESSION['sucursal_id'],$cantidad_inicial,$cantidad_inicial]);
            }
        }

        // Actualizar proveedores
        $pdo->prepare("DELETE FROM producto_proveedor WHERE producto_id = ?")->execute([$producto_id]);
        foreach ($proveedores_sel as $idx => $prov_id) {
            if (!$prov_id) continue;
            $cod = $codigos_prov[$idx] ?? '';
            $pdo->prepare("INSERT INTO producto_proveedor (producto_id,proveedor_id,codigo_proveedor) VALUES (?,?,?)")
                ->execute([$producto_id, intval($prov_id), $cod]);
        }

        header('Location: productos.php?msg='.($esEdicion?'editado':'creado'));
        exit();
    }
}

$categorias  = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$proveedores = $pdo->query("SELECT proveedor_id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$stmtUnd = $pdo->prepare("SELECT nombre FROM unidades_medida WHERE sucursal_id = ? ORDER BY nombre ASC");
$stmtUnd->execute([$_SESSION['sucursal_id']]);
$unidadesMedida = $stmtUnd->fetchAll(PDO::FETCH_COLUMN);

// Categoría actual para pre-llenar el autocomplete
$categoriaNombreActual = '';
if ($editando && $editando['categoria_id']) {
    foreach ($categorias as $cat) {
        if ($cat['categoria_id'] == $editando['categoria_id']) {
            $categoriaNombreActual = $cat['nombre'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $editando?'Editar':'Nuevo' ?> Producto — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; display: flex; justify-content: center; }
    .form-card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 26px; width: 100%; max-width: 680px; height: fit-content; }
    .form-card h1 { font-size: 18px; font-weight: 600; color: #222; margin: 0 0 6px; }
    .form-card > p { font-size: 13px; color: #888; margin: 0 0 22px; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 18px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .seccion { margin-bottom: 22px; padding-bottom: 22px; border-bottom: 0.5px solid #f0f0f0; }
    .seccion:last-of-type { border-bottom: none; }
    .seccion-titulo { font-size: 12px; font-weight: 700; color: #aaa; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 14px; }
    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; color: #333; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .form-group textarea { min-height: 70px; resize: vertical; }
    .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
    .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .hint { font-size: 11px; color: #aaa; margin-top: 3px; }

    /* ── Autocomplete ── */
    .autocomplete-wrap { position: relative; }
    .autocomplete-wrap input { width: 100%; }
    .autocomplete-dropdown { display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #e0e0e0; border-radius: 6px; box-shadow: 0 4px 14px rgba(0,0,0,0.1); z-index: 200; max-height: 220px; overflow-y: auto; margin-top: 2px; }
    .autocomplete-dropdown.visible { display: block; }
    .ac-item { padding: 10px 14px; cursor: pointer; font-size: 13px; color: #333; border-bottom: 0.5px solid #f5f5f5; transition: background 0.1s; }
    .ac-item:last-child { border-bottom: none; }
    .ac-item:hover, .ac-item.highlighted { background: #eef8ff; color: #14ace7; }
    .ac-item strong { color: #14ace7; }
    .ac-empty { padding: 12px 14px; font-size: 13px; color: #aaa; text-align: center; }
    .ac-tag { display: inline-flex; align-items: center; gap: 6px; background: #e3f2fd; color: #1565c0; font-size: 13px; font-weight: 600; padding: 5px 12px; border-radius: 99px; margin-top: 6px; }
    .ac-tag button { background: none; border: none; color: #1565c0; cursor: pointer; font-size: 16px; line-height: 1; padding: 0; }
    .ac-tag.cat-tag { background: #e3f2fd; color: #1565c0; }
    .ac-tag.cat-tag button { color: #1565c0; }

    /* ── Precios ── */
    .margen-preview { background: #f9f9f9; border-radius: 6px; padding: 10px 12px; font-size: 12px; color: #555; margin-top: 8px; display: none; }
    .margen-preview.positivo { background: #e8f5e9; color: #2e7d32; }
    .margen-preview.negativo { background: #fdecea; color: #c0392b; }

    /* ── Tipo venta ── */
    .tipo-venta-selector { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .tipo-btn { border: 2px solid #eee; border-radius: 8px; padding: 12px; cursor: pointer; text-align: center; transition: all 0.15s; }
    .tipo-btn:hover { border-color: #ffcc80; }
    .tipo-btn.selected { border-color: #14ace7; background: #eef8ff; }
    .tipo-btn input { display: none; }
    .tipo-btn-titulo { font-size: 13px; font-weight: 700; color: #333; }
    .tipo-btn-desc { font-size: 11px; color: #888; margin-top: 2px; }

    /* ── Proveedores ── */
    .prov-entrada { background: #f9f9f9; border-radius: 8px; padding: 14px; margin-bottom: 10px; }
    .prov-entrada-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
    .prov-entrada-titulo { font-size: 12px; font-weight: 600; color: #888; }
    .btn-quitar-prov { background: #fdecea; color: #c0392b; border: none; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 600; }
    .prov-row-campos { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .prov-row-campos input { padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; width: 100%; }
    .prov-row-campos input:focus { outline: none; border-color: #14ace7; }
    .prov-tag-nombre { font-size: 13px; font-weight: 600; color: #333; }
    .btn-agregar-prov { background: #f0f0f0; color: #555; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-agregar-prov:hover { background: #e0e0e0; }

    /* ── Acciones ── */
    .acciones-form { display: flex; gap: 10px; }
    .btn-guardar { flex: 1; background: #14ace7; color: white; border: none; padding: 13px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar { background: white; color: #666; border: 1px solid #ddd; padding: 13px 22px; border-radius: 6px; font-size: 14px; cursor: pointer; text-decoration: none; display: flex; align-items: center; }
    .btn-cancelar:hover { background: #f5f5f5; }
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
            <h2><?= $editando?'Editar producto':'Nuevo producto' ?></h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <div class="form-card">
            <h1><?= $editando?'Editar producto':'Registrar nuevo producto' ?></h1>
            <p><?= $editando?'Modifica los datos del producto.':'Completa el formulario para agregar un producto al inventario.' ?></p>

            <?php if (!empty($errores)): ?>
                <div class="errores">
                    <ul><?php foreach($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="formProducto">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="producto_id" value="<?= $editando['producto_id'] ?? 0 ?>">
                <input type="hidden" name="categoria_id" id="hiddenCategoriaId" value="<?= $editando['categoria_id'] ?? '' ?>">

                <!-- ── IDENTIFICACIÓN ── -->
                <div class="seccion">
                    <div class="seccion-titulo">Identificación</div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>Código *</label>
                            <input type="text" name="codigo"
                                value="<?= htmlspecialchars($_POST['codigo'] ?? $editando['codigo'] ?? '') ?>"
                                placeholder="Ej. TUBO-3/4-PVC"
                                oninput="this.value=this.value.toUpperCase()"
                                autocomplete="off">
                            <div class="hint">Único en el catálogo. Puede ser código de barras.</div>
                        </div>

                        <!-- Categoría con autocomplete -->
                        <div class="form-group">
                            <label>Categoría</label>
                            <div class="autocomplete-wrap" id="wrapCategoria">
                                <input type="text" id="inputCategoria"
                                    placeholder="Escribe para buscar..."
                                    autocomplete="off"
                                    value="<?= htmlspecialchars($categoriaNombreActual) ?>"
                                    onfocus="abrirAc('categoria')"
                                    oninput="filtrarAc('categoria', this.value)">
                                <div class="autocomplete-dropdown" id="dropCategoria"></div>
                            </div>
                            <?php if ($categoriaNombreActual): ?>
                                <div class="ac-tag cat-tag" id="tagCategoria">
                                    <?= htmlspecialchars($categoriaNombreActual) ?>
                                    <button type="button" onclick="limpiarCategoria()">×</button>
                                </div>
                            <?php else: ?>
                                <div class="ac-tag cat-tag" id="tagCategoria" style="display:none;"></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Nombre del producto *</label>
                        <input type="text" name="nombre_producto"
                            value="<?= htmlspecialchars($_POST['nombre_producto'] ?? $editando['nombre_producto'] ?? '') ?>"
                            placeholder="Nombre completo del producto">
                    </div>

                    <div class="form-group">
                        <label>Descripción</label>
                        <textarea name="descripcion" placeholder="Descripción, especificaciones técnicas..."><?= htmlspecialchars($_POST['descripcion'] ?? $editando['descripcion'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- ── PRECIOS ── -->
                <div class="seccion">
                    <div class="seccion-titulo">Precios</div>
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Precio de compra</label>
                            <input type="number" name="precio_compra"
                                value="<?= $_POST['precio_compra'] ?? $editando['precio_compra'] ?? 0 ?>"
                                id="inputPrecioCompra"
                                class="js-zero-default"
                                step="0.01" min="0" placeholder="0.00"
                                oninput="calcularMargen()">
                        </div>
                        <div class="form-group">
                            <label>Precio de venta *</label>
                            <input type="number" name="precio_venta"
                                value="<?= $_POST['precio_venta'] ?? $editando['precio_venta'] ?? '' ?>"
                                id="inputPrecioVenta"
                                class="js-zero-default"
                                step="0.01" min="0.01" placeholder="0.00"
                                oninput="calcularMargen()">
                        </div>
                        <div class="form-group">
                            <label>Precio mayoreo</label>
                            <input type="number" name="precio_mayoreo"
                                value="<?= $_POST['precio_mayoreo'] ?? $editando['precio_mayoreo'] ?? 0 ?>"
                                class="js-zero-default"
                                step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-row-2">
                        <div class="form-group">
                            <label>Porcentaje de ganancia</label>
                            <input type="number" id="inputPorcentajeGanancia"
                                class="js-zero-default"
                                step="0.01" min="0" placeholder="0.00"
                                oninput="actualizarPrecioDesdeGanancia()">
                            <div class="hint">Al modificarlo se recalcula el precio de venta.</div>
                        </div>
                    </div>
                    <div class="margen-preview" id="margenPreview"></div>
                </div>

                <!-- ── STOCK ── -->
                <div class="seccion">
                    <div class="seccion-titulo">Control de stock</div>
                    <div class="form-row-3">
                        <?php if (!$editando): ?>
                        <div class="form-group">
                            <label>Cantidad inicial</label>
                            <input type="text" name="cantidad_inicial"
                                value="<?= $_POST['cantidad_inicial'] ?? 0 ?>"
                                class="js-zero-default js-stock-control"
                                step="1" min="0" placeholder="0" inputmode="decimal" lang="en">
                            <div class="hint">Stock con el que arranca.</div>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label>Stock mínimo</label>
                            <input type="text" name="stock_minimo"
                                value="<?= $_POST['stock_minimo'] ?? $editando['stock_minimo'] ?? 0 ?>"
                                class="js-zero-default js-stock-control"
                                step="1" min="0" placeholder="0" inputmode="decimal" lang="en">
                            <div class="hint">Dispara alerta de reabasto.</div>
                        </div>
                        <div class="form-group">
                            <label>Stock máximo</label>
                            <input type="text" name="stock_maximo"
                                value="<?= $_POST['stock_maximo'] ?? $editando['stock_maximo'] ?? 0 ?>"
                                class="js-zero-default js-stock-control"
                                step="1" min="0" placeholder="0" inputmode="decimal" lang="en">
                            <div class="hint" id="hintStockDecimales">Si eliges Suelto / Granel puedes usar decimales.</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Tipo de venta</label>
                        <?php $tipoActual = $_POST['tipo_venta'] ?? $editando['tipo_venta'] ?? 'Unidad'; ?>
                        <div class="tipo-venta-selector">
                            <label class="tipo-btn <?= $tipoActual==='Unidad'?'selected':'' ?>" onclick="seleccionarTipo('Unidad')">
                                <input type="radio" name="tipo_venta" value="Unidad" <?= $tipoActual==='Unidad'?'checked':'' ?>>
                                <div class="tipo-btn-titulo">Unidad</div>
                                <div class="tipo-btn-desc">Se vende por piezas enteras</div>
                            </label>
                            <label class="tipo-btn <?= $tipoActual==='Suelto'?'selected':'' ?>" onclick="seleccionarTipo('Suelto')">
                                <input type="radio" name="tipo_venta" value="Suelto" <?= $tipoActual==='Suelto'?'checked':'' ?>>
                                <div class="tipo-btn-titulo">Suelto / Granel</div>
                                <div class="tipo-btn-desc">Se vende por metros, kg, litros...</div>
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Unidad de medida</label>
                        <?php
                        $unidadActual = $_POST['unidad_medida'] ?? $editando['unidad_medida'] ?? '';
                        $enLista = in_array($unidadActual, $unidadesMedida, true);
                        ?>
                        <select name="unidad_medida" id="selectUnidadMedida" onchange="toggleUnidadPersonalizada(this.value)">
                            <option value="">— Sin unidad —</option>
                            <?php foreach ($unidadesMedida as $u): ?>
                                <option value="<?= htmlspecialchars($u) ?>" <?= $unidadActual === $u ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u) ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="__otra__" <?= ($unidadActual && !$enLista) ? 'selected' : '' ?>>— Escribir otra —</option>
                        </select>
                        <input type="text" id="inputUnidadPersonalizada" name="unidad_medida_custom"
                            value="<?= ($unidadActual && !$enLista) ? htmlspecialchars($unidadActual) : '' ?>"
                            placeholder="Escribe la unidad..."
                            style="margin-top:8px;display:<?= ($unidadActual && !$enLista) ? 'block' : 'none' ?>;">
                        <div class="hint">Aparece en el punto de venta junto a la cantidad.
                            <a href="unidades.php" style="color:#14ace7;">Administrar unidades</a>
                        </div>
                    </div>
                </div>

                <!-- ── PROVEEDORES ── -->
                <div class="seccion">
                    <div class="seccion-titulo">Proveedores (opcional)</div>
                    <div id="proveedoresLista">
                        <?php
                        $provsIniciales = !empty($proveedoresProducto)
                            ? $proveedoresProducto
                            : [['proveedor_id'=>'','nombre_proveedor'=>'','codigo_proveedor'=>'']];
                        foreach ($provsIniciales as $idx => $pp):
                        ?>
                        <div class="prov-entrada" id="provEntrada<?= $idx ?>">
                            <div class="prov-entrada-header">
                                <span class="prov-entrada-titulo">Proveedor <?= $idx+1 ?></span>
                                <button type="button" class="btn-quitar-prov" onclick="quitarProv(<?= $idx ?>)">Quitar</button>
                            </div>
                            <!-- Hidden para enviar el id del proveedor -->
                            <input type="hidden" name="proveedores[]" id="hiddenProv<?= $idx ?>" value="<?= $pp['proveedor_id'] ?>">
                            <div class="prov-row-campos">
                                <!-- Autocomplete de proveedor -->
                                <div>
                                    <div class="autocomplete-wrap">
                                        <input type="text"
                                            id="inputProv<?= $idx ?>"
                                            placeholder="Buscar proveedor..."
                                            autocomplete="off"
                                            value="<?= htmlspecialchars($pp['nombre_proveedor']??'') ?>"
                                            onfocus="abrirAcProv(<?= $idx ?>)"
                                            oninput="filtrarAcProv(<?= $idx ?>, this.value)">
                                        <div class="autocomplete-dropdown" id="dropProv<?= $idx ?>"></div>
                                    </div>
                                </div>
                                <!-- Código del proveedor -->
                                <div>
                                    <input type="text" name="codigos_prov[]"
                                        value="<?= htmlspecialchars($pp['codigo_proveedor']??'') ?>"
                                        placeholder="Código del proveedor (opcional)">
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn-agregar-prov" onclick="agregarProv()">+ Agregar otro proveedor</button>
                </div>

                <div class="acciones-form">
                    <a class="btn-cancelar" href="productos.php">Cancelar</a>
                    <button class="btn-guardar" type="submit">
                        <?= $editando?'Guardar cambios':'Registrar producto' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Datos para autocomplete ────────────────────────────────────────────────
const CATEGORIAS = <?= json_encode(array_map(fn($c) => [
    'id'     => $c['categoria_id'],
    'nombre' => $c['nombre']
], $categorias)) ?>;

const PROVEEDORES = <?= json_encode(array_map(fn($p) => [
    'id'     => $p['proveedor_id'],
    'nombre' => $p['nombre']
], $proveedores)) ?>;

let provIdx = <?= count($provsIniciales) ?>;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

// ── Utilidades ─────────────────────────────────────────────────────────────
function esc(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function resaltarCoincidencia(texto, busqueda) {
    if (!busqueda) return esc(texto);
    const re = new RegExp(`(${busqueda.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi');
    return esc(texto).replace(re, '<strong>$1</strong>');
}

// ── Autocomplete CATEGORÍA ─────────────────────────────────────────────────
function abrirAc(tipo) {
    if (tipo === 'categoria') filtrarAc('categoria', document.getElementById('inputCategoria').value);
}

function filtrarAc(tipo, valor) {
    const drop = document.getElementById('dropCategoria');
    const lista = CATEGORIAS.filter(c =>
        c.nombre.toLowerCase().includes(valor.toLowerCase())
    );

    if (!lista.length) {
        drop.innerHTML = `<div class="ac-empty">Sin coincidencias</div>`;
    } else {
        drop.innerHTML = lista.map(c => `
            <div class="ac-item" onclick="seleccionarCategoria(${c.id}, '${esc(c.nombre)}')">
                ${resaltarCoincidencia(c.nombre, valor)}
            </div>`).join('');
    }
    drop.classList.add('visible');
}

function seleccionarCategoria(id, nombre) {
    document.getElementById('hiddenCategoriaId').value = id;
    document.getElementById('inputCategoria').value    = '';
    document.getElementById('dropCategoria').classList.remove('visible');

    const tag = document.getElementById('tagCategoria');
    tag.innerHTML = `${esc(nombre)} <button type="button" onclick="limpiarCategoria()">×</button>`;
    tag.className = 'ac-tag cat-tag';
    tag.style.display = 'inline-flex';
}

function limpiarCategoria() {
    document.getElementById('hiddenCategoriaId').value = '';
    document.getElementById('inputCategoria').value    = '';
    const tag = document.getElementById('tagCategoria');
    tag.style.display = 'none';
}

// ── Autocomplete PROVEEDORES ───────────────────────────────────────────────
function abrirAcProv(idx) {
    filtrarAcProv(idx, document.getElementById('inputProv'+idx)?.value || '');
}

function filtrarAcProv(idx, valor) {
    const drop  = document.getElementById('dropProv'+idx);
    if (!drop) return;

    const lista = PROVEEDORES.filter(p =>
        p.nombre.toLowerCase().includes(valor.toLowerCase())
    );

    if (!lista.length) {
        drop.innerHTML = `<div class="ac-empty">Sin coincidencias</div>`;
    } else {
        drop.innerHTML = lista.map(p => `
            <div class="ac-item" onclick="seleccionarProv(${idx}, ${p.id}, '${esc(p.nombre)}')">
                ${resaltarCoincidencia(p.nombre, valor)}
            </div>`).join('');
    }
    drop.classList.add('visible');
}

function seleccionarProv(idx, id, nombre) {
    const hidden = document.getElementById('hiddenProv'+idx);
    const input  = document.getElementById('inputProv'+idx);
    const drop   = document.getElementById('dropProv'+idx);
    if (hidden) hidden.value = id;
    if (input)  input.value  = nombre;
    if (drop)   drop.classList.remove('visible');
}

// ── Agregar / quitar proveedor ─────────────────────────────────────────────
function agregarProv() {
    const lista = document.getElementById('proveedoresLista');
    const idx   = provIdx;
    const div   = document.createElement('div');
    div.className = 'prov-entrada';
    div.id        = 'provEntrada' + idx;
    div.innerHTML = `
        <div class="prov-entrada-header">
            <span class="prov-entrada-titulo">Proveedor ${lista.children.length + 1}</span>
            <button type="button" class="btn-quitar-prov" onclick="quitarProv(${idx})">Quitar</button>
        </div>
        <input type="hidden" name="proveedores[]" id="hiddenProv${idx}" value="">
        <div class="prov-row-campos">
            <div>
                <div class="autocomplete-wrap">
                    <input type="text" id="inputProv${idx}"
                        placeholder="Buscar proveedor..."
                        autocomplete="off"
                        onfocus="abrirAcProv(${idx})"
                        oninput="filtrarAcProv(${idx}, this.value)">
                    <div class="autocomplete-dropdown" id="dropProv${idx}"></div>
                </div>
            </div>
            <div>
                <input type="text" name="codigos_prov[]" placeholder="Código del proveedor (opcional)">
            </div>
        </div>`;
    lista.appendChild(div);
    provIdx++;
}

function quitarProv(idx) {
    const el = document.getElementById('provEntrada' + idx);
    if (el) el.remove();
}

// ── Cerrar dropdowns al hacer click fuera ─────────────────────────────────
document.addEventListener('click', function(e) {
    if (!e.target.closest('.autocomplete-wrap')) {
        document.querySelectorAll('.autocomplete-dropdown').forEach(d => d.classList.remove('visible'));
    }
});

// ── Margen de ganancia ─────────────────────────────────────────────────────
function calcularMargen() {
    const compra  = parseFloat(document.getElementById('inputPrecioCompra').value) || 0;
    const venta   = parseFloat(document.getElementById('inputPrecioVenta').value) || 0;
    const preview = document.getElementById('margenPreview');
    const inputGanancia = document.getElementById('inputPorcentajeGanancia');

    if (compra > 0 && venta > 0) {
        const utilidad = venta - compra;
        const margen   = ((utilidad / compra) * 100).toFixed(1);
        if (document.activeElement !== inputGanancia) {
            inputGanancia.value = margen;
        }
        preview.style.display = 'block';
        preview.className = `margen-preview ${utilidad >= 0 ? 'positivo' : 'negativo'}`;
        preview.textContent = utilidad >= 0
            ? `✅ Utilidad: $${utilidad.toFixed(2)} · Margen: ${margen}%`
            : `⚠ Precio de venta menor al costo · Pérdida: $${Math.abs(utilidad).toFixed(2)}`;
    } else {
        preview.style.display = 'none';
        if (inputGanancia && document.activeElement !== inputGanancia) {
            inputGanancia.value = '';
        }
    }
}

// ── Tipo de venta ──────────────────────────────────────────────────────────
function actualizarPrecioDesdeGanancia() {
    const compra = parseFloat(document.getElementById('inputPrecioCompra').value) || 0;
    const porcentaje = parseFloat(document.getElementById('inputPorcentajeGanancia').value) || 0;
    if (compra <= 0) {
        calcularMargen();
        return;
    }
    document.getElementById('inputPrecioVenta').value = (compra * (1 + (porcentaje / 100))).toFixed(2);
    calcularMargen();
}

function seleccionarTipo(valor) {
    document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('selected'));
    const radio = document.querySelector(`input[name="tipo_venta"][value="${valor}"]`);
    if (radio) { radio.checked = true; radio.closest('.tipo-btn').classList.add('selected'); }
    actualizarModoCantidades();
}

function actualizarModoCantidades() {
    const tipoVenta = document.querySelector('input[name="tipo_venta"]:checked')?.value || 'Unidad';
    const permiteDecimal = tipoVenta === 'Suelto';
    document.querySelectorAll('.js-stock-control').forEach((input) => {
        input.step = permiteDecimal ? '0.001' : '1';
        input.setAttribute('inputmode', permiteDecimal ? 'decimal' : 'numeric');
        input.dataset.decimales = permiteDecimal ? 'si' : 'no';
        if (!permiteDecimal && String(input.value).includes('.')) {
            const entero = Math.floor(parseFloat(input.value) || 0);
            input.value = String(entero);
        }
    });
    const hint = document.getElementById('hintStockDecimales');
    if (hint) {
        hint.textContent = permiteDecimal
            ? 'Modo Suelto / Granel activo: puedes usar decimales como 0.25, 1.5 o 2.75.'
            : 'Modo Unidad activo: aquí solo se aceptan números enteros.';
    }
}

// ── Unidad de medida: toggle campo personalizado ───────────────────────────
function toggleUnidadPersonalizada(val) {
    const inp = document.getElementById('inputUnidadPersonalizada');
    inp.style.display = (val === '__otra__') ? 'block' : 'none';
    if (val !== '__otra__') inp.value = '';
}

document.getElementById('formProducto').addEventListener('submit', function() {
    const sel = document.getElementById('selectUnidadMedida');
    const inp = document.getElementById('inputUnidadPersonalizada');
    if (sel && sel.value === '__otra__') {
        const h = document.createElement('input');
        h.type  = 'hidden';
        h.name  = 'unidad_medida';
        h.value = inp.value.trim();
        this.appendChild(h);
        sel.name = '';
    }
    if (inp) inp.name = '';
});

// ── Inicializar margen al cargar en modo edición ───────────────────────────
calcularMargen();

document.querySelectorAll('.js-zero-default').forEach((input) => {
    input.addEventListener('focus', function() {
        const valor = String(this.value ?? '').trim();
        if (valor === '0' || valor === '0.00' || valor === '0,00') {
            this.value = '';
        }
    });
    input.addEventListener('blur', function() {
        if (String(this.value ?? '').trim() === '') {
            this.value = this.step === '1' ? '0' : '0.00';
            if (typeof this.oninput === 'function') this.oninput();
        }
    });
});

document.querySelectorAll('.js-stock-control').forEach((input) => {
    input.addEventListener('input', function() {
        if (this.dataset.decimales === 'si') {
            this.value = this.value.replace(/[^0-9.,]/g, '');
            this.value = this.value.replace(',', '.');
            const partes = this.value.split('.');
            if (partes.length > 2) {
                this.value = partes.shift() + '.' + partes.join('');
            }
            if (this.value.includes('.')) {
                const [entera, decimal = ''] = this.value.split('.');
                this.value = entera + '.' + decimal.slice(0, 3);
            }
            return;
        }
        this.value = this.value.replace(/[^\d]/g, '');
    });
});

document.querySelectorAll('input[name="tipo_venta"]').forEach((radio) => {
    radio.addEventListener('change', function() {
        seleccionarTipo(this.value);
    });
});

actualizarModoCantidades();
calcularMargen();
</script>
</body>
</html>
