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
// sucursal=0 → "Todas las sucursales" (vista global)
if (isset($_GET['sucursal']) && $_SESSION['rol'] === 'Administrador') {
    $sucursalGet = intval($_GET['sucursal']);
    $ids = array_column($_todasSucursales, 'sucursal_id');
    if ($sucursalGet === 0 || in_array($sucursalGet, $ids)) {
        $_SESSION['admin_sucursal_filtro'] = $sucursalGet;
    }
}

// Definir $sucursalVista  (0 = todas las sucursales, solo Administrador)
if ($_SESSION['rol'] === 'Administrador') {
    // Default para admin: "Todas las sucursales" (0), a menos que haya elegido una específica
    $sucursalVista = isset($_SESSION['admin_sucursal_filtro'])
        ? intval($_SESSION['admin_sucursal_filtro'])
        : 0;
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

    ?>
<div class="filtro-group">
    <label>Sucursal</label>
    <select name="sucursal" onchange="this.form.submit()">
        <option value="0"<?= $sucursalVista === 0 ? ' selected' : '' ?>>Todas las sucursales</option>
        <?php foreach ($_todasSucursales as $_s): ?>
            <option value="<?= intval($_s['sucursal_id']) ?>"<?= intval($_s['sucursal_id']) === intval($sucursalVista) ? ' selected' : '' ?>><?= htmlspecialchars($_s['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
    <?php
}
