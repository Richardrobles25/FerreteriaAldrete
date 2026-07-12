<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);

$editando  = null;
$errores   = [];
$esEdicion = isset($_GET['id']);
$tfExistentes = [];

if ($esEdicion) {
    $stmt = $pdo->prepare("SELECT * FROM asistencia WHERE asistencia_id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editando) { header('Location: asistencia.php'); exit(); }

    $tfStmt = $pdo->prepare("SELECT * FROM asistencia_tiempos_fuera WHERE asistencia_id = ? ORDER BY hora_salida");
    $tfStmt->execute([$editando['asistencia_id']]);
    $tfExistentes = $tfStmt->fetchAll(PDO::FETCH_ASSOC);
}

function timeToMinutes(string $t): int {
    if (!$t) return 0;
    [$h, $m] = explode(':', $t);
    return intval($h) * 60 + intval($m);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerirCSRF($_POST['_token'] ?? '', 'formAsistencia.php');

    $empleado_id   = intval($_POST['empleado_id']  ?? 0);
    $fecha         = trim($_POST['fecha']          ?? '');
    $tipo          = trim($_POST['tipo']           ?? '');
    $hora_entrada  = trim($_POST['hora_entrada']   ?? '');
    $hora_salida   = trim($_POST['hora_salida']    ?? '');
    $razon         = trim($_POST['razon']          ?? '');
    $resolucion    = trim($_POST['resolucion']     ?? 'Pendiente');
    $notas         = trim($_POST['notas']          ?? '');
    $asistencia_id = intval($_POST['asistencia_id'] ?? 0);

    $tfSalidas   = $_POST['tf_salida']  ?? [];
    $tfRegresos  = $_POST['tf_regreso'] ?? [];

    // Tardanza y Salida temprana ya no se ofrecen (las cubre Tiempo fuera),
    // pero siguen siendo validas para poder editar registros antiguos
    $tiposValidos     = ['Asistencia normal','Tardanza','Falta','Salida temprana','Tiempo fuera','Horas extra'];
    $resolucionesVal  = ['Pendiente','Deducido','Compensado','Justificado','Pagado integro'];

    if (!$empleado_id)                          $errores[] = 'Selecciona un empleado.';
    if (!$fecha || !strtotime($fecha))          $errores[] = 'La fecha no es valida.';

    // Un solo registro por empleado por fecha (al editar se excluye el registro actual)
    if ($empleado_id && $fecha) {
        $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM asistencia WHERE empleado_id = ? AND fecha = ? AND asistencia_id != ?");
        $stmtDup->execute([$empleado_id, $fecha, $asistencia_id]);
        if ($stmtDup->fetchColumn() > 0) {
            $errores[] = 'Este empleado ya tiene un registro en esa fecha. Edita el registro existente en lugar de crear otro.';
        }
    }
    if (!in_array($tipo, $tiposValidos))        $errores[] = 'El tipo no es valido.';
    if (!in_array($resolucion, $resolucionesVal)) $errores[] = 'La resolucion no es valida.';

    if ($tipo !== 'Falta' && $tipo !== 'Asistencia normal') {
        if (!$hora_entrada) $errores[] = 'La hora de entrada es obligatoria.';
        if (!$hora_salida)  $errores[] = 'La hora de salida es obligatoria.';
        if ($hora_entrada && $hora_salida && timeToMinutes($hora_salida) <= timeToMinutes($hora_entrada))
            $errores[] = 'La hora de salida debe ser mayor a la de entrada.';
    }

    // Validate time intervals
    $intervalosLimpios = [];
    foreach ($tfSalidas as $i => $tfs) {
        $tfr = $tfRegresos[$i] ?? '';
        if ($tfs && $tfr) {
            if (timeToMinutes($tfr) <= timeToMinutes($tfs))
                $errores[] = 'En tiempo fuera fila ' . ($i + 1) . ': hora de regreso debe ser mayor a la de salida.';
            $intervalosLimpios[] = ['salida' => $tfs, 'regreso' => $tfr];
        }
    }

    if (empty($errores)) {
        // Calculate hours
        $diaSemana      = intval(date('N', strtotime($fecha))); // 1=Mon, 6=Sat
        $horasEsperadas = ($diaSemana === 6) ? 6.0 : 9.0;

        if ($tipo === 'Falta') {
            $horasNoTrabajadas = $horasEsperadas;
            $horasExtra        = 0.0;
        } elseif ($tipo === 'Asistencia normal' && !$hora_entrada && !$hora_salida) {
            // Dia completo sin especificar horario — se asume que trabajo sus horas completas
            $horasNoTrabajadas = 0.0;
            $horasExtra        = 0.0;
        } else {
            $minEntrada    = timeToMinutes($hora_entrada);
            $minSalida     = timeToMinutes($hora_salida);
            $minTrabajados = $minSalida - $minEntrada;

            // Restar intervalos fuera para todos los tipos (turnos partidos, descansos, etc.)
            $minFuera = 0;
            foreach ($intervalosLimpios as $intv) {
                $minFuera += timeToMinutes($intv['regreso']) - timeToMinutes($intv['salida']);
            }
            $minTrabajados -= $minFuera;

            $horasTrabajadas   = $minTrabajados / 60.0;
            $horasNoTrabajadas = round(max(0.0, $horasEsperadas - $horasTrabajadas), 2);
            $horasExtra        = round(max(0.0, $horasTrabajadas - $horasEsperadas), 2);
        }

        $horaEntradaDB = ($tipo !== 'Falta' && $hora_entrada) ? $hora_entrada : null;
        $horaSalidaDB  = ($tipo !== 'Falta' && $hora_salida)  ? $hora_salida  : null;

        if ($asistencia_id) {
            $pdo->prepare("
                UPDATE asistencia
                SET empleado_id=?, fecha=?, tipo=?, hora_entrada=?, hora_salida=?,
                    horas_no_trabajadas=?, horas_extra=?, razon=?, resolucion=?, notas=?
                WHERE asistencia_id=?
            ")->execute([
                $empleado_id, $fecha, $tipo, $horaEntradaDB, $horaSalidaDB,
                $horasNoTrabajadas, $horasExtra,
                $razon ?: null, $resolucion, $notas ?: null,
                $asistencia_id
            ]);
            $pdo->prepare("DELETE FROM asistencia_tiempos_fuera WHERE asistencia_id=?")->execute([$asistencia_id]);
        } else {
            $pdo->prepare("
                INSERT INTO asistencia (empleado_id, fecha, tipo, hora_entrada, hora_salida,
                    horas_no_trabajadas, horas_extra, razon, resolucion, notas)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $empleado_id, $fecha, $tipo, $horaEntradaDB, $horaSalidaDB,
                $horasNoTrabajadas, $horasExtra,
                $razon ?: null, $resolucion, $notas ?: null
            ]);
            $asistencia_id = $pdo->lastInsertId();
        }

        // Insert time intervals
        if ($intervalosLimpios) {
            $stmtTF = $pdo->prepare("INSERT INTO asistencia_tiempos_fuera (asistencia_id, hora_salida, hora_regreso) VALUES (?,?,?)");
            foreach ($intervalosLimpios as $intv) {
                $stmtTF->execute([$asistencia_id, $intv['salida'], $intv['regreso']]);
            }
        }

        header('Location: asistencia.php?msg=registrado');
        exit();
    }
}

$empleados   = $pdo->query("SELECT empleado_id, nombre FROM empleados WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$tipos       = ['Asistencia normal','Falta','Tiempo fuera','Horas extra'];
// Si se edita un registro antiguo con un tipo retirado, mantenerlo en el combo
if ($editando && !in_array($editando['tipo'], $tipos)) $tipos[] = $editando['tipo'];
$resoluciones = ['Pendiente','Deducido','Compensado','Justificado','Pagado integro'];

$v = [
    'empleado_id'  => $_POST['empleado_id']  ?? $editando['empleado_id']  ?? '',
    'fecha'        => $_POST['fecha']        ?? $editando['fecha']        ?? date('Y-m-d'),
    'tipo'         => $_POST['tipo']         ?? $editando['tipo']         ?? 'Asistencia normal',
    'hora_entrada' => $_POST['hora_entrada'] ?? ($editando && $editando['hora_entrada'] ? substr($editando['hora_entrada'], 0, 5) : ''),
    'hora_salida'  => $_POST['hora_salida']  ?? ($editando && $editando['hora_salida']  ? substr($editando['hora_salida'],  0, 5) : ''),
    'razon'        => $_POST['razon']        ?? $editando['razon']        ?? '',
    'resolucion'   => $_POST['resolucion']   ?? $editando['resolucion']   ?? 'Pendiente',
    'notas'        => $_POST['notas']        ?? $editando['notas']        ?? '',
];

// Rebuild intervals from POST on error, or from DB on edit
$intervalosForm = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tfS = $_POST['tf_salida']  ?? [];
    $tfR = $_POST['tf_regreso'] ?? [];
    foreach ($tfS as $i => $s) {
        $r = $tfR[$i] ?? '';
        if ($s || $r) $intervalosForm[] = ['salida' => $s, 'regreso' => $r];
    }
} elseif ($tfExistentes) {
    foreach ($tfExistentes as $tf) {
        $intervalosForm[] = [
            'salida'  => substr($tf['hora_salida'],  0, 5),
            'regreso' => substr($tf['hora_regreso'], 0, 5),
        ];
    }
}
if (!$intervalosForm) $intervalosForm[] = ['salida' => '', 'regreso' => ''];

// Vacaciones aprobadas (para aviso en frontend al seleccionar empleado + fecha)
$vacAprobadas = $pdo->query("
    SELECT empleado_id, fecha_inicio, fecha_fin FROM vacaciones WHERE estado = 'Aprobado'
")->fetchAll(PDO::FETCH_ASSOC);

// Fechas que ya tienen registro por empleado (para bloquearlas en el formulario)
$fechasOcupadas = [];
foreach ($pdo->query("SELECT empleado_id, fecha, asistencia_id FROM asistencia")->fetchAll(PDO::FETCH_ASSOC) as $fo) {
    $fechasOcupadas[$fo['empleado_id']][$fo['fecha']] = intval($fo['asistencia_id']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $esEdicion ? 'Editar' : 'Nuevo' ?> Registro — Ferreteria Aldrete</title>
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
    .toggle-btn { background: none; border: none; color: white; cursor: pointer; font-size: 20px; padding: 4px 8px; border-radius: 4px; }
    .toggle-btn:hover { background: rgba(255,255,255,0.2); }
    .topbar-right { display: flex; align-items: center; gap: 14px; font-size: 13px; }
    .logout-btn { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); color: white; padding: 5px 14px; border-radius: 5px; cursor: pointer; font-size: 12px; }
    .content { flex: 1; padding: 24px; overflow-y: auto; display: flex; justify-content: center; align-items: flex-start; }
    .card { background: white; border-radius: 10px; border: 0.5px solid #e8e8e8; padding: 28px; width: 100%; max-width: 580px; }
    .card-title { font-size: 16px; font-weight: 700; color: #222; margin-bottom: 22px; padding-bottom: 14px; border-bottom: 1px solid #f0f0f0; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; margin-bottom: 6px; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .form-group textarea { resize: vertical; min-height: 65px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .section-label { font-size: 11px; color: #aaa; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 20px 0 10px; border-top: 1px solid #f0f0f0; padding-top: 14px; }
    .tf-row { display: flex; gap: 10px; align-items: center; margin-bottom: 8px; }
    .tf-row input { flex: 1; padding: 10px 12px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; font-family: Arial, sans-serif; }
    .tf-row input:focus { outline: none; border-color: #14ace7; }
    .tf-sep { font-size: 12px; color: #bbb; white-space: nowrap; }
    .btn-rm-tf { background: #fff0f0; border: 1px solid #fdd; color: #c0392b; width: 28px; height: 28px; border-radius: 6px; cursor: pointer; font-size: 16px; line-height: 1; flex-shrink: 0; }
    .btn-add-tf { background: #eef8ff; border: 1px solid #cce5f7; color: #14ace7; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; margin-top: 4px; }
    .calc-box { background: #f7f7f7; border-radius: 8px; padding: 14px; margin-bottom: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .calc-item p { font-size: 10px; color: #aaa; text-transform: uppercase; }
    .calc-item span { font-size: 15px; font-weight: 700; color: #222; }
    .calc-item.rojo span { color: #c0392b; }
    .calc-item.verde span { color: #27ae60; }
    .errores { background: #fff0f0; border: 1px solid #fdd; border-radius: 7px; padding: 12px 14px; margin-bottom: 16px; }
    .errores ul { padding-left: 18px; }
    .errores li { font-size: 13px; color: #c0392b; margin-bottom: 4px; }
    .form-actions { display: flex; gap: 10px; margin-top: 24px; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 11px 24px; border-radius: 7px; cursor: pointer; font-size: 14px; font-weight: 600; flex: 1; }
    .btn-guardar:hover { background: #119dd4; }
    .btn-cancelar { background: white; color: #666; border: 1px solid #ddd; padding: 11px 20px; border-radius: 7px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; text-align: center; }
    .btn-cancelar:hover { background: #f5f5f5; }
    .hidden { display: none !important; }
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

<?php renderAdminSidebar('rrhh_form_asistencia'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2><?= $esEdicion ? 'Editar Registro' : 'Registrar Incidente' ?></h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <div class="card">
            <div class="card-title"><?= $esEdicion ? 'Editar registro de asistencia' : 'Nuevo registro de asistencia' ?></div>

            <?php if (!empty($errores)): ?>
            <div class="errores"><ul><?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <form method="POST" id="mainForm">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php if ($esEdicion): ?>
                    <input type="hidden" name="asistencia_id" value="<?= $editando['asistencia_id'] ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label>Empleado</label>
                        <select name="empleado_id" id="selEmpleado" required onchange="verificarVacacion(); verificarFechaOcupada();">
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($empleados as $emp): ?>
                                <option value="<?= $emp['empleado_id'] ?>" <?= $v['empleado_id'] == $emp['empleado_id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fecha</label>
                        <input type="date" name="fecha" value="<?= htmlspecialchars($v['fecha']) ?>" id="inputFecha" required>
                    </div>
                </div>

                <div id="avisoVacacion" style="display:none;background:#fff9e6;border:1px solid #f0b429;border-radius:7px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#b7860b;">
                    <strong>Atenci&oacute;n:</strong> este empleado tiene vacaciones aprobadas para esta fecha. Verifica que corresponda registrar un incidente.
                </div>

                <div id="avisoFechaOcupada" style="display:none;background:#fff0f0;border:1px solid #fdd;border-radius:7px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#c0392b;">
                    <strong>Fecha no disponible:</strong> este empleado ya tiene un registro en ese dia. Editalo desde la bitacora en lugar de crear otro.
                </div>

                <div class="form-group">
                    <label>Tipo de incidente</label>
                    <select name="tipo" id="selectTipo" required onchange="actualizarVista()">
                        <?php foreach ($tipos as $t): ?>
                            <option value="<?= $t ?>" <?= $v['tipo'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Cuadro informativo contextual (se actualiza según el tipo) -->
                <div id="infoContextual" style="display:none;border-radius:7px;padding:10px 14px;margin-bottom:14px;font-size:13px;"></div>

                <!-- Seccion tiempos -->
                <div id="seccionTiempos">
                    <div class="form-row">
                        <div class="form-group">
                            <label id="lblEntrada">Hora de entrada</label>
                            <input type="time" name="hora_entrada" id="horaEntrada" value="<?= htmlspecialchars($v['hora_entrada']) ?>" onchange="calcular()">
                        </div>
                        <div class="form-group">
                            <label id="lblSalida">Hora de salida</label>
                            <input type="time" name="hora_salida" id="horaSalida" value="<?= htmlspecialchars($v['hora_salida']) ?>" onchange="calcular()">
                        </div>
                    </div>

                    <!-- Intervalos de tiempo fuera (solo visible para tipo "Tiempo fuera") -->
                    <div id="seccionTF">
                        <div class="section-label">Intervalos fuera <span style="font-weight:400;text-transform:none;color:#bbb;">— registra cada salida y regreso</span></div>
                        <div id="tfContainer">
                            <?php foreach ($intervalosForm as $idx => $intv): ?>
                            <div class="tf-row">
                                <input type="time" name="tf_salida[]" value="<?= htmlspecialchars($intv['salida']) ?>" placeholder="Salida" onchange="calcular()">
                                <span class="tf-sep">hasta</span>
                                <input type="time" name="tf_regreso[]" value="<?= htmlspecialchars($intv['regreso']) ?>" placeholder="Regreso" onchange="calcular()">
                                <button type="button" class="btn-rm-tf" onclick="quitarTF(this)" title="Quitar">&#215;</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn-add-tf" onclick="agregarTF()">+ Agregar intervalo</button>
                    </div>
                </div>

                <!-- Calculo en tiempo real -->
                <div class="section-label">Calculo automatico</div>
                <div class="calc-box">
                    <div class="calc-item">
                        <p>Horas esperadas</p>
                        <span id="calcEsperadas">—</span>
                    </div>
                    <div class="calc-item">
                        <p>Horas trabajadas</p>
                        <span id="calcTrabajadas">—</span>
                    </div>
                    <div class="calc-item rojo">
                        <p>Horas no trabajadas</p>
                        <span id="calcNoTrabajadas">—</span>
                    </div>
                    <div class="calc-item verde">
                        <p>Horas extra</p>
                        <span id="calcExtra">—</span>
                    </div>
                </div>

                <div class="form-group" id="grupoRazon">
                    <label id="lblRazon">Razon</label>
                    <input type="text" name="razon" id="inputRazon" value="<?= htmlspecialchars($v['razon']) ?>" placeholder="" maxlength="500">
                </div>

                <div class="form-group">
                    <label>Notas <span style="font-weight:400;text-transform:none;color:#bbb;">(opcional)</span></label>
                    <textarea name="notas" placeholder="Informacion adicional..."><?= htmlspecialchars($v['notas']) ?></textarea>
                </div>

                <div class="form-actions">
                    <a class="btn-cancelar" href="asistencia.php">Cancelar</a>
                    <button class="btn-guardar" type="submit"><?= $esEdicion ? 'Guardar cambios' : 'Registrar' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

var tipoConfig = {
    'Asistencia normal': {
        tiempos: false, intervalos: false, required: false,
        entrada: '',                       salida: '',
        razon: 'Notas del dia',           razonPh: 'Ej. Sin novedad (opcional)',
        infoColor: { bg:'#f0fff0', border:'#b7dfb8', text:'#1e8449' },
        info: 'Dia completo sin incidente: se registran las horas esperadas completas (9 hrs entre semana, 6 hrs sabado).'
    },
    'Tardanza': {
        tiempos: true,  intervalos: false, required: true,
        entrada: 'Hora real de llegada',  salida: 'Hora de salida',
        razon: 'Motivo de la tardanza',   razonPh: 'Ej. Trafico, transporte, se le paso...',
        infoColor: { bg:'#fff9e6', border:'#f0b429', text:'#b7860b' },
        info: 'Captura la hora real a la que llego el empleado para calcular automaticamente las horas no trabajadas.'
    },
    'Falta': {
        tiempos: false, intervalos: false, required: false,
        entrada: '',                       salida: '',
        razon: 'Motivo de la falta',      razonPh: 'Ej. Enfermedad, permiso sin goce...',
        infoColor: { bg:'#fff0f0', border:'#fdd', text:'#c0392b' },
        info: 'Se contara el dia completo como no trabajado (9 hrs entre semana, 6 hrs sabado).'
    },
    'Salida temprana': {
        tiempos: true,  intervalos: false, required: true,
        entrada: 'Hora de entrada',        salida: 'Hora de salida anticipada',
        razon: 'Motivo de la salida',      razonPh: 'Ej. Cita medica, emergencia familiar...',
        infoColor: { bg:'#fff9e6', border:'#f0b429', text:'#b7860b' },
        info: 'Captura la hora a la que el empleado salio antes de terminar su jornada.'
    },
    'Tiempo fuera': {
        tiempos: true,  intervalos: true,  required: true,
        entrada: 'Hora real de llegada',   salida: 'Hora real de salida',
        razon: 'Motivo del tiempo fuera',  razonPh: 'Ej. Llego tarde, salio antes, mandado, cita medica...',
        infoColor: { bg:'#fff9e6', border:'#f0b429', text:'#b7860b' },
        info: 'Captura las horas reales de llegada y salida (cubre tardanzas y salidas tempranas). Si ademas salio a media jornada, registra abajo cada intervalo fuera.'
    },
    'Horas extra': {
        tiempos: true,  intervalos: true,  required: true,
        entrada: 'Hora de entrada',        salida: 'Hora de salida real',
        razon: 'Motivo de las horas extra', razonPh: 'Ej. Pedido urgente, inventario, cierre...',
        infoColor: { bg:'#f0fff0', border:'#b7dfb8', text:'#1e8449' },
        info: 'Las horas por encima de la jornada se pagan al 1.5x de la tarifa normal.'
    }
};

function actualizarVista() {
    var tipo = document.getElementById('selectTipo').value;
    var cfg  = tipoConfig[tipo] || tipoConfig['Asistencia normal'];

    // Mostrar / ocultar secciones
    document.getElementById('seccionTiempos').classList.toggle('hidden', !cfg.tiempos);
    document.getElementById('seccionTF').classList.toggle('hidden', !cfg.intervalos);

    // Si las horas quedan ocultas, limpiarlas para que no se envien valores viejos
    if (!cfg.tiempos) {
        document.getElementById('horaEntrada').value = '';
        document.getElementById('horaSalida').value  = '';
    }

    // Required en hora entrada/salida
    var horaEnt = document.getElementById('horaEntrada');
    var horaSal = document.getElementById('horaSalida');
    if (cfg.required) {
        horaEnt.setAttribute('required', '');
        horaSal.setAttribute('required', '');
        horaEnt.removeAttribute('placeholder');
        horaSal.removeAttribute('placeholder');
    } else {
        horaEnt.removeAttribute('required');
        horaSal.removeAttribute('required');
        horaEnt.placeholder = 'Opcional';
        horaSal.placeholder = 'Opcional';
    }

    // Labels dinámicos
    document.getElementById('lblEntrada').textContent = cfg.entrada;
    document.getElementById('lblSalida').textContent  = cfg.salida;
    document.getElementById('lblRazon').textContent   = cfg.razon;
    document.getElementById('inputRazon').placeholder = cfg.razonPh;

    // Cuadro informativo contextual
    var box = document.getElementById('infoContextual');
    box.textContent         = cfg.info;
    box.style.display       = 'block';
    box.style.background    = cfg.infoColor.bg;
    box.style.border        = '1px solid ' + cfg.infoColor.border;
    box.style.color         = cfg.infoColor.text;

    calcular();
}

function timeToMin(t) {
    if (!t) return null;
    var parts = t.split(':');
    return parseInt(parts[0]) * 60 + parseInt(parts[1]);
}

function calcular() {
    var tipo    = document.getElementById('selectTipo').value;
    var fecha   = document.getElementById('inputFecha').value;
    var entrada = document.getElementById('horaEntrada').value;
    var salida  = document.getElementById('horaSalida').value;

    // Determine expected hours from day of week
    var esperadas = 9;
    if (fecha) {
        var d = new Date(fecha + 'T12:00:00');
        var dow = d.getDay(); // 0=Sun, 6=Sat
        if (dow === 6) esperadas = 6;
    }

    document.getElementById('calcEsperadas').textContent = esperadas + ' h';

    if (tipo === 'Falta') {
        document.getElementById('calcTrabajadas').textContent   = '0 h';
        document.getElementById('calcNoTrabajadas').textContent = esperadas + ' h';
        document.getElementById('calcExtra').textContent        = '0 h';
        return;
    }

    if (tipo === 'Asistencia normal') {
        document.getElementById('calcTrabajadas').textContent   = esperadas + ' h';
        document.getElementById('calcNoTrabajadas').textContent = '0 h';
        document.getElementById('calcExtra').textContent        = '0 h';
        return;
    }

    var mEnt = timeToMin(entrada);
    var mSal = timeToMin(salida);
    if (mEnt === null || mSal === null || mSal <= mEnt) {
        document.getElementById('calcTrabajadas').textContent   = '—';
        document.getElementById('calcNoTrabajadas').textContent = '—';
        document.getElementById('calcExtra').textContent        = '—';
        return;
    }

    var minTrabajados = mSal - mEnt;

    // Subtract time intervals for all types (split shifts, breaks, etc.)
    document.querySelectorAll('#tfContainer .tf-row').forEach(function(row) {
        var inputs = row.querySelectorAll('input[type=time]');
        var tfs = timeToMin(inputs[0] ? inputs[0].value : '');
        var tfr = timeToMin(inputs[1] ? inputs[1].value : '');
        if (tfs !== null && tfr !== null && tfr > tfs) {
            minTrabajados -= (tfr - tfs);
        }
    });

    var horasTrab = minTrabajados / 60;
    var noTrab    = Math.max(0, esperadas - horasTrab);
    var extra     = Math.max(0, horasTrab - esperadas);

    document.getElementById('calcTrabajadas').textContent   = horasTrab.toFixed(2) + ' h';
    document.getElementById('calcNoTrabajadas').textContent = noTrab > 0 ? noTrab.toFixed(2) + ' h' : '0 h';
    document.getElementById('calcExtra').textContent        = extra > 0 ? '+' + extra.toFixed(2) + ' h' : '0 h';
}

function agregarTF() {
    var container = document.getElementById('tfContainer');
    var row = document.createElement('div');
    row.className = 'tf-row';
    row.innerHTML = '<input type="time" name="tf_salida[]" placeholder="Salida" onchange="calcular()">'
                  + '<span class="tf-sep">hasta</span>'
                  + '<input type="time" name="tf_regreso[]" placeholder="Regreso" onchange="calcular()">'
                  + '<button type="button" class="btn-rm-tf" onclick="quitarTF(this)" title="Quitar">&#215;</button>';
    container.appendChild(row);
}

function quitarTF(btn) {
    var rows = document.querySelectorAll('#tfContainer .tf-row');
    if (rows.length > 1) {
        btn.closest('.tf-row').remove();
        calcular();
    }
}

// Vacaciones aprobadas para verificar en frontend
var vacAprobadas = <?= json_encode(array_map(fn($v) => [
    'empleado_id'  => (string)$v['empleado_id'],
    'fecha_inicio' => $v['fecha_inicio'],
    'fecha_fin'    => $v['fecha_fin'],
], $vacAprobadas)) ?>;

function verificarVacacion() {
    var empId = document.getElementById('selEmpleado').value;
    var fecha = document.getElementById('inputFecha').value;
    var aviso = document.getElementById('avisoVacacion');
    if (!empId || !fecha) { aviso.style.display = 'none'; return; }
    var enVacacion = vacAprobadas.some(function(v) {
        return v.empleado_id === empId && fecha >= v.fecha_inicio && fecha <= v.fecha_fin;
    });
    aviso.style.display = enVacacion ? 'block' : 'none';
}

// Fechas con registro existente por empleado: { empleado_id: { 'YYYY-MM-DD': asistencia_id } }
var fechasOcupadas = <?= json_encode($fechasOcupadas) ?>;
var editandoId     = <?= $editando ? intval($editando['asistencia_id']) : 0 ?>;

function verificarFechaOcupada() {
    var empId = document.getElementById('selEmpleado').value;
    var fechaInput = document.getElementById('inputFecha');
    var fecha = fechaInput.value;
    var aviso = document.getElementById('avisoFechaOcupada');

    var ocupada = empId && fecha
        && fechasOcupadas[empId]
        && fechasOcupadas[empId][fecha]
        && fechasOcupadas[empId][fecha] !== editandoId;

    if (ocupada) {
        aviso.style.display = 'block';
        fechaInput.value = '';
    } else {
        aviso.style.display = 'none';
    }
}

// Init on load
actualizarVista();
document.getElementById('inputFecha').addEventListener('change', function() { verificarFechaOcupada(); calcular(); verificarVacacion(); });
verificarVacacion();
verificarFechaOcupada();
</script>
</body>
</html>
