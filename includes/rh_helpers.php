<?php
// Helpers compartidos del modulo de Recursos Humanos (admin/empleados, vacaciones, formVacacion)

// Un empleado ya tiene derecho a vacaciones a partir de su primer aniversario de ingreso.
function tieneDerechoVacaciones(string $fechaIngreso): bool {
    $hoy     = new DateTime();
    $ingreso = new DateTime($fechaIngreso);
    if ($ingreso > $hoy) return false;
    return (int)$hoy->diff($ingreso)->y >= 1;
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
function calcSaldoVacaciones(PDO $pdo, int $empleadoId, string $fechaIngreso, ?string $hastaFecha = null, ?int $excluirVacacionId = null): int {
    $hasta   = $hastaFecha ? new DateTime($hastaFecha) : new DateTime();
    $ingreso = new DateTime($fechaIngreso);
    if ($ingreso > $hasta) return 0;

    $eventos = [];

    // Aniversarios de acreditacion (+6, tope 12) hasta la fecha de corte
    $n = 1;
    while (true) {
        $aniv = (clone $ingreso)->modify("+{$n} year");
        if ($aniv > $hasta) break;
        $eventos[] = ['fecha' => $aniv, 'dias' => 6];
        $n++;
    }
    if (empty($eventos)) return 0; // aun no cumple su primer aniversario

    // Vacaciones ya tomadas (no rechazadas) hasta la fecha de corte, que descuentan saldo
    $sql = "SELECT fecha_inicio, dias_tomados FROM vacaciones
            WHERE empleado_id = ? AND estado != 'Rechazado' AND fecha_inicio <= ?";
    $params = [$empleadoId, $hasta->format('Y-m-d')];
    if ($excluirVacacionId) {
        $sql .= " AND vacacion_id != ?";
        $params[] = $excluirVacacionId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $eventos[] = ['fecha' => new DateTime($u['fecha_inicio']), 'dias' => -(int)$u['dias_tomados']];
    }

    usort($eventos, fn($a, $b) => $a['fecha'] <=> $b['fecha']);

    $saldo = 0;
    foreach ($eventos as $ev) {
        $saldo = $ev['dias'] > 0 ? min(12, $saldo + $ev['dias']) : max(0, $saldo + $ev['dias']);
    }
    return $saldo;
}

// Cuenta los dias del periodo excluyendo domingos (no son dia laboral)
// [FIX-MEDIO-G-25] La regla de "cuantas horas se esperan segun el dia de la semana" (9h
// entre semana, 6h sabado, 0h domingo) estaba repetida por separado en formAsistencia.php
// (PHP del servidor), formAsistencia.php (JS del navegador) y semanaLaboral.php (CASE SQL) —
// tres copias independientes que un cambio futuro (ej. jornada de sabado a 5h) tendria que
// actualizar a mano en las tres, con alto riesgo de dejarlas desincronizadas otra vez. Se deja
// UNA sola fuente de verdad en PHP: formAsistencia.php y semanaLaboral.php la consumen
// directamente, y se le pasa al navegador via json_encode() para que el JS de formAsistencia.php
// tambien la lea en vez de tener su propia copia hardcodeada.
function jornadaConfig(): array {
    return ['normal' => 9.0, 'sabado' => 6.0, 'domingo' => 0.0];
}

function horasEsperadasDia(string $fecha): float {
    $cfg = jornadaConfig();
    $diaSemana = intval(date('N', strtotime($fecha))); // 1=Lun ... 6=Sab, 7=Dom
    if ($diaSemana === 7) return $cfg['domingo'];
    if ($diaSemana === 6) return $cfg['sabado'];
    return $cfg['normal'];
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
