<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

if (isset($_GET['eliminar'])) {
    // [AUTOFIX] SEC-01: Verificar CSRF token antes de accion destructiva por GET
    requerirCSRF($_GET['_token'] ?? '', 'proveedores.php');
    $pdo->prepare("UPDATE proveedores SET activo = 0 WHERE proveedor_id = ?")->execute([intval($_GET['eliminar'])]);
    header('Location: proveedores.php?msg=eliminado'); exit();
}
if (isset($_GET['toggle'])) {
    // [AUTOFIX] SEC-01: Verificar CSRF token antes de accion destructiva por GET
    requerirCSRF($_GET['_token'] ?? '', 'proveedores.php');
    $pdo->prepare("UPDATE proveedores SET activo = NOT activo WHERE proveedor_id = ?")->execute([intval($_GET['toggle'])]);
    header('Location: proveedores.php'); exit();
}

$errores  = [];
$editando = null;
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM proveedores WHERE proveedor_id = ?");
    $stmt->execute([intval($_GET['editar'])]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [FIX-CSRF-01] Verificar CSRF antes de crear/editar proveedor (antes solo ?eliminar=/?toggle= lo tenian)
    requerirCSRF($_POST['_token'] ?? '', 'proveedores.php');
    $nombre    = trim($_POST['nombre'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $correo    = trim($_POST['correo'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $cats      = $_POST['categorias'] ?? [];
    $id        = intval($_POST['proveedor_id'] ?? 0);

    if (!$nombre) $errores[] = 'El nombre es obligatorio.';

    // [AUTOFIX] BUG-02: Verificar nombre duplicado antes de insertar/actualizar
    if ($nombre && empty($errores)) {
        $stmtDup = $pdo->prepare("SELECT proveedor_id FROM proveedores WHERE LOWER(nombre) = LOWER(?) AND proveedor_id != ? AND activo = 1");
        $stmtDup->execute([$nombre, $id]);
        if ($stmtDup->fetch()) $errores[] = 'Ya existe un proveedor activo con ese nombre.';
    }

    if (empty($errores)) {
        if ($id) {
            $pdo->prepare("UPDATE proveedores SET nombre=?, telefono=?, correo=?, direccion=? WHERE proveedor_id=?")
                ->execute([$nombre, $telefono, $correo, $direccion, $id]);
            $pdo->prepare("DELETE FROM proveedor_categorias WHERE proveedor_id = ?")->execute([$id]);
            foreach ($cats as $cat) {
                $pdo->prepare("INSERT INTO proveedor_categorias (proveedor_id, categoria_id) VALUES (?,?)")->execute([$id, $cat]);
            }
            header('Location: proveedores.php?msg=editado');
        } else {
            $pdo->prepare("INSERT INTO proveedores (nombre, telefono, correo, direccion, activo) VALUES (?,?,?,?,1)")
                ->execute([$nombre, $telefono, $correo, $direccion]);
            $nuevoId = $pdo->lastInsertId();
            foreach ($cats as $cat) {
                $pdo->prepare("INSERT INTO proveedor_categorias (proveedor_id, categoria_id) VALUES (?,?)")->execute([$nuevoId, $cat]);
            }
            header('Location: proveedores.php?msg=creado');
        }
        exit();
    }
}

$busqueda  = trim($_GET['buscar'] ?? '');
$filtrocat = intval($_GET['categoria'] ?? 0);

$where  = "WHERE p.activo = 1";
$params = [];
// [AUTOFIX] BUG-04: Extender búsqueda para incluir teléfono además del nombre
if ($busqueda)  { $where .= " AND (p.nombre LIKE ? OR p.telefono LIKE ?)"; $params[] = '%'.$busqueda.'%'; $params[] = '%'.$busqueda.'%'; }
if ($filtrocat) { $where .= " AND pc.categoria_id = ?"; $params[] = $filtrocat; }

$join = $filtrocat ? "JOIN proveedor_categorias pc ON p.proveedor_id = pc.proveedor_id" : "LEFT JOIN proveedor_categorias pc ON p.proveedor_id = pc.proveedor_id";

$stmt = $pdo->prepare("SELECT DISTINCT p.* FROM proveedores p $join $where ORDER BY p.nombre ASC");
$stmt->execute($params);
$proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categorias = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

// Categorías del proveedor en edición
$catsEditando = [];
if ($editando) {
    $stmtC = $pdo->prepare("SELECT categoria_id FROM proveedor_categorias WHERE proveedor_id = ?");
    $stmtC->execute([$editando['proveedor_id']]);
    $catsEditando = array_column($stmtC->fetchAll(PDO::FETCH_ASSOC), 'categoria_id');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proveedores — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 16px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
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
    th { padding: 11px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .cats-lista { display: flex; gap: 4px; flex-wrap: wrap; }
    .cat-badge { background: #e3f2fd; color: #1565c0; font-size: 11px; padding: 2px 8px; border-radius: 99px; font-weight: 600; }
    .acciones { display: flex; gap: 6px; }
    .btn-accion { padding: 5px 11px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #e3f2fd; color: #1565c0; }
    .btn-editar:hover { background: #bbdefb; }
    .btn-desactivar { background: #fff8e1; color: #1565c0; }
    .btn-activar { background: #e8f5e9; color: #2e7d32; }
    .btn-eliminar { background: #fdecea; color: #c0392b; }
    .btn-eliminar:hover { background: #ffcdd2; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .form-group input:focus { outline: none; border-color: #14ace7; }
    .areas-buscador { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .areas-buscador:focus { outline: none; border-color: #14ace7; }
    .areas-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; min-height: 10px; }
    .area-tag { background: #e3f2fd; color: #1565c0; font-size: 12px; padding: 3px 8px 3px 10px; border-radius: 99px; font-weight: 600; display: flex; align-items: center; gap: 5px; }
    .area-tag button { background: none; border: none; cursor: pointer; color: #1565c0; font-size: 14px; line-height: 1; padding: 0; }
    .areas-dropdown { border: 1px solid #e8e8e8; border-radius: 6px; max-height: 180px; overflow-y: auto; background: #fff; display: none; margin-top: 4px; }
    .area-opcion { padding: 9px 12px; cursor: pointer; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    .area-opcion:last-child { border-bottom: none; }
    .area-opcion:hover { background: #f0f9ff; }
    .area-opcion.seleccionada { color: #aaa; pointer-events: none; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; width: 100%; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar-edit { background: white; color: #666; border: 1px solid #ddd; padding: 10px; border-radius: 6px; cursor: pointer; font-size: 13px; width: 100%; margin-top: 8px; text-decoration: none; display: block; text-align: center; }
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
        <a class="menu-item" href="entradas.php">Entradas</a>
        <a class="menu-item" href="salidas.php">Salidas y mermas</a>
        <a class="menu-item" href="historial.php">Movimientos</a>
        <div class="divider"></div>

        <div class="menu-label">Proveedores</div>
        <a class="menu-item active" href="proveedores.php">Proveedores</a>
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
            <h2>Proveedores</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <div>
            <?php if (isset($_GET['msg'])): ?>
                <?php $msgs = ['creado'=>'Proveedor creado.','editado'=>'Proveedor actualizado.','eliminado'=>'Proveedor eliminado.']; // [AUTOFIX] BUG-02: no se necesita msg especial pues el error ya lo muestra el form ?>
                <div class="msg msg-exito"><?= $msgs[$_GET['msg']] ?? '' ?></div>
            <?php endif; ?>

            <form method="GET" action="proveedores.php">
                <div class="filtros">
                    <div class="filtro-group">
                        <label>Buscar</label>
                        <input type="text" name="buscar" placeholder="Nombre del proveedor..." value="<?= htmlspecialchars($busqueda) ?>" oninput="filtrarTabla(this.value)">
                    </div>
                    <div class="filtro-group">
                        <label>Filtrar por área</label>
                        <select name="categoria">
                            <option value="">Todas las áreas</option>
                            <?php foreach ($categorias as $c): ?>
                                <option value="<?= $c['categoria_id'] ?>" <?= $filtrocat===$c['categoria_id']?'selected':'' ?>><?= htmlspecialchars($c['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn-filtrar" type="submit">Filtrar</button>
                    <?php if ($busqueda || $filtrocat): ?><a class="btn-limpiar" href="proveedores.php">Limpiar</a><?php endif; ?>
                </div>
            </form>

            <div class="card" style="padding:0;">
                <?php if (count($proveedores) > 0): ?>
                <table>
                    <thead>
                        <tr><th>Nombre</th><th>Teléfono</th><th>Correo</th><th>Áreas</th><th>Acciones</th></tr>
                    </thead>
                    <tbody id="tablaFiltrable">
                        <?php foreach ($proveedores as $p):
                            $stmtC = $pdo->prepare("SELECT c.nombre FROM proveedor_categorias pc JOIN categorias c ON pc.categoria_id = c.categoria_id WHERE pc.proveedor_id = ?");
                            $stmtC->execute([$p['proveedor_id']]);
                            $cats = $stmtC->fetchAll(PDO::FETCH_COLUMN);
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($p['nombre']) ?></strong></td>
                            <td><?= htmlspecialchars($p['telefono']??'—') ?></td>
                            <td style="font-size:12px;"><?= htmlspecialchars($p['correo']??'—') ?></td>
                            <td>
                                <div class="cats-lista">
                                    <?php foreach ($cats as $cat): ?>
                                        <span class="cat-badge"><?= htmlspecialchars($cat) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (!$cats): ?><span style="color:#aaa;font-size:12px;">—</span><?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="acciones">
                                    <a class="btn-accion btn-editar" href="proveedores.php?editar=<?= $p['proveedor_id'] ?>">Editar</a>
                                    <!-- [AUTOFIX] SEC-01: Token CSRF en links destructivos -->
                                    <a class="btn-accion <?= $p['activo']?'btn-desactivar':'btn-activar' ?>" href="proveedores.php?toggle=<?= $p['proveedor_id'] ?>&_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>" onclick="return confirm('¿Confirmar cambio?')">
                                        <?= $p['activo']?'Desactivar':'Activar' ?>
                                    </a>
                                    <a class="btn-accion btn-eliminar" href="proveedores.php?eliminar=<?= $p['proveedor_id'] ?>&_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>" onclick="return confirm('¿Eliminar proveedor?')">Eliminar</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay proveedores registrados.</div>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="card">
                <h3><?= $editando ? 'Editar proveedor' : 'Nuevo proveedor' ?></h3>
                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach($errores as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
                <?php endif; ?>
                <form method="POST">
                    <!-- [FIX-CSRF-01] Token CSRF para proteger crear/editar proveedor -->
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="proveedor_id" value="<?= $editando['proveedor_id'] ?? 0 ?>">
                    <div class="form-group">
                        <label>Nombre *</label>
                        <input type="text" name="nombre" value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>" placeholder="Nombre del proveedor">
                    </div>
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="text" name="telefono" value="<?= htmlspecialchars($editando['telefono'] ?? '') ?>" placeholder="10 dígitos">
                    </div>
                    <div class="form-group">
                        <label>Correo</label>
                        <input type="email" name="correo" value="<?= htmlspecialchars($editando['correo'] ?? '') ?>" placeholder="correo@ejemplo.com">
                    </div>
                    <div class="form-group">
                        <label>Dirección</label>
                        <input type="text" name="direccion" value="<?= htmlspecialchars($editando['direccion'] ?? '') ?>" placeholder="Dirección del proveedor">
                    </div>
                    <div class="form-group">
                        <label>Áreas que abastece</label>
                        <div class="areas-tags" id="areasTags"></div>
                        <input type="text" id="buscarAreasProveedor" class="areas-buscador"
                            placeholder="Buscar y agregar área..."
                            autocomplete="off"
                            oninput="filtrarAreasDropdown(this.value)"
                            onfocus="filtrarAreasDropdown(this.value)"
                            onblur="setTimeout(()=>document.getElementById('areasDropdown').style.display='none',200)">
                        <div class="areas-dropdown" id="areasDropdown"></div>
                        <div id="areasHiddenInputs"></div>
                    </div>
                    <button class="btn-guardar" type="submit"><?= $editando ? 'Guardar cambios' : 'Agregar proveedor' ?></button>
                    <?php if ($editando): ?><a class="btn-cancelar-edit" href="proveedores.php">Cancelar</a><?php endif; ?>
                </form>
            </div>
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

// ── Sistema de tags para áreas ──────────────────────────────────────────────
const todasLasAreas = <?= json_encode(array_values(array_map(fn($c) => ['id' => $c['categoria_id'], 'nombre' => $c['nombre']], $categorias))) ?>;
let areasSeleccionadas = <?= json_encode(array_values(array_map(fn($id) => intval($id), $catsEditando))) ?>;
// [FIX-C7] Este archivo no tenia ninguna funcion de limpieza para insertar texto en el DOM.
// Se agrega la misma esc() ya usada en otros archivos del proyecto (nuevaVenta.php, formProducto.php).
function esc(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function renderAreasTags() {
    const tags = document.getElementById('areasTags');
    const hidden = document.getElementById('areasHiddenInputs');
    tags.innerHTML = '';
    hidden.innerHTML = '';
    areasSeleccionadas.forEach(function(id) {
        const area = todasLasAreas.find(a => a.id == id);
        if (!area) return;
        const tag = document.createElement('span');
        tag.className = 'area-tag';
        tag.innerHTML = esc(area.nombre) + '<button type="button" onclick="quitarArea(' + id + ')">×</button>';
        tags.appendChild(tag);
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'categorias[]';
        inp.value = id;
        hidden.appendChild(inp);
    });
}

function quitarArea(id) {
    areasSeleccionadas = areasSeleccionadas.filter(i => i != id);
    renderAreasTags();
    filtrarAreasDropdown(document.getElementById('buscarAreasProveedor').value);
}

function filtrarAreasDropdown(q) {
    const qn = normalizar(q);
    const drop = document.getElementById('areasDropdown');
    const disponibles = todasLasAreas.filter(a => !areasSeleccionadas.includes(a.id) && (qn === '' || normalizar(a.nombre).includes(qn)));
    if (!disponibles.length) {
        drop.innerHTML = '<div style="padding:10px;text-align:center;color:#aaa;font-size:12px;">Sin resultados</div>';
    } else {
        drop.innerHTML = disponibles.map(a =>
            '<div class="area-opcion" onclick="agregarArea(' + a.id + ')">' + esc(a.nombre) + '</div>'
        ).join('');
    }
    drop.style.display = 'block';
}

function agregarArea(id) {
    if (!areasSeleccionadas.includes(id)) areasSeleccionadas.push(id);
    document.getElementById('buscarAreasProveedor').value = '';
    document.getElementById('areasDropdown').style.display = 'none';
    renderAreasTags();
}

// Inicializar tags al cargar
document.addEventListener('DOMContentLoaded', renderAreasTags);
</script>
<script src="../includes/auto_filter.js"></script>
</body>
</html>
