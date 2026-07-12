<?php
// Helpers compartidos del modulo de Recursos Humanos (admin/empleados, vacaciones, formVacacion)

function calcVacacionesDisponibles(string $fechaIngreso): int {
    $hoy     = new DateTime();
    $ingreso = new DateTime($fechaIngreso);
    $anios   = (int)$hoy->diff($ingreso)->y;
    if ($anios < 1) return 0;
    return min($anios, 2) * 6;
}

// Cuenta los dias del periodo excluyendo domingos (no son dia laboral)
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
    $diff    = $hoy->diff($ingreso);
    if ($diff->y >= 1) return $diff->y . ' año' . ($diff->y > 1 ? 's' : '');
    if ($diff->m >= 1) return $diff->m . ' mes' . ($diff->m > 1 ? 'es' : '');
    return $diff->d . ' día' . ($diff->d != 1 ? 's' : '');
}
