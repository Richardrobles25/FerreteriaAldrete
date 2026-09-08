<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../includes/icons.php';
require_once '../config/database.php';
require_once '../includes/rh_helpers.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);
require_once '../includes/topbar_info.php';

$editando  = null;
$errores   = [];
$esEdicion = isset($_GET['id']);

if ($esEdicion) {
    $stmt = $pdo->prepare("SELECT * FROM vacaciones WHERE vacacion_id = ?");
    $stmt->execute([intval(is_scalar($_GET['id'] ?? null) ? $_GET['id'] : 0)]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
    // [FIX-VACACION-EDITAR-FANTASMA] (mismo patron ya corregido en admin/formGasto.php y
    // admin/formAsistencia.php) redirigir aqui SIEMPRE (sin importar el metodo) descartaba
    // en silencio un POST de edicion real si otra sesion borraba el registro entre que el
    // formulario se cargaba y se enviaba -- redirigia a la lista sin ningun aviso. En un
    // GET (enlace viejo o id invalido) si tiene sentido redirigir de inmediato.
    if (!$editando && $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: vacaciones.php'); exit(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerirCSRF($_POST['_token'] ?? '', 'formVacacion.php');
    if ($esEdicion && !$editando) {
        $errores[] = 'Este registro ya no existe (probablemente fue eliminado por otra sesión). No se guardaron los cambios.';
    }

    // [FIX-TIPO-ARRAY-ID] (mismo patron ya corregido en admin/gastos*.php,
    // admin/formEmpleado.php y admin/formAsistencia.php) un campo mandado como array
    // truena trim()/htmlspecialchars() con un TypeError sin capturar.
    $empleado_id  = intval(is_scalar($_POST['empleado_id'] ?? null) ? $_POST['empleado_id'] : 0);
    $fecha_inicio = trim(is_scalar($_POST['fecha_inicio'] ?? null) ? (string)$_POST['fecha_inicio'] : '');
    $fecha_fin    = trim(is_scalar($_POST['fecha_fin'] ?? null) ? (string)$_POST['fecha_fin'] : '');
    $estado       = trim(is_scalar($_POST['estado'] ?? null) ? (string)$_POST['estado'] : 'Solicitado');
    $notas        = trim(is_scalar($_POST['notas'] ?? null) ? (string)$_POST['notas'] : '');
    // [FIX-VACACION-ID-DESINCRONIZADO] (mismo patron ya corregido en admin/formGasto.php,
    // admin/formEmpleado.php y admin/formAsistencia.php) $vacacion_id salia del campo
    // oculto del POST, mientras $editando (usado para excluirse a si mismo del check de
    // saldo/traslape) sale del ?id= de la URL -- se ancla al de $editando para que el
    // UPDATE y las exclusiones de saldo/traslape siempre apunten al mismo registro.
    $vacacion_id  = ($esEdicion && $editando) ? intval($editando['vacacion_id']) : 0;
    $estadosVal   = ['Solicitado','Aprobado','Rechazado'];

    if (!$empleado_id)                                    $errores[] = 'Selecciona un empleado.';
    // [FIX-FECHA-CALENDARIO-INVALIDA] (mismo patron ya corregido en formEmpleado.php y
    // admin/formGasto.php/formAsistencia.php) "!strtotime($fecha)" no rechaza fechas de
    // calendario imposibles -- probado en vivo: fecha_inicio='2026-02-30' se guardo
    // localmente como '0000-00-00' para una vacacion real de un empleado real (Fernando
    // Perez), con dias_tomados calculado sobre esa fecha basura. Se exige formato Y-m-d
    // exacto y se valida con checkdate().
    $fechaInicioValida = $fecha_inicio && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha_inicio, $mFI) && checkdate((int)$mFI[2], (int)$mFI[3], (int)$mFI[1]);
    $fechaFinValida    = $fecha_fin    && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha_fin,    $mFF) && checkdate((int)$mFF[2], (int)$mFF[3], (int)$mFF[1]);
    if (!$fechaInicioValida)                              $errores[] = 'La fecha de inicio no es valida.';
    if (!$fechaFinValida)                                 $errores[] = 'La fecha de fin no es valida.';
    if ($fechaInicioValida && $fechaFinValida && $fecha_fin < $fecha_inicio)
                                                          $errores[] = 'La fecha de fin debe ser igual o posterior a la de inicio.';
    if (!in_array($estado, $estadosVal))                  $errores[] = 'Estado no valido.';

    // [FIX-CRIT-G-04] Antes "anio" llegaba crudo del POST y se usaba tal cual para
    // validar el saldo (WHERE empleado_id=? AND anio=?). Como el <select> del año se
    // arma en JavaScript, bastaba con mandar cualquier valor (otro año, o incluso 0)
    // para que la validacion de saldo mirara un cajon vacio y aceptara dias de mas —
    // se confirmo que asi se lograban 36 dias aprobados sobre las mismas fechas para
    // un empleado con derecho a 12, y un registro con anio=0 quedaba aprobado e
    // invisible/imborrable desde la interfaz. Ahora el año siempre se deriva en el
    // servidor a partir de fecha_inicio, nunca del valor que mande el cliente.
    $anio = ($fecha_inicio && strtotime($fecha_inicio)) ? (int)date('Y', strtotime($fecha_inicio)) : intval(date('Y'));

    // [FIX-VACACION-RACE] El saldo disponible y el traslape (mas abajo) son un check-then-
    // insert clasico sin ningun candado: dos solicitudes casi simultaneas para el mismo
    // empleado podian leer el mismo saldo/traslape "libre" antes de que cualquiera insertara,
    // y ambas pasar la validacion aunque juntas excedan el saldo real o se encimen entre si.
    // Mismo patron ya usado (GET_LOCK) para el telefono duplicado de clientes y el nombre
    // duplicado de empleados.
    $lockVacacion     = null;
    $lockVacAdquirido = true;
    if ($empleado_id > 0) {
        $lockVacacion     = 'vacacion_empleado_' . $empleado_id;
        $lockVacAdquirido = (bool) $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockVacacion) . ", 5)")->fetchColumn();
        if (!$lockVacAdquirido) {
            $errores[] = 'Otro usuario está registrando vacaciones para este empleado en este momento. Intenta de nuevo.';
        }
    }

    $diasTomados = 0;
    if ($fecha_inicio && $fecha_fin && !$errores) {
        $diasTomados = contarDiasVacacion($fecha_inicio, $fecha_fin);
        if ($diasTomados === 0) $errores[] = 'El periodo seleccionado solo abarca domingo (dia no laboral).';

        // Validate disponibles: saldo acumulado real (tope 12, no se resetea por año calendario)
        $stmtEmp = $pdo->prepare("SELECT fecha_ingreso FROM empleados WHERE empleado_id=?");
        $stmtEmp->execute([$empleado_id]);
        $empRow = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        if ($empRow) {
            if (!tieneDerechoVacaciones($empRow['fecha_ingreso'])) {
                $errores[] = 'Este empleado aun no tiene derecho a vacaciones.';
            } else {
                // Saldo justo antes de que arranque este periodo (no cuenta este mismo registro)
                $diaAnterior = (new DateTime($fecha_inicio))->modify('-1 day')->format('Y-m-d');
                $saldoDisp = calcSaldoVacaciones($pdo, $empleado_id, $empRow['fecha_ingreso'], $diaAnterior, $vacacion_id ?: null);
                if ($diasTomados > $saldoDisp) {
                    $errores[] = "Este empleado solo tiene $saldoDisp dias acumulados disponibles y no puede tomar $diasTomados.";
                }
            }
        }

        // [FIX-MEDIO-G-28] No habia ninguna validacion contra periodos de vacaciones
        // traslapados del mismo empleado: se podian aprobar dos solicitudes con fechas que se
        // encimaran, lo que ademas inflaba "dias de vacaciones esta semana" en
        // semanaLaboral.php mas alla de los dias reales de la semana (G-23) al sumar ambos
        // periodos. Se rechaza cualquier periodo (Solicitado o Aprobado, un Rechazado no
        // cuenta) que se traslape con otro ya existente del mismo empleado.
        // [FIX-VACACION-TRASLAPE-DOMINGO] El check de traslape comparaba rangos de fecha
        // "en bruto" (fecha_inicio <= fin_otro AND fecha_fin >= inicio_otro), sin excluir
        // domingo -- pero dias_tomados SI excluye domingo (contarDiasVacacion). Dos periodos
        // consecutivos que comparten unicamente la fecha de un domingo en su frontera (ej.
        // lunes-a-domingo seguido de domingo-a-sabado) se rechazaban como "traslapados" aunque
        // ningun dia habil real estuviera duplicado y la suma de ambos cupiera perfecto en el
        // saldo disponible. Probado en vivo: 6 dias habiles (lun-dom) + 6 dias habiles
        // (dom-sab) = 12 dias reales sin duplicar ninguno, y el segundo se rechazaba igual. Se
        // calcula la interseccion real de fechas y solo se cuenta como traslape autentico si
        // esa interseccion contiene al menos un dia habil (contarDiasVacacion > 0).
        if (empty($errores)) {
            $stmtSolape = $pdo->prepare("
                SELECT vacacion_id, fecha_inicio, fecha_fin FROM vacaciones
                WHERE empleado_id = ? AND estado != 'Rechazado' AND vacacion_id != ?
                  AND fecha_inicio <= ? AND fecha_fin >= ?
            ");
            $stmtSolape->execute([$empleado_id, $vacacion_id, $fecha_fin, $fecha_inicio]);
            foreach ($stmtSolape->fetchAll(PDO::FETCH_ASSOC) as $existente) {
                $desde = max($fecha_inicio, $existente['fecha_inicio']);
                $hasta = min($fecha_fin, $existente['fecha_fin']);
                if ($desde <= $hasta && contarDiasVacacion($desde, $hasta) > 0) {
                    $errores[] = 'Este empleado ya tiene otro periodo de vacaciones registrado que se traslapa con estas fechas.';
                    break;
                }
            }
        }
    }

    // [FIX-ALTO-G-12] Ver nota en formGasto.php: capturar PDOException para no filtrar
    // ruta/esquema del servidor en un HTTP 500 crudo.
    if (empty($errores)) {
        try {
            if ($vacacion_id) {
                $pdo->prepare("
                    UPDATE vacaciones SET empleado_id=?, fecha_inicio=?, fecha_fin=?, dias_tomados=?, anio=?, estado=?, notas=?
                    WHERE vacacion_id=?
                ")->execute([$empleado_id, $fecha_inicio, $fecha_fin, $diasTomados, $anio, $estado, $notas ?: null, $vacacion_id]);
                header('Location: vacaciones.php?msg=actualizado');
            } else {
                $pdo->prepare("
                    INSERT INTO vacaciones (empleado_id, fecha_inicio, fecha_fin, dias_tomados, anio, estado, notas)
                    VALUES (?,?,?,?,?,?,?)
                ")->execute([$empleado_id, $fecha_inicio, $fecha_fin, $diasTomados, $anio, $estado, $notas ?: null]);
                header('Location: vacaciones.php?msg=registrado');
            }
            if ($lockVacacion) $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockVacacion) . ")");
            exit();
        } catch (PDOException $e) {
            $errores[] = 'No se pudo guardar la vacación. Verifica que el empleado siga existiendo e intenta de nuevo.';
        }
    }
    if ($lockVacacion && $lockVacAdquirido) $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockVacacion) . ")");
}

$empleados = $pdo->query("
    SELECT e.empleado_id, e.nombre, e.fecha_ingreso
    FROM empleados e
    WHERE e.activo = 1
      AND e.fecha_ingreso <= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
    ORDER BY e.nombre
")->fetchAll(PDO::FETCH_ASSOC);

// [FIX-VACACION-EMPLEADO-INACTIVO] Si se edita un registro de un empleado que desde entonces
// se desactivo, el <select> de arriba (solo activo=1) no traía ninguna opcion para el, asi
// que se veia "-- Seleccionar --" sin nada marcado -- igual que si nadie estuviera elegido.
// Probado en vivo: al guardar esa edicion (por ejemplo solo para agregar una nota) sin fijarse
// en elegir de nuevo un empleado, el formulario aceptaba CUALQUIER empleado activo del
// dropdown y REASIGNABA en silencio esos dias de vacacion a una persona totalmente distinta,
// inflando su saldo de vacaciones tomadas sin que el ni el admin hicieran nada raro a
// proposito. Mismo patron ya usado en formAsistencia.php para un "tipo" retirado: si se esta
// editando y el empleado del registro ya no esta en la lista de activos, se agrega igual,
// marcado como inactivo para que sea obvio en el dropdown.
if ($esEdicion && $editando) {
    $yaEnLista = false;
    foreach ($empleados as $e) {
        if ($e['empleado_id'] == $editando['empleado_id']) { $yaEnLista = true; break; }
    }
    if (!$yaEnLista) {
        $stmtEmpInactivo = $pdo->prepare("SELECT empleado_id, nombre, fecha_ingreso FROM empleados WHERE empleado_id = ?");
        $stmtEmpInactivo->execute([$editando['empleado_id']]);
        $empInactivo = $stmtEmpInactivo->fetch(PDO::FETCH_ASSOC);
        if ($empInactivo) {
            $empInactivo['nombre'] .= ' (inactivo)';
            $empleados[] = $empInactivo;
        }
    }
}

// [FIX-TIPO-ARRAY-ID] Repoblar el formulario tras un error releyendo $_POST crudo tenia el
// mismo hueco ya corregido en admin/formGasto.php: si algun campo llegaba como array, el
// saneo de arriba no aplicaba aqui. Se usan las variables YA saneadas del manejador de
// POST (siempre escalares).
$esPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$v = [
    'empleado_id'  => $esPost ? $empleado_id  : ($editando['empleado_id']  ?? ''),
    'fecha_inicio' => $esPost ? $fecha_inicio : ($editando['fecha_inicio'] ?? ''),
    'fecha_fin'    => $esPost ? $fecha_fin    : ($editando['fecha_fin']    ?? ''),
    'anio'         => $esPost ? $anio         : ($editando['anio']         ?? date('Y')),
    'estado'       => $esPost ? $estado       : ($editando['estado']       ?? 'Solicitado'),
    'notas'        => $esPost ? $notas        : ($editando['notas']        ?? ''),
];
$estadosVal = ['Solicitado','Aprobado','Rechazado'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $esEdicion ? 'Editar' : 'Registrar' ?> Vacaciones — Ferreteria Aldrete</title>
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
    .card { background: white; border-radius: 10px; border: 0.5px solid #e8e8e8; padding: 28px; width: 100%; max-width: 480px; }
    .card-title { font-size: 16px; font-weight: 700; color: #222; margin-bottom: 22px; padding-bottom: 14px; border-bottom: 1px solid #f0f0f0; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; margin-bottom: 6px; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .form-group textarea { resize: vertical; min-height: 65px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .info-box { background: #eef8ff; border: 1px solid #cce5f7; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; font-size: 13px; color: #1a7db5; }
    .info-box span { font-weight: 700; }
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
        .form-row { grid-template-columns: 1fr; }
    }
</style>

<?php renderAdminSidebar('rrhh_form_vacacion'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()"><?= icono('menu') ?></button>
            <h2><?= $esEdicion ? 'Editar Vacaciones' : 'Registrar Vacaciones' ?></h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <div class="card">
            <div class="card-title"><?= $esEdicion ? 'Editar periodo de vacaciones' : 'Registrar periodo de vacaciones' ?></div>

            <div class="info-box" id="infoVac" style="display:none;"></div>

            <?php if (!empty($errores)): ?>
            <div class="errores"><ul><?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <form method="POST" id="mainForm">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php if ($esEdicion): ?>
                    <input type="hidden" name="vacacion_id" value="<?= $editando['vacacion_id'] ?? ($_POST['vacacion_id'] ?? '') ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label>Empleado</label>
                    <select name="empleado_id" id="selEmpleado" required onchange="actualizarInfo()">
                        <option value="">-- Seleccionar --</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?= $emp['empleado_id'] ?>"
                                    data-ingreso="<?= $emp['fecha_ingreso'] ?>"
                                    <?= $v['empleado_id'] == $emp['empleado_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Fecha inicio</label>
                        <input type="date" name="fecha_inicio" id="fechaInicio" value="<?= htmlspecialchars($v['fecha_inicio']) ?>" required onchange="actualizarMinFechaFin(); calcDias();" <?= !$esEdicion ? 'min="' . date('Y-m-d') . '"' : '' ?>>
                    </div>
                    <div class="form-group">
                        <label>Fecha fin</label>
                        <input type="date" name="fecha_fin" id="fechaFin" value="<?= htmlspecialchars($v['fecha_fin']) ?>" required onchange="calcDias()">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Año vacacional</label>
                        <select name="anio" id="selAnio" data-selected="<?= intval($v['anio']) ?>">
                            <option value="">-- Selecciona un empleado --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="estado">
                            <?php foreach ($estadosVal as $e): ?>
                                <option value="<?= $e ?>" <?= $v['estado'] === $e ? 'selected' : '' ?>><?= $e ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Notas <span style="font-weight:400;text-transform:none;color:#bbb;">(opcional)</span></label>
                    <textarea name="notas" placeholder="Informacion adicional..."><?= htmlspecialchars($v['notas']) ?></textarea>
                </div>

                <div class="form-actions">
                    <a class="btn-cancelar" href="vacaciones.php">Cancelar</a>
                    <button class="btn-guardar" type="submit"><?= $esEdicion ? 'Guardar cambios' : 'Registrar' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Empleados data from PHP
var empleadosData = <?php
    $empData = [];
    foreach ($empleados as $e) $empData[$e['empleado_id']] = $e['fecha_ingreso'];
    echo json_encode($empData);
?>;

// Estimado por antiguedad solamente (no conoce el historial de vacaciones ya usadas).
// El saldo real, acumulado con tope de 12, se valida en el servidor al guardar.
function estimarTopePorAntiguedad(fechaIngreso) {
    var ingreso = new Date(fechaIngreso);
    var hoy = new Date();
    var anios = hoy.getFullYear() - ingreso.getFullYear();
    var m = hoy.getMonth() - ingreso.getMonth();
    if (m < 0 || (m === 0 && hoy.getDate() < ingreso.getDate())) anios--;
    if (anios < 1) return 0;
    return Math.min(anios, 2) * 6;
}

function actualizarAnios(fechaIngreso) {
    var selAnio = document.getElementById('selAnio');
    var anioDeseado = parseInt(selAnio.dataset.selected) || new Date().getFullYear();

    var anioInicio = new Date(fechaIngreso).getFullYear() + 1; // primer año con derecho
    var anioActual = new Date().getFullYear();

    selAnio.innerHTML = '';
    for (var a = anioActual; a >= anioInicio; a--) {
        var opt = document.createElement('option');
        opt.value = a;
        opt.textContent = a;
        if (a === anioDeseado) opt.selected = true;
        selAnio.appendChild(opt);
    }
    // Si el valor deseado no estaba en el rango, selecciona el más reciente
    if (!selAnio.value) selAnio.options[0].selected = true;
}

function actualizarInfo() {
    var sel = document.getElementById('selEmpleado');
    var eid = sel.value;
    var infoBox = document.getElementById('infoVac');
    var selAnio = document.getElementById('selAnio');

    if (!eid || !empleadosData[eid]) {
        infoBox.style.display = 'none';
        selAnio.innerHTML = '<option value="">-- Selecciona un empleado --</option>';
        return;
    }

    actualizarAnios(empleadosData[eid]);

    var diasDisp = estimarTopePorAntiguedad(empleadosData[eid]);
    infoBox.innerHTML = 'Tope acumulable segun antiguedad: <span>' + diasDisp + '</span> <span style="font-weight:400;color:#999;">(el saldo real disponible se valida al guardar)</span>';
    infoBox.style.background = '#eef8ff';
    infoBox.style.borderColor = '#cce5f7';
    infoBox.style.color = '#1a7db5';
    infoBox.style.display = 'block';
    calcDias();
}

function calcDias() {
    var fi = document.getElementById('fechaInicio').value;
    var ff = document.getElementById('fechaFin').value;
    var infoBox = document.getElementById('infoVac');
    var sel = document.getElementById('selEmpleado');
    var eid = sel.value;
    if (!fi || !ff || !eid) return;
    var d1 = new Date(fi + 'T12:00:00'), d2 = new Date(ff + 'T12:00:00');
    if (d2 < d1) return;
    // Contar dias excluyendo domingos (no son dia laboral)
    var dias = 0;
    var dt = new Date(d1);
    while (dt <= d2) {
        if (dt.getDay() !== 0) dias++;
        dt.setDate(dt.getDate() + 1);
    }
    var diasDisp = estimarTopePorAntiguedad(empleadosData[eid] || '');
    if (diasDisp > 0 && infoBox.style.display !== 'none') {
        infoBox.innerHTML = 'Tope acumulable: <span>' + diasDisp + '</span> | Solicitud: <span>' + dias + ' dias</span> <span style="font-weight:400;color:#999;">(sin contar domingos; saldo real se valida al guardar)</span>';
    }
}

function actualizarMinFechaFin() {
    var fi = document.getElementById('fechaInicio').value;
    var ff = document.getElementById('fechaFin');
    if (fi) {
        ff.min = fi;
        if (ff.value && ff.value < fi) ff.value = fi;
    }
}

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

// Init
actualizarInfo();
actualizarMinFechaFin();
</script>
</body>
</html>
