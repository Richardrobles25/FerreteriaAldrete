<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

$sucursalId = intval($_SESSION['sucursal_id']);

// Eliminar unidad
if (isset($_GET['eliminar'])) {
    // [FIX-CONSISTENCIA, ajustado 2026-08-26] Regla confirmada: crear/editar/eliminar
    // unidades de medida (necesarias para la importación de productos) es SOLO
    // Administrador — ni Inventario (puro) ni Inventario/Cajero.
    if (($_SESSION['rol'] ?? '') !== 'Administrador') {
        header('Location: unidades.php?msg=no_autorizado');
        exit();
    }
    // [AUTOFIX] SEC-01: Verificar CSRF token antes de accion destructiva por GET
    requerirCSRF($_GET['_token'] ?? '', 'unidades.php');
    $id = intval($_GET['eliminar']);
    $u = $pdo->prepare("SELECT nombre FROM unidades_medida WHERE unidad_id = ? AND sucursal_id = ?");
    $u->execute([$id, $sucursalId]);
    $row = $u->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE unidad_medida = ? AND activo = 1");
        $check->execute([$row['nombre']]);
        if ($check->fetchColumn() > 0) {
            header('Location: unidades.php?msg=error_productos');
            exit();
        }
    }
    $pdo->prepare("DELETE FROM unidades_medida WHERE unidad_id = ? AND sucursal_id = ?")->execute([$id, $sucursalId]);
    header('Location: unidades.php?msg=eliminado');
    exit();
}

// Guardar unidad (crear o editar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [FIX-CONSISTENCIA, ajustado 2026-08-26] Regla confirmada: crear/editar/eliminar
    // unidades de medida (necesarias para la importación de productos) es SOLO
    // Administrador — ni Inventario (puro) ni Inventario/Cajero.
    if (($_SESSION['rol'] ?? '') !== 'Administrador') {
        header('Location: unidades.php?msg=no_autorizado');
        exit();
    }
    // [FIX-CSRF-01] Verificar CSRF antes de crear/editar unidad (antes solo ?eliminar= lo tenia)
    requerirCSRF($_POST['_token'] ?? '', 'unidades.php');
    $nombre = trim($_POST['nombre'] ?? '');
    $id     = intval($_POST['unidad_id'] ?? 0);

    // [FIX-CONSISTENCIA] Igual que admin/inventario_unidades.php (FIX-MEDIO-B-19):
    // unidades_medida.nombre es VARCHAR(50), pero productos.unidad_medida (donde se copia el
    // nombre elegido) es VARCHAR(30) — un nombre mas largo se truncaba en silencio al
    // guardarlo en el producto, desalineandolo del catalogo de unidades.
    if ($nombre && mb_strlen($nombre) > 30) {
        header('Location: unidades.php?msg=muy_largo');
        exit();
    }

    if ($nombre) {
        // [AUTOFIX] ERROR-UNIT-01 (portado de admin/inventario_unidades.php): capturar
        // PDOException de clave duplicada en lugar de exponer el error crudo del servidor.
        try {
            if ($id) {
                // [FIX-CONSISTENCIA] Igual que admin/inventario_unidades.php (FIX-MEDIO-B-20 +
                // FIX-MEDIO-H-07): renombrar una unidad no tocaba los productos que ya la
                // usaban (productos.unidad_medida es una copia de texto, no una FK) — quedaban
                // con un nombre de unidad que ya no existe en el catalogo. Se propaga el
                // renombre a los productos que tenian el nombre viejo exacto, envuelto en una
                // transaccion para que ambos UPDATE tengan exito o ninguno.
                $stmtNombreViejo = $pdo->prepare("SELECT nombre FROM unidades_medida WHERE unidad_id = ? AND sucursal_id = ?");
                $stmtNombreViejo->execute([$id, $sucursalId]);
                $nombreViejo = $stmtNombreViejo->fetchColumn();

                $pdo->beginTransaction();
                $pdo->prepare("UPDATE unidades_medida SET nombre = ? WHERE unidad_id = ? AND sucursal_id = ?")
                    ->execute([$nombre, $id, $sucursalId]);
                if ($nombreViejo !== false && $nombreViejo !== $nombre) {
                    $pdo->prepare("UPDATE productos SET unidad_medida = ? WHERE unidad_medida = ?")
                        ->execute([$nombre, $nombreViejo]);
                }
                $pdo->commit();
                header('Location: unidades.php?msg=editado');
            } else {
                $pdo->prepare("INSERT INTO unidades_medida (nombre, sucursal_id) VALUES (?, ?)")
                    ->execute([$nombre, $sucursalId]);
                header('Location: unidades.php?msg=creado');
            }
            exit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') {
                header('Location: unidades.php?msg=duplicado');
                exit();
            }
            throw $e;
        }
    }
}

$stmt = $pdo->prepare("
    SELECT u.*, COUNT(p.producto_id) AS total_productos
    FROM unidades_medida u
    LEFT JOIN productos p ON p.unidad_medida = u.nombre AND p.activo = 1
    WHERE u.sucursal_id = ?
    GROUP BY u.unidad_id
    ORDER BY u.nombre ASC
");
$stmt->execute([$sucursalId]);
$unidades = $stmt->fetchAll(PDO::FETCH_ASSOC);

$editando = null;
if (isset($_GET['editar'])) {
    $stmt2 = $pdo->prepare("SELECT * FROM unidades_medida WHERE unidad_id = ? AND sucursal_id = ?");
    $stmt2->execute([intval($_GET['editar']), $sucursalId]);
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
    .content { flex: 1; padding: 28px; overflow-y: auto; display: grid; grid-template-columns: 1fr 320px; gap: 20px; align-items: start; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 16px; }
    .barra-busqueda { display: flex; gap: 10px; margin-bottom: 16px; }
    .barra-busqueda input { flex: 1; padding: 9px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .barra-busqueda input:focus { outline: none; border-color: #14ace7; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .msg-error { background: #fdecea; color: #c0392b; border-left: 3px solid #c0392b; }
    table { width: 100%; border-collapse: collapse; }
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
    .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .form-group input:focus { outline: none; border-color: #14ace7; }
    .hint { font-size: 11px; color: #aaa; margin-top: 4px; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; width: 100%; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar-edit { background: white; color: #666; border: 1px solid #ddd; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; width: 100%; margin-top: 8px; text-decoration: none; display: block; text-align: center; }
    .btn-cancelar-edit:hover { background: #f5f5f5; }
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
        <a class="menu-item active" href="unidades.php">Unidades de medida</a>
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
                <?php elseif ($_GET['msg'] === 'no_autorizado'): ?>
                    <div class="msg msg-error">No tienes permisos para esta acción. Tu rol no puede crear, editar ni eliminar unidades de medida.</div>
                <?php elseif ($_GET['msg'] === 'muy_largo'): ?>
                    <div class="msg msg-error">El nombre de la unidad no puede tener más de 30 caracteres.</div>
                <?php elseif ($_GET['msg'] === 'duplicado'): ?>
                    <div class="msg msg-error">Ya existe una unidad con ese nombre en esta sucursal. Elige un nombre diferente.</div>
                <?php elseif ($_GET['msg'] === 'error_guardar'): ?>
                    <div class="msg msg-error">No se pudo guardar la unidad. Intenta de nuevo.</div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="barra-busqueda">
                <input type="text" id="inputBuscar" placeholder="Buscar unidad..." oninput="filtrar(this.value)">
            </div>

            <div class="card" style="padding:0;">
                <?php if (count($unidades) > 0): ?>
                <table>
                    <colgroup>
                        <col>
                        <col style="width:130px;">
                        <col style="width:150px;">
                    </colgroup>
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
                                    <a class="btn-accion btn-editar" href="unidades.php?editar=<?= $u['unidad_id'] ?>">Editar</a>
                                    <!-- [AUTOFIX] SEC-01: Token CSRF en link destructivo -->
                                    <a class="btn-accion btn-eliminar" href="unidades.php?eliminar=<?= $u['unidad_id'] ?>&_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>" onclick="return confirm('¿Eliminar esta unidad?')">Eliminar</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div id="sinResultadosFiltro" class="sin-resultados" style="display:none;">Sin resultados para tu búsqueda.</div>
                <?php else: ?>
                    <div class="sin-resultados">No hay unidades registradas.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario lateral -->
        <div>
            <div class="card">
                <h3><?= $editando ? 'Editar unidad' : 'Nueva unidad' ?></h3>
                <form method="POST">
                    <!-- [FIX-CSRF-01] Token CSRF para proteger crear/editar unidad -->
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="unidad_id" value="<?= $editando['unidad_id'] ?? 0 ?>">
                    <div class="form-group">
                        <label>Nombre *</label>
                        <input type="text" name="nombre"
                            value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>"
                            placeholder="Ej. pieza, kg, metro, litro" autofocus>
                        <div class="hint">Aparecerá en el punto de venta junto a la cantidad.</div>
                    </div>
                    <button class="btn-guardar" type="submit">
                        <?= $editando ? 'Guardar cambios' : 'Agregar unidad' ?>
                    </button>
                    <?php if ($editando): ?>
                        <a class="btn-cancelar-edit" href="unidades.php">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
function filtrar(q) {
    const texto = q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    let visibles = 0;
    document.querySelectorAll('#tablaFiltrable tr').forEach(function(tr) {
        const celda = tr.querySelector('td strong');
        if (!celda) return;
        const nombre = celda.textContent.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        const mostrar = nombre.includes(texto);
        tr.style.display = mostrar ? '' : 'none';
        if (mostrar) visibles++;
    });
    document.getElementById('sinResultadosFiltro').style.display = visibles === 0 ? '' : 'none';
}

</script>
</body>
</html>
