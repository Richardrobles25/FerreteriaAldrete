<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../includes/icons.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);
require_once '../includes/topbar_info.php';

$editando  = null;
$errores   = [];
$esEdicion = isset($_GET['id']);

if ($esEdicion) {
    // [FIX-TIPO-ARRAY-ID] intval() sobre un array (ej. "?id[]=1") no lanza error: lo
    // coacciona a 1 (o 0 si el array esta vacio) -- ver nota completa mas abajo, junto a
    // los mismos campos del POST.
    $stmt = $pdo->prepare("SELECT * FROM gastos WHERE gasto_id = ?");
    $stmt->execute([intval(is_scalar($_GET['id'] ?? null) ? $_GET['id'] : 0)]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
    // [FIX-GASTO-EDITAR-FANTASMA] Antes se redirigia aqui SIEMPRE que el gasto ya no
    // existiera, sin importar el metodo -- si otra sesion borraba el gasto entre que
    // este formulario se cargaba (GET) y se enviaba (POST, al mismo ?id=X, ya que un
    // <form> sin "action" postea a la URL actual incluyendo su query string), el POST
    // con los cambios reales del admin se descartaba en silencio aqui mismo, antes de
    // llegar siquiera al manejador de POST: se le redirigia a la lista sin ningun
    // aviso, dando la impresion de que su edicion se guardo. En un GET (enlace viejo o
    // id invalido) si tiene sentido redirigir de inmediato sin explicacion.
    if (!$editando && $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: gastos.php'); exit(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [FIX-CRIT-G-01] CSRF ausente antes. Se valida con verificarCSRF() (no
    // requerirCSRF()) para que el error se muestre igual que las demás validaciones
    // de este formulario — inline, sin perder lo que el usuario ya había capturado.
    if (!verificarCSRF($_POST['_token'] ?? '')) {
        $errores[] = 'La sesión expiró o el formulario no es válido. Recarga la página e intenta de nuevo.';
    }
    if ($esEdicion && !$editando) {
        $errores[] = 'Este gasto ya no existe (probablemente fue eliminado por otra sesión). No se guardaron los cambios.';
    }
    // [FIX-TIPO-ARRAY-ID] Todos estos campos asumian que $_POST[...] siempre era un
    // string/numero simple, pero un POST directo (no un <form> normal) puede mandar
    // cualquier campo como ARRAY (ej. "descripcion[]=x"). Probado en vivo, dos fallas
    // reales distintas: (1) trim()/htmlspecialchars() sobre un array lanzan un TypeError
    // SIN CAPTURAR -- "Fatal error: Uncaught TypeError... must be of type string, array
    // given" con la ruta completa del servidor y stack trace expuestos en la respuesta
    // (HTTP 200, sin ningun mensaje de error normal); (2) intval() sobre un array NO
    // truena, sino que lo coacciona silenciosamente a 1 (array no vacio) o 0 (array
    // vacio) -- probado en vivo en gastos_categorias.php mandando "toggle_id[]=99999"
    // (un id que ni existe): en vez de fallar, afecto de verdad a categoria_gasto_id=1
    // (la categoria real "Vehiculos", la desactivo sin que nadie la hubiera pedido).
    // Se valida is_scalar() antes de pasar cualquier valor a intval()/trim(), tratando
    // un array como si el campo viniera vacio/en cero.
    $sucursal_id        = intval(is_scalar($_POST['sucursal_id'] ?? null) ? $_POST['sucursal_id'] : 0);
    $categoria_gasto_id = intval(is_scalar($_POST['categoria_gasto_id'] ?? null) ? $_POST['categoria_gasto_id'] : 0);
    $descripcion        = trim(is_scalar($_POST['descripcion'] ?? null) ? (string)$_POST['descripcion'] : '');
    $monto              = floatval(str_replace(',', '', is_scalar($_POST['monto'] ?? null) ? $_POST['monto'] : 0));
    $fecha              = trim(is_scalar($_POST['fecha'] ?? null) ? (string)$_POST['fecha'] : '');
    $notas              = trim(is_scalar($_POST['notas'] ?? null) ? (string)$_POST['notas'] : '');
    // [FIX-GASTO-ID-DESINCRONIZADO] Antes $gasto_id salia del campo oculto del POST,
    // mientras $editando (usado para decidir si la sucursal/categoria "propia inactiva"
    // se puede preservar, y si el gasto sigue existiendo) sale del ?id= de la URL -- dos
    // identidades que DEBERIAN ser siempre la misma, pero un POST manipulado podia
    // desincronizarlas. Probado en vivo: abriendo la pagina de edicion del gasto A (con
    // una sucursal inactiva propia) pero mandando en el cuerpo el gasto_id de OTRO gasto
    // B (con una sucursal activa normal, sin ninguna relacion con esa sucursal inactiva)
    // -- B termino reasignado a la sucursal inactiva de A (y con la descripcion/monto de
    // A tambien), colandose por la excepcion de "es mi propio valor" que en realidad
    // aplicaba a A, no a B. Se usa el id de $editando (fijado por la URL, ya verificado
    // que existe) como unica fuente de verdad para saber que gasto se esta editando,
    // ignorando cualquier gasto_id que venga del POST.
    $gasto_id           = ($esEdicion && $editando) ? intval($editando['gasto_id']) : 0;

    if (!$sucursal_id)                               $errores[] = 'Selecciona una sucursal.';
    // [FIX-SUCURSAL-CATEGORIA-INACTIVA-BYPASS] Antes solo se comprobaba que sucursal_id/
    // categoria_gasto_id no fueran 0 (mismo hueco que ya se corrigio en formUsuario.php,
    // FIX-ALTO-A-09) -- nunca que pertenecieran a una sucursal/categoria ACTIVA real. El
    // <select> del formulario solo ofrece activas (mas la propia del gasto si se esta
    // editando uno que ya tenia una inactiva), pero un POST directo podia mandar CUALQUIER
    // id inactivo y se guardaba igual, sin ningun error -- tanto al crear un gasto nuevo
    // como al reasignar uno existente a una sucursal/categoria inactiva SIN RELACION con el
    // gasto original. Probado en vivo: gasto nuevo creado con sucursal_id de una sucursal
    // inactiva (que ni siquiera aparecia en el <select>) y con categoria_gasto_id de una
    // categoria inactiva, ambos sin ningun error. Se permite unicamente si esta activa, o
    // si es edicion y coincide con el valor que el gasto YA tenia (para no romper el fix de
    // categoria/sucursal inactiva de mas abajo, que preserva ediciones legitimas).
    elseif (!empty($sucursal_id)) {
        $stmtSucValida = $pdo->prepare("SELECT activo FROM sucursales WHERE sucursal_id = ?");
        $stmtSucValida->execute([$sucursal_id]);
        $sucActiva = $stmtSucValida->fetchColumn();
        $esSucursalPropiaDelGasto = $esEdicion && $editando && intval($editando['sucursal_id']) === $sucursal_id;
        if ($sucActiva === false) {
            $errores[] = 'La sucursal seleccionada no existe.';
        } elseif (!intval($sucActiva) && !$esSucursalPropiaDelGasto) {
            $errores[] = 'La sucursal seleccionada ya no está activa.';
        }
    }
    if (!$categoria_gasto_id)                        $errores[] = 'Selecciona una categoria.';
    elseif (!empty($categoria_gasto_id)) {
        $stmtCatValida = $pdo->prepare("SELECT activo FROM categorias_gastos WHERE categoria_gasto_id = ?");
        $stmtCatValida->execute([$categoria_gasto_id]);
        $catActiva = $stmtCatValida->fetchColumn();
        $esCategoriaPropiaDelGasto = $esEdicion && $editando && intval($editando['categoria_gasto_id']) === $categoria_gasto_id;
        if ($catActiva === false) {
            $errores[] = 'La categoría seleccionada no existe.';
        } elseif (!intval($catActiva) && !$esCategoriaPropiaDelGasto) {
            $errores[] = 'La categoría seleccionada ya no está activa.';
        }
    }
    if ($descripcion === '')                         $errores[] = 'La descripcion es obligatoria.';
    // [FIX-DESCRIPCION-LARGO] gastos.descripcion es VARCHAR(500) sin validar longitud en
    // servidor -- el maxlength="500" del <input> es solo una ayuda del navegador, un POST
    // directo podia mandar mas de 500 caracteres y se guardaba TRUNCADO en silencio, sin
    // ningun aviso. Mismo patron ya corregido en varios archivos del proyecto
    // (categorias.php, proveedores.php, clientes.php, formEmpleado.php, etc.). Probado en
    // vivo: 550 caracteres enviados, 500 guardados, sin ningun error.
    elseif (mb_strlen($descripcion) > 500)           $errores[] = 'La descripcion no puede tener mas de 500 caracteres.';
    if ($monto <= 0)                                 $errores[] = 'El monto debe ser mayor a $0.';
    // [FIX-PRECIO-MAX-GASTO] monto es DECIMAL(10,2); sin tope, un valor absurdo caía en el
    // catch generico de abajo con un mensaje que no explica la causa real.
    if ($monto > 500000)                             $errores[] = 'El monto no puede ser mayor a $500,000.00. Verifica la cantidad capturada.';
    // [FIX-FECHA-CALENDARIO-INVALIDA] strtotime() no rechaza fechas de calendario
    // imposibles (dias/meses fuera de rango) -- las "normaliza" corriendo el mes
    // (p. ej. strtotime('2026-02-30') da 2 de marzo), pero el string LITERAL que se
    // guarda en la BD sigue siendo el original ('2026-02-30'), no el normalizado.
    // Probado en vivo: INSERT directo con fecha='2026-02-30' se guardo localmente
    // como '0000-00-00' sin ningun error (sql_mode local no es estricto), dejando el
    // gasto con una fecha basura invisible para cualquier filtro de fecha normal.
    // Mismo patron ya usado en formEmpleado.php: exigir formato Y-m-d exacto y
    // validar con checkdate() que la fecha exista realmente en el calendario.
    if (!$fecha || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $mFecha) || !checkdate((int)$mFecha[2], (int)$mFecha[3], (int)$mFecha[1])) {
        $errores[] = 'La fecha no es valida.';
    }
    // [FIX-MEDIO-G-16] No habia tope contra fechas futuras.
    elseif ($fecha > date('Y-m-d'))                  $errores[] = 'La fecha no puede ser en el futuro.';

    // [FIX-ALTO-G-12] Antes una PDOException (p. ej. sucursal_id/categoria_gasto_id de un
    // <select> desactualizado que ya no existe o esta inactivo, violando la FK) se dejaba
    // sin capturar: HTTP 500 con la ruta del servidor y el esquema de la BD expuestos en
    // vez de un mensaje de error normal en el formulario.
    if (empty($errores)) {
        try {
            if ($gasto_id) {
                $stmtUpdGasto = $pdo->prepare("
                    UPDATE gastos SET sucursal_id = ?, categoria_gasto_id = ?, descripcion = ?, monto = ?, fecha = ?, notas = ?
                    WHERE gasto_id = ?
                ");
                $stmtUpdGasto->execute([$sucursal_id, $categoria_gasto_id, $descripcion, $monto, $fecha, $notas ?: null, $gasto_id]);
                // [FIX-GASTO-EDITAR-FANTASMA] (mismo patron ya usado en clientes.php,
                // formSucursal.php, inventario_categorias.php, etc.) Antes se redirigia a
                // "exito" sin comprobar si el gasto_id todavia existia -- si otra sesion lo
                // borraba entre que se abrio el formulario y se guardo, el UPDATE afectaba 0
                // filas en silencio y el admin igual veia la pantalla de exito, creyendo que
                // su edicion se guardo cuando en realidad no paso nada. rowCount()===0 tambien
                // puede significar "se guardo sin cambios reales" (no un error), por lo que se
                // verifica la existencia por separado solo en ese caso.
                if ($stmtUpdGasto->rowCount() === 0) {
                    $stmtExisteGasto = $pdo->prepare("SELECT 1 FROM gastos WHERE gasto_id = ?");
                    $stmtExisteGasto->execute([$gasto_id]);
                    if (!$stmtExisteGasto->fetchColumn()) {
                        $errores[] = 'Este gasto ya no existe (probablemente fue eliminado por otra sesión). No se guardaron los cambios.';
                    }
                }
            } else {
                $pdo->prepare("
                    INSERT INTO gastos (sucursal_id, usuario_id, categoria_gasto_id, descripcion, monto, fecha, notas)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([$sucursal_id, $_SESSION['usuario_id'], $categoria_gasto_id, $descripcion, $monto, $fecha, $notas ?: null]);
            }
            if (empty($errores)) {
                header('Location: gastos.php');
                exit();
            }
        } catch (PDOException $e) {
            $errores[] = 'No se pudo guardar el gasto. Verifica que la sucursal y la categoría sigan siendo válidas e intenta de nuevo.';
        }
    }
}

// [FIX-MEDIO-G-21] El combo de sucursales solo traia "activo=1" — si la sucursal de un gasto
// ya existente se desactivaba despues, el <select> al editar ya no tenia esa opcion, el
// navegador caia al valor en blanco, y guardar CUALQUIER cambio (hasta solo corregir la
// descripcion) quedaba bloqueado por "Selecciona una sucursal" a menos que se reasignara el
// gasto a una sucursal distinta de la real. Se incluye la sucursal actual del gasto aunque
// este inactiva, marcada como tal.
$sucursalActualId = $editando['sucursal_id'] ?? null;
if ($sucursalActualId) {
    $sucursales = $pdo->prepare("SELECT sucursal_id, nombre, activo FROM sucursales WHERE activo = 1 OR sucursal_id = ? ORDER BY nombre");
    $sucursales->execute([$sucursalActualId]);
    $sucursales = $sucursales->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sucursales = $pdo->query("SELECT sucursal_id, nombre, activo FROM sucursales WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
}
// [FIX-CATEGORIA-INACTIVA-EDITAR] (mismo patron que FIX-MEDIO-G-21 arriba, aplicado aqui a
// categoria_gasto_id) El combo de categorias solo traia "activo=1" — si la categoria de un
// gasto ya existente se desactivaba despues (gastos_categorias.php), el <select> al editar
// ya no tenia esa opcion, el navegador caia al valor en blanco, y guardar CUALQUIER cambio
// (hasta solo corregir la descripcion) quedaba bloqueado por "Selecciona una categoria" a
// menos que se reasignara el gasto a una categoria distinta de la real. Probado en vivo:
// crear categoria + gasto, desactivar la categoria, editar el gasto sin tocar la categoria
// -> bloqueado con ese error, la descripcion no se guardaba. Se incluye la categoria actual
// del gasto aunque este inactiva, marcada como tal.
$categoriaActualId = $editando['categoria_gasto_id'] ?? null;
if ($categoriaActualId) {
    $categorias = $pdo->prepare("SELECT categoria_gasto_id, nombre, activo FROM categorias_gastos WHERE activo = 1 OR categoria_gasto_id = ? ORDER BY nombre");
    $categorias->execute([$categoriaActualId]);
    $categorias = $categorias->fetchAll(PDO::FETCH_ASSOC);
} else {
    $categorias = $pdo->query("SELECT categoria_gasto_id, nombre, activo FROM categorias_gastos WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
}

// [FIX-TIPO-ARRAY-ID] Esto volvia a leer $_POST[...] CRUDO para repoblar el formulario
// tras un error de validacion -- si algun campo llegaba como array, el saneo de mas
// arriba (is_scalar antes de trim()/intval()) no aplicaba aqui, y el htmlspecialchars()
// de mas abajo (al pintar el value="...") volvia a tronar con el mismo TypeError.
// Probado en vivo: "descripcion[]=x" ya no truena en el saneo de arriba, pero SI seguia
// tronando aqui hasta este fix. Se usan las variables YA saneadas del manejador de POST
// (siempre escalares) en vez de releer $_POST directo.
$esPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$v = [
    'sucursal_id'        => $esPost ? $sucursal_id        : ($editando['sucursal_id']        ?? ''),
    'categoria_gasto_id' => $esPost ? $categoria_gasto_id : ($editando['categoria_gasto_id'] ?? ''),
    'descripcion'        => $esPost ? $descripcion        : ($editando['descripcion']        ?? ''),
    'monto'              => $esPost ? $monto              : ($editando['monto']              ?? ''),
    'fecha'              => $esPost ? $fecha              : ($editando['fecha']              ?? date('Y-m-d')),
    'notas'              => $esPost ? $notas              : ($editando['notas']              ?? ''),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $esEdicion ? 'Editar' : 'Nuevo' ?> Gasto — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; display: flex; justify-content: center; align-items: flex-start; }
    .card { background: white; border-radius: 10px; border: 0.5px solid #e8e8e8; padding: 28px; width: 100%; max-width: 520px; }
    .card-title { font-size: 16px; font-weight: 700; color: #222; margin-bottom: 22px; padding-bottom: 14px; border-bottom: 1px solid #f0f0f0; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; margin-bottom: 6px; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .form-group textarea { resize: vertical; min-height: 70px; }
    .monto-prefix { position: relative; }
    .monto-prefix span { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 13px; color: #888; }
    .monto-prefix input { padding-left: 22px; }
    .errores { background: #fff0f0; border: 1px solid #fdd; border-radius: 7px; padding: 12px 14px; margin-bottom: 16px; }
    .errores ul { padding-left: 18px; }
    .errores li { font-size: 13px; color: #c0392b; margin-bottom: 4px; }
    .form-actions { display: flex; gap: 10px; margin-top: 24px; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 11px 24px; border-radius: 7px; cursor: pointer; font-size: 14px; font-weight: 600; flex: 1; }
    .btn-guardar:hover { background: #119dd4; }
    .btn-cancelar { background: white; color: #666; border: 1px solid #ddd; padding: 11px 20px; border-radius: 7px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; text-align: center; }
    .btn-cancelar:hover { background: #f5f5f5; }
    @media (max-width: 768px) {
        .sidebar { position: fixed; top: 0; left: 0; height: 100%; z-index: 300; width: 0; transition: width 0.3s; }
        .sidebar.collapsed { width: 260px; box-shadow: 4px 0 16px rgba(0,0,0,.15); }
        .main { width: 100%; }
        .topbar { padding: 0 12px; height: 48px; }
        .topbar-right > span { display: none; }
        .content { padding: 12px; }
        .form-group input, .form-group select, .form-group textarea { font-size: 16px; }
    }
</style>

<?php renderAdminSidebar('gastos'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()"><?= icono('menu') ?></button>
            <h2><?= $esEdicion ? 'Editar Gasto' : 'Nuevo Gasto' ?></h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <div class="card">
            <div class="card-title"><?= $esEdicion ? 'Editar gasto' : 'Registrar nuevo gasto' ?></div>

            <?php if (!empty($errores)): ?>
            <div class="errores">
                <ul>
                    <?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php if ($esEdicion): ?>
                    <input type="hidden" name="gasto_id" value="<?= $editando['gasto_id'] ?? ($_POST['gasto_id'] ?? '') ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Sucursal</label>
                    <select name="sucursal_id" required>
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= $s['sucursal_id'] ?>" <?= $v['sucursal_id'] == $s['sucursal_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nombre']) ?><?= !$s['activo'] ? ' (inactiva)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Categoria</label>
                    <select name="categoria_gasto_id" required>
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?= $cat['categoria_gasto_id'] ?>" <?= $v['categoria_gasto_id'] == $cat['categoria_gasto_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['nombre']) ?><?= !$cat['activo'] ? ' (inactiva)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Descripcion</label>
                    <input type="text" name="descripcion" value="<?= htmlspecialchars($v['descripcion']) ?>" placeholder="Ej. Cambio de llanta camioneta de reparto" maxlength="500" required>
                </div>

                <div class="form-group">
                    <label>Monto</label>
                    <div class="monto-prefix">
                        <span>$</span>
                        <input type="number" name="monto" value="<?= htmlspecialchars($v['monto']) ?>" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Fecha</label>
                    <input type="date" name="fecha" value="<?= htmlspecialchars($v['fecha']) ?>" max="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label>Notas <span style="font-weight:400;text-transform:none;color:#bbb;">(opcional)</span></label>
                    <textarea name="notas" placeholder="Informacion adicional..."><?= htmlspecialchars($v['notas']) ?></textarea>
                </div>

                <div class="form-actions">
                    <a class="btn-cancelar" href="gastos.php">Cancelar</a>
                    <button class="btn-guardar" type="submit"><?= $esEdicion ? 'Guardar cambios' : 'Registrar gasto' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>
