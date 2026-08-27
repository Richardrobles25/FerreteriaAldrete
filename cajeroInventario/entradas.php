<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

$productoPreseleccionado = null;
if (isset($_GET['producto_id'])) {
    $stmt = $pdo->prepare("
        SELECT p.*, ss.stock_actual, ss.stock_minimo, ss.stock_maximo
        FROM productos p
        INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
        WHERE p.producto_id = ?
    ");
    $stmt->execute([$_SESSION['sucursal_id'], intval($_GET['producto_id'])]);
    $productoPreseleccionado = $stmt->fetch(PDO::FETCH_ASSOC);
}

// [AUTOFIX] B-05: Migración eliminada de aqui — ejecutar manualmente si es necesario:
// ALTER TABLE movimientos_inventario ADD COLUMN IF NOT EXISTS proveedor_id INT NULL DEFAULT NULL;

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [FIX-A1] Verificar CSRF antes de procesar la entrada
    requerirCSRF($_POST['_token'] ?? '', 'entradas.php');
    $producto_id       = intval($_POST['producto_id'] ?? 0);
    $cantidad_raw      = $_POST['cantidad'] ?? '';
    $motivo            = trim($_POST['motivo'] ?? 'Entrada manual');
    $proveedor_entrada = intval($_POST['proveedor_id_entrada'] ?? 0) ?: null;

    if (!$producto_id) $errores[] = 'Selecciona un producto.';

    if (empty($errores)) {
        // [FIX] Consultar tipo_venta ANTES de validar la cantidad: los productos tipo
        // "Suelto" (granel) deben aceptar decimales (2.5 kg). Antes se usaba intval()
        // para todos los productos, lo que truncaba silenciosamente 2.5 -> 2 y hacia
        // imposible registrar 0.5 (se volvia 0 y el sistema pedia "al menos 1").
        // salidas.php ya usa floatval() para todos — con este cambio ambas pantallas
        // quedan consistentes para productos a granel.
        $stmtP = $pdo->prepare("
            SELECT p.nombre_producto, p.tipo_venta, ss.stock_actual
            FROM productos p
            INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
            WHERE p.producto_id = ?
        ");
        $stmtP->execute([$_SESSION['sucursal_id'], $producto_id]);
        $prod = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            $errores[] = 'Producto no encontrado.';
        } else {
            $esSuelto = ($prod['tipo_venta'] === 'Suelto');
            $cantidad = $esSuelto ? round(floatval($cantidad_raw), 3) : intval($cantidad_raw);
            $cantidadMin = $esSuelto ? 0.001 : 1;
            if ($cantidad < $cantidadMin) {
                $errores[] = $esSuelto ? 'La cantidad debe ser mayor a 0.' : 'La cantidad debe ser al menos 1.';
            }
        }

        if (empty($errores) && $prod) {
            // [FIX-A5] Bloquear la fila de stock dentro de una transaccion para evitar que
            // dos entradas simultaneas del mismo producto se pisen (perdida de actualizacion).
            $pdo->beginTransaction();
            $stmtLockStock = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
            $stmtLockStock->execute([$producto_id, $_SESSION['sucursal_id']]);
            $stockAnterior = floatval($stmtLockStock->fetchColumn());
            $stockNuevo    = $stockAnterior + $cantidad;

            $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")->execute([$stockNuevo, $producto_id, $_SESSION['sucursal_id']]);
            $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo, proveedor_id) VALUES (?,?,?,'Entrada',?,?,?,?,?)")
                ->execute([$producto_id, $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $cantidad, $stockAnterior, $stockNuevo, $motivo, $proveedor_entrada]);
            $pdo->commit();

            header('Location: entradas.php?msg=exito&prod='.urlencode($prod['nombre_producto']));
            exit();
        }
    }
}

// Historial de entradas recientes
$stmtH = $pdo->prepare("
    SELECT m.cantidad, m.stock_anterior, m.stock_nuevo, m.motivo, m.created_at,
           p.nombre_producto, p.codigo, p.tipo_venta,
           pr.nombre AS nombre_proveedor
    FROM movimientos_inventario m
    JOIN productos p ON m.producto_id = p.producto_id
    LEFT JOIN proveedores pr ON m.proveedor_id = pr.proveedor_id
    -- [FIX] Filtrar por la sucursal del MOVIMIENTO (antes se filtraba solo que el producto
    -- existiera en la sucursal y se veían entradas de otras sucursales).
    -- Se incluyen los registros viejos sin sucursal (NULL) para no ocultar historial antiguo.
    WHERE m.tipo = 'Entrada' AND (m.sucursal_id = ? OR m.sucursal_id IS NULL)
    ORDER BY m.created_at DESC
    LIMIT 25
");
$stmtH->execute([$_SESSION['sucursal_id']]);
$historial = $stmtH->fetchAll(PDO::FETCH_ASSOC);

// Productos con su proveedor default
$stmtProds = $pdo->prepare("
    SELECT p.producto_id, p.codigo, p.nombre_producto, p.tipo_venta,
           ss.stock_actual, ss.stock_minimo, ss.stock_maximo,
           MIN(pp.proveedor_id) AS proveedor_default_id,
           MIN(prov.nombre)     AS proveedor_default_nombre
    FROM productos p
    INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
    LEFT JOIN producto_proveedor pp ON p.producto_id = pp.producto_id
    LEFT JOIN proveedores prov      ON pp.proveedor_id = prov.proveedor_id
    WHERE p.activo = 1 AND ss.activo = 1
    GROUP BY p.producto_id, p.codigo, p.nombre_producto, p.tipo_venta, ss.stock_actual, ss.stock_minimo, ss.stock_maximo
    ORDER BY p.nombre_producto ASC
");
$stmtProds->execute([$_SESSION['sucursal_id']]);
$productos = $stmtProds->fetchAll(PDO::FETCH_ASSOC);

// Todos los proveedores activos
$proveedores = $pdo->query("SELECT proveedor_id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entradas de Productos — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; display: grid; grid-template-columns: 380px 1fr; gap: 20px; align-items: start; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 16px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group select:focus { outline: none; border-color: #14ace7; }
    .stock-info { background: #e8f5e9; border-radius: 6px; padding: 10px 14px; font-size: 13px; color: #2e7d32; margin-bottom: 14px; display: none; }
    .stock-info.bajo { background: #fdecea; color: #c0392b; }
    .motivos-rapidos { display: flex; gap: 5px; flex-wrap: wrap; margin-bottom: 8px; }
    .motivo-chip { background: #f0f0f0; border: none; padding: 5px 12px; border-radius: 99px; font-size: 12px; cursor: pointer; color: #555; }
    .motivo-chip:hover { background: #bbdefb; color: #1565c0; }
    .btn-guardar { background: #2e7d32; color: white; border: none; padding: 12px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; width: 100%; }
    .btn-guardar:hover { background: #1b5e20; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 10px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 10px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    .prod-search-wrap { position: relative; }
    .prod-drop { display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #e0e0e0; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,.1); z-index: 200; max-height: 220px; overflow-y: auto; margin-top: 2px; }
    .prod-drop-item { padding: 10px 14px; cursor: pointer; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; display: flex; justify-content: space-between; align-items: center; }
    .prod-drop-item:hover { background: #eef8ff; }
    .prod-drop-item:last-child { border-bottom: none; }
    .prod-chip { display: none; margin-top: 8px; background: #eef8ff; border: 1px solid #bbdefb; border-radius: 6px; padding: 8px 12px; font-size: 13px; justify-content: space-between; align-items: center; }
    .prod-chip button { background: none; border: none; color: #c0392b; cursor: pointer; font-size: 12px; font-weight: 700; }
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
        <a class="menu-item" href="productos.php">Productos</a>
        <a class="menu-item" href="categorias.php">Categorías</a>
        <a class="menu-item" href="unidades.php">Unidades de medida</a>
        <a class="menu-item active" href="entradas.php">Entradas</a>
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
            <h2>Entradas de productos</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Formulario -->
        <div>
            <div class="card">
                <h3>Registrar entrada</h3>

                <?php if (isset($_GET['msg']) && $_GET['msg'] === 'exito'): ?>
                    <div class="msg msg-exito">
                        Entrada registrada: <strong><?= htmlspecialchars($_GET['prod'] ?? '') ?></strong>
                    </div>
                <?php endif; ?>
                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach($errores as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
                <?php endif; ?>

                <form method="POST">
                    <!-- [FIX-A1] Token CSRF para proteger el registro de entrada -->
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="form-group">
                        <label>Producto *</label>
                        <input type="hidden" name="producto_id" id="productoIdHidden"
                            value="<?= $productoPreseleccionado ? $productoPreseleccionado['producto_id'] : '' ?>">
                        <div class="prod-search-wrap">
                            <input type="text" id="buscarProducto"
                                placeholder="Buscar por nombre o código..."
                                autocomplete="off"
                                oninput="filtrarProductos(this.value)"
                                style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:Arial,sans-serif;">
                            <div class="prod-drop" id="dropProductos"></div>
                        </div>
                        <div class="prod-chip" id="productoChip">
                            <span id="productoChipNombre"><?= $productoPreseleccionado ? htmlspecialchars($productoPreseleccionado['nombre_producto']) : '' ?></span>
                            <button type="button" onclick="limpiarProducto()">✕ Quitar</button>
                        </div>
                    </div>

                    <div class="stock-info" id="stockInfo">
                        Stock actual: <strong id="stockActualVal"></strong>
                        · Mínimo: <span id="stockMinimoVal"></span>
                        · Máximo: <span id="stockMaximoVal"></span>
                    </div>

                    <div class="form-group" id="grupoProveedor" style="display:none;">
                        <label>Proveedor</label>
                        <input type="hidden" name="proveedor_id_entrada" id="proveedorIdEntrada" value="">
                        <div class="prod-search-wrap">
                            <input type="text" id="buscarProveedor"
                                placeholder="Buscar proveedor..."
                                autocomplete="off"
                                oninput="filtrarProveedores(this.value)"
                                style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:Arial,sans-serif;">
                            <div class="prod-drop" id="dropProveedores"></div>
                        </div>
                        <div class="prod-chip" id="proveedorChip" style="display:none;">
                            <span id="proveedorChipNombre"></span>
                            <button type="button" onclick="limpiarProveedor()">✕ Cambiar</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Cantidad a ingresar *</label>
                        <!-- [FIX-MEDIO-C-09] (portado de admin/inventario_entradas.php): faltaba
                             htmlspecialchars: un valor de cantidad manipulado por POST directo se
                             reflejaba crudo en el atributo value, permitiendo cerrar el atributo e
                             inyectar HTML/JS. -->
                        <input type="number" name="cantidad" id="inputCantidad"
                            placeholder="0" step="1" min="1"
                            value="<?= htmlspecialchars($_POST['cantidad'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="form-group">
                        <label>Motivo / Descripción</label>
                        <div class="motivos-rapidos">
                            <button type="button" class="motivo-chip" onclick="setMotivo('Compra a proveedor')">Compra a proveedor</button>
                            <button type="button" class="motivo-chip" onclick="setMotivo('Ajuste de inventario')">Ajuste</button>
                            <button type="button" class="motivo-chip" onclick="setMotivo('Devolución de cliente')">Devolución</button>
                            <button type="button" class="motivo-chip" onclick="setMotivo('Inventario inicial')">Inventario inicial</button>
                        </div>
                        <input type="text" name="motivo" id="inputMotivo"
                            value="<?= htmlspecialchars($_POST['motivo'] ?? 'Entrada manual') ?>"
                            placeholder="Describe el origen de esta entrada">
                    </div>

                    <button class="btn-guardar" type="submit">Registrar entrada</button>
                </form>
            </div>
        </div>

        <!-- Historial -->
        <div>
            <div class="card" style="padding:0;">
                <div style="padding:16px 20px;border-bottom:0.5px solid #eee;display:flex;justify-content:space-between;align-items:center;gap:12px;">
                    <h3 style="margin:0;">Entradas recientes</h3>
                    <input type="text" placeholder="Filtrar historial..." oninput="filtrarTabla(this.value)"
                        style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:12px;width:160px;">
                </div>
                <?php if (count($historial) > 0): ?>
                <table>
                    <thead>
                        <tr><th>Producto</th><th>Proveedor</th><th>Cant. ingresada</th><th>Stock ant.</th><th>Stock nuevo</th><th>Motivo</th><th>Fecha</th></tr>
                    </thead>
                    <tbody id="tablaFiltrable">
                        <?php foreach ($historial as $h): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($h['nombre_producto']) ?></strong>
                                <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($h['codigo']) ?></div>
                            </td>
                            <td style="font-size:12px;"><?= htmlspecialchars($h['nombre_proveedor'] ?? '—') ?></td>
                            <td style="color:#2e7d32;font-weight:700;">+<?= number_format($h['cantidad'], $h['tipo_venta'] === 'Suelto' ? 2 : 0) ?></td>
                            <td style="color:#888;"><?= number_format($h['stock_anterior'],2) ?></td>
                            <td style="font-weight:600;"><?= number_format($h['stock_nuevo'],2) ?></td>
                            <td style="font-size:12px;color:#888;"><?= htmlspecialchars($h['motivo']??'—') ?></td>
                            <td style="font-size:12px;color:#aaa;"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay entradas registradas.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

// El sidebar se vuelve a pintar en cada navegacion (no es un SPA), asi que sin esto
// el scroll siempre vuelve a 0 y hay que bajar de nuevo para ver los modulos de abajo.
// (mismo fix ya aplicado en admin/_admin_sidebar.php)
(function() {
    var menu = document.querySelector('.sidebar-menu');
    if (!menu) return;
    var saved = sessionStorage.getItem('cajeroInvSidebarScroll');
    if (saved !== null) menu.scrollTop = parseInt(saved, 10) || 0;
    menu.addEventListener('scroll', function() {
        sessionStorage.setItem('cajeroInvSidebarScroll', menu.scrollTop);
    });
})();

// ── Datos precargados ────────────────────────────────────────────────────────
const productosData   = <?= json_encode(array_values($productos)) ?>;
const proveedoresData = <?= json_encode(array_values($proveedores)) ?>;

function normalizar(str) {
    return String(str || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

// ── Autocomplete de producto ─────────────────────────────────────────────────
function filtrarProductos(q) {
    const drop = document.getElementById('dropProductos');
    if (!q.trim()) { drop.style.display = 'none'; return; }
    const norm = normalizar(q);
    const matches = productosData.filter(function(p) {
        return normalizar(p.nombre_producto).includes(norm) || normalizar(p.codigo).includes(norm);
    }).slice(0, 25);
    if (!matches.length) {
        drop.innerHTML = '<div style="padding:10px 14px;color:#aaa;font-size:13px;">Sin resultados</div>';
    } else {
        drop.innerHTML = matches.map(function(p) {
            const stockOk = parseFloat(p.stock_actual) > parseFloat(p.stock_minimo);
            return '<div class="prod-drop-item" onclick="seleccionarProducto(' + p.producto_id + ')">'
                + '<div><strong>' + p.nombre_producto + '</strong><span style="color:#aaa;font-size:11px;"> · ' + p.codigo + '</span></div>'
                + '<span style="font-size:12px;font-weight:600;color:' + (stockOk ? '#2e7d32' : '#c0392b') + ';">Stock: ' + parseFloat(p.stock_actual).toFixed(2) + '</span>'
                + '</div>';
        }).join('');
    }
    drop.style.display = 'block';
}

function seleccionarProducto(id) {
    const p = productosData.find(function(x){ return x.producto_id == id; });
    if (!p) return;
    document.getElementById('productoIdHidden').value = id;
    document.getElementById('buscarProducto').value   = '';
    document.getElementById('dropProductos').style.display = 'none';
    document.getElementById('buscarProducto').style.display = 'none';
    document.getElementById('productoChipNombre').textContent = p.nombre_producto;
    document.getElementById('productoChip').style.display    = 'flex';
    mostrarStockInfo(parseFloat(p.stock_actual), parseFloat(p.stock_minimo), parseFloat(p.stock_maximo));
    // [FIX] Productos "Suelto" (granel) aceptan decimales; el resto solo enteros —
    // consistente con la validacion del servidor y con nuevaVenta.php/salidas.php.
    const inpCant = document.getElementById('inputCantidad');
    if (p.tipo_venta === 'Suelto') {
        inpCant.step = '0.001'; inpCant.min = '0.001';
    } else {
        inpCant.step = '1'; inpCant.min = '1';
    }
    // Cargar proveedor default del producto
    document.getElementById('grupoProveedor').style.display = 'block';
    if (p.proveedor_default_id) {
        document.getElementById('proveedorIdEntrada').value = p.proveedor_default_id;
        document.getElementById('proveedorChipNombre').textContent = p.proveedor_default_nombre;
        document.getElementById('proveedorChip').style.display = 'flex';
        document.getElementById('buscarProveedor').value = '';
    } else {
        limpiarProveedor();
    }
}

function limpiarProducto() {
    document.getElementById('productoIdHidden').value = '';
    document.getElementById('productoChip').style.display = 'none';
    document.getElementById('stockInfo').style.display    = 'none';
    document.getElementById('buscarProducto').value = '';
    document.getElementById('buscarProducto').style.display = '';
    document.getElementById('grupoProveedor').style.display = 'none';
    limpiarProveedor();
    const inpCant = document.getElementById('inputCantidad');
    inpCant.step = '1'; inpCant.min = '1';
    document.getElementById('buscarProducto').focus();
}

function mostrarStockInfo(stock, minimo, maximo) {
    const info = document.getElementById('stockInfo');
    document.getElementById('stockActualVal').textContent = stock.toFixed(2);
    document.getElementById('stockMinimoVal').textContent = minimo.toFixed(2);
    document.getElementById('stockMaximoVal').textContent = maximo.toFixed(2);
    info.style.display = 'block';
    info.className = 'stock-info' + (stock <= minimo ? ' bajo' : '');
}

// ── Autocomplete de proveedor ────────────────────────────────────────────────
function filtrarProveedores(q) {
    const drop = document.getElementById('dropProveedores');
    if (!q.trim()) { drop.style.display = 'none'; return; }
    const norm = normalizar(q);
    const matches = proveedoresData.filter(function(p) {
        return normalizar(p.nombre).includes(norm);
    }).slice(0, 20);
    if (!matches.length) {
        drop.innerHTML = '<div style="padding:10px 14px;color:#aaa;font-size:13px;">Sin resultados</div>';
    } else {
        drop.innerHTML = matches.map(function(p) {
            return '<div class="prod-drop-item" onclick="seleccionarProveedor(' + p.proveedor_id + ')">'
                + '<strong>' + p.nombre + '</strong>'
                + '</div>';
        }).join('');
    }
    drop.style.display = 'block';
}

function seleccionarProveedor(id) {
    const p = proveedoresData.find(function(x){ return x.proveedor_id == id; });
    if (!p) return;
    document.getElementById('proveedorIdEntrada').value = id;
    document.getElementById('proveedorChipNombre').textContent = p.nombre;
    document.getElementById('proveedorChip').style.display = 'flex';
    document.getElementById('buscarProveedor').value = '';
    document.getElementById('dropProveedores').style.display = 'none';
}

function limpiarProveedor() {
    document.getElementById('proveedorIdEntrada').value = '';
    document.getElementById('proveedorChip').style.display = 'none';
    document.getElementById('buscarProveedor').value = '';
    document.getElementById('dropProveedores').style.display = 'none';
}

// Cerrar dropdowns al hacer clic fuera
document.addEventListener('click', function(e) {
    if (!e.target.closest('.prod-search-wrap')) {
        document.getElementById('dropProductos').style.display = 'none';
        document.getElementById('dropProveedores').style.display = 'none';
    }
});

// ── Si hay producto preseleccionado, mostrarlo ───────────────────────────────
<?php if ($productoPreseleccionado): ?>
window.addEventListener('load', function() {
    seleccionarProducto(<?= $productoPreseleccionado['producto_id'] ?>);
});
<?php endif; ?>

// ── Filtro del historial de entradas ─────────────────────────────────────────
function filtrarTabla(q) {
    q = normalizar(q);
    document.querySelectorAll('#tablaFiltrable tr').forEach(function(tr) {
        tr.style.display = normalizar(tr.textContent).includes(q) ? '' : 'none';
    });
}

function setMotivo(texto) {
    document.getElementById('inputMotivo').value = texto;
}
</script>
</body>
</html>
