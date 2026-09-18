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

if (isset($_GET['eliminar'])) {
    // [FIX-CRIT-A-01] Esta accion borra en cascada TODO el historial contable de la
    // sucursal (ventas, cajas, creditos, movimientos) y antes era un GET sin token —
    // alcanzable con un simple enlace/imagen en cualquier pagina que el Administrador
    // visitara con su sesion abierta. Ahora exige el mismo token CSRF que ya usa el
    // resto del sistema.
    requerirCSRF($_GET['_token'] ?? '', 'sucursales.php');
    // [FIX-TIPO-ARRAY-ID] (mismo patron ya corregido en admin/gastos*.php,
    // admin/empleados.php, etc.) intval() sobre un array no truena, se coacciona en
    // silencio a 1/0 -- sin este guard, "?eliminar[]=x" intentaria borrar siempre la
    // sucursal real sucursal_id=1 sin importar que id se haya mandado.
    $sid = intval(is_scalar($_GET['eliminar'] ?? null) ? $_GET['eliminar'] : 0);

    // [FEATURE-CERRAR-SUCURSAL] Si la sucursal ya está CERRADA (activo=0, ver toggle_activo
    // abajo), las guardas de usuarios/stock se omiten por completo -- cerrar es en sí mismo
    // el paso deliberado que confirma la intención de deshacerse de la sucursal (y ya exige su
    // propio candado: no se puede cerrar con una caja abierta). Exigir ADEMÁS reasignar
    // usuarios y vaciar stock a mano, sobre una sucursal que el propio admin ya marcó como
    // cerrada, no protege nada real -- solo vuelve inalcanzable el caso que de verdad importa:
    // una sucursal creada por error o de prueba. Si sigue ACTIVA, las guardas de siempre
    // aplican tal cual (ver más abajo).
    $stmtActivaChk = $pdo->prepare("SELECT activo FROM sucursales WHERE sucursal_id = ?");
    $stmtActivaChk->execute([$sid]);
    $sucursalActivaParaEliminar = $stmtActivaChk->fetchColumn();

    if ($sucursalActivaParaEliminar === false) {
        header('Location: sucursales.php?error=con_registros'); exit();
    }

    // [FEATURE-PROTECCION-ULTIMO-ADMIN] El sistema nunca debe poder quedar sin NINGUNA
    // sucursal, ni sin NINGUN usuario Administrador -- ambos casos son irrecuperables desde
    // la propia interfaz (no habria con que sesion volver a entrar). Estas dos guardas son
    // INCONDICIONALES: a diferencia de las guardas de usuarios/stock de abajo, NO se saltan
    // aunque la sucursal ya este cerrada -- cerrar no borra nada, pero eliminar si, y
    // "cerrada" no es garantia de que ya no tenga al unico admin que queda.
    $totalSucursales = intval($pdo->query("SELECT COUNT(*) FROM sucursales")->fetchColumn());
    if ($totalSucursales <= 1) {
        header('Location: sucursales.php?error=ultima_sucursal'); exit();
    }
    $stmtOtrosAdmins = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = 'Administrador' AND sucursal_id <> ?");
    $stmtOtrosAdmins->execute([$sid]);
    if (intval($stmtOtrosAdmins->fetchColumn()) === 0) {
        header('Location: sucursales.php?error=sin_admin_restante'); exit();
    }

    if (intval($sucursalActivaParaEliminar) === 1) {
        // [FIX-ALTO-A-05] Antes esta guarda solo contaba usuarios/stock ACTIVOS, pero el
        // DELETE de abajo borra TODAS las filas de esas tablas sin importar su estado —
        // un usuario desactivado o una fila de stock_sucursal ya inactiva (o en 0) pasaban
        // la guarda como si no existieran y se perdian en silencio junto con la sucursal.
        // Ahora las guardas cuentan TODO lo que el DELETE realmente va a tocar.
        // Bloquear si hay CUALQUIER usuario asignado (activo o no) -- desactivar NO alcanza
        // para pasar esta guarda (a proposito). El camino real es reasignar al usuario a
        // OTRA sucursal desde Usuarios, o cerrar esta sucursal primero (ver arriba).
        $stmtU = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE sucursal_id = ?");
        $stmtU->execute([$sid]);
        if ($stmtU->fetchColumn() > 0) {
            header('Location: sucursales.php?error=con_usuarios'); exit();
        }

        // [FEATURE-ELIMINAR-SUCURSAL-VIABLE] Antes bloqueaba con CUALQUIER fila en
        // stock_sucursal, incluso ya inactiva y en $0.00 de existencia real -- eso volvia la
        // eliminacion practicamente imposible para siempre en cuanto una sucursal tuviera un
        // solo producto dado de alta alguna vez. Lo que de verdad importa proteger es la
        // EXISTENCIA REAL en riesgo de perderse sin aviso, no la mera presencia de una fila --
        // se suma stock_actual de TODAS las filas (activas o no) y se bloquea solo si esa suma
        // es mayor a cero.
        $stmtChk = $pdo->prepare("SELECT COALESCE(SUM(stock_actual),0) FROM stock_sucursal WHERE sucursal_id = ?");
        $stmtChk->execute([$sid]);
        if ($stmtChk->fetchColumn() > 0) {
            header('Location: sucursales.php?error=con_stock'); exit();
        }
    }

    // [FIX-LOGO-HUERFANO] El logo subido para esta sucursal (ver formSucursal.php) vive en
    // uploads/logos/ como archivo suelto, fuera de cualquier tabla que el DELETE de abajo
    // toque -- sin esto, el archivo se queda huerfano en disco para siempre cada vez que se
    // elimina una sucursal que tenia logo. Se obtiene antes de borrar y se limpia solo si la
    // transaccion de verdad se confirma (para no perder el archivo si el DELETE fallara).
    $ticketLogoAEliminar = $pdo->prepare("SELECT ticket_logo FROM sucursales WHERE sucursal_id = ?");
    $ticketLogoAEliminar->execute([$sid]);
    $logoParaBorrar = $ticketLogoAEliminar->fetchColumn();

    try {
        $pdo->beginTransaction();
        // [FIX-CASCADA-SUCURSAL-COMPLETA] Encontrado probando el "Eliminar" de una sucursal
        // cerrada con datos reales de verdad (venta+credito+abono+devolucion+compra+
        // transferencia+movimientos), no solo con filas vacias: el orden de abajo se quedaba
        // corto contra el grafo REAL de llaves foraneas -- faltaban 3 tablas completas
        // (compra_productos, mora_cancelaciones, movimientos_mora, gastos, promociones) y
        // movimientos_caja se borraba DESPUES de devoluciones aunque la referencia
        // (fk_movcaja_dev) va en sentido contrario. Cualquiera de estos, con datos reales
        // (ej. una sola compra a proveedor, o un credito que ya cobro mora -- que Pinar y
        // Barrio de los Indios YA tienen), tronaba a medio camino con un error SQL crudo.
        // Verificado en vivo: reproducido el error real (compra_productos/compras_proveedor),
        // corregido, y vuelto a probar con la misma sucursal hasta borrar limpio.

        // compra_productos -> compras_proveedor (tiene que irse antes que su cabecera)
        $pdo->prepare("DELETE cp FROM compra_productos cp JOIN compras_proveedor cpv ON cp.compra_id = cpv.compras_proveedor_id WHERE cpv.sucursal_id = ?")->execute([$sid]);
        // mora_cancelaciones / movimientos_mora -> creditos (antes de borrar creditos; MUY
        // probable en la practica, cualquier credito Vencido ya tiene fila en movimientos_mora)
        $pdo->prepare("DELETE mc FROM mora_cancelaciones mc JOIN creditos cr ON mc.credito_id = cr.credito_id JOIN ventas v ON cr.venta_id = v.venta_id JOIN cajas c ON v.caja_id = c.caja_id WHERE c.sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE mm FROM movimientos_mora mm JOIN creditos cr ON mm.credito_id = cr.credito_id JOIN ventas v ON cr.venta_id = v.venta_id JOIN cajas c ON v.caja_id = c.caja_id WHERE c.sucursal_id = ?")->execute([$sid]);
        // abonos → creditos → ventas → cajas, PERO ademas abonos.caja_id es un FK DIRECTO a
        // cajas (los creditos son globales -- un abono se puede pagar en CUALQUIER sucursal,
        // no solo donde se origino el credito). Borrar solo por la ruta credito->venta->caja
        // se queda corto: un abono pagado AQUI para un credito abierto en OTRA sucursal
        // sobrevive con su caja_id apuntando a una caja que esta a punto de borrarse, y el
        // DELETE de `cajas` mas abajo truena con fk_abonos_caja. Reproducido en vivo (Pinar,
        // con abonos reales pagados ahi para creditos abiertos en Barrio de los Indios).
        $pdo->prepare("DELETE a FROM abonos a LEFT JOIN creditos cr ON a.credito_id = cr.credito_id LEFT JOIN ventas v ON cr.venta_id = v.venta_id LEFT JOIN cajas cv ON v.caja_id = cv.caja_id LEFT JOIN cajas ca ON a.caja_id = ca.caja_id WHERE cv.sucursal_id = ? OR ca.sucursal_id = ?")->execute([$sid, $sid]);
        $pdo->prepare("DELETE cr FROM creditos cr JOIN ventas v ON cr.venta_id = v.venta_id JOIN cajas c ON v.caja_id = c.caja_id WHERE c.sucursal_id = ?")->execute([$sid]);
        // movimientos_caja -> devoluciones (fk_movcaja_dev): tiene que irse ANTES que
        // devoluciones, no despues (orden viejo, incorrecto, invertido aqui).
        $pdo->prepare("DELETE FROM movimientos_caja WHERE sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE d FROM devoluciones d JOIN ventas v ON d.venta_id = v.venta_id JOIN cajas c ON v.caja_id = c.caja_id WHERE c.sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE vp FROM venta_productos vp JOIN ventas v ON vp.venta_id = v.venta_id JOIN cajas c ON v.caja_id = c.caja_id WHERE c.sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE v FROM ventas v JOIN cajas c ON v.caja_id = c.caja_id WHERE c.sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM cajas WHERE sucursal_id = ?")->execute([$sid]);
        // movimientos_inventario ahora tiene sucursal_id propio — se borran directamente
        $pdo->prepare("DELETE FROM movimientos_inventario WHERE sucursal_id = ?")->execute([$sid]);
        // compra_productos ya se borro arriba -- ahora si es seguro borrar la cabecera
        $pdo->prepare("DELETE FROM compras_proveedor WHERE sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM transferencias WHERE sucursal_origen_id = ? OR sucursal_destino_id = ?")->execute([$sid, $sid]);
        // gastos -> sucursales directo (nunca se borraba; una sola fila bloqueaba el DELETE
        // final de sucursales).
        $pdo->prepare("DELETE FROM gastos WHERE sucursal_id = ?")->execute([$sid]);
        // promociones -> sucursales directo (nunca se borraba). Solo las ESPECIFICAS de esta
        // sucursal -- las promociones globales tienen sucursal_id NULL y no las toca este
        // WHERE, correctamente siguen aplicando a las demas sucursales.
        $pdo->prepare("DELETE FROM promociones WHERE sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM stock_sucursal WHERE sucursal_id = ?")->execute([$sid]);
        // Usuarios inactivos se pueden borrar ya que sus movimientos fueron eliminados arriba
        $pdo->prepare("DELETE FROM usuarios WHERE sucursal_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM sucursales WHERE sucursal_id = ?")->execute([$sid]);
        $pdo->commit();
        if ($logoParaBorrar) {
            $logoPath = __DIR__ . '/../' . $logoParaBorrar;
            if (file_exists($logoPath)) @unlink($logoPath);
        }
        header('Location: sucursales.php?msg=eliminado'); exit();
    } catch (\PDOException $e) {
        $pdo->rollBack();
        // [FIX-CASCADA-SUCURSAL-COMPLETA] Antes de este fix, un usuario cuyo historial quedo
        // repartido entre DOS sucursales (ej. se reasigno de una a otra sin que sus ventas
        // viejas se movieran, lo cual nunca sucede solo -- solo pasaria si alguien reasigna
        // manualmente a un empleado) podia seguir bloqueando el DELETE de usuarios incluso con
        // todo lo demas resuelto. Es un caso extremo que no vale la pena intentar resolver
        // automaticamente (implicaria borrar o mover historial de OTRA sucursal que no se esta
        // cerrando) -- se deja como rollback seguro (nada se pierde) con un mensaje mas claro
        // que el error SQL crudo.
        header('Location: sucursales.php?error=con_registros&detail=' . urlencode($e->getMessage())); exit();
    }
}

// [FEATURE-CERRAR-SUCURSAL] Cerrar/reabrir una sucursal SIN borrar nada -- a diferencia de
// "eliminar" (arriba), esto solo cambia sucursales.activo. No exige mover usuarios ni vaciar
// stock: el punto es justo que sea la opcion ligera y reversible. Lo que SI cambia al cerrar:
// (a) deja de aparecer en el selector de sucursal al dar de alta/editar usuarios
// (formUsuario.php ya filtraba WHERE activo=1, sin necesidad de tocarlo), y (b) ya no se puede
// abrir una caja nueva ahi (ver admin/cajero_abrirCaja.php y cajeroInventario/abrirCaja.php) --
// bloqueando ese punto de entrada se bloquea de facto toda venta/movimiento nuevo, porque el
// sistema ya exige caja abierta para casi todo. Su historial (ventas, creditos, cortes,
// movimientos ya registrados) sigue consultandose normal desde el selector de sucursal del
// Administrador (ver _admin_sucursal_filtro.php).
if (isset($_GET['toggle_activo'])) {
    requerirCSRF($_GET['_token'] ?? '', 'sucursales.php');
    $sidToggle = intval(is_scalar($_GET['toggle_activo'] ?? null) ? $_GET['toggle_activo'] : 0);

    $stmtSuc = $pdo->prepare("SELECT activo FROM sucursales WHERE sucursal_id = ?");
    $stmtSuc->execute([$sidToggle]);
    $sucActual = $stmtSuc->fetch(PDO::FETCH_ASSOC);

    if (!$sucActual) {
        header('Location: sucursales.php?error=con_registros'); exit();
    }

    if (intval($sucActual['activo']) === 1) {
        // Va a CERRAR -- bloquear si hay una caja abierta ahora mismo (no cerrar a media
        // operacion, con dinero fisico ya contado dentro de un turno activo).
        $stmtCajaAbierta = $pdo->prepare("SELECT COUNT(*) FROM cajas WHERE sucursal_id = ? AND estado = 'Abierta'");
        $stmtCajaAbierta->execute([$sidToggle]);
        if ($stmtCajaAbierta->fetchColumn() > 0) {
            header('Location: sucursales.php?error=con_caja_abierta'); exit();
        }
        $pdo->prepare("UPDATE sucursales SET activo = 0 WHERE sucursal_id = ?")->execute([$sidToggle]);
        header('Location: sucursales.php?msg=cerrada'); exit();
    } else {
        $pdo->prepare("UPDATE sucursales SET activo = 1 WHERE sucursal_id = ?")->execute([$sidToggle]);
        header('Location: sucursales.php?msg=reabierta'); exit();
    }
}

// [FIX-ALTO-A-05] total_usuarios/con_stock siguen contando solo lo ACTIVO — son la
// estadistica que se muestra en la tarjeta ("N usuarios", "N productos en stock") y esa
// lectura de negocio no cambia. Para decidir si el boton "Eliminar" debe estar
// habilitado se usan columnas que reflejan EXACTAMENTE lo que la guarda del servidor va a
// revisar, para que el boton nunca prometa algo que luego se rechaza.
// [FEATURE-ELIMINAR-SUCURSAL-VIABLE] stock_valor_riesgo reemplaza el conteo de filas de
// stock_sucursal (ver comentario junto a la guarda de arriba) por la existencia real en
// riesgo (activa o no) -- una sucursal con productos ya en $0 de existencia SI se puede
// eliminar, aunque conserve filas de stock_sucursal inactivas.
$stmt = $pdo->query("
    SELECT s.*,
        (SELECT COUNT(*) FROM usuarios u WHERE u.sucursal_id = s.sucursal_id AND u.activo = 1) AS total_usuarios,
        (SELECT COUNT(*) FROM stock_sucursal ss
         JOIN productos p ON p.producto_id = ss.producto_id AND p.activo = 1
         WHERE ss.sucursal_id = s.sucursal_id AND ss.activo = 1 AND ss.stock_actual > 0) AS con_stock,
        (SELECT COUNT(*) FROM usuarios u2 WHERE u2.sucursal_id = s.sucursal_id) AS usuarios_totales_incl_inactivos,
        (SELECT COALESCE(SUM(ss2.stock_actual),0) FROM stock_sucursal ss2 WHERE ss2.sucursal_id = s.sucursal_id) AS stock_valor_riesgo
    FROM sucursales s
    ORDER BY s.nombre ASC
");
$sucursales = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sucursales — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; }
    .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .content-header h1 { font-size: 20px; color: #222; font-weight: 600; }
    .btn-nuevo { background: #14ace7; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-nuevo:hover { background: #1196cb; }
    .sucursales-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px,1fr)); gap: 16px; }
    .suc-card { background: white; border-radius: 10px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .suc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
    .suc-nombre { font-size: 16px; font-weight: 700; color: #222; margin: 0 0 4px; }
    .suc-rfc { font-size: 12px; color: #aaa; font-family: monospace; }
    .suc-info { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
    .suc-dato { font-size: 12px; color: #555; }
    .suc-dato span { display: block; font-size: 10px; color: #aaa; text-transform: uppercase; margin-bottom: 2px; }
    .suc-stats { display: flex; gap: 14px; padding: 12px 0; border-top: 0.5px solid #f5f5f5; border-bottom: 0.5px solid #f5f5f5; margin-bottom: 14px; }
    .suc-stat { text-align: center; flex: 1; }
    .suc-stat strong { font-size: 18px; font-weight: 700; color: #222; display: block; }
    .suc-stat span { font-size: 11px; color: #aaa; }
    .suc-acciones { display: flex; gap: 8px; }
    .btn-accion { padding: 7px 14px; border-radius: 6px; font-size: 13px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #e3f2fd; color: #1565c0; flex: 1; text-align: center; }
    .btn-editar:hover { background: #bbdefb; }
    .btn-eliminar { background: #fdecea; color: #c0392b; }
    .btn-eliminar:hover { background: #ffcdd2; }
    .btn-eliminar-disabled { background: #f5f5f5; color: #bbb; cursor: not-allowed; }
    .btn-cerrar { background: #fff3e0; color: #e65100; }
    .btn-cerrar:hover { background: #ffe0b2; }
    .btn-reabrir { background: #e8f5e9; color: #2e7d32; }
    .btn-reabrir:hover { background: #c8e6c9; }
    .badge-cerrada { display: inline-block; background: #ffe0b2; color: #e65100; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; padding: 2px 7px; border-radius: 99px; vertical-align: middle; }
    .ticket-preview { background: #f9f9f9; border-radius: 6px; padding: 10px 12px; font-size: 12px; color: #666; margin-bottom: 14px; font-family: monospace; line-height: 1.6; max-height: 80px; overflow: hidden; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; }
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

<?php renderAdminSidebar('sucursales'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()"><?= icono('menu') ?></button>
            <h2>Sucursales</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <div class="content-header">
            <h1>Sucursales</h1>
            <a class="btn-nuevo" href="formSucursal.php">+ Nueva sucursal</a>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'error_token'): ?>
            <div style="background:#fdecea;color:#c0392b;padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:16px;border-left:3px solid #c0392b;">
                La sesión expiró o el enlace no es válido. Recarga la página e intenta de nuevo.
            </div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'error_no_existe'): ?>
            <div style="background:#fdecea;color:#c0392b;padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:16px;border-left:3px solid #c0392b;">
                No se guardaron los cambios: esa sucursal ya no existe (pudo haberse eliminado desde otra sesión).
            </div>
        <?php elseif (isset($_GET['msg'])): ?>
            <?php $msgs = ['creado' => 'Sucursal creada.', 'editado' => 'Sucursal actualizada.', 'eliminado' => 'Sucursal eliminada correctamente.', 'cerrada' => 'Sucursal cerrada. Su historial sigue disponible para consulta; no se puede operar (abrir caja, vender) ni asignarle usuarios nuevos hasta que se reabra.', 'reabierta' => 'Sucursal reabierta — ya puede operar con normalidad.']; ?>
            <?php $msgKeySuc = is_scalar($_GET['msg'] ?? null) ? $_GET['msg'] : ''; ?>
            <div style="background:#e8f5e9;color:#2e7d32;padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:16px;border-left:3px solid #2e7d32;">
                <?= htmlspecialchars($msgs[$msgKeySuc] ?? '') ?>
            </div>
        <?php endif; ?>
        <?php
            $errMsgs = [
                'con_stock'       => 'No se puede eliminar la sucursal porque todavía tiene productos con existencia (stock mayor a $0). Registra una Salida para dejarlo en 0, o transfiérelo a otra sucursal, antes de eliminar.',
                'con_usuarios'    => 'No se puede eliminar la sucursal porque tiene usuarios asignados (desactivarlos no alcanza). Reasígnalos a otra sucursal desde Usuarios → Editar antes de eliminar.',
                'con_registros'   => 'Ocurrió un error al eliminar la sucursal. Intenta de nuevo.',
                'con_caja_abierta'=> 'No se puede cerrar la sucursal: hay una caja abierta ahí ahora mismo. Espera a que se cierre el turno primero.',
                'ultima_sucursal' => 'No se puede eliminar: es la única sucursal que queda. El sistema siempre necesita al menos una.',
                'sin_admin_restante' => 'No se puede eliminar: se quedarían sin ningún Administrador en el sistema. Crea o reasigna un Administrador en otra sucursal antes de eliminar esta.',
            ];
        ?>
        <?php $errKeySuc = is_scalar($_GET['error'] ?? null) ? $_GET['error'] : ''; ?>
        <?php if (isset($errMsgs[$errKeySuc])): ?>
            <div style="background:#fdecea;color:#c0392b;padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:16px;border-left:3px solid #c0392b;">
                <?= $errMsgs[$errKeySuc] ?>
                <?php if (isset($_GET['detail']) && is_scalar($_GET['detail'])): ?>
                    <div style="margin-top:6px;font-size:11px;opacity:.8;font-family:monospace;"><?= htmlspecialchars($_GET['detail']) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (count($sucursales) > 0): ?>
        <div class="sucursales-grid">
            <?php foreach ($sucursales as $s): ?>
            <div class="suc-card">
                <div class="suc-header">
                    <div>
                        <div class="suc-nombre"><?= htmlspecialchars($s['nombre']) ?><?php if (intval($s['activo']) === 0): ?> <span class="badge-cerrada">Cerrada</span><?php endif; ?></div>
                        <?php if ($s['rfc']): ?><div class="suc-rfc"><?= htmlspecialchars($s['rfc']) ?></div><?php endif; ?>
                    </div>
                </div>

                <div class="suc-info">
                    <div class="suc-dato"><span>Dirección</span><?= htmlspecialchars($s['direccion']??'—') ?></div>
                    <div class="suc-dato"><span>Teléfono</span><?= htmlspecialchars($s['telefono']??'—') ?></div>
                </div>

                <?php if ($s['datos_ticket']): ?>
                <div class="ticket-preview"><?= htmlspecialchars($s['datos_ticket']) ?></div>
                <?php endif; ?>

                <div class="suc-stats">
                    <div class="suc-stat"><strong><?= $s['total_usuarios'] ?></strong><span>Usuarios</span></div>
                    <div class="suc-stat"><strong><?= intval($s['con_stock']) ?></strong><span>Productos en stock</span></div>
                </div>

                <div class="suc-acciones">
                    <a class="btn-accion btn-editar" href="formSucursal.php?id=<?= $s['sucursal_id'] ?>">Editar datos</a>
                    <?php if (intval($s['activo']) === 1): ?>
                        <a class="btn-accion btn-cerrar"
                           href="sucursales.php?toggle_activo=<?= $s['sucursal_id'] ?>&_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                           onclick="return confirm('¿Cerrar la sucursal <?= htmlspecialchars($s['nombre'], ENT_QUOTES) ?>? No se borra nada — su historial sigue disponible para consulta, pero no se podrán abrir cajas nuevas, vender, ni asignarle usuarios ahí hasta que la reabras.')">Cerrar</a>
                    <?php else: ?>
                        <a class="btn-accion btn-reabrir"
                           href="sucursales.php?toggle_activo=<?= $s['sucursal_id'] ?>&_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                           onclick="return confirm('¿Reabrir la sucursal <?= htmlspecialchars($s['nombre'], ENT_QUOTES) ?>? Volverá a operar con normalidad.')">Reabrir</a>
                    <?php endif; ?>
                    <?php if (intval($s['activo']) === 0): ?>
                        <?php
                            // [FEATURE-ELIMINAR-SUCURSAL-CERRADA] Una sucursal CERRADA se puede
                            // eliminar siempre, sin importar usuarios/stock -- cerrar ya fue el
                            // paso deliberado. El warning avisa explícitamente cuánto se va a
                            // llevar entre manos, para que el único candado que queda (el
                            // confirm de abajo) siga siendo informativo y no una formalidad vacía.
                            $advertenciaExtra = '';
                            if (intval($s['usuarios_totales_incl_inactivos']) > 0) {
                                $advertenciaExtra .= ' Tiene ' . intval($s['usuarios_totales_incl_inactivos']) . ' usuario(s) asignado(s) que también se eliminarán.';
                            }
                            if (floatval($s['stock_valor_riesgo']) > 0) {
                                $advertenciaExtra .= ' Todavía tiene existencia real de productos en stock.';
                            }
                        ?>
                        <a class="btn-accion btn-eliminar"
                           href="sucursales.php?eliminar=<?= $s['sucursal_id'] ?>&_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                           onclick="return confirm('¿Eliminar la sucursal <?= htmlspecialchars($s['nombre'], ENT_QUOTES) ?>?<?= htmlspecialchars($advertenciaExtra, ENT_QUOTES) ?> Se eliminarán todos sus datos (ventas, créditos, cortes, movimientos). Esta acción no se puede deshacer.')">Eliminar</a>
                    <?php elseif (intval($s['usuarios_totales_incl_inactivos']) > 0): ?>
                        <span class="btn-accion btn-eliminar-disabled" title="No se puede eliminar: tiene usuarios asignados (incluidos inactivos) — reasígnalos a otra sucursal desde Usuarios, o cierra la sucursal primero para poder eliminarla directo">Eliminar</span>
                    <?php elseif (floatval($s['stock_valor_riesgo']) > 0): ?>
                        <span class="btn-accion btn-eliminar-disabled" title="No se puede eliminar: todavía tiene productos con existencia (stock > 0) — déjalo en 0 con una Salida, transfiérelo, o cierra la sucursal primero para poder eliminarla directo">Eliminar</span>
                    <?php else: ?>
                        <a class="btn-accion btn-eliminar"
                           href="sucursales.php?eliminar=<?= $s['sucursal_id'] ?>&_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                           onclick="return confirm('¿Eliminar la sucursal <?= htmlspecialchars($s['nombre'], ENT_QUOTES) ?>? Se eliminaran todos sus datos. Esta accion no se puede deshacer.')">Eliminar</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="sin-resultados">No hay sucursales registradas.</div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>


