<?php
// Helpers compartidos del modulo de Recursos Humanos (admin/empleados, vacaciones, formVacacion)

// [FIX-RH-TEXTO-NO-COMPATIBLE] La conexion a MySQL usa charset utf8 (3 bytes): un emoji u otro caracter de
// 4 bytes en notas/razon/nombre/motivo hace fallar el INSERT en modo estricto con "Incorrect string value",
// y el aviso generico ("verifica que el empleado siga existiendo") mandaba a buscar el problema donde no es.
function textoNoCompatibleBD(PDOException $e): bool {
    return stripos($e->getMessage(), 'Incorrect string value') !== false;
}

// Un empleado ya tiene derecho a vacaciones a partir de su primer aniversario de ingreso.
// [FIX-VACACIONES-SIMULACION-DERECHO] Antes esta funcion no aceptaba una fecha de corte y
// siempre comparaba contra el reloj real del sistema -- vacaciones.php le pasaba $simFecha a
// calcSaldoVacaciones() (que si la acepta) pero NUNCA a esta funcion, asi que
// "?simular_fecha=" (pensado para verificar en vivo que el tope/acreditacion funciona con el
// paso del tiempo) jamas mostraba a un empleado ganando su primer aniversario: $tieneDerecho
// seguia evaluando la fecha real de hoy y, si aun era false, forzaba $diasRest=0 sin llegar a
// invocar calcSaldoVacaciones() con la fecha simulada. Ahora acepta el mismo $hastaFecha
// opcional que calcSaldoVacaciones(), con el mismo default (null = hoy real).
function tieneDerechoVacaciones(string $fechaIngreso, ?string $hastaFecha = null): bool {
    $hasta   = $hastaFecha ? new DateTime($hastaFecha) : new DateTime();
    $ingreso = new DateTime($fechaIngreso);
    if ($ingreso > $hasta) return false;
    return (int)$hasta->diff($ingreso)->y >= 1;
}

// [FIX-NEGOCIO-RH-01] Politica real de la empresa: se acreditan 6 dias por cada aniversario
// de ingreso, pero el saldo NUNCA se acumula mas alla de 12 — si el empleado no gasto lo que
// tenia, el aniversario siguiente no le suma otros 6 sobre eso, se queda topado en 12 hasta
// que use dias y libere espacio. Antes (calcVacacionesDisponibles) se recalculaba cada año
// calendario desde cero como min(anios,2)*6 comparado solo contra lo tomado ESE año — un
// empleado con antiguedad de 3+ años que no agoto su saldo el año pasado recibia otros 12
// dias frescos en enero, en vez de conservar el saldo real que traia. Ahora se simula la
// linea de tiempo completa (aniversarios que acreditan +6 topado en 12, e historial real de
// vacaciones que descuenta) para obtener el saldo verdadero a una fecha de corte.
// [FIX-VACACION-SALDO-POSTERIORES] $agregar permite simular un periodo hipotetico
// (['fecha_inicio' => 'Y-m-d', 'dias_tomados' => n]) que todavia no esta en la tabla, para
// poder revisar si guardarlo dejaria sin saldo a periodos POSTERIORES ya registrados.
function calcSaldoVacaciones(PDO $pdo, int $empleadoId, string $fechaIngreso, ?string $hastaFecha = null, ?int $excluirVacacionId = null, ?array $agregar = null): int {
    $hasta   = $hastaFecha ? new DateTime($hastaFecha) : new DateTime();
    $ingreso = new DateTime($fechaIngreso);
    if ($ingreso > $hasta) return 0;

    // [FEATURE-SALDO-INICIAL-VACACIONES] Un empleado que ya trabajaba antes de que existiera
    // el sistema puede traer dias acumulados (o ya gastados) que el sistema nunca vio -- ni sus
    // aniversarios pasados ni las vacaciones que ya tomo antes quedaron registrados aqui. Si el
    // admin capturo un ajuste manual (empleados.saldo_vacaciones_ajuste/_fecha, normalmente al
    // darlo de alta) se usa ese numero como punto de partida real en vez de simular desde 0 en
    // fecha_ingreso -- solo se simulan aniversarios y vacaciones ESTRICTAMENTE POSTERIORES a esa
    // fecha de ajuste (todo lo anterior ya esta neteado en el numero capturado a mano). Si no
    // hay ajuste (el caso normal, empleados nuevos que arrancan de cero), el comportamiento es
    // identico al de siempre.
    $saldoInicial = 0;
    $desde        = $ingreso;
    $stmtAjuste = $pdo->prepare("SELECT saldo_vacaciones_ajuste, saldo_vacaciones_ajuste_fecha FROM empleados WHERE empleado_id = ?");
    $stmtAjuste->execute([$empleadoId]);
    $ajusteRow = $stmtAjuste->fetch(PDO::FETCH_ASSOC);
    if ($ajusteRow && $ajusteRow['saldo_vacaciones_ajuste'] !== null && $ajusteRow['saldo_vacaciones_ajuste_fecha']) {
        $fechaAjuste = new DateTime($ajusteRow['saldo_vacaciones_ajuste_fecha']);
        // Si la fecha de ajuste es invalida (posterior al corte que se esta consultando, o
        // anterior a la fecha de ingreso real por un error de captura) se ignora el ajuste y
        // se cae al comportamiento normal, en vez de producir un saldo negativo o adelantado.
        if ($fechaAjuste <= $hasta && $fechaAjuste >= $ingreso) {
            $saldoInicial = max(0, min(12, (int)$ajusteRow['saldo_vacaciones_ajuste']));
            $desde        = $fechaAjuste;
        }
    }

    $eventos = [];

    // Aniversarios de acreditacion (+6, tope 12) hasta la fecha de corte, solo los posteriores
    // al punto de partida (fecha_ingreso normalmente, o la fecha de ajuste si aplica).
    $n = 1;
    while (true) {
        $aniv = (clone $ingreso)->modify("+{$n} year");
        if ($aniv > $hasta) break;
        if ($aniv > $desde) $eventos[] = ['fecha' => $aniv, 'dias' => 6];
        $n++;
    }
    if (empty($eventos) && $saldoInicial <= 0) return 0; // aun no cumple su primer aniversario (y sin ajuste manual)

    // Vacaciones ya tomadas (no rechazadas) hasta la fecha de corte, que descuentan saldo
    $sql = "SELECT fecha_inicio, dias_tomados FROM vacaciones
            WHERE empleado_id = ? AND estado != 'Rechazado' AND fecha_inicio <= ? AND fecha_inicio > ?";
    $params = [$empleadoId, $hasta->format('Y-m-d'), $desde->format('Y-m-d')];
    if ($excluirVacacionId) {
        $sql .= " AND vacacion_id != ?";
        $params[] = $excluirVacacionId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $eventos[] = ['fecha' => new DateTime($u['fecha_inicio']), 'dias' => -(int)$u['dias_tomados']];
    }
    if ($agregar) {
        $fAgr = new DateTime($agregar['fecha_inicio']);
        if ($fAgr <= $hasta && $fAgr > $desde) $eventos[] = ['fecha' => $fAgr, 'dias' => -(int)$agregar['dias_tomados']];
    }

    usort($eventos, fn($a, $b) => $a['fecha'] <=> $b['fecha']);

    $saldo = $saldoInicial;
    foreach ($eventos as $ev) {
        $saldo = $ev['dias'] > 0 ? min(12, $saldo + $ev['dias']) : max(0, $saldo + $ev['dias']);
    }
    return $saldo;
}

// [FEATURE-HORAS-SEMANALES 2026-09-30] Reemplaza a la antigua horasEsperadasDia(), que fijaba
// el domingo en 0 horas esperadas para todos los empleados -- cualquier hora trabajada ese dia
// se contaba de inmediato como extra, sin importar cuanto hubiera trabajado el resto de la
// semana. El usuario pidio manejarlo SOLO por el total semanal: domingo deja de tener cualquier
// trato especial, es un dia mas dentro de su semana (domingo a sabado, el mismo agrupamiento
// que ya usaba lunesDeLaSemana()).
//
// Cada registro de asistencia ahora guarda "horas_trabajadas" crudas (sin comparar contra
// nada). Esta funcion recorre TODOS los registros de la semana de un empleado en orden
// cronologico y le asigna a cada uno el excedente que su acumulado corriente le hizo cruzar por
// encima de horas_esperadas_semana -- por eso "horas_extra" en Asistencia empieza en 0 cada
// semana y solo sube una vez que el acumulado ya paso el limite (visible dia por dia segun se
// va capturando asistencia). Se debe volver a llamar cada vez que se guarda, edita o borra
// CUALQUIER registro de esa semana: cambiar un dia recorre el acumulado de todos los dias
// posteriores de la misma semana.
function recalcularHorasExtraSemana(PDO $pdo, int $empleadoId, string $fecha, float $horasEsperadasSemana): void {
    $lunes           = lunesDeLaSemana($fecha);
    $domingoAnterior = (new DateTime($lunes))->modify('-1 day')->format('Y-m-d');
    $sabado          = (new DateTime($lunes))->modify('+5 days')->format('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT asistencia_id, fecha, horas_trabajadas
        FROM asistencia
        WHERE empleado_id = ? AND fecha BETWEEN ? AND ?
        ORDER BY fecha ASC, asistencia_id ASC
    ");
    $stmt->execute([$empleadoId, $domingoAnterior, $sabado]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $entradas = [];
    foreach ($filas as $r) $entradas[] = ['fecha' => $r['fecha'], 'id' => (int)$r['asistencia_id'], 'h' => floatval($r['horas_trabajadas'])];

    // [FEATURE-VACACIONES-AUTOMATICAS 2026-10-26] Los dias de vacaciones aprobadas SIN registro de asistencia se pagan solos como
    // jornada normal (ver vacacionesCreditoSemana): tambien entran al acumulado de la semana, en su fecha, para que las horas extra
    // por dia que se ven en Asistencia cuadren con las que cobra Semana laboral. No tienen fila que actualizar.
    $rangos = vacacionesRangosAprobadas($pdo, $empleadoId, $lunes, $sabado);
    if ($rangos) {
        $stmtHd = $pdo->prepare("SELECT horas_por_dia FROM empleados WHERE empleado_id = ?");
        $stmtHd->execute([$empleadoId]);
        $hd = floatval($stmtHd->fetchColumn() ?: 9);
        $conRegistro = [];
        foreach ($filas as $r) $conRegistro[$r['fecha']] = true;
        foreach (vacacionesCreditoSemana($rangos, $lunes, $sabado, $conRegistro, $horasEsperadasSemana, $hd) as $fv => $hv) {
            $entradas[] = ['fecha' => $fv, 'id' => null, 'h' => $hv];
        }
        usort($entradas, function ($a, $b) {
            return [$a['fecha'], $a['id'] ?? PHP_INT_MAX] <=> [$b['fecha'], $b['id'] ?? PHP_INT_MAX];
        });
    }

    $acumulado = 0.0;
    $stmtUpd   = $pdo->prepare("UPDATE asistencia SET horas_extra = ? WHERE asistencia_id = ?");
    foreach ($entradas as $r) {
        $acumuladoAntes = $acumulado;
        $acumulado     += $r['h'];
        $extraDelDia    = round(
            max(0.0, $acumulado - $horasEsperadasSemana) - max(0.0, $acumuladoAntes - $horasEsperadasSemana),
            2
        );
        if ($r['id'] !== null) $stmtUpd->execute([$extraDelDia, $r['id']]);
    }
}

// [FEATURE-VACACIONES-AUTOMATICAS 2026-10-26] Las vacaciones son semanas (o dias) PAGADOS. Antes habia que capturar a mano cada dia
// como "Asistencia normal" (y el sabado con horario, o se pagaban horas de mas); sin eso la semana quedaba en "Faltan N dias".
// Ahora todo dia de lunes a sabado dentro de unas vacaciones APROBADAS que no tenga registro de asistencia cuenta como jornada
// normal: lunes a viernes = horas_por_dia del empleado y sabado = lo que le falta a su semana (horas esperadas - 5 dias), asi una
// semana completa de vacaciones suma exactamente sus horas esperadas (sin deduccion ni bono) y una parcial se completa con los dias
// trabajados. El domingo no cuenta (igual que los dias de vacaciones). Si el dia SI tiene registro (trabajo o falta), manda el registro.
// $rangos = [[fecha_inicio, fecha_fin], ...] aprobados; $hasta = ultimo dia a considerar (el corte de la semana en curso);
// $fechasConRegistro = [fecha => true]. Devuelve [fecha => horas]. El total nunca pasa de las horas esperadas de la semana.
function vacacionesCreditoSemana(array $rangos, string $lunes, string $hasta, array $fechasConRegistro, float $horasEsperadasSemana, float $horasPorDia): array {
    $credito = [];
    if (!$rangos || $hasta === '') return $credito;
    $horasSabado = max(0.0, $horasEsperadasSemana - 5 * $horasPorDia);
    $restante    = max(0.0, $horasEsperadasSemana);
    $dt = new DateTime($lunes);
    for ($k = 0; $k < 6; $k++, $dt->modify('+1 day')) {
        $d = $dt->format('Y-m-d');
        if ($d > $hasta) break;
        if (isset($fechasConRegistro[$d])) continue;
        $enVacaciones = false;
        foreach ($rangos as $rg) {
            if ($d >= $rg[0] && $d <= $rg[1]) { $enVacaciones = true; break; }
        }
        if (!$enVacaciones) continue;
        $h = min($k === 5 ? $horasSabado : $horasPorDia, $restante);
        $restante -= $h;
        $credito[$d] = round($h, 2);
    }
    return $credito;
}

// Vacaciones APROBADAS de un empleado que tocan el rango [desde, hasta]: [[fecha_inicio, fecha_fin], ...]
function vacacionesRangosAprobadas(PDO $pdo, int $empleadoId, string $desde, string $hasta): array {
    $st = $pdo->prepare("SELECT fecha_inicio, fecha_fin FROM vacaciones WHERE empleado_id = ? AND estado = 'Aprobado' AND fecha_inicio <= ? AND fecha_fin >= ?");
    $st->execute([$empleadoId, $hasta, $desde]);
    return array_map(function ($r) { return [$r['fecha_inicio'], $r['fecha_fin']]; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

// Al aprobar, mover o borrar unas vacaciones cambia el acumulado semanal de las semanas que tocan: se recalculan las horas extra por
// dia de Asistencia (informativas) de cada una, menos las que ya estan pagadas (su pago ya esta congelado).
function recalcularExtrasPorVacacion(PDO $pdo, int $empleadoId, string $ini, string $fin): void {
    $st = $pdo->prepare("SELECT horas_esperadas_semana FROM empleados WHERE empleado_id = ?");
    $st->execute([$empleadoId]);
    $hs = floatval($st->fetchColumn() ?: 51);
    $stPago = $pdo->prepare("SELECT COUNT(*) FROM pagos_nomina WHERE empleado_id = ? AND semana_inicio = ?");
    $lun    = new DateTime(lunesDeLaSemana($ini));
    $limite = new DateTime($fin);
    for ($i = 0; $i < 60 && $lun <= $limite; $i++, $lun->modify('+7 days')) {
        $l = $lun->format('Y-m-d');
        $stPago->execute([$empleadoId, $l]);
        if (!$stPago->fetchColumn()) recalcularHorasExtraSemana($pdo, $empleadoId, $l, $hs);
    }
}

// [FIX-ADELANTO-LIMITE-SEMANA] Devuelve el lunes de la semana de nomina (lunes-sabado) a la
// que pertenece $fecha. Domingo no es parte de ningun ciclo lunes-sabado, asi que se adelanta
// al lunes SIGUIENTE (igual que semanaLaboral.php ya hacia para su vista por defecto). Antes
// adelantos.php calculaba esto por separado con strtotime('monday this week'), que en domingo
// regresa el lunes de la semana que ACABA de terminar en vez de avanzar -- un adelanto
// registrado en domingo se etiquetaba (y su tope de "una semana de sueldo" se evaluaba) contra
// la semana vieja en vez de la que empieza al dia siguiente, desincronizado de como
// semanaLaboral.php interpreta ese mismo domingo. Se centraliza aqui para que ambos archivos
// usen exactamente el mismo criterio, sin volver a poder desincronizarse.
function lunesDeLaSemana(string $fecha): string {
    $dt  = new DateTime($fecha);
    $dow = (int)$dt->format('N');
    if ($dow === 7) $dt->modify('+1 day');
    elseif ($dow !== 1) $dt->modify('last monday');
    return $dt->format('Y-m-d');
}

function contarDiasVacacion(string $fechaInicio, string $fechaFin): int {
    $dt  = new DateTime($fechaInicio);
    $fin = new DateTime($fechaFin);
    $dias = 0;
    while ($dt <= $fin) {
        if ((int)$dt->format('N') !== 7) $dias++;
        $dt->modify('+1 day');
    }
    return $dias;
}

function calcAntiguedad(string $fechaIngreso): string {
    $hoy     = new DateTime();
    $ingreso = new DateTime($fechaIngreso);
    // [FIX-ALTO-G-08] Mismo problema de diff() absoluto que calcVacacionesDisponibles.
    if ($ingreso > $hoy) return 'Fecha de ingreso futura';
    $diff    = $hoy->diff($ingreso);
    if ($diff->y >= 1) return $diff->y . ' año' . ($diff->y > 1 ? 's' : '');
    if ($diff->m >= 1) return $diff->m . ' mes' . ($diff->m > 1 ? 'es' : '');
    return $diff->d . ' día' . ($diff->d != 1 ? 's' : '');
}
