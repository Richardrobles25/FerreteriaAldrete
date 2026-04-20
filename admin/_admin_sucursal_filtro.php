<?php
/**
 * _admin_sucursal_filtro.php
 * Include que define $sucursalVista, $nombreSucursalVista y renderSucursalSwitcher()
 * para que el Administrador pueda cambiar entre sucursales en las páginas de inventario.
 *
 * Requiere: sesión iniciada, $pdo disponible, $_SESSION['rol'] y $_SESSION['sucursal_id'].
 */

// Obtener todas las sucursales activas
$_suc_stmt = $pdo->prepare("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre");
$_suc_stmt->execute();
$_todasSucursales = $_suc_stmt->fetchAll(PDO::FETCH_ASSOC);

// Si llega ?sucursal= y el usuario es Administrador, guardar en sesión
if (isset($_GET['sucursal']) && $_SESSION['rol'] === 'Administrador') {
    $sucursalGet = intval($_GET['sucursal']);
    // Validar que la sucursal exista en la lista
    $ids = array_column($_todasSucursales, 'sucursal_id');
    if (in_array($sucursalGet, $ids)) {
        $_SESSION['admin_sucursal_filtro'] = $sucursalGet;
    }
}

// Definir $sucursalVista
if ($_SESSION['rol'] === 'Administrador' && isset($_SESSION['admin_sucursal_filtro'])) {
    $sucursalVista = intval($_SESSION['admin_sucursal_filtro']);
} else {
    $sucursalVista = intval($_SESSION['sucursal_id']);
}

// Definir $nombreSucursalVista
$nombreSucursalVista = '';
foreach ($_todasSucursales as $_s) {
    if (intval($_s['sucursal_id']) === $sucursalVista) {
        $nombreSucursalVista = $_s['nombre'];
        break;
    }
}
if ($nombreSucursalVista === '') {
    $nombreSucursalVista = $_SESSION['sucursal_id'] ?? '';
}

/**
 * Renderiza el selector de sucursales (solo para Administrador).
 * Preserva los demás GET params de la URL actual.
 */
function renderSucursalSwitcher(): void {
    global $_todasSucursales, $sucursalVista;

    if ($_SESSION['rol'] !== 'Administrador') return;
    if (empty($_todasSucursales)) return;

    // Params actuales sin 'sucursal' (para preservarlos al cambiar)
    $paramsBase = $_GET;
    unset($paramsBase['sucursal']);
    ?>
<style>
.sucursal-switcher { display:flex; align-items:center; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
.sucursal-label { font-size:12px; color:#888; font-weight:600; }
.suc-btn { padding:5px 14px; border-radius:20px; font-size:12px; font-weight:600; text-decoration:none; border:1.5px solid #14ace7; color:#14ace7; background:white; transition:all 0.15s; }
.suc-btn:hover { background:#eef8ff; }
.suc-btn.active { background:#14ace7; color:white; }
</style>
<div class="sucursal-switcher">
    <span class="sucursal-label">&#128205; Sucursal:</span>
    <?php foreach ($_todasSucursales as $_s): ?>
        <?php
        $params = array_merge($paramsBase, ['sucursal' => $_s['sucursal_id']]);
        $url = '?' . http_build_query($params);
        $activo = (intval($_s['sucursal_id']) === intval($sucursalVista)) ? ' active' : '';
        ?>
        <a href="<?= htmlspecialchars($url) ?>" class="suc-btn<?= $activo ?>"><?= htmlspecialchars($_s['nombre']) ?></a>
    <?php endforeach; ?>
</div>
    <?php
}
