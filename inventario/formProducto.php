<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

$editando  = null;
$errores   = [];
$esEdicion = isset($_GET['id']);
$proveedoresProducto = [];

if ($esEdicion) {
    $stmt = $pdo->prepare("SELECT * FROM productos WHERE producto_id = ? AND sucursal_id = ?");
    $stmt->execute([intval($_GET['id']), $_SESSION['sucursal_id']]);
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
    $codigo           = strtoupper(trim($_POST['codigo'] ?? ''));
    $nombre_producto  = trim($_POST['nombre_producto'] ?? '');
    $descripcion      = trim($_POST['descripcion'] ?? '');
    $categoria_id     = intval($_POST['categoria_id'] ?? 0) ?: null;
    $precio_compra    = floatval($_POST['precio_compra'] ?? 0);
    $precio_venta     = floatval($_POST['precio_venta'] ?? 0);
    $precio_mayoreo   = floatval($_POST['precio_mayoreo'] ?? 0);
    $stock_minimo     = floatval($_POST['stock_minimo'] ?? 0);
    $stock_maximo     = floatval($_POST['stock_maximo'] ?? 0);
    $tipo_venta       = $_POST['tipo_venta'] ?? 'Unidad';
    $cantidad_inicial = floatval($_POST['cantidad_inicial'] ?? 0);
    $proveedores_sel  = $_POST['proveedores'] ?? [];
    $codigos_prov     = $_POST['codigos_prov'] ?? [];
    $producto_id      = intval($_POST['producto_id'] ?? 0);

    if (!$codigo)           $errores[] = 'El cÃ³digo es obligatorio.';
    if (!$nombre_producto)  $errores[] = 'El nombre del producto es obligatorio.';
    if ($precio_venta <= 0) $errores[] = 'El precio de venta debe ser mayor a 0.';

    if ($codigo) {
        $stmtCheck = $pdo->prepare("SELECT producto_id FROM productos WHERE codigo = ? AND sucursal_id = ? AND producto_id != ?");
        $stmtCheck->execute([$codigo, $_SESSION['sucursal_id'], $producto_id]);
        if ($stmtCheck->fetch()) $errores[] = 'Ya existe un producto con ese cÃ³digo en esta sucursal.';
    }

    if (empty($errores)) {
        if ($producto_id) {
            $pdo->prepare("
                UPDATE productos SET codigo=?,nombre_producto=?,descripcion=?,categoria_id=?,
                precio_compra=?,precio_venta=?,precio_mayoreo=?,stock_minimo=?,stock_maximo=?,tipo_venta=?
                WHERE producto_id=? AND sucursal_id=?
            ")->execute([$codigo,$nombre_producto,$descripcion,$categoria_id,
                         $precio_compra,$precio_venta,$precio_mayoreo,
                         $stock_minimo,$stock_maximo,$tipo_venta,
                         $producto_id,$_SESSION['sucursal_id']]);
        } else {
            $pdo->prepare("
                INSERT INTO productos
                (sucursal_id,categoria_id,codigo,nombre_producto,descripcion,
                 precio_compra,precio_venta,precio_mayoreo,stock_actual,
                 stock_minimo,stock_maximo,tipo_venta,activo)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)
            ")->execute([$_SESSION['sucursal_id'],$categoria_id,$codigo,$nombre_producto,
                         $descripcion,$precio_compra,$precio_venta,$precio_mayoreo,
                         $cantidad_inicial,$stock_minimo,$stock_maximo,$tipo_venta]);
            $producto_id = $pdo->lastInsertId();

            if ($cantidad_inicial > 0) {
                $pdo->prepare("
                    INSERT INTO movimientos_inventario
                    (producto_id,usuario_id,tipo,cantidad,stock_anterior,stock_nuevo,motivo)
                    VALUES (?,?,'Entrada',?,0,?,'Inventario inicial')
                ")->execute([$producto_id,$_SESSION['usuario_id'],$cantidad_inicial,$cantidad_inicial]);
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

// CategorÃ­a actual para pre-llenar el autocomplete
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
    <title><?= $editando?'Editar':'Nuevo' ?> Producto â€” FerreterÃ­a Aldrete</title>
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

    /* â”€â”€ Autocomplete â”€â”€ */
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

    /* â”€â”€ Precios â”€â”€ */
    .margen-preview { background: #f9f9f9; border-radius: 6px; padding: 10px 12px; font-size: 12px; color: #555; margin-top: 8px; display: none; }
    .margen-preview.positivo { background: #e8f5e9; color: #2e7d32; }
    .margen-preview.negativo { background: #fdecea; color: #c0392b; }

    /* â”€â”€ Tipo venta â”€â”€ */
    .tipo-venta-selector { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .tipo-btn { border: 2px solid #eee; border-radius: 8px; padding: 12px; cursor: pointer; text-align: center; transition: all 0.15s; }
    .tipo-btn:hover { border-color: #ffcc80; }
    .tipo-btn.selected { border-color: #14ace7; background: #eef8ff; }
    .tipo-btn input { display: none; }
    .tipo-btn-titulo { font-size: 13px; font-weight: 700; color: #333; }
    .tipo-btn-desc { font-size: 11px; color: #888; margin-top: 2px; }

    /* â”€â”€ Proveedores â”€â”€ */
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

    /* â”€â”€ Acciones â”€â”€ */
    .acciones-form { display: flex; gap: 10px; }
    .btn-guardar { flex: 1; background: #14ace7; color: white; border: none; padding: 13px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar { background: white; color: #666; border: 1px solid #ddd; padding: 13px 22px; border-radius: 6px; font-size: 14px; cursor: pointer; text-decoration: none; display: flex; align-items: center; }
    .btn-cancelar:hover { background: #f5f5f5; }
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
        <a class="menu-item" href="historialMovimientos.php">Historial de movimientos</a>
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
            <h2><?= $editando?'Editar producto':'Nuevo producto' ?></h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">â€” <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesiÃ³n</button></form>
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
                <input type="hidden" name="producto_id" value="<?= $editando['producto_id'] ?? 0 ?>">
                <input type="hidden" name="categoria_id" id="hiddenCategoriaId" value="<?= $editando['categoria_id'] ?? '' ?>">

                <!-- â”€â”€ IDENTIFICACIÃ“N â”€â”€ -->
                <div class="seccion">
                    <div class="seccion-titulo">IdentificaciÃ³n</div>

                    <div class="form-row-2">
                        <div class="form-group">
                            <label>CÃ³digo *</label>
                            <input type="text" name="codigo"
                                value="<?= htmlspecialchars($_POST['codigo'] ?? $editando['codigo'] ?? '') ?>"
                                placeholder="Ej. TUBO-3/4-PVC"
                                oninput="this.value=this.value.toUpperCase()"
                                autocomplete="off">
                            <div class="hint">Ãšnico por sucursal. Puede ser cÃ³digo de barras.</div>
                        </div>

                        <!-- CategorÃ­a con autocomplete -->
                        <div class="form-group">
                            <label>CategorÃ­a</label>
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
                                    <button type="button" onclick="limpiarCategoria()">Ã—</button>
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
                        <label>DescripciÃ³n</label>
                        <textarea name="descripcion" placeholder="DescripciÃ³n, especificaciones tÃ©cnicas..."><?= htmlspecialchars($_POST['descripcion'] ?? $editando['descripcion'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- â”€â”€ PRECIOS â”€â”€ -->
                <div class="seccion">
                    <div class="seccion-titulo">Precios</div>
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Precio de compra</label>
                            <input type="number" name="precio_compra"
                                value="<?= $_POST['precio_compra'] ?? $editando['precio_compra'] ?? 0 ?>"
                                step="0.01" min="0" placeholder="0.00"
                                oninput="calcularMargen()">
                        </div>
                        <div class="form-group">
                            <label>Precio de venta *</label>
                            <input type="number" name="precio_venta"
                                value="<?= $_POST['precio_venta'] ?? $editando['precio_venta'] ?? '' ?>"
                                step="0.01" min="0.01" placeholder="0.00"
                                oninput="calcularMargen()">
                        </div>
                        <div class="form-group">
                            <label>Precio mayoreo</label>
                            <input type="number" name="precio_mayoreo"
                                value="<?= $_POST['precio_mayoreo'] ?? $editando['precio_mayoreo'] ?? 0 ?>"
                                step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="margen-preview" id="margenPreview"></div>
                </div>

                <!-- â”€â”€ STOCK â”€â”€ -->
                <div class="seccion">
                    <div class="seccion-titulo">Control de stock</div>
                    <div class="form-row-3">
                        <?php if (!$editando): ?>
                        <div class="form-group">
                            <label>Cantidad inicial</label>
                            <input type="number" name="cantidad_inicial"
                                value="<?= $_POST['cantidad_inicial'] ?? 0 ?>"
                                step="1" min="0" placeholder="0">
                            <div class="hint">Stock con el que arranca.</div>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label>Stock mÃ­nimo</label>
                            <input type="number" name="stock_minimo"
                                value="<?= $_POST['stock_minimo'] ?? $editando['stock_minimo'] ?? 0 ?>"
                                step="1" min="0" placeholder="0">
                            <div class="hint">Dispara alerta de reabasto.</div>
                        </div>
                        <div class="form-group">
                            <label>Stock mÃ¡ximo</label>
                            <input type="number" name="stock_maximo"
                                value="<?= $_POST['stock_maximo'] ?? $editando['stock_maximo'] ?? 0 ?>"
                                step="1" min="0" placeholder="0">
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
                </div>

                <!-- â”€â”€ PROVEEDORES â”€â”€ -->
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
                                <!-- CÃ³digo del proveedor -->
                                <div>
                                    <input type="text" name="codigos_prov[]"
                                        value="<?= htmlspecialchars($pp['codigo_proveedor']??'') ?>"
                                        placeholder="CÃ³digo del proveedor (opcional)">
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
// â”€â”€ Datos para autocomplete â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ Utilidades â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function esc(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function resaltarCoincidencia(texto, busqueda) {
    if (!busqueda) return esc(texto);
    const re = new RegExp(`(${busqueda.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi');
    return esc(texto).replace(re, '<strong>$1</strong>');
}

// â”€â”€ Autocomplete CATEGORÃA â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
    tag.innerHTML = `${esc(nombre)} <button type="button" onclick="limpiarCategoria()">Ã—</button>`;
    tag.className = 'ac-tag cat-tag';
    tag.style.display = 'inline-flex';
}

function limpiarCategoria() {
    document.getElementById('hiddenCategoriaId').value = '';
    document.getElementById('inputCategoria').value    = '';
    const tag = document.getElementById('tagCategoria');
    tag.style.display = 'none';
}

// â”€â”€ Autocomplete PROVEEDORES â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

// â”€â”€ Agregar / quitar proveedor â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
                <input type="text" name="codigos_prov[]" placeholder="CÃ³digo del proveedor (opcional)">
            </div>
        </div>`;
    lista.appendChild(div);
    provIdx++;
}

function quitarProv(idx) {
    const el = document.getElementById('provEntrada' + idx);
    if (el) el.remove();
}

// â”€â”€ Cerrar dropdowns al hacer click fuera â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.addEventListener('click', function(e) {
    if (!e.target.closest('.autocomplete-wrap')) {
        document.querySelectorAll('.autocomplete-dropdown').forEach(d => d.classList.remove('visible'));
    }
});

// â”€â”€ Margen de ganancia â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function calcularMargen() {
    const compra  = parseFloat(document.querySelector('[name=precio_compra]').value) || 0;
    const venta   = parseFloat(document.querySelector('[name=precio_venta]').value) || 0;
    const preview = document.getElementById('margenPreview');

    if (compra > 0 && venta > 0) {
        const utilidad = venta - compra;
        const margen   = ((utilidad / compra) * 100).toFixed(1);
        preview.style.display = 'block';
        preview.className = `margen-preview ${utilidad >= 0 ? 'positivo' : 'negativo'}`;
        preview.textContent = utilidad >= 0
            ? `âœ… Utilidad: $${utilidad.toFixed(2)} Â· Margen: ${margen}%`
            : `âš  Precio de venta menor al costo Â· PÃ©rdida: $${Math.abs(utilidad).toFixed(2)}`;
    } else {
        preview.style.display = 'none';
    }
}

// â”€â”€ Tipo de venta â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function seleccionarTipo(valor) {
    document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('selected'));
    const radio = document.querySelector(`input[name="tipo_venta"][value="${valor}"]`);
    if (radio) { radio.checked = true; radio.closest('.tipo-btn').classList.add('selected'); }
}

// â”€â”€ Inicializar margen al cargar en modo ediciÃ³n â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
calcularMargen();
</script>
</body>
</html>

