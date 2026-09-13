<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../includes/icons.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
// [FIX-CRIT-B-04, ajustado 2026-08-26] Regla confirmada: crear/editar/eliminar unidades de
// medida (necesarias para la importación de productos) es SOLO Administrador — ni Inventario
// (puro) ni Inventario/Cajero.
verificarRol(['Administrador']);
require_once '../includes/topbar_info.php';

// [CAMBIO-UNIDAD-GLOBAL 2026-09-09] Unidades de medida ahora es un catalogo GLOBAL, igual
// que categorias/proveedores/paquetes -- confirmado con el usuario: era la unica entidad de
// catalogo aislada por sucursal, y eso obligaba a elegir una sucursal especifica para crear
// una unidad en vez de compartirla entre todas. Se alinea con el mismo patron ya usado en
// inventario_categorias.php.

// Eliminar unidad
if (isset($_GET['eliminar'])) {
    // [FIX-CRIT-B-03] CSRF ausente antes.
    requerirCSRF($_GET['_token'] ?? '', 'inventario_unidades.php');
    $id = intval(is_scalar($_GET['eliminar'] ?? null) ? $_GET['eliminar'] : 0);
    $u = $pdo->prepare("SELECT nombre FROM unidades_medida WHERE unidad_id = ?");
    $u->execute([$id]);
    $unidadRow = $u->fetch(PDO::FETCH_ASSOC);
    if ($unidadRow) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE unidad_medida = ? AND activo = 1");
        $check->execute([$unidadRow['nombre']]);
        if ($check->fetchColumn() > 0) {
            header('Location: inventario_unidades.php?msg=error_productos');
            exit();
        }
    }
    $pdo->prepare("DELETE FROM unidades_medida WHERE unidad_id = ?")->execute([$id]);
    header('Location: inventario_unidades.php?msg=eliminado');
    exit();
}

// Guardar unidad (crear o editar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [FIX-CRIT-B-03] CSRF ausente antes.
    requerirCSRF($_POST['_token'] ?? '', 'inventario_unidades.php');
    $nombre = trim(is_scalar($_POST['nombre'] ?? null) ? (string)$_POST['nombre'] : '');
    // [FIX-UNIDAD-ID-DESINCRONIZADO] (mismo patron que categorias.php): se ancla al ?editar=
    // de la URL, no al <input hidden name="unidad_id">.
    $id = is_scalar($_GET['editar'] ?? null) ? intval($_GET['editar']) : 0;

    // [AUTOFIX] VALIDACION-3B-2: Validar nombre en blanco antes de tocar la BD
    if ($nombre === '') {
        header('Location: inventario_unidades.php?msg=vacio');
        exit();
    }
    // [FIX-MEDIO-B-19] unidades_medida.nombre es VARCHAR(50), pero productos.unidad_medida
    // (donde se copia el nombre elegido) es VARCHAR(30) — un nombre mas largo se truncaba
    // en silencio al guardarlo en el producto, desalineandolo del catalogo de unidades. Se
    // exige aqui el mismo limite de 30 para que nunca se guarde algo que no cabe despues.
    if (mb_strlen($nombre) > 30) {
        header('Location: inventario_unidades.php?msg=muy_largo');
        exit();
    }

    // [AUTOFIX] ERROR-UNIT-01: Capturar PDOException de clave duplicada en lugar de exponer el error PHP
    try {
        if ($id) {
            // [FIX-UNIDAD-EDICION-FANTASMA] Antes, si $id no correspondía a ninguna unidad real
            // (borrada por otra sesión, o un POST directo con un id inventado), el UPDATE de
            // abajo afectaba 0 filas en silencio y aun así se reportaba "Unidad actualizada
            // correctamente" — probado en vivo con unidad_id=999999.
            $stmtNombreViejo = $pdo->prepare("SELECT nombre FROM unidades_medida WHERE unidad_id = ?");
            $stmtNombreViejo->execute([$id]);
            $nombreViejo = $stmtNombreViejo->fetchColumn();
            if ($nombreViejo === false) {
                header('Location: inventario_unidades.php?msg=no_encontrado');
                exit();
            }

            // [FIX-MEDIO-H-07] El renombre de la unidad y su propagacion en cascada a
            // productos.unidad_medida (copia de texto, no una FK) son dos UPDATE sueltos: si
            // el segundo fallara, el catalogo ya mostraria el nombre nuevo pero los productos
            // que la usaban se quedarian con el nombre viejo, huerfano.
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE unidades_medida SET nombre = ? WHERE unidad_id = ?")
                ->execute([$nombre, $id]);
            if ($nombreViejo !== $nombre) {
                $pdo->prepare("UPDATE productos SET unidad_medida = ? WHERE unidad_medida = ?")
                    ->execute([$nombre, $nombreViejo]);
            }
            $pdo->commit();
            header('Location: inventario_unidades.php?msg=editado');
        } else {
            $pdo->prepare("INSERT INTO unidades_medida (nombre) VALUES (?)")
                ->execute([$nombre]);
            header('Location: inventario_unidades.php?msg=creado');
        }
        exit();
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') {
            // Clave duplicada — nombre ya existe
            header('Location: inventario_unidades.php?msg=duplicado');
            exit();
        }
        throw $e;
    }
}

$busqueda = trim(is_scalar($_GET['buscar'] ?? null) ? (string)$_GET['buscar'] : '');
if ($busqueda) {
    $stmt = $pdo->prepare("
        SELECT u.*, COUNT(p.producto_id) AS total_productos
        FROM unidades_medida u
        LEFT JOIN productos p ON p.unidad_medida = u.nombre AND p.activo = 1
        WHERE u.nombre LIKE ?
        GROUP BY u.unidad_id
        ORDER BY u.nombre ASC
    ");
    $stmt->execute(['%'.$busqueda.'%']);
} else {
    $stmt = $pdo->query("
        SELECT u.*, COUNT(p.producto_id) AS total_productos
        FROM unidades_medida u
        LEFT JOIN productos p ON p.unidad_medida = u.nombre AND p.activo = 1
        GROUP BY u.unidad_id
        ORDER BY u.nombre ASC
    ");
}
$unidades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unidad a editar
$editando = null;
if (isset($_GET['editar'])) {
    $stmt2 = $pdo->prepare("SELECT * FROM unidades_medida WHERE unidad_id = ?");
    $stmt2->execute([intval(is_scalar($_GET['editar'] ?? null) ? $_GET['editar'] : 0)]);
    $editando = $stmt2->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unidades de medida — Ferretería Aldrete</title>
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
    .toggle-btn { background: none; border: none; color: white; cursor: pointer; font-size: 20px; padding: 4px 8px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; }
    .toggle-btn:hover { background: rgba(255,255,255,0.2); }
    .topbar-right { display: flex; align-items: center; gap: 14px; font-size: 13px; }
    .logout-btn { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); color: white; padding: 5px 14px; border-radius: 5px; cursor: pointer; font-size: 12px; }
    .logout-btn:hover { background: rgba(255,255,255,0.3); }
    .content { flex: 1; padding: 28px; overflow-y: auto; display: grid; grid-template-columns: 1fr 320px; gap: 20px; align-items: start; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 16px; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .barra-busqueda { display: flex; gap: 10px; margin-bottom: 16px; }
    .barra-busqueda input { flex: 1; padding: 9px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .barra-busqueda input:focus { outline: none; border-color: #14ace7; }
    .btn-buscar { background: #14ace7; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .msg-error { background: #fdecea; color: #c0392b; border-left: 3px solid #c0392b; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    thead { background: #f9f9f9; }
    th { padding: 11px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .acciones { display: flex; gap: 6px; }
    .btn-accion { padding: 5px 12px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #e3f2fd; color: #1565c0; }
    .btn-editar:hover { background: #bbdefb; }
    .btn-eliminar { background: #fdecea; color: #c0392b; }
    .btn-eliminar:hover { background: #ffcdd2; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
    .badge-count { background: #f0f0f0; color: #666; font-size: 11px; padding: 2px 10px; border-radius: 99px; font-weight: 600; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .form-group input:focus, .form-group select:focus { outline: none; border-color: #14ace7; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; width: 100%; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar-edit { background: white; color: #666; border: 1px solid #ddd; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; width: 100%; margin-top: 8px; text-decoration: none; display: block; text-align: center; }
    .btn-cancelar-edit:hover { background: #f5f5f5; }
    .hint { font-size: 11px; color: #aaa; margin-top: 4px; }
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

<?php renderAdminSidebar('inventario_unidades'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()"><?= icono('menu') ?></button>
            <h2>Unidades de medida</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="content">
        <!-- Lista -->
        <div>
            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'creado'): ?>
                    <div class="msg msg-exito">Unidad creada correctamente.</div>
                <?php elseif ($_GET['msg'] === 'editado'): ?>
                    <div class="msg msg-exito">Unidad actualizada correctamente.</div>
                <?php elseif ($_GET['msg'] === 'eliminado'): ?>
                    <div class="msg msg-exito">Unidad eliminada correctamente.</div>
                <?php elseif ($_GET['msg'] === 'error_productos'): ?>
                    <div class="msg msg-error">No puedes eliminar esta unidad porque tiene productos asociados.</div>
                <?php elseif ($_GET['msg'] === 'duplicado'): ?>
                    <div class="msg msg-error">Ya existe una unidad con ese nombre. Elige un nombre diferente.</div>
                <?php elseif ($_GET['msg'] === 'vacio'): ?>
                    <div class="msg msg-error">El nombre de la unidad de medida es obligatorio.</div>
                <?php elseif ($_GET['msg'] === 'muy_largo'): ?>
                    <div class="msg msg-error">El nombre de la unidad no puede tener más de 30 caracteres.</div>
                <?php elseif ($_GET['msg'] === 'no_encontrado'): ?>
                    <div class="msg msg-error">Esa unidad ya no existe (puede que otra sesión la haya eliminado). Recarga la página.</div>
                <?php elseif ($_GET['msg'] === 'error_token'): ?>
                    <div class="msg msg-error">La sesión expiró o el enlace no es válido. Intenta de nuevo.</div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="GET" action="inventario_unidades.php">
                <div class="barra-busqueda">
                    <input type="text" name="buscar" placeholder="Buscar unidad..." value="<?= htmlspecialchars($busqueda) ?>" oninput="filtrarTabla(this.value)">
                    <button class="btn-buscar" type="submit">Buscar</button>
                    <?php if ($busqueda): ?>
                        <a class="btn-limpiar" href="inventario_unidades.php">Limpiar</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="card" style="padding:0;">
                <?php if (count($unidades) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Productos</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tablaFiltrable">
                        <?php foreach ($unidades as $u): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($u['nombre']) ?></strong></td>
                            <td><span class="badge-count"><?= $u['total_productos'] ?> productos</span></td>
                            <td>
                                <div class="acciones">
                                    <a class="btn-accion btn-editar" href="inventario_unidades.php?editar=<?= $u['unidad_id'] ?>">Editar</a>
                                    <a class="btn-accion btn-eliminar" href="inventario_unidades.php?eliminar=<?= $u['unidad_id'] ?>&_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>" onclick="return confirm('¿Eliminar esta unidad?')">Eliminar</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay unidades registradas<?= $busqueda ? ' con esa búsqueda' : '' ?>.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario lateral -->
        <div>
            <div class="card">
                <h3><?= $editando ? 'Editar unidad' : 'Nueva unidad' ?></h3>
                <form method="POST">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="unidad_id" value="<?= $editando['unidad_id'] ?? 0 ?>">

                    <div class="form-group">
                        <label>Nombre *</label>
                        <input type="text" name="nombre" maxlength="30"
                            value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>"
                            placeholder="Ej. pieza, kg, metro, litro" autofocus>
                        <div class="hint">Este nombre aparecerá en el punto de venta junto a la cantidad, en todas tus sucursales.</div>
                    </div>

                    <button class="btn-guardar" type="submit">
                        <?= $editando ? 'Guardar cambios' : 'Agregar unidad' ?>
                    </button>
                    <?php if ($editando): ?>
                        <a class="btn-cancelar-edit" href="inventario_unidades.php">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Sugerencias comunes -->
            <?php if (!$editando): ?>
            <div class="card" style="margin-top:14px;">
                <h3 style="margin-bottom:10px;">Unidades comunes</h3>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    <?php foreach (['pieza','kg','gramo','litro','metro','cm','caja','bolsa','rollo','par','juego','lb','tonelada','ml','m²'] as $sug): ?>
                        <button type="button" onclick="usarSugerida('<?= $sug ?>')"
                            style="background:#f0f9ff;border:1px solid #b3e0f7;color:#0077a8;padding:5px 12px;border-radius:99px;font-size:12px;cursor:pointer;">
                            <?= $sug ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function normalizar(str) {
    return String(str || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}
function filtrarTabla(q) {
    q = normalizar(q);
    document.querySelectorAll('#tablaFiltrable tr').forEach(function(tr) {
        tr.style.display = normalizar(tr.textContent).includes(q) ? '' : 'none';
    });
}
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}
function usarSugerida(nombre) {
    const inp = document.querySelector('input[name="nombre"]');
    if (inp) { inp.value = nombre; inp.focus(); }
}
</script>
</body>
</html>
