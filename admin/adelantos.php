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

// ---- Filtros del historial de adelantos ----
// Se leen de $_GET (para mostrar la tabla) y de $_POST (para volver al mismo listado despues de "Marcar liquidado").
// [FIX-TIPO-ARRAY-ID] Cada valor se acepta solo si es escalar: "?empleado[]=x" no debe tronar ni coaccionarse a 1.
function adelantosFiltros(array $src): array {
    $s = function (string $k) use ($src): string {
        return is_scalar($src[$k] ?? null) ? trim((string)$src[$k]) : '';
    };
    $fechaOk = function (string $v): string {
        return ($v !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) ? $v : '';
    };
    $estado = in_array($s('estado'), ['Pendiente', 'Liquidado'], true) ? $s('estado') : '';
    $desde  = $fechaOk($s('desde'));
    $hasta  = $fechaOk($s('hasta'));
    if ($desde !== '' && $hasta !== '' && $desde > $hasta) { $tmp = $desde; $desde = $hasta; $hasta = $tmp; }
    $orden  = in_array($s('orden'), ['fecha', 'empleado', 'monto', 'estado'], true) ? $s('orden') : 'fecha';
    $dirDef = in_array($orden, ['empleado', 'estado'], true) ? 'asc' : 'desc';
    return [
        'vista'    => $s('vista') === 'saldos' ? 'saldos' : 'historial',
        'empleado' => max(0, intval($s('empleado'))),
        'estado'   => $estado,
        'desde'    => $desde,
        'hasta'    => $hasta,
        'q'        => mb_substr($s('q'), 0, 100),
        'orden'    => $orden,
        'dir'      => in_array($s('dir'), ['asc', 'desc'], true) ? $s('dir') : $dirDef,
        'pag'      => max(1, intval($s('pag'))),
    ];
}

// Solo los parametros que no son los de por defecto (URLs cortas y legibles).
function adelantosParams(array $f, array $cambios = []): array {
    $f = array_merge($f, $cambios);
    $p = [];
    if ($f['vista'] === 'saldos')   $p['vista']    = 'saldos';
    if ($f['empleado'] > 0)         $p['empleado'] = $f['empleado'];
    if ($f['estado'] !== '')        $p['estado']   = $f['estado'];
    if ($f['desde'] !== '')         $p['desde']    = $f['desde'];
    if ($f['hasta'] !== '')         $p['hasta']    = $f['hasta'];
    if ($f['q'] !== '')             $p['q']        = $f['q'];
    if (!($f['orden'] === 'fecha' && $f['dir'] === 'desc')) { $p['orden'] = $f['orden']; $p['dir'] = $f['dir']; }
    if ($f['pag'] > 1)              $p['pag']      = $f['pag'];
    return $p;
}

function adelantosUrl(array $f, array $cambios = []): string {
    $qs = http_build_query(adelantosParams($f, $cambios));
    return 'adelantos.php' . ($qs !== '' ? '?' . $qs : '');
}

// Encabezado de columna que ordena al hacer clic (otro clic invierte el orden).
function adelantosLinkOrden(array $f, string $col, string $etiqueta): string {
    $dirDef  = in_array($col, ['empleado', 'estado'], true) ? 'asc' : 'desc';
    $activa  = $f['orden'] === $col;
    $nuevaDir = $activa ? ($f['dir'] === 'asc' ? 'desc' : 'asc') : $dirDef;
    $flecha  = $activa ? ($f['dir'] === 'asc' ? '▲' : '▼') : '↕';
    return '<a href="' . htmlspecialchars(adelantosUrl($f, ['orden' => $col, 'dir' => $nuevaDir, 'pag' => 1])) . '" class="th-orden' . ($activa ? ' activo' : '') . '">'
         . htmlspecialchars($etiqueta) . ' <span class="flecha">' . $flecha . '</span></a>';
}

// "27 jul – 1 ago": semana de nomina (lunes a sabado) a la que cuenta un adelanto (un domingo cuenta para la semana siguiente,
// igual que en Semana laboral).
function adelantosEtiquetaSemana(string $fecha): string {
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $lun = new DateTime(lunesDeLaSemana($fecha));
    $sab = (clone $lun)->modify('+5 days');
    return $lun->format('j') . ' ' . $meses[(int)$lun->format('n') - 1] . ' – ' . $sab->format('j') . ' ' . $meses[(int)$sab->format('n') - 1];
}

// Registrar nuevo adelanto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_adelanto'])) {
    requerirCSRF($_POST['_token'] ?? '', 'adelantos.php');

    $empleado_id = intval(is_scalar($_POST['empleado_id'] ?? null) ? $_POST['empleado_id'] : 0);
    $monto       = floatval(is_scalar($_POST['monto'] ?? null) ? $_POST['monto'] : 0);
    $fecha       = trim(is_scalar($_POST['fecha'] ?? null) ? (string)$_POST['fecha'] : date('Y-m-d'));
    $motivo      = trim(is_scalar($_POST['motivo'] ?? null) ? (string)$_POST['motivo'] : '');

    $errores = [];
    // [FIX-ADELANTO-RACE] El tope de una semana de sueldo y el chequeo de duplicado son "leer y luego
    // insertar" sin ningun candado: cuatro envios simultaneos (dos pestanas, doble clic, dos cajeros)
    // leian el mismo total pendiente y todos pasaban. Probado con 4 servidores a la vez: $2,860 de
    // adelantos pendientes sobre un tope de $2,000, y un adelanto duplicado. Se serializa por empleado
    // con GET_LOCK (mismo patron que formVacacion.php).
    $lockAdelanto = null; $lockAdelantoOk = true;
    if ($empleado_id > 0) {
        $lockAdelanto   = 'adelanto_empleado_' . $empleado_id;
        $lockAdelantoOk = (bool)$pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockAdelanto) . ", 5)")->fetchColumn();
        if (!$lockAdelantoOk) $errores[] = 'Otro usuario está registrando un adelanto para este empleado en este momento. Intenta de nuevo.';
    }
    if ($empleado_id <= 0) $errores[] = 'Selecciona un empleado.';
    if ($monto <= 0) $errores[] = 'El monto debe ser mayor a $0.00.';
    // [FIX-MEDIO-G-15] adelantos_sueldo.motivo es VARCHAR(255) sin ningun tope en el
    // servidor (el textarea tampoco tenia maxlength) — mismo patron de truncado
    // silencioso/500 ya visto y corregido en otros formularios de este mismo modulo.
    if (mb_strlen($motivo) > 255) $errores[] = 'El motivo no puede tener más de 255 caracteres.';

    // El adelanto es contra el sueldo de la semana en curso (lunes a sabado)
    // [FIX-ADELANTO-LIMITE-SEMANA] Antes usaba strtotime('monday this week'), que en domingo
    // regresa el lunes de la semana que ACABA de terminar en vez de avanzar a la que empieza
    // manana -- desincronizado de como semanaLaboral.php interpreta ese mismo domingo (ver
    // lunesDeLaSemana() en rh_helpers.php). Confirmado con una comparacion dia por dia: los
    // otros 6 dias ya coincidian, solo domingo daba semanas distintas.
    $lunesSemana  = lunesDeLaSemana(date('Y-m-d'));
    $sabadoSemana = date('Y-m-d', strtotime($lunesSemana . ' +5 days'));
    // [FIX-ADELANTO-DOMINGO] En domingo "la semana en curso" ya es la que empieza manana, asi que un adelanto
    // dado HOY (domingo) no podia fecharse con la fecha de hoy y el formulario proponia el sabado siguiente.
    // Se permite desde el dia de hoy cuando es domingo: sigue contando para la semana que empieza el lunes
    // (los adelantos cuentan si su fecha es <= sabado de la semana que se paga).
    $desdePermitido = min($lunesSemana, date('Y-m-d'));
    // [FIX-ADELANTO-FECHA-CALENDARIO-INVALIDA] "!strtotime($fecha)" no rechaza fechas de
    // calendario imposibles (mismo patron ya corregido en formEmpleado.php/formVacacion.php/
    // formAsistencia.php: strtotime('2026-02-30') no da false, MySQL es quien la rechaza).
    // Aqui era peor: el rango valido es una sola semana lunes-sabado (6 caracteres de
    // diferencia como mucho), y comparar el STRING crudo contra ese rango ("$fecha < $lunes ||
    // $fecha > $sabado") deja pasar una fecha de calendario invalida cuando la semana cruza un
    // cambio de mes -- ej. semana lunes 2026-09-28 a sabado 2026-10-03: "2026-09-31" (Septiembre
    // solo tiene 30 dias) ordena lexicograficamente ENTRE ambos limites ('2026-09-28' <
    // '2026-09-31' < '2026-10-03' como texto), aunque esa fecha no exista. Sin este fix, ese
    // valor pasaba las dos validaciones de abajo y llegaba crudo al INSERT (sin try/catch),
    // que en el servidor (sql_mode estricto) truena con un HTTP 500 exponiendo la ruta del
    // proyecto. Se exige el formato Y-m-d exacto y se valida con checkdate() que sea una fecha
    // de calendario real, antes de compararla contra el rango de la semana.
    $fechaValida = $fecha && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $mFecha) && checkdate((int)$mFecha[2], (int)$mFecha[3], (int)$mFecha[1]);
    if (!$fechaValida) {
        $errores[] = 'La fecha no es valida.';
    } elseif ($fecha < $desdePermitido || $fecha > $sabadoSemana) {
        $errores[] = 'Solo se puede adelantar el sueldo de la semana en curso (' . date('d/m/Y', strtotime($desdePermitido)) . ' al ' . date('d/m/Y', strtotime($sabadoSemana)) . ').';
    }

    // [FIX-CRIT-G-02] Si la semana en curso ya se pago (existe fila en pagos_nomina
    // para este empleado y esta semana), un adelanto registrado ahora ya no tiene
    // ninguna nomina pendiente de la que descontarse — antes se aceptaba igual y
    // quedaba "Pendiente" para siempre, dinero entregado que el sistema nunca
    // recuperaba. Se rechaza con un mensaje claro en vez de aceptarlo en silencio.
    if ($empleado_id > 0 && empty($errores)) {
        $stmtYaPagada = $pdo->prepare("SELECT 1 FROM pagos_nomina WHERE empleado_id = ? AND semana_inicio = ?");
        $stmtYaPagada->execute([$empleado_id, $lunesSemana]);
        if ($stmtYaPagada->fetchColumn()) {
            $errores[] = 'La semana en curso ya fue pagada para este empleado. Este adelanto no podría descontarse de ningún sueldo pendiente — regístralo la próxima semana en su lugar.';
        }
    }

    // Tope: el TOTAL pendiente por descontar (no solo lo tomado esta semana) no puede
    // superar el sueldo semanal.
    // [FIX-ADELANTO-TOPE-ACUMULADO] El tope solo sumaba adelantos "fecha BETWEEN lunes Y
    // sabado" de la semana en curso, ignorando cualquier adelanto de una semana ANTERIOR que
    // siguiera 'Pendiente' (recuperado solo parcialmente, o de plano no recuperado) -- un
    // empleado con $3,000 sin liquidar de la semana pasada podia pedir otros $3,000 esta
    // semana sin ningun aviso, porque "lo de esta semana" seguia en $0. Probado en vivo: se
    // acumularon $6,000 pendientes contra un sueldo de $3,000 (200%) antes de este fix. La
    // logica correcta es la misma que ya usa semanaLaboral.php (FIX-ALTO-G-06) para calcular
    // cuanto descontar: TODO adelanto 'Pendiente' del empleado, sin importar en que semana se
    // tomo, cuenta contra el limite de una semana de sueldo.
    if ($empleado_id > 0 && $monto > 0 && empty($errores)) {
        $stmtSueldo = $pdo->prepare("SELECT sueldo_semanal FROM empleados WHERE empleado_id = ? AND activo = 1");
        $stmtSueldo->execute([$empleado_id]);
        $sueldoSem = floatval($stmtSueldo->fetchColumn());
        if ($sueldoSem <= 0) {
            $errores[] = 'El empleado no es valido o no tiene sueldo registrado.';
        } else {
            $stmtYa = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM adelantos_sueldo WHERE empleado_id = ? AND estado = 'Pendiente'");
            $stmtYa->execute([$empleado_id]);
            $yaAdelantado = floatval($stmtYa->fetchColumn());
            if ($yaAdelantado + $monto > $sueldoSem) {
                $disponible = max(0, $sueldoSem - $yaAdelantado);
                $errores[] = 'No se puede adelantar mas de una semana de sueldo ($' . number_format($sueldoSem, 2) . '). Ya tiene $' . number_format($yaAdelantado, 2) . ' pendiente de descontar (incluye semanas anteriores); el maximo disponible es $' . number_format($disponible, 2) . '.';
            }
        }
    }

    // [FIX-MEDIO-G-19] Un doble clic en "Registrar adelanto" (o un reenvio del formulario)
    // mandaba dos POSTs identicos antes de que el primero terminara de redirigir, y no habia
    // nada que detectara que eran la misma solicitud — se creaban dos adelantos_sueldo
    // iguales, duplicando el monto entregado realmente una sola vez. Se rechaza si ya existe
    // un adelanto pendiente para el mismo empleado, mismo monto y misma fecha, creado en los
    // ultimos 10 segundos (ventana mas que suficiente para un doble clic, demasiado corta
    // para chocar con un adelanto legitimo distinto capturado despues).
    if (empty($errores) && $empleado_id > 0) {
        $stmtDupAdel = $pdo->prepare("
            SELECT 1 FROM adelantos_sueldo
            WHERE empleado_id = ? AND monto = ? AND fecha = ?
              AND created_at >= NOW() - INTERVAL 10 SECOND
            LIMIT 1
        ");
        $stmtDupAdel->execute([$empleado_id, $monto, $fecha]);
        if ($stmtDupAdel->fetchColumn()) {
            $errores[] = 'Este adelanto ya se registró hace unos segundos (parece un envío duplicado). Revisa el historial antes de intentar de nuevo.';
        }
    }

    // [FIX-ADELANTO-INSERT-SIN-CAPTURAR] Mismo patron ya corregido en formEmpleado.php/
    // formVacacion.php/formAsistencia.php: una PDOException aqui (ej. empleado_id de un
    // <select> desactualizado que ya no existe, violando la FK) se dejaba sin capturar --
    // HTTP 500 con la ruta del servidor y el esquema de la BD expuestos.
    if (empty($errores)) {
        try {
            $pdo->prepare("INSERT INTO adelantos_sueldo (empleado_id, monto, fecha, motivo, estado) VALUES (?, ?, ?, ?, 'Pendiente')")
                ->execute([$empleado_id, $monto, $fecha, $motivo]);
            if ($lockAdelanto && $lockAdelantoOk) $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockAdelanto) . ")");
            header('Location: adelantos.php?msg=registrado');
            exit();
        } catch (PDOException $e) {
            $errores[] = textoNoCompatibleBD($e) ? 'El texto contiene caracteres que el sistema no puede guardar (por ejemplo emojis). Quítalos e intenta de nuevo.' : 'No se pudo registrar el adelanto. Verifica que el empleado siga existiendo e intenta de nuevo.';
        }
    }
    if ($lockAdelanto && $lockAdelantoOk) $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockAdelanto) . ")");
    $_SESSION['_errores_adelanto'] = $errores;
    $_SESSION['_old_adelanto'] = [
        'empleado_id' => $empleado_id,
        'monto'       => is_scalar($_POST['monto'] ?? null) ? (string)$_POST['monto'] : '',
        'fecha'       => $fecha,
        'motivo'      => $motivo,
    ];
    header('Location: adelantos.php');
    exit();
}

// Marcar adelanto como liquidado (ya se le descontó al empleado de su sueldo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['liquidar_id'])) {
    requerirCSRF($_POST['_token'] ?? '', 'adelantos.php');
    $aid = intval(is_scalar($_POST['liquidar_id'] ?? null) ? $_POST['liquidar_id'] : 0);
    // Solo si sigue 'Pendiente': si alguien ya lo liquido (o una nomina ya lo descontó) no se reporta un exito falso.
    $stLiq = $pdo->prepare("UPDATE adelantos_sueldo SET estado = 'Liquidado' WHERE adelanto_id = ? AND estado = 'Pendiente'");
    $stLiq->execute([$aid]);
    // Se regresa al mismo listado (mismos filtros, orden y pagina) en vez de mandar al usuario al principio.
    $volver = adelantosParams(adelantosFiltros($_POST));
    $volver['msg'] = $stLiq->rowCount() > 0 ? 'liquidado' : 'ya_liquidado';
    header('Location: adelantos.php?' . http_build_query($volver));
    exit();
}

$errores = $_SESSION['_errores_adelanto'] ?? [];
unset($_SESSION['_errores_adelanto']);
// Lo que se habia capturado cuando hubo error (para no perderlo al regresar el formulario).
$viejo = $_SESSION['_old_adelanto'] ?? [];
unset($_SESSION['_old_adelanto']);
if (!is_array($viejo)) $viejo = [];

$flt     = adelantosFiltros($_GET);
$POR_PAG = 25;

// Empleados activos (a ellos se les puede registrar un adelanto)
$empleados = $pdo->query("SELECT empleado_id, nombre, sueldo_semanal FROM empleados WHERE activo = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

// Saldo por empleado: pendiente de descontar, cuanto le queda disponible (tope = una semana de sueldo) y su historial en numeros.
// Se usa en la pestana "Saldo por empleado" y en la ayuda del formulario.
$saldos = $pdo->query("
    SELECT e.empleado_id, e.nombre, e.sueldo_semanal, e.activo,
           COALESCE(SUM(CASE WHEN a.estado = 'Pendiente' THEN a.monto END), 0) AS pendiente,
           COALESCE(SUM(CASE WHEN a.estado = 'Pendiente' THEN 1 END), 0)       AS n_pendientes,
           COUNT(a.adelanto_id)                                                 AS n_total,
           COALESCE(SUM(a.monto), 0)                                            AS total_historico,
           MAX(a.fecha)                                                         AS ultimo
    FROM empleados e
    LEFT JOIN adelantos_sueldo a ON a.empleado_id = e.empleado_id
    WHERE e.activo = 1 OR a.adelanto_id IS NOT NULL
    GROUP BY e.empleado_id, e.nombre, e.sueldo_semanal, e.activo
    ORDER BY pendiente DESC, e.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Tarjetas de arriba (siempre del total, no del filtro)
$rowPend = $pdo->query("SELECT COALESCE(SUM(monto), 0) AS total, COUNT(*) AS n, COUNT(DISTINCT empleado_id) AS emps FROM adelantos_sueldo WHERE estado = 'Pendiente'")->fetch(PDO::FETCH_ASSOC);
$totalPendienteGlobal = floatval($rowPend['total']);
$nPendientes          = (int)$rowPend['n'];
$nEmpConPendiente     = (int)$rowPend['emps'];
$totalLiquidadoGlobal = floatval($pdo->query("SELECT COALESCE(SUM(monto), 0) FROM adelantos_sueldo WHERE estado = 'Liquidado'")->fetchColumn());

// "Esta semana" = el mismo rango en el que el formulario deja registrar adelantos (semana en curso, lunes a sabado).
$hoyPag    = date('Y-m-d');
$lunesPag  = lunesDeLaSemana($hoyPag);
$sabadoPag = date('Y-m-d', strtotime($lunesPag . ' +5 days'));
$desdePag  = min($lunesPag, $hoyPag);
$stSem = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) AS total, COUNT(*) AS n FROM adelantos_sueldo WHERE fecha BETWEEN ? AND ?");
$stSem->execute([$desdePag, $sabadoPag]);
$rowSem = $stSem->fetch(PDO::FETCH_ASSOC);

// ---------- Historial filtrado, ordenado y paginado ----------
$filas = []; $totales = ['n' => 0, 'total' => 0, 'pend' => 0, 'liq' => 0]; $totalPag = 1; $nFilas = 0; $offset = 0;
$todosEmp = [];
if ($flt['vista'] === 'historial') {
    $where = ['1 = 1']; $params = [];
    if ($flt['empleado'] > 0)   { $where[] = 'a.empleado_id = ?';  $params[] = $flt['empleado']; }
    if ($flt['estado'] !== '')  { $where[] = 'a.estado = ?';       $params[] = $flt['estado']; }
    if ($flt['desde'] !== '')   { $where[] = 'a.fecha >= ?';       $params[] = $flt['desde']; }
    if ($flt['hasta'] !== '')   { $where[] = 'a.fecha <= ?';       $params[] = $flt['hasta']; }
    if ($flt['q'] !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $flt['q']) . '%';
        $where[] = '(a.motivo LIKE ? OR e.nombre LIKE ?)';
        $params[] = $like; $params[] = $like;
    }
    $whereSql = implode(' AND ', $where);

    $stTot = $pdo->prepare("
        SELECT COUNT(*) AS n, COALESCE(SUM(a.monto), 0) AS total,
               COALESCE(SUM(CASE WHEN a.estado = 'Pendiente' THEN a.monto END), 0) AS pend,
               COALESCE(SUM(CASE WHEN a.estado = 'Liquidado' THEN a.monto END), 0) AS liq
        FROM adelantos_sueldo a JOIN empleados e ON e.empleado_id = a.empleado_id
        WHERE $whereSql
    ");
    $stTot->execute($params);
    $totales  = $stTot->fetch(PDO::FETCH_ASSOC);
    $nFilas   = (int)$totales['n'];
    $totalPag = max(1, (int)ceil($nFilas / $POR_PAG));
    $flt['pag'] = min($flt['pag'], $totalPag);
    $offset   = ($flt['pag'] - 1) * $POR_PAG;

    $colOrden = ['fecha' => 'a.fecha', 'empleado' => 'e.nombre', 'monto' => 'a.monto', 'estado' => 'a.estado'][$flt['orden']];
    $dirSql   = $flt['dir'] === 'asc' ? 'ASC' : 'DESC';
    $stLst = $pdo->prepare("
        SELECT a.*, e.nombre AS empleado_nombre, e.activo AS empleado_activo
        FROM adelantos_sueldo a JOIN empleados e ON e.empleado_id = a.empleado_id
        WHERE $whereSql
        ORDER BY $colOrden $dirSql, a.fecha DESC, a.adelanto_id DESC
        LIMIT " . intval($POR_PAG) . " OFFSET " . intval($offset)
    );
    $stLst->execute($params);
    $filas = $stLst->fetchAll(PDO::FETCH_ASSOC);

    // Lista del filtro "Empleado": todos (tambien los inactivos, que pueden tener adelantos)
    $todosEmp = $pdo->query("SELECT empleado_id, nombre, activo FROM empleados ORDER BY activo DESC, nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
}
$hayFiltros = ($flt['empleado'] > 0 || $flt['estado'] !== '' || $flt['desde'] !== '' || $flt['hasta'] !== '' || $flt['q'] !== '');

// Datos para la ayuda del formulario (sueldo, pendiente y disponible de cada empleado activo)
$pendPorEmp = [];
foreach ($saldos as $sd) $pendPorEmp[(int)$sd['empleado_id']] = floatval($sd['pendiente']);
$mapaEmp = [];
foreach ($empleados as $e) {
    $mapaEmp[(int)$e['empleado_id']] = ['sueldo' => floatval($e['sueldo_semanal']), 'pendiente' => $pendPorEmp[(int)$e['empleado_id']] ?? 0.0];
}

// Fecha por defecto del formulario (igual que antes)
// [FIX-ADELANTO-LIMITE-SEMANA] el default de "value" no puede ser simplemente "hoy": si hoy es domingo, hoy cae FUERA del rango
// lunes-sabado (min/max) -- se usa el sabado (ultimo dia valido de la semana que ya cerro) como default en ese caso.
$lunesForm  = lunesDeLaSemana(date('Y-m-d'));
$sabadoForm = date('Y-m-d', strtotime($lunesForm . ' +5 days'));
$hoyForm    = date('Y-m-d');
$desdeForm  = min($lunesForm, $hoyForm);
$valorForm  = ($hoyForm >= $desdeForm && $hoyForm <= $sabadoForm) ? $hoyForm : $sabadoForm;
$vEmp   = intval(is_scalar($viejo['empleado_id'] ?? null) ? $viejo['empleado_id'] : 0);
if ($vEmp <= 0 && $flt['empleado'] > 0 && isset($mapaEmp[$flt['empleado']])) $vEmp = $flt['empleado'];
$vMonto = is_scalar($viejo['monto'] ?? null) ? (string)$viejo['monto'] : '';
$vFecha = (is_scalar($viejo['fecha'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$viejo['fecha'])) ? (string)$viejo['fecha'] : $valorForm;
$vMotivo = is_scalar($viejo['motivo'] ?? null) ? (string)$viejo['motivo'] : '';

$diasSem = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
$msgPag  = is_scalar($_GET['msg'] ?? null) ? (string)$_GET['msg'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adelantos de sueldo — Ferreteria Aldrete</title>
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
    .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #f7f7f7; min-width: 0; }
    .topbar { background: #14ace7; color: white; padding: 0 20px; height: 52px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .topbar-left { display: flex; align-items: center; gap: 12px; }
    .topbar h2 { font-size: 15px; font-weight: 600; }
    .toggle-btn { background: none; border: none; color: white; cursor: pointer; font-size: 20px; padding: 4px 8px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; }
    .toggle-btn:hover { background: rgba(255,255,255,0.2); }
    .topbar-right { display: flex; align-items: center; gap: 14px; font-size: 13px; }
    .logout-btn { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); color: white; padding: 5px 14px; border-radius: 5px; cursor: pointer; font-size: 12px; }
    .logout-btn:hover { background: rgba(255,255,255,0.3); }

    .content { flex: 1; padding: 22px 24px; overflow-y: auto; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .msg-aviso { background: #fff9e6; color: #b7860b; border-left: 3px solid #f0b429; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }

    /* tarjetas de resumen */
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-bottom: 18px; }
    .stat { background: white; border-radius: 8px; padding: 14px 18px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 0.4px; }
    .stat h3 { font-size: 22px; font-weight: 700; color: #222; margin: 0; font-variant-numeric: tabular-nums; }
    .stat small { display: block; font-size: 11px; color: #aaa; margin-top: 3px; }

    /* panel con pestanas, filtros y tabla */
    .panel { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    .panel-cabecera { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0 16px; border-bottom: 1px solid #eee; flex-wrap: wrap; }
    .tabs { display: flex; gap: 4px; }
    .tab { padding: 15px 14px 12px; font-size: 13px; font-weight: 600; color: #888; text-decoration: none; border-bottom: 3px solid transparent; white-space: nowrap; }
    .tab:hover { color: #14ace7; }
    .tab.activa { color: #14ace7; border-bottom-color: #14ace7; }
    .btn-nuevo { display: inline-flex; align-items: center; gap: 6px; background: #14ace7; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 700; margin: 8px 0; }
    .btn-nuevo:hover { background: #119dd4; }

    .filtros { display: flex; flex-wrap: wrap; gap: 10px 12px; align-items: flex-end; padding: 14px 16px; background: #fafafa; border-bottom: 1px solid #eee; }
    .filtros .campo { display: flex; flex-direction: column; }
    .filtros label { font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; margin: 0 0 4px; }
    .filtros input, .filtros select { width: auto; margin: 0; padding: 7px 9px; font-size: 13px; border: 1px solid #ddd; border-radius: 6px; background: white; }
    .filtros input[type=text] { width: 210px; }
    .filtros input:focus, .filtros select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-filtrar:hover { background: #119dd4; }
    .btn-limpiar { color: #888; font-size: 12px; text-decoration: none; padding: 8px 4px; }
    .btn-limpiar:hover { color: #c0392b; }

    .tabla-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; min-width: 820px; }
    thead { background: #f9f9f9; }
    th { padding: 11px 14px; text-align: left; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; white-space: nowrap; }
    td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f2f2f2; vertical-align: middle; }
    tbody tr:hover td { background: #fafcfe; }
    th.num, td.num { text-align: right; font-variant-numeric: tabular-nums; }
    th a.th-orden { color: inherit; text-decoration: none; }
    th a.th-orden:hover, th a.th-orden.activo { color: #14ace7; }
    th a.th-orden .flecha { font-size: 9px; opacity: 0.55; }
    th a.th-orden.activo .flecha { opacity: 1; }
    .fecha-dia { font-size: 10px; color: #aaa; text-transform: uppercase; margin-left: 4px; }
    .motivo { color: #777; font-size: 12px; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    td.emp { font-weight: 600; white-space: nowrap; }
    .semana { color: #888; font-size: 12px; white-space: nowrap; }
    .monto { font-weight: 700; color: #222; white-space: nowrap; }
    .monto.pend { color: #b7860b; }
    .inactivo { font-size: 10px; color: #aaa; font-weight: 400; margin-left: 4px; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .badge-pendiente { background: #fff9e6; color: #b7860b; }
    .badge-liquidado { background: #f0fff0; color: #1e8449; }
    .btn-liquidar { background: white; border: 1px solid #cfe9d6; color: #1e8449; padding: 5px 11px; border-radius: 5px; cursor: pointer; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .btn-liquidar:hover { background: #f0fff0; }
    .btn-ver { color: #14ace7; font-size: 12px; font-weight: 600; text-decoration: none; white-space: nowrap; }
    .btn-ver:hover { text-decoration: underline; }
    tfoot td { background: #f9f9f9; font-weight: 700; color: #222; border-top: 2px solid #eee; border-bottom: none; }
    tfoot td.sub { font-weight: 400; font-size: 12px; color: #777; }
    .sin-resultados { padding: 38px 20px; text-align: center; color: #999; font-size: 13px; }
    .sin-resultados a { color: #14ace7; text-decoration: none; font-weight: 600; }

    .barra { width: 90px; height: 7px; background: #eee; border-radius: 99px; overflow: hidden; display: inline-block; vertical-align: middle; margin-right: 8px; }
    .barra span { display: block; height: 100%; background: #14ace7; }
    .barra.tope span { background: #e74c3c; }
    .barra.medio span { background: #f0b429; }

    .pie { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; padding: 12px 16px; font-size: 12px; color: #888; border-top: 1px solid #eee; }
    .paginas { display: flex; gap: 4px; flex-wrap: wrap; }
    .paginas a, .paginas span { display: inline-block; min-width: 30px; padding: 5px 9px; text-align: center; border: 1px solid #e5e5e5; border-radius: 5px; font-size: 12px; color: #555; text-decoration: none; background: white; }
    .paginas a:hover { border-color: #14ace7; color: #14ace7; }
    .paginas .actual { background: #14ace7; border-color: #14ace7; color: white; font-weight: 700; }
    .paginas .off { color: #ccc; }

    /* ventana "Nuevo adelanto" */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 500; align-items: center; justify-content: center; padding: 16px; }
    .modal-overlay.visible { display: flex; }
    .modal { background: white; border-radius: 10px; padding: 24px; width: 430px; max-width: 100%; max-height: 94vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,.25); }
    .modal h3 { font-size: 16px; font-weight: 700; color: #333; margin: 0 0 16px; }
    .modal label { display: block; font-size: 12px; color: #666; font-weight: 600; margin-bottom: 5px; }
    .modal input, .modal select, .modal textarea { width: 100%; padding: 9px 11px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; margin-bottom: 14px; font-family: inherit; }
    .modal input:focus, .modal select:focus, .modal textarea:focus { outline: none; border-color: #14ace7; }
    .ayuda-emp { font-size: 12px; color: #666; background: #f4f9fc; border: 1px solid #dcecf5; border-radius: 6px; padding: 8px 11px; margin: -6px 0 14px; display: none; line-height: 1.5; }
    .ayuda-emp.mala { background: #fdecea; border-color: #f3c9c4; color: #c0392b; }
    .modal-botones { display: flex; gap: 10px; justify-content: flex-end; margin-top: 4px; }
    .btn-cancelar { background: #f0f0f0; border: none; color: #555; padding: 10px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-cancelar:hover { background: #e6e6e6; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 700; }
    .btn-guardar:hover { background: #119dd4; }

    @media (max-width: 700px) {
        .content { padding: 14px; }
        .filtros input[type=text] { width: 100%; }
    }
</style>

<?php renderAdminSidebar('rrhh_adelantos'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()"><?= icono('menu') ?></button>
            <h2>Adelantos de sueldo</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <?php if ($msgPag === 'registrado'): ?>
            <div class="msg msg-exito">Adelanto registrado correctamente.</div>
        <?php elseif ($msgPag === 'liquidado'): ?>
            <div class="msg msg-exito">Adelanto marcado como liquidado.</div>
        <?php elseif ($msgPag === 'ya_liquidado'): ?>
            <div class="msg msg-aviso">Ese adelanto ya no estaba pendiente (alguien lo liquidó antes o ya se descontó en una nómina). No se cambió nada.</div>
        <?php elseif ($msgPag === 'error_token'): ?>
            <div class="errores">La accion no se pudo validar, intenta de nuevo.</div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat" style="border-top-color:#f0b429;">
                <p>Pendiente por descontar</p>
                <h3 style="color:#b7860b;">$<?= number_format($totalPendienteGlobal, 2) ?></h3>
                <small><?= $nPendientes ?> adelanto<?= $nPendientes != 1 ? 's' : '' ?> · <?= $nEmpConPendiente ?> empleado<?= $nEmpConPendiente != 1 ? 's' : '' ?></small>
            </div>
            <div class="stat">
                <p>Adelantos de esta semana</p>
                <h3>$<?= number_format($rowSem['total'], 2) ?></h3>
                <small><?= (int)$rowSem['n'] ?> registrado<?= (int)$rowSem['n'] != 1 ? 's' : '' ?> (<?= date('d/m', strtotime($desdePag)) ?> al <?= date('d/m', strtotime($sabadoPag)) ?>)</small>
            </div>
            <div class="stat" style="border-top-color:#27ae60;">
                <p>Liquidado (histórico)</p>
                <h3 style="color:#1e8449;">$<?= number_format($totalLiquidadoGlobal, 2) ?></h3>
                <small>ya descontado de sueldos</small>
            </div>
        </div>

        <div class="panel">
            <div class="panel-cabecera">
                <div class="tabs">
                    <a class="tab <?= $flt['vista'] === 'historial' ? 'activa' : '' ?>" href="adelantos.php">Historial de adelantos</a>
                    <a class="tab <?= $flt['vista'] === 'saldos' ? 'activa' : '' ?>" href="adelantos.php?vista=saldos">Saldo por empleado</a>
                </div>
                <button type="button" class="btn-nuevo" onclick="abrirModal()"><?= icono('plus', '', 16) ?> Nuevo adelanto</button>
            </div>

            <?php if ($flt['vista'] === 'saldos'): ?>
                <!-- ============ SALDO POR EMPLEADO ============ -->
                <div class="tabla-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Empleado</th>
                            <th class="num">Sueldo semanal</th>
                            <th class="num" title="Adelantos que todavía se le van a descontar de su sueldo">Pendiente</th>
                            <th title="Qué parte de su sueldo semanal ya tiene adelantada">% del sueldo</th>
                            <th class="num" title="Lo que todavía se le puede adelantar: el tope es una semana de sueldo.">Disponible</th>
                            <th class="num" title="Cuántos adelantos tiene pendientes de descontar">N.º pend.</th>
                            <th>Último</th>
                            <th class="num" title="Suma de todos los adelantos que ha recibido (entre paréntesis, cuántos)">Histórico</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($saldos) > 0): foreach ($saldos as $sd):
                        $sueldoS = floatval($sd['sueldo_semanal']); $pendS = floatval($sd['pendiente']);
                        $pct = $sueldoS > 0 ? ($pendS / $sueldoS) * 100 : 0;
                        $dispS = max(0, $sueldoS - $pendS);
                        $claseBarra = $pct >= 100 ? 'tope' : ($pct >= 60 ? 'medio' : '');
                    ?>
                        <tr>
                            <td class="emp"><?= htmlspecialchars($sd['nombre']) ?><?= $sd['activo'] ? '' : '<span class="inactivo">(inactivo)</span>' ?></td>
                            <td class="num">$<?= number_format($sueldoS, 2) ?></td>
                            <td class="num monto <?= $pendS > 0 ? 'pend' : '' ?>"><?= $pendS > 0 ? '$' . number_format($pendS, 2) : '—' ?></td>
                            <td>
                                <?php if ($pendS > 0): ?>
                                    <span class="barra <?= $claseBarra ?>"><span style="width:<?= min(100, round($pct)) ?>%;"></span></span><?= number_format($pct, 0) ?>%
                                <?php else: ?><span style="color:#ccc;">—</span><?php endif; ?>
                            </td>
                            <td class="num">
                                <?php if (!$sd['activo']): ?><span style="color:#ccc;">—</span>
                                <?php elseif ($dispS <= 0.001): ?><span style="color:#c0392b;font-weight:600;">$0.00 · tope alcanzado</span>
                                <?php else: ?>$<?= number_format($dispS, 2) ?><?php endif; ?>
                            </td>
                            <td class="num"><?= (int)$sd['n_pendientes'] ?></td>
                            <td><?= $sd['ultimo'] ? date('d/m/Y', strtotime($sd['ultimo'])) : '<span style="color:#ccc;">—</span>' ?></td>
                            <td class="num">$<?= number_format($sd['total_historico'], 2) ?> <span style="color:#bbb;font-size:11px;">(<?= (int)$sd['n_total'] ?>)</span></td>
                            <td><?php if ((int)$sd['n_total'] > 0): ?><a class="btn-ver" href="adelantos.php?empleado=<?= (int)$sd['empleado_id'] ?>">Ver →</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="9" class="sin-resultados">No hay empleados registrados.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if (count($saldos) > 1): ?>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td></td>
                            <td class="num">$<?= number_format($totalPendienteGlobal, 2) ?></td>
                            <td colspan="2"></td>
                            <td class="num"><?= $nPendientes ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
                </div>

            <?php else: ?>
                <!-- ============ HISTORIAL ============ -->
                <form method="GET" action="adelantos.php" class="filtros">
                    <input type="hidden" name="orden" value="<?= htmlspecialchars($flt['orden']) ?>">
                    <input type="hidden" name="dir" value="<?= htmlspecialchars($flt['dir']) ?>">
                    <div class="campo">
                        <label for="f_empleado">Empleado</label>
                        <select name="empleado" id="f_empleado" onchange="this.form.submit()">
                            <option value="0">Todos</option>
                            <?php foreach ($todosEmp as $te): ?>
                                <option value="<?= (int)$te['empleado_id'] ?>" <?= $flt['empleado'] === (int)$te['empleado_id'] ? 'selected' : '' ?>><?= htmlspecialchars($te['nombre']) ?><?= $te['activo'] ? '' : ' (inactivo)' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="campo">
                        <label for="f_estado">Estado</label>
                        <select name="estado" id="f_estado" onchange="this.form.submit()">
                            <option value="">Todos</option>
                            <option value="Pendiente" <?= $flt['estado'] === 'Pendiente' ? 'selected' : '' ?>>Pendiente</option>
                            <option value="Liquidado" <?= $flt['estado'] === 'Liquidado' ? 'selected' : '' ?>>Liquidado</option>
                        </select>
                    </div>
                    <div class="campo">
                        <label for="f_desde">Desde</label>
                        <input type="date" name="desde" id="f_desde" value="<?= htmlspecialchars($flt['desde']) ?>">
                    </div>
                    <div class="campo">
                        <label for="f_hasta">Hasta</label>
                        <input type="date" name="hasta" id="f_hasta" value="<?= htmlspecialchars($flt['hasta']) ?>">
                    </div>
                    <div class="campo">
                        <label for="f_q">Buscar</label>
                        <input type="text" name="q" id="f_q" value="<?= htmlspecialchars($flt['q']) ?>" placeholder="Empleado o motivo" maxlength="100">
                    </div>
                    <button type="submit" class="btn-filtrar">Filtrar</button>
                    <?php if ($hayFiltros): ?><a class="btn-limpiar" href="adelantos.php">Quitar filtros</a><?php endif; ?>
                </form>

                <div class="tabla-wrap">
                <table>
                    <thead>
                        <tr>
                            <th><?= adelantosLinkOrden($flt, 'fecha', 'Fecha') ?></th>
                            <th><?= adelantosLinkOrden($flt, 'empleado', 'Empleado') ?></th>
                            <th>Motivo</th>
                            <th title="Semana de nómina (lunes a sábado) a la que cuenta este adelanto">Semana de pago</th>
                            <th class="num"><?= adelantosLinkOrden($flt, 'monto', 'Monto') ?></th>
                            <th><?= adelantosLinkOrden($flt, 'estado', 'Estado') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($filas) > 0): foreach ($filas as $h): $esPend = $h['estado'] === 'Pendiente'; ?>
                        <tr>
                            <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($h['fecha'])) ?><span class="fecha-dia"><?= $diasSem[(int)date('w', strtotime($h['fecha']))] ?></span></td>
                            <td class="emp"><?= htmlspecialchars($h['empleado_nombre']) ?><?= $h['empleado_activo'] ? '' : '<span class="inactivo">(inactivo)</span>' ?></td>
                            <td><div class="motivo" title="<?= htmlspecialchars($h['motivo'] ?? '') ?>"><?= ($h['motivo'] ?? '') !== '' ? htmlspecialchars($h['motivo']) : '<span style="color:#ccc;">—</span>' ?></div></td>
                            <td class="semana"><?= htmlspecialchars(adelantosEtiquetaSemana($h['fecha'])) ?></td>
                            <td class="num monto <?= $esPend ? 'pend' : '' ?>">$<?= number_format($h['monto'], 2) ?></td>
                            <td><span class="badge <?= $esPend ? 'badge-pendiente' : 'badge-liquidado' ?>"><?= htmlspecialchars($h['estado']) ?></span></td>
                            <td style="text-align:right;">
                                <?php if ($esPend): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Marcar este adelanto como liquidado? Esto significa que ya se descontó del sueldo del empleado.')">
                                    <input type="hidden" name="liquidar_id" value="<?= (int)$h['adelanto_id'] ?>">
                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <?php foreach (adelantosParams($flt) as $pk => $pv): ?>
                                        <input type="hidden" name="<?= htmlspecialchars($pk) ?>" value="<?= htmlspecialchars((string)$pv) ?>">
                                    <?php endforeach; ?>
                                    <button type="submit" class="btn-liquidar">Marcar liquidado</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="sin-resultados">
                            <?php if ($hayFiltros): ?>No hay adelantos con esos filtros. <a href="adelantos.php">Quitar filtros</a>
                            <?php else: ?>Aun no se han registrado adelantos.<?php endif; ?>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($nFilas > 0): ?>
                    <tfoot>
                        <tr>
                            <td colspan="4"><?= $nFilas === 1 ? 'Total del adelanto' . ($hayFiltros ? ' filtrado' : '') : 'Total de los ' . $nFilas . ' adelantos' . ($hayFiltros ? ' filtrados' : '') ?></td>
                            <td class="num">$<?= number_format($totales['total'], 2) ?></td>
                            <td class="sub" colspan="2">Pendiente $<?= number_format($totales['pend'], 2) ?> · Liquidado $<?= number_format($totales['liq'], 2) ?></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
                </div>

                <?php if ($nFilas > 0): ?>
                <div class="pie">
                    <span>Mostrando <?= $offset + 1 ?>–<?= min($offset + $POR_PAG, $nFilas) ?> de <?= $nFilas ?></span>
                    <?php if ($totalPag > 1):
                        $pagActual = $flt['pag'];
                        $ini = max(1, $pagActual - 2); $fin = min($totalPag, $pagActual + 2);
                    ?>
                    <div class="paginas">
                        <?php if ($pagActual > 1): ?><a href="<?= htmlspecialchars(adelantosUrl($flt, ['pag' => $pagActual - 1])) ?>">‹ Anterior</a><?php else: ?><span class="off">‹ Anterior</span><?php endif; ?>
                        <?php if ($ini > 1): ?><a href="<?= htmlspecialchars(adelantosUrl($flt, ['pag' => 1])) ?>">1</a><?php if ($ini > 2): ?><span class="off">…</span><?php endif; ?><?php endif; ?>
                        <?php for ($i = $ini; $i <= $fin; $i++): ?>
                            <?php if ($i === $pagActual): ?><span class="actual"><?= $i ?></span><?php else: ?><a href="<?= htmlspecialchars(adelantosUrl($flt, ['pag' => $i])) ?>"><?= $i ?></a><?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($fin < $totalPag): ?><?php if ($fin < $totalPag - 1): ?><span class="off">…</span><?php endif; ?><a href="<?= htmlspecialchars(adelantosUrl($flt, ['pag' => $totalPag])) ?>"><?= $totalPag ?></a><?php endif; ?>
                        <?php if ($pagActual < $totalPag): ?><a href="<?= htmlspecialchars(adelantosUrl($flt, ['pag' => $pagActual + 1])) ?>">Siguiente ›</a><?php else: ?><span class="off">Siguiente ›</span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============ VENTANA: NUEVO ADELANTO ============ -->
<div class="modal-overlay" id="modalAdelanto">
    <div class="modal">
        <h3>Nuevo adelanto de sueldo</h3>
        <?php if (!empty($errores)): ?>
            <div class="errores">
                <ul>
                    <?php foreach ($errores as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (count($empleados) === 0): ?>
            <p style="font-size:13px;color:#888;margin-bottom:16px;">No hay empleados activos registrados.</p>
            <div class="modal-botones"><button type="button" class="btn-cancelar" onclick="cerrarModal()">Cerrar</button></div>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="registrar_adelanto" value="1">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <label for="empleado_id">Empleado</label>
            <select name="empleado_id" id="empleado_id" required onchange="mostrarAyuda()">
                <option value="">— Selecciona —</option>
                <?php foreach ($empleados as $e): ?>
                    <option value="<?= (int)$e['empleado_id'] ?>" <?= $vEmp === (int)$e['empleado_id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['nombre']) ?> — $<?= number_format($e['sueldo_semanal'], 2) ?>/sem</option>
                <?php endforeach; ?>
            </select>
            <div class="ayuda-emp" id="ayudaEmpleado"></div>

            <label for="monto">Monto del adelanto</label>
            <input type="number" name="monto" id="monto" min="0.01" step="0.01" placeholder="Ej. 500.00" value="<?= htmlspecialchars($vMonto) ?>" required oninput="mostrarAyuda()">

            <label for="fecha">Fecha <span style="font-weight:400;color:#aaa;">(dentro de la semana en curso)</span></label>
            <input type="date" name="fecha" id="fecha" value="<?= htmlspecialchars($vFecha) ?>" min="<?= $desdeForm ?>" max="<?= $sabadoForm ?>" required>

            <label for="motivo">Motivo (opcional)</label>
            <textarea name="motivo" id="motivo" rows="3" maxlength="255" placeholder="Ej. Emergencia familiar"><?= htmlspecialchars($vMotivo) ?></textarea>

            <div class="modal-botones">
                <button type="button" class="btn-cancelar" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn-guardar">Registrar adelanto</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

// Sueldo semanal y adelanto pendiente de cada empleado activo (para avisar antes de pasarse del tope de una semana de sueldo).
var EMP = <?= json_encode($mapaEmp, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function dinero(n) { return '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

function mostrarAyuda() {
    var sel = document.getElementById('empleado_id');
    var caja = document.getElementById('ayudaEmpleado');
    if (!sel || !caja) return;
    var d = EMP[sel.value];
    if (!d) { caja.style.display = 'none'; return; }
    var disponible = Math.max(0, d.sueldo - d.pendiente);
    var monto = parseFloat(document.getElementById('monto').value);
    var txt = 'Sueldo semanal ' + dinero(d.sueldo) + ' · Ya tiene pendiente ' + dinero(d.pendiente) + ' · <strong>Disponible ' + dinero(disponible) + '</strong>';
    var mala = false;
    if (!isNaN(monto) && monto > disponible + 0.004) {
        mala = true;
        txt += '<br>Ese monto pasa de lo disponible: el tope es una semana de sueldo entre todos sus adelantos pendientes.';
    }
    caja.innerHTML = txt;
    caja.className = 'ayuda-emp' + (mala ? ' mala' : '');
    caja.style.display = 'block';
}

function abrirModal() {
    document.getElementById('modalAdelanto').classList.add('visible');
    mostrarAyuda();
    var sel = document.getElementById('empleado_id');
    if (sel) sel.focus();
}
function cerrarModal() { document.getElementById('modalAdelanto').classList.remove('visible'); }

document.getElementById('modalAdelanto').addEventListener('mousedown', function (e) { if (e.target === this) cerrarModal(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrarModal(); });
<?php if (!empty($errores)): ?>
abrirModal();   // hubo un error al registrar: se reabre la ventana con lo que se habia capturado
<?php endif; ?>
</script>
<script src="../includes/seleccionar_cantidad.js"></script>
<script src="../includes/anti_doble_envio.js"></script>
</body>
</html>
