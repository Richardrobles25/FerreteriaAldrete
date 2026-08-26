<?php
// Helpers compartidos del modulo de Recursos Humanos (admin/empleados, vacaciones, formVacacion)

function calcVacacionesDisponibles(string $fechaIngreso): int {
    $hoy     = new DateTime();
    $ingreso = new DateTime($fechaIngreso);
    // [FIX-ALTO-G-08] DateTime::diff() siempre regresa una diferencia ABSOLUTA (positiva)
    // sin importar cual fecha es mas reciente — con una fecha_ingreso futura (dato corrupto
    // o error de captura), ->y salia positivo igual que con una fecha pasada real, dando
    // antiguedad y dias de vacaciones a alguien que ni siquiera ha ingresado todavia.
    if ($ingreso > $hoy) return 0;
    $anios   = (int)$hoy->diff($ingreso)->y;
    if ($anios < 1) return 0;
    return min($anios, 2) * 6;
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
