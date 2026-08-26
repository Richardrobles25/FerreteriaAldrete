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
    // [FIX-MEDIO-A-10] Si la sucursal guardada en sesión ya no está activa (alguien la
    // desactivó mientras el admin la tenía seleccionada), antes se seguía usando ese ID
    // para TODAS las consultas de la página, mientras que el <select> — que solo lista
    // sucursales activas — no tenía ningún <option> que coincidiera, así que el navegador
    // mostraba "Todas las sucursales" por default aunque $sucursalVista siguiera apuntando
    // a la sucursal fantasma. Se revalida en cada carga y se regresa a 0 si ya no es válida.
    if ($sucursalVista !== 0 && !in_array($sucursalVista, array_map('intval', array_column($_todasSucursales, 'sucursal_id')), true)) {
        $sucursalVista = 0;
        $_SESSION['admin_sucursal_filtro'] = 0;
    }
} else {
    $sucursalVista = intval($_SESSION['sucursal_id']);
}

// Definir $nombreSucursalVista
$nombreSucursalVista = ($sucursalVista === 0) ? 'Todas las sucursales' : '';
foreach ($_todasSucursales as $_s) {
    if (intval($_s['sucursal_id']) === $sucursalVista) {
        $nombreSucursalVista = $_s['nombre'];
        break;
    }
}

/**
 * Renderiza el selector de sucursales (solo para Administrador).
 * Preserva los demás GET params de la URL actual.
 */
function renderSucursalSwitcher(): void {
    global $_todasSucursales, $sucursalVista;

    if ($_SESSION['rol'] !== 'Administrador') return;
    if (empty($_todasSucursales)) return;

    // [FIX] El <select> no estaba dentro de ningun <form>, asi que "this.form.submit()"
    // fallaba en silencio (this.form era null) y el selector nunca cambiaba de sucursal
    // al hacer clic. En vez de envolverlo en su propio <form> (que romperia paginas donde
    // el switcher se llama DENTRO de otro <form> ya existente, creando un <form> anidado
    // invalido), se navega por JS preservando todos los parametros GET actuales de la URL.
    ?>
<div class="filtro-group">
    <label>Sucursal</label>
    <select name="sucursal" onchange="_cambiarSucursalSwitcher(this.value)">
        <option value="0"<?= $sucursalVista === 0 ? ' selected' : '' ?>>Todas las sucursales</option>
        <?php foreach ($_todasSucursales as $_s): ?>
            <option value="<?= intval($_s['sucursal_id']) ?>"<?= intval($_s['sucursal_id']) === intval($sucursalVista) ? ' selected' : '' ?>><?= htmlspecialchars($_s['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<script>
if (typeof _cambiarSucursalSwitcher !== 'function') {
    function _cambiarSucursalSwitcher(val) {
        var params = new URLSearchParams(window.location.search);
        params.set('sucursal', val);
        window.location.search = params.toString();
    }
}
</script>
    <?php
}
