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

    $tiposValidos     = ['Asistencia normal','Tardanza','Falta','Salida temprana','Tiempo fuera','Horas extra'];
    $resolucionesVal  = ['Pendiente','Deducido','Compensado','Justificado','Pagado integro'];

    if (!$empleado_id)                          $errores[] = 'Selecciona un empleado.';
    if (!$fecha || !strtotime($fecha))          $errores[] = 'La fecha no es valida.';
    if (!in_array($tipo, $tiposValidos))        $errores[] = 'El tipo no es valido.';
    if (!in_array($resolucion, $resolucionesVal)) $errores[] = 'La resolucion no es valida.';

    if ($tipo !== 'Falta' && $tipo !== 'Asistencia normal') {
        if (!$hora_entrada) $errores[] = 'La hora de entrada es obligatoria.';
        if (!$hora_salida)  $errores[] = 'La hora de salida es obligatoria.';
        if ($hora_entrada && $hora_salida && timeToMinutes($hora_salida) <= timeToMinutes($hora_entrada))
            $errores[] = 'La hora de salida debe ser mayor a la de entrada.';
    }
    if ($tipo !== 'Falta' && $tipo !== 'Asistencia normal' && $hora_entrada && $hora_salida) {
        if (timeToMinutes($hora_salida) <= timeToMinutes($hora_entrada))
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

        header('Location: asistencia.php');
        exit();
    }
}

$empleados   = $pdo->query("SELECT empleado_id, nombre FROM empleados WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$tipos       = ['Asistencia normal','Tardanza','Falta','Salida temprana','Tiempo fuera','Horas extra'];
$resoluciones = ['Pendiente','Deducido','Compensado','Justificado','Pagado integro'];

$v = [
    'empleado_id'  => $_POST['empleado_id']  ?? $editando['empleado_id']  ?? '',
    'fecha'        => $_POST['fecha']        ?? $editando['fecha']        ?? date('Y-m-d'),
    'tipo'         => $_POST['tipo']         ?? $editando['tipo']         ?? 'Tardanza',
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
    .tf-row input { flex: 1; }
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
                <?php if ($esEdicion): ?>
                    <input type="hidden" name="asistencia_id" value="<?= $editando['asistencia_id'] ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label>Empleado</label>
                        <select name="empleado_id" required>
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

                <div class="form-group">
                    <label>Tipo de incidente</label>
                    <select name="tipo" id="selectTipo" required onchange="actualizarVista()">
                        <?php foreach ($tipos as $t): ?>
                            <option value="<?= $t ?>" <?= $v['tipo'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Info asistencia normal -->
                <div id="infoNormal" style="display:none;background:#f0fff0;border:1px solid #b7dfb8;border-radius:7px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#1e8449;">
                    Dia completo: si no capturas horas, el sistema asume que trabajo sus horas esperadas (9 hrs entre semana, 6 hrs sabado). Puedes capturar las horas reales si quieres mayor detalle.
                </div>

                <!-- Seccion tiempos (oculta para Falta) -->
                <div id="seccionTiempos">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Hora de entrada</label>
                            <input type="time" name="hora_entrada" id="horaEntrada" value="<?= htmlspecialchars($v['hora_entrada']) ?>" onchange="calcular()">
                        </div>
                        <div class="form-group">
                            <label>Hora de salida</label>
                            <input type="time" name="hora_salida" id="horaSalida" value="<?= htmlspecialchars($v['hora_salida']) ?>" onchange="calcular()">
                        </div>
                    </div>

                    <!-- Tiempos fuera (para todos los tipos excepto Falta) -->
                    <div id="seccionTF">
                        <div class="section-label">Intervalos de tiempo fuera <span style="font-weight:400;text-transform:none;color:#bbb;">(opcional — turno partido, descansos, etc.)</span></div>
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

                <div class="form-group">
                    <label>Razon</label>
                    <input type="text" name="razon" value="<?= htmlspecialchars($v['razon']) ?>" placeholder="Ej. Llegó tarde por trafico" maxlength="500">
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

function actualizarVista() {
    var tipo = document.getElementById('selectTipo').value;
    var secTiempos = document.getElementById('seccionTiempos');
    var secTF      = document.getElementById('seccionTF');
    var lblEntrada = document.querySelector('label[for_entrada]') || document.querySelector('#seccionTiempos .form-row .form-group:first-child label');

    if (tipo === 'Falta') {
        secTiempos.classList.add('hidden');
        secTF.classList.add('hidden');
    } else {
        secTiempos.classList.remove('hidden');
        secTF.classList.remove('hidden');
    }

    // Para asistencia normal, las horas son opcionales
    var esNormal = tipo === 'Asistencia normal';
    var inputs = document.querySelectorAll('#seccionTiempos input[type=time]');
    inputs.forEach(function(inp) {
        if (esNormal) {
            inp.removeAttribute('required');
            inp.placeholder = 'Opcional';
        } else {
            inp.placeholder = '';
        }
    });

    // Etiqueta informativa
    var infoNormal = document.getElementById('infoNormal');
    if (infoNormal) infoNormal.style.display = esNormal ? 'block' : 'none';

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

// Init on load
actualizarVista();
document.getElementById('inputFecha').addEventListener('change', calcular);
</script>
</body>
</html>
