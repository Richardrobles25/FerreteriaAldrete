<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

if (isset($_GET['toggle'])) {
    $pdo->prepare("UPDATE paquetes SET activo = NOT activo WHERE paquete_id = ?")->execute([intval($_GET['toggle'])]);
    header('Location: paquetes.php'); exit();
}

if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    $pdo->prepare("DELETE FROM paquete_productos WHERE paquete_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM paquetes WHERE paquete_id = ?")->execute([$id]);
    header('Location: paquetes.php?msg=eliminado'); exit();
}

$errores      = [];
$editando     = null;
$prodsPaquete = [];

if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM paquetes WHERE paquete_id = ?");
    $stmt->execute([intval($_GET['editar'])]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editando) {
        $stmtPP = $pdo->prepare("
            SELECT pp.producto_id, pp.cantidad,
                   p.nombre_producto, p.codigo,
                   COALESCE(px.precio_venta, 0) AS precio_venta
            FROM paquete_productos pp
            JOIN (
                SELECT producto_id, MIN(nombre_producto) AS nombre_producto, MIN(codigo) AS codigo
                FROM productos WHERE activo = 1 GROUP BY producto_id
            ) p ON pp.producto_id = p.producto_id
            LEFT JOIN productos px ON pp.producto_id = px.producto_id AND px.sucursal_id = ?
            WHERE pp.paquete_id = ?
        ");
        $stmtPP->execute([$_SESSION['sucursal_id'], $editando['paquete_id']]);
        $prodsPaquete = $stmtPP->fetchAll(PDO::FETCH_ASSOC);
    }
}

// CÃ³digo sugerido
$stmtUltimo     = $pdo->query("SELECT COALESCE(MAX(paquete_id),0)+1 FROM paquetes");
$siguienteId    = intval($stmtUltimo->fetchColumn());
$codigoSugerido = 'PAQ'.str_pad($siguienteId, 4, '0', STR_PAD_LEFT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre         = trim($_POST['nombre'] ?? '');
    $codigo         = strtoupper(trim($_POST['codigo'] ?? ''));
    $descripcion    = trim($_POST['descripcion'] ?? '');
    $precio_paquete = floatval($_POST['precio_paquete'] ?? 0);
    $items          = json_decode($_POST['items_paquete'] ?? '[]', true);
    $paquete_id     = intval($_POST['paquete_id'] ?? 0);

    if (!$nombre)             $errores[] = 'El nombre es obligatorio.';
    if (!$codigo)             $errores[] = 'El cÃ³digo es obligatorio.';
    if ($precio_paquete <= 0) $errores[] = 'El precio debe ser mayor a 0.';
    if (empty($items))        $errores[] = 'Agrega al menos un producto.';

    if ($codigo) {
        $stmtCheck = $pdo->prepare("SELECT paquete_id FROM paquetes WHERE codigo = ? AND paquete_id != ?");
        $stmtCheck->execute([$codigo, $paquete_id]);
        if ($stmtCheck->fetch()) $errores[] = 'El cÃ³digo ya existe en otro paquete.';
    }

    if (empty($errores)) {
        if ($paquete_id) {
            $pdo->prepare("UPDATE paquetes SET codigo=?, nombre=?, descripcion=?, precio_paquete=? WHERE paquete_id=?")
                ->execute([$codigo, $nombre, $descripcion, $precio_paquete, $paquete_id]);
            $pdo->prepare("DELETE FROM paquete_productos WHERE paquete_id = ?")->execute([$paquete_id]);
        } else {
            $pdo->prepare("INSERT INTO paquetes (codigo, nombre, descripcion, precio_paquete, activo) VALUES (?,?,?,?,1)")
                ->execute([$codigo, $nombre, $descripcion, $precio_paquete]);
            $paquete_id = $pdo->lastInsertId();
        }
        foreach ($items as $item) {
            $pdo->prepare("INSERT INTO paquete_productos (paquete_id, producto_id, cantidad) VALUES (?,?,?)")
                ->execute([$paquete_id, intval($item['producto_id']), floatval($item['cantidad'])]);
        }
        header('Location: paquetes.php?msg='.($paquete_id&&$_POST['paquete_id']?'editado':'creado'));
        exit();
    }
}

// Listar paquetes globales
$stmt     = $pdo->query("SELECT * FROM paquetes ORDER BY activo DESC, nombre ASC");
$paquetes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Productos de esta sucursal para el formulario
$stmtProds = $pdo->prepare("
    SELECT producto_id, codigo, nombre_producto, precio_venta
    FROM productos
    WHERE sucursal_id = ? AND activo = 1
    ORDER BY nombre_producto ASC
");
$stmtProds->execute([$_SESSION['sucursal_id']]);
$productos = $stmtProds->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paquetes â€” FerreterÃ­a Aldrete</title>
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
    .content { flex: 1; padding: 20px; overflow-y: auto; display: grid; grid-template-columns: 1fr 390px; gap: 16px; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 18px; }
    .card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 14px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .global-badge { background: #e3f2fd; color: #1565c0; font-size: 11px; padding: 2px 8px; border-radius: 99px; font-weight: 600; }
    .paquete-item { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 16px; margin-bottom: 12px; }
    .paquete-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
    .paquete-info h4 { font-size: 14px; color: #333; margin: 0 0 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .paquete-codigo { font-size: 11px; color: #14ace7; font-weight: 700; background: #e3f2fd; padding: 2px 8px; border-radius: 99px; }
    .paquete-desc { font-size: 12px; color: #888; margin: 2px 0 0; }
    .paquete-precios { font-size: 12px; color: #aaa; margin-top: 4px; }
    .ahorro-label { color: #2e7d32; font-weight: 600; }
    .paquete-precio { font-size: 20px; font-weight: 700; color: #14ace7; white-space: nowrap; margin-left: 16px; }
    .paquete-prods { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 10px; }
    .prod-tag { background: #f5f5f5; font-size: 11px; padding: 3px 10px; border-radius: 99px; color: #555; }
    .acciones { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
    .btn-accion { padding: 5px 11px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #e3f2fd; color: #1565c0; }
    .btn-editar:hover { background: #bbdefb; }
    .btn-activar { background: #e8f5e9; color: #2e7d32; }
    .btn-desactivar { background: #fff8e1; color: #1565c0; }
    .btn-eliminar { background: #fdecea; color: #c0392b; }
    .btn-eliminar:hover { background: #ffcdd2; }
    .badge-inactivo { background: #f0f0f0; color: #999; font-size: 11px; padding: 2px 8px; border-radius: 99px; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; }
    .form-group { margin-bottom: 13px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 600; }
    .form-group input { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .form-group input:focus { outline: none; border-color: #14ace7; }
    .hint { font-size: 11px; color: #aaa; margin-top: 3px; }
    .codigo-row { display: flex; gap: 8px; }
    .codigo-row input { flex: 1; }
    .btn-generar { background: #f0f0f0; color: #555; border: none; padding: 9px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; white-space: nowrap; }
    .btn-generar:hover { background: #e0e0e0; }
    .agregar-prod-row { display: flex; gap: 6px; margin-bottom: 10px; align-items: center; }
    .agregar-prod-row select { flex: 1; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 12px; }
    .agregar-prod-row select:focus { outline: none; border-color: #14ace7; }
    .agregar-prod-row input[type=number] { width: 72px; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; text-align: center; }
    .agregar-prod-row input[type=number]:focus { outline: none; border-color: #14ace7; }
    .btn-add-prod { background: #14ace7; color: white; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 700; flex-shrink: 0; }
    .lista-paq { border: 0.5px solid #eee; border-radius: 6px; min-height: 54px; margin-bottom: 13px; overflow: hidden; }
    .paq-item { display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; color: #444; }
    .paq-item:last-child { border-bottom: none; }
    .paq-item-info { flex: 1; }
    .paq-item-nombre { font-weight: 500; }
    .paq-item-sub { font-size: 11px; color: #aaa; margin-top: 2px; }
    .paq-vacio { text-align: center; color: #aaa; font-size: 12px; padding: 16px; }
    .btn-quitar-paq { background: none; border: none; color: #c0392b; cursor: pointer; font-size: 18px; line-height: 1; padding: 0 4px; flex-shrink: 0; }
    .precio-hint { background: #f9f9f9; border-radius: 6px; padding: 10px 12px; font-size: 12px; margin-bottom: 13px; }
    .precio-hint.con-ahorro { background: #e8f5e9; color: #2e7d32; }
    .precio-hint.sin-ahorro { background: #fff8e1; color: #1565c0; }
    .btn-guardar { width: 100%; background: #14ace7; color: white; border: none; padding: 11px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar-edit { width: 100%; background: white; color: #666; border: 1px solid #ddd; padding: 9px; border-radius: 6px; cursor: pointer; font-size: 13px; margin-top: 8px; text-decoration: none; display: block; text-align: center; }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>FerreterÃ­a Aldrete</h3>
        <p><?= $_SESSION['rol'] ?></p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item" href="inicioInventario.php">Inicio</a>
        <a class="menu-item" href="productos.php">Productos</a>
        <a class="menu-item" href="categorias.php">CategorÃ­as</a>
        <div class="divider"></div>
        <a class="menu-item" href="entradas.php">Entradas de productos</a>
        <a class="menu-item" href="salidas.php">Salidas y mermas</a>
        <a class="menu-item" href="historialMovimientos.php">Historial de movimientos</a>
        <div class="divider"></div>
        <a class="menu-item" href="proveedores.php">Proveedores</a>
        <a class="menu-item" href="compras.php">Compras a proveedor</a>
        <div class="divider"></div>
        <a class="menu-item active" href="paquetes.php">Paquetes</a>
        <a class="menu-item" href="transferencias.php">Transferencias</a>
        <a class="menu-item" href="masVendidos.php">MÃ¡s vendidos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Paquetes de productos</h2>
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
                <?php $msgs=['creado'=>'Paquete creado.','editado'=>'Paquete actualizado.','eliminado'=>'Paquete eliminado.']; ?>
                <div class="msg msg-exito"><?= $msgs[$_GET['msg']] ?? '' ?></div>
            <?php endif; ?>

            <?php if (count($paquetes) > 0): ?>
                <?php foreach ($paquetes as $paq):
                    $stmtPP = $pdo->prepare("
                        SELECT pp.cantidad,
                               COALESCE(p2.nombre_producto, p1.nombre_producto) AS nombre_producto,
                               COALESCE(p2.precio_venta, 0) AS precio_venta
                        FROM paquete_productos pp
                        JOIN (
                            SELECT producto_id, MIN(nombre_producto) AS nombre_producto
                            FROM productos WHERE activo = 1 GROUP BY producto_id
                        ) p1 ON pp.producto_id = p1.producto_id
                        LEFT JOIN productos p2 ON pp.producto_id = p2.producto_id AND p2.sucursal_id = ?
                        WHERE pp.paquete_id = ?
                    ");
                    $stmtPP->execute([$_SESSION['sucursal_id'], $paq['paquete_id']]);
                    $prods = $stmtPP->fetchAll(PDO::FETCH_ASSOC);
                    $precioSeparado = array_sum(array_map(fn($p) => $p['cantidad'] * $p['precio_venta'], $prods));
                    $ahorro = $precioSeparado > 0 ? $precioSeparado - $paq['precio_paquete'] : 0;
                ?>
                <div class="paquete-item">
                    <div class="paquete-header">
                        <div class="paquete-info">
                            <h4>
                                <?= htmlspecialchars($paq['nombre']) ?>
                                <span class="paquete-codigo"><?= htmlspecialchars($paq['codigo']) ?></span>
                                <span class="global-badge">Global</span>
                                <?php if (!$paq['activo']): ?>
                                    <span class="badge-inactivo">Inactivo</span>
                                <?php endif; ?>
                            </h4>
                            <?php if ($paq['descripcion']): ?>
                                <div class="paquete-desc"><?= htmlspecialchars($paq['descripcion']) ?></div>
                            <?php endif; ?>
                            <?php if ($precioSeparado > 0): ?>
                                <div class="paquete-precios">
                                    Sin paquete: $<?= number_format($precioSeparado,2) ?>
                                    <?php if ($ahorro > 0): ?>
                                        Â· <span class="ahorro-label">Ahorro: $<?= number_format($ahorro,2) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="paquete-precio">$<?= number_format($paq['precio_paquete'],2) ?></div>
                    </div>
                    <div class="paquete-prods">
                        <?php foreach ($prods as $pr): ?>
                            <span class="prod-tag"><?= htmlspecialchars($pr['nombre_producto']) ?> Ã— <?= number_format($pr['cantidad'],2) ?></span>
                        <?php endforeach; ?>
                        <?php if (empty($prods)): ?>
                            <span style="font-size:11px;color:#aaa;">Sin productos asignados</span>
                        <?php endif; ?>
                    </div>
                    <div class="acciones">
                        <a class="btn-accion btn-editar" href="paquetes.php?editar=<?= $paq['paquete_id'] ?>">Editar</a>
                        <a class="btn-accion <?= $paq['activo']?'btn-desactivar':'btn-activar' ?>"
                           href="paquetes.php?toggle=<?= $paq['paquete_id'] ?>"
                           onclick="return confirm('Â¿Confirmar cambio?')">
                            <?= $paq['activo']?'Desactivar':'Activar' ?>
                        </a>
                        <a class="btn-accion btn-eliminar"
                           href="paquetes.php?eliminar=<?= $paq['paquete_id'] ?>"
                           onclick="return confirm('Â¿Eliminar este paquete?')">Eliminar</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="sin-resultados">No hay paquetes registrados.</div>
            <?php endif; ?>
        </div>

        <!-- Formulario -->
        <div>
            <div class="card">
                <h3><?= $editando ? 'Editar paquete' : 'Nuevo paquete' ?></h3>
                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach($errores as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
                <?php endif; ?>

                <form method="POST" id="formPaquete">
                    <input type="hidden" name="paquete_id" value="<?= $editando['paquete_id'] ?? 0 ?>">
                    <input type="hidden" name="items_paquete" id="inputItemsPaquete">

                    <div class="form-group">
                        <label>CÃ³digo Ãºnico *</label>
                        <div class="codigo-row">
                            <input type="text" name="codigo" id="inputCodigo"
                                value="<?= htmlspecialchars($editando['codigo'] ?? $codigoSugerido) ?>"
                                placeholder="PAQ0001"
                                oninput="this.value=this.value.toUpperCase()">
                            <button type="button" class="btn-generar" onclick="generarCodigo()">Auto</button>
                        </div>
                        <div class="hint">Se usa para buscar el paquete en el punto de venta.</div>
                    </div>

                    <div class="form-group">
                        <label>Nombre del paquete *</label>
                        <input type="text" name="nombre" id="inputNombre"
                            value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>"
                            placeholder="Ej. Kit instalaciÃ³n elÃ©ctrica"
                            oninput="sugerirCodigo(this.value)">
                    </div>

                    <div class="form-group">
                        <label>DescripciÃ³n</label>
                        <input type="text" name="descripcion"
                            value="<?= htmlspecialchars($editando['descripcion'] ?? '') ?>"
                            placeholder="DescripciÃ³n breve (opcional)">
                    </div>

                    <div class="form-group">
                        <label>Precio especial del paquete *</label>
                        <input type="number" name="precio_paquete" id="inputPrecio"
                            value="<?= $editando['precio_paquete'] ?? '' ?>"
                            step="0.01" min="0.01" placeholder="0.00"
                            oninput="actualizarHintAhorro()">
                    </div>

                    <div class="precio-hint" id="hintAhorro" style="display:none;"></div>

                    <div class="form-group">
                        <label>Productos del paquete</label>
                        <div class="agregar-prod-row">
                            <select id="selPaqProd">
                                <option value="">-- Selecciona un producto --</option>
                                <?php foreach ($productos as $p): ?>
                                    <option value="<?= $p['producto_id'] ?>"
                                        data-nombre="<?= htmlspecialchars($p['nombre_producto']) ?>"
                                        data-precio="<?= $p['precio_venta'] ?>">
                                        <?= htmlspecialchars($p['nombre_producto']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" id="cantPaq" placeholder="Cant." step="1" min="1">
                            <button type="button" class="btn-add-prod" onclick="agregarProdPaquete()">+</button>
                        </div>
                        <div class="lista-paq" id="listaPaq">
                            <div class="paq-vacio">Sin productos agregados</div>
                        </div>
                    </div>

                    <button class="btn-guardar" type="submit" onclick="return prepararPaquete()">
                        <?= $editando ? 'Guardar cambios' : 'Crear paquete global' ?>
                    </button>
                    <?php if ($editando): ?>
                        <a class="btn-cancelar-edit" href="paquetes.php">Cancelar ediciÃ³n</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let itemsPaquete = <?= json_encode(array_map(fn($p) => [
    'producto_id' => intval($p['producto_id']),
    'nombre'      => $p['nombre_producto'],
    'cantidad'    => floatval($p['cantidad']),
    'precio'      => floatval($p['precio_venta'])
], $prodsPaquete)) ?>;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function generarCodigo() {
    const nombre = document.getElementById('inputNombre').value.trim();
    let prefix = 'PAQ';
    if (nombre.length > 2) {
        const palabras = nombre.toUpperCase().replace(/[^A-Z0-9 ]/g,'').split(' ').filter(p => p.length > 1);
        prefix = palabras.slice(0,2).map(p => p.substring(0,3)).join('') || 'PAQ';
    }
    document.getElementById('inputCodigo').value = prefix + Date.now().toString().slice(-4);
}

function sugerirCodigo(nombre) {
    const actual = document.getElementById('inputCodigo').value;
    if (!actual || actual.match(/^PAQ\d+$/)) {
        if (nombre.length > 3) {
            const palabras = nombre.toUpperCase().replace(/[^A-Z0-9 ]/g,'').split(' ').filter(p => p.length > 1);
            const prefix   = palabras.slice(0,2).map(p => p.substring(0,3)).join('') || 'PAQ';
            document.getElementById('inputCodigo').value = prefix + '001';
        }
    }
}

function agregarProdPaquete() {
    const sel  = document.getElementById('selPaqProd');
    const cant = parseInt(document.getElementById('cantPaq').value) || 0;
    if (!sel.value) { alert('Selecciona un producto.'); return; }
    if (cant < 1)   { alert('La cantidad debe ser al menos 1.'); return; }

    const opt    = sel.options[sel.selectedIndex];
    const id     = parseInt(sel.value);
    const nombre = opt.dataset.nombre;
    const precio = parseFloat(opt.dataset.precio) || 0;

    const existe = itemsPaquete.find(i => i.producto_id === id);
    if (existe) { existe.cantidad = cant; }
    else { itemsPaquete.push({ producto_id: id, nombre, cantidad: cant, precio }); }

    sel.value = '';
    document.getElementById('cantPaq').value = '';
    renderListaPaq();
    actualizarHintAhorro();
}

function renderListaPaq() {
    const div = document.getElementById('listaPaq');
    if (!itemsPaquete.length) {
        div.innerHTML = '<div class="paq-vacio">Sin productos agregados</div>';
        return;
    }
    div.innerHTML = itemsPaquete.map((item, idx) => `
        <div class="paq-item">
            <div class="paq-item-info">
                <div class="paq-item-nombre">${esc(item.nombre)}</div>
                <div class="paq-item-sub">Ã— ${item.cantidad} Â· $${(item.cantidad * item.precio).toFixed(2)} precio normal</div>
            </div>
            <button class="btn-quitar-paq" type="button" onclick="quitarProdPaq(${idx})">Ã—</button>
        </div>`).join('');
}

function quitarProdPaq(idx) {
    itemsPaquete.splice(idx, 1);
    renderListaPaq();
    actualizarHintAhorro();
}

function actualizarHintAhorro() {
    const totalSeparado = itemsPaquete.reduce((s, i) => s + i.cantidad * i.precio, 0);
    const precioPaq     = parseFloat(document.getElementById('inputPrecio').value) || 0;
    const hint          = document.getElementById('hintAhorro');
    if (totalSeparado <= 0) { hint.style.display = 'none'; return; }
    hint.style.display = 'block';
    const ahorro = totalSeparado - precioPaq;
    if (ahorro > 0.01) {
        hint.className = 'precio-hint con-ahorro';
        hint.textContent = `âœ… Precio individual: $${totalSeparado.toFixed(2)} Â· Ahorro: $${ahorro.toFixed(2)}`;
    } else {
        hint.className = 'precio-hint sin-ahorro';
        hint.textContent = `âš  Precio individual: $${totalSeparado.toFixed(2)} â€” revisa el precio del paquete`;
    }
}

document.getElementById('inputPrecio').addEventListener('input', actualizarHintAhorro);

function prepararPaquete() {
    if (!itemsPaquete.length) { alert('Agrega al menos un producto.'); return false; }
    document.getElementById('inputItemsPaquete').value = JSON.stringify(
        itemsPaquete.map(i => ({ producto_id: i.producto_id, cantidad: i.cantidad }))
    );
    return true;
}

function esc(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

renderListaPaq();
actualizarHintAhorro();
</script>
</body>
</html>

