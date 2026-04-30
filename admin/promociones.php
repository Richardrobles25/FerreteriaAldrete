<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);

$msg    = '';
$error  = '';

// ── Búsqueda de productos por sucursal (AJAX) ────────────────────────────────
if (isset($_GET['buscar_prods'])) {
    $sucursalId = intval($_GET['sucursal_id'] ?? 0);
    $q          = trim($_GET['q'] ?? '');
    header('Content-Type: application/json');
    if (!$sucursalId) { echo json_encode([]); exit(); }
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT p.producto_id, p.codigo, p.nombre_producto, p.precio_venta, p.tipo_venta
        FROM productos p
        INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ? AND ss.activo = 1
        WHERE p.activo = 1
          AND (p.nombre_producto LIKE ? OR p.codigo LIKE ?)
        ORDER BY p.nombre_producto ASC LIMIT 30
    ");
    $stmt->execute([$sucursalId, $like, $like]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// ── Acciones POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $productoId       = intval($_POST['producto_id'] ?? 0);
        $precioPromo      = floatval($_POST['precio_promocional'] ?? 0);
        $fechaInicio      = trim($_POST['fecha_inicio'] ?? '');
        $fechaFin         = trim($_POST['fecha_fin'] ?? '');
        $descripcion      = trim($_POST['descripcion'] ?? '');

        if (!$productoId)         $error = 'Selecciona un producto.';
        elseif ($precioPromo <= 0) $error = 'El precio promocional debe ser mayor a 0.';
        elseif (!$fechaInicio || !$fechaFin) $error = 'Ingresa las fechas de la promoción.';
        elseif ($fechaFin < $fechaInicio)    $error = 'La fecha de fin no puede ser anterior al inicio.';
        else {
            // Verificar que el precio promo es menor al precio normal
            $stmtP = $pdo->prepare("SELECT precio_venta, nombre_producto FROM productos WHERE producto_id = ?");
            $stmtP->execute([$productoId]);
            $prod = $stmtP->fetch(PDO::FETCH_ASSOC);
            if (!$prod) { $error = 'Producto no encontrado.'; }
            elseif ($precioPromo >= $prod['precio_venta']) {
                $error = 'El precio promocional ($' . number_format($precioPromo,2) . ') debe ser menor al precio normal ($' . number_format($prod['precio_venta'],2) . ').';
            } else {
                $pdo->prepare("INSERT INTO promociones (producto_id, precio_promocional, fecha_inicio, fecha_fin, descripcion, usuario_id) VALUES (?,?,?,?,?,?)")
                    ->execute([$productoId, $precioPromo, $fechaInicio, $fechaFin, $descripcion ?: null, $_SESSION['usuario_id']]);
                $msg = 'Promoción creada para "' . htmlspecialchars($prod['nombre_producto']) . '".';
            }
        }

    } elseif ($accion === 'desactivar') {
        $id = intval($_POST['promocion_id'] ?? 0);
        $pdo->prepare("UPDATE promociones SET activo = 0 WHERE promocion_id = ?")->execute([$id]);
        $msg = 'Promoción desactivada.';

    } elseif ($accion === 'activar') {
        $id = intval($_POST['promocion_id'] ?? 0);
        $pdo->prepare("UPDATE promociones SET activo = 1 WHERE promocion_id = ?")->execute([$id]);
        $msg = 'Promoción reactivada.';

    } elseif ($accion === 'eliminar') {
        $id = intval($_POST['promocion_id'] ?? 0);
        $pdo->prepare("DELETE FROM promociones WHERE promocion_id = ?")->execute([$id]);
        $msg = 'Promoción eliminada.';
    }
}

// ── Filtros ──────────────────────────────────────────────────────────────────
$filtroSucursal = intval($_GET['sucursal_f'] ?? 0);
$filtroEstado   = $_GET['estado_f'] ?? 'todas';

$where  = '1=1';
$params = [];
if ($filtroSucursal) { $where .= ' AND u.sucursal_id = ?'; $params[] = $filtroSucursal; }
if ($filtroEstado === 'activas') {
    $where .= " AND pr.activo = 1 AND CURDATE() BETWEEN pr.fecha_inicio AND pr.fecha_fin";
} elseif ($filtroEstado === 'proximas') {
    $where .= " AND pr.activo = 1 AND pr.fecha_inicio > CURDATE()";
} elseif ($filtroEstado === 'vencidas') {
    $where .= " AND (pr.activo = 0 OR pr.fecha_fin < CURDATE())";
}

// ── Lista de promociones ─────────────────────────────────────────────────────
$stmtList = $pdo->prepare("
    SELECT pr.*,
           p.nombre_producto, p.codigo, p.precio_venta, p.tipo_venta,
           s.nombre AS sucursal_nombre,
           u.nombre_completo AS creador
    FROM promociones pr
    JOIN productos p   ON pr.producto_id = p.producto_id
    JOIN usuarios u    ON pr.usuario_id   = u.usuario_id
    JOIN sucursales s  ON u.sucursal_id   = s.sucursal_id
    WHERE $where
    ORDER BY pr.created_at DESC
    LIMIT 200
");
$stmtList->execute($params);
$promociones = $stmtList->fetchAll(PDO::FETCH_ASSOC);

// ── Sucursales para filtros y formulario ─────────────────────────────────────
$sucursales = $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Promociones — Ferretería Aldrete</title>
</head>
<body>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Arial, sans-serif; display: flex; height: 100vh; overflow: hidden; }
    .sidebar { width: 220px; background: white; border-right: 1px solid #e8e8e8; display: flex; flex-direction: column; transition: width 0.3s; flex-shrink: 0; overflow: hidden; }
    .sidebar.collapsed { width: 0; }
    .sidebar-header { padding: 18px 16px; border-bottom: 1px solid #f0f0f0; }
    .sidebar-header h3 { font-size: 14px; font-weight: 700; color: #14ace7; margin: 0; }
    .sidebar-header p  { font-size: 11px; color: #999; margin: 4px 0 0; }
    .sidebar-menu { flex: 1; padding: 8px 0; overflow-y: auto; }
    .menu-item { display: block; padding: 10px 16px; font-size: 13px; color: #555; cursor: pointer; border-left: 3px solid transparent; text-decoration: none; transition: all 0.15s; white-space: nowrap; }
    .menu-item:hover { background: #eef8ff; color: #14ace7; }
    .menu-item.active { background: #eef8ff; border-left-color: #14ace7; color: #14ace7; font-weight: 600; }
    .menu-label { padding: 8px 16px 4px; font-size: 10px; color: #14ace7; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
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
    .content { flex: 1; padding: 20px; overflow-y: auto; display: grid; grid-template-columns: 1fr 360px; gap: 16px; align-items: start; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 18px; margin-bottom: 14px; }
    .card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 14px; }
    .msg-ok  { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; padding: 11px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-err { background: #fdecea; color: #c0392b; border-left: 3px solid #c0392b; padding: 11px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 9px 12px; text-align: left; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid #eee; }
    td { padding: 9px 12px; font-size: 12px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-activa   { background: #e8f5e9; color: #2e7d32; }
    .badge-proxima  { background: #e3f2fd; color: #1565c0; }
    .badge-vencida  { background: #f0f0f0; color: #888; }
    .badge-inactiva { background: #fdecea; color: #c0392b; }
    .form-group { margin-bottom: 13px; }
    .form-group label { display: block; font-size: 12px; color: #555; margin-bottom: 5px; font-weight: 600; }
    .form-group input, .form-group select, .form-group textarea {
        width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif;
    }
    .form-group input:focus, .form-group select:focus { outline: none; border-color: #14ace7; }
    .btn-guardar { width: 100%; background: #14ace7; color: white; border: none; padding: 11px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-guardar:hover { background: #1196cb; }
    .acciones { display: flex; gap: 4px; flex-wrap: wrap; }
    .btn-sm { padding: 4px 9px; border-radius: 5px; font-size: 11px; cursor: pointer; border: none; font-weight: 600; }
    .btn-desact { background: #fff8e1; color: #e65100; }
    .btn-act    { background: #e8f5e9; color: #2e7d32; }
    .btn-del    { background: #fdecea; color: #c0392b; }
    .search-wrap { position: relative; }
    .sugerencias { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ddd; border-top: none; border-radius: 0 0 6px 6px; z-index: 200; max-height: 200px; overflow-y: auto; display: none; box-shadow: 0 4px 12px rgba(0,0,0,.08); }
    .sug-item { padding: 9px 12px; cursor: pointer; font-size: 13px; border-bottom: 0.5px solid #f5f5f5; }
    .sug-item:hover { background: #eef8ff; }
    .prod-sel-box { background: #eef8ff; border: 1px solid #bbdefb; border-radius: 6px; padding: 10px 12px; display: none; margin-bottom: 10px; }
    .prod-sel-box strong { font-size: 13px; color: #1565c0; }
    .prod-sel-box small  { font-size: 11px; color: #666; display: block; margin-top: 2px; }
    .precio-preview { font-size: 12px; color: #2e7d32; margin-top: 4px; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    .filter-bar { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 12px; }
    .filter-bar select, .filter-bar input { padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filter-bar button { background: #14ace7; color: #fff; border: none; padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
    .ahorro-badge { font-size: 11px; color: #2e7d32; font-weight: 700; }
    .precio-orig  { font-size: 11px; color: #aaa; text-decoration: line-through; }
</style>

<?php renderAdminSidebar('promociones'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Promociones</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Lista -->
        <div>
            <?php if ($msg):  ?><div class="msg-ok"><?=  htmlspecialchars($msg)  ?></div><?php endif; ?>
            <?php if ($error):?><div class="msg-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <!-- Filtros -->
            <form method="GET" class="filter-bar" style="margin-bottom:12px;">
                <div>
                    <label style="display:block;font-size:11px;color:#888;margin-bottom:3px;font-weight:600;">Sucursal</label>
                    <select name="sucursal_f">
                        <option value="">Todas</option>
                        <?php foreach ($sucursales as $s): ?>
                        <option value="<?= $s['sucursal_id'] ?>" <?= $filtroSucursal == $s['sucursal_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:11px;color:#888;margin-bottom:3px;font-weight:600;">Estado</label>
                    <select name="estado_f">
                        <?php foreach (['todas'=>'Todas','activas'=>'Activas','proximas'=>'Próximas','vencidas'=>'Vencidas/Inactivas'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $filtroEstado===$k?'selected':'' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit">Filtrar</button>
                <a href="promociones.php" style="font-size:12px;color:#aaa;align-self:center;text-decoration:none;">Limpiar</a>
            </form>

            <div class="card" style="padding:0;">
                <?php if (count($promociones) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Sucursal</th>
                            <th>Precio normal</th>
                            <th>Precio promo</th>
                            <th>Ahorro</th>
                            <th>Periodo</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($promociones as $pr):
                            $hoy    = date('Y-m-d');
                            $inicio = $pr['fecha_inicio'];
                            $fin    = $pr['fecha_fin'];
                            if (!$pr['activo']) {
                                $estBadge = 'badge-inactiva'; $estLabel = 'Inactiva';
                            } elseif ($hoy < $inicio) {
                                $estBadge = 'badge-proxima'; $estLabel = 'Próxima';
                            } elseif ($hoy > $fin) {
                                $estBadge = 'badge-vencida'; $estLabel = 'Vencida';
                            } else {
                                $estBadge = 'badge-activa'; $estLabel = 'Activa';
                            }
                            $ahorroPct = $pr['precio_venta'] > 0
                                ? round((1 - $pr['precio_promocional'] / $pr['precio_venta']) * 100, 1)
                                : 0;
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($pr['nombre_producto']) ?></strong>
                                <div style="font-size:10px;color:#aaa;"><?= htmlspecialchars($pr['codigo']) ?></div>
                                <?php if ($pr['descripcion']): ?>
                                <div style="font-size:11px;color:#888;margin-top:2px;"><?= htmlspecialchars($pr['descripcion']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;"><?= htmlspecialchars($pr['sucursal_nombre']) ?></td>
                            <td class="precio-orig">$<?= number_format($pr['precio_venta'], 2) ?></td>
                            <td style="font-weight:700;color:#2e7d32;">$<?= number_format($pr['precio_promocional'], 2) ?></td>
                            <td><span class="ahorro-badge">-<?= $ahorroPct ?>%</span></td>
                            <td style="font-size:11px;color:#555;">
                                <?= date('d/m/Y', strtotime($inicio)) ?><br>
                                <span style="color:#aaa;">→ <?= date('d/m/Y', strtotime($fin)) ?></span>
                            </td>
                            <td><span class="badge <?= $estBadge ?>"><?= $estLabel ?></span></td>
                            <td>
                                <div class="acciones">
                                    <?php if ($pr['activo']): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="accion" value="desactivar">
                                        <input type="hidden" name="promocion_id" value="<?= $pr['promocion_id'] ?>">
                                        <button class="btn-sm btn-desact" type="submit">Pausar</button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="accion" value="activar">
                                        <input type="hidden" name="promocion_id" value="<?= $pr['promocion_id'] ?>">
                                        <button class="btn-sm btn-act" type="submit">Activar</button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminar esta promoción definitivamente?')">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="promocion_id" value="<?= $pr['promocion_id'] ?>">
                                        <button class="btn-sm btn-del" type="submit">Eliminar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay promociones con ese filtro.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario crear -->
        <div>
            <div class="card">
                <h3>Nueva promoción</h3>

                <form method="POST" id="formPromo">
                    <input type="hidden" name="accion" value="crear">
                    <input type="hidden" id="inputProductoId" name="producto_id">

                    <div class="form-group">
                        <label>Sucursal *</label>
                        <select id="selSucursal" onchange="onSucursalChange(this.value)">
                            <option value="">-- Selecciona sucursal --</option>
                            <?php foreach ($sucursales as $s): ?>
                            <option value="<?= $s['sucursal_id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Buscar producto *</label>
                        <div class="search-wrap">
                            <input type="text" id="busqProd" autocomplete="off"
                                   placeholder="Selecciona primero la sucursal..."
                                   disabled oninput="buscarProd()" onfocus="buscarProd()">
                            <div class="sugerencias" id="sugProd"></div>
                        </div>
                    </div>

                    <div class="prod-sel-box" id="prodSelBox">
                        <strong id="prodSelNombre"></strong>
                        <small id="prodSelInfo"></small>
                        <div class="precio-preview" id="precioPreview"></div>
                    </div>

                    <div class="form-group">
                        <label>Precio promocional ($) *</label>
                        <input type="number" name="precio_promocional" id="inputPrecioPromo"
                               step="0.01" min="0.01" placeholder="Ej. 45.00"
                               oninput="actualizarPreview()">
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div class="form-group">
                            <label>Fecha inicio *</label>
                            <input type="date" name="fecha_inicio" id="inputFechaInicio"
                                   value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label>Fecha fin *</label>
                            <input type="date" name="fecha_fin" id="inputFechaFin"
                                   value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Descripción (opcional)</label>
                        <input type="text" name="descripcion" placeholder="Ej. Oferta de temporada, Liquidación, etc.">
                    </div>

                    <button class="btn-guardar" type="submit" onclick="return validarForm()">
                        Crear promoción
                    </button>
                </form>
            </div>

            <div class="card" style="font-size:12px;color:#666;line-height:1.7;">
                <h3 style="margin-bottom:10px;">Cómo funcionan</h3>
                <ul style="padding-left:16px;">
                    <li>El precio promocional se aplica automáticamente en <strong>Nueva venta</strong> mientras la promoción esté activa.</li>
                    <li>Se muestra el precio anterior tachado y el precio con descuento.</li>
                    <li>Al finalizar la venta se calcula el <strong>ahorro total</strong> del cliente.</li>
                    <li>Solo aplica al rango de fechas definido.</li>
                    <li>Puedes <em>pausar</em> una promoción sin eliminarla.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

let prodSelPrecio = 0;

function onSucursalChange(val) {
    const inp = document.getElementById('busqProd');
    inp.disabled = !val;
    inp.value    = '';
    inp.placeholder = val ? 'Escribe nombre o código...' : 'Selecciona primero la sucursal...';
    document.getElementById('inputProductoId').value = '';
    document.getElementById('prodSelBox').style.display = 'none';
    document.getElementById('sugProd').style.display = 'none';
    prodSelPrecio = 0;
}

function buscarProd() {
    const suc = document.getElementById('selSucursal').value;
    const q   = document.getElementById('busqProd').value.trim();
    if (!suc) return;
    fetch(`promociones.php?buscar_prods=1&sucursal_id=${suc}&q=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(prods => {
            const div = document.getElementById('sugProd');
            if (!prods.length) { div.style.display = 'none'; return; }
            div.innerHTML = prods.map(p => `
                <div class="sug-item" onclick="selProd(${p.producto_id},'${esc(p.nombre_producto)}','${esc(p.codigo)}',${p.precio_venta},'${p.tipo_venta}')">
                    <strong>${esc(p.nombre_producto)}</strong>
                    <span style="color:#aaa;font-size:11px;margin-left:6px;">${esc(p.codigo)}</span>
                    <span style="float:right;color:#1565c0;font-size:12px;">$${parseFloat(p.precio_venta).toFixed(2)}</span>
                </div>
            `).join('');
            div.style.display = 'block';
        });
}

function selProd(id, nombre, codigo, precio, tipo) {
    document.getElementById('inputProductoId').value = id;
    document.getElementById('busqProd').value = nombre;
    document.getElementById('sugProd').style.display = 'none';
    prodSelPrecio = parseFloat(precio);

    document.getElementById('prodSelNombre').textContent = nombre;
    document.getElementById('prodSelInfo').textContent   = 'Código: ' + codigo + ' · Precio normal: $' + parseFloat(precio).toFixed(2) + (tipo === 'Suelto' ? ' (granel)' : '');
    document.getElementById('prodSelBox').style.display = 'block';
    actualizarPreview();
    document.getElementById('inputPrecioPromo').focus();
}

function actualizarPreview() {
    const promo = parseFloat(document.getElementById('inputPrecioPromo').value) || 0;
    const div   = document.getElementById('precioPreview');
    if (!prodSelPrecio || !promo) { div.textContent = ''; return; }
    const ahorro = prodSelPrecio - promo;
    const pct    = ((ahorro / prodSelPrecio) * 100).toFixed(1);
    if (ahorro <= 0) {
        div.style.color = '#c0392b';
        div.textContent = '⚠ El precio promo debe ser menor al precio normal.';
    } else {
        div.style.color = '#2e7d32';
        div.textContent = `Ahorro: $${ahorro.toFixed(2)} (${pct}% de descuento)`;
    }
}

function validarForm() {
    if (!document.getElementById('inputProductoId').value) {
        alert('Selecciona un producto.'); return false;
    }
    const promo = parseFloat(document.getElementById('inputPrecioPromo').value) || 0;
    if (!promo) { alert('Ingresa el precio promocional.'); return false; }
    if (prodSelPrecio > 0 && promo >= prodSelPrecio) {
        alert('El precio promocional debe ser menor al precio normal ($' + prodSelPrecio.toFixed(2) + ').'); return false;
    }
    const ini = document.getElementById('inputFechaInicio').value;
    const fin = document.getElementById('inputFechaFin').value;
    if (!ini || !fin) { alert('Ingresa las fechas de la promoción.'); return false; }
    if (fin < ini) { alert('La fecha de fin no puede ser anterior al inicio.'); return false; }
    return true;
}

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/'/g,'&#39;').replace(/"/g,'&quot;');
}

document.getElementById('busqProd').addEventListener('blur', function() {
    setTimeout(() => { document.getElementById('sugProd').style.display = 'none'; }, 200);
});
</script>
</body>
</html>
