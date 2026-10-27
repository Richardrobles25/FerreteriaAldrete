<?php
/**
 * [FEATURE-VOLVER-TRAS-CAJA 2026-10-21] Regresar al modulo en el que se estaba despues de abrir la caja.
 *
 * Antes: un modulo que necesita caja abierta (Nueva venta, Abonos, Devoluciones...) mandaba a "Abrir caja" y, al abrirla,
 * el sistema dejaba al usuario en el Inicio; habia que volver a buscar el modulo a mano.
 *
 * Ahora: el modulo que rebota anota (en la sesion) cual era, y "Abrir caja" lo usa para regresar ahi al terminar.
 *  - El nombre del modulo lo toma el SERVIDOR (SCRIPT_NAME), nunca de la URL ni de un formulario, y "Abrir caja" solo
 *    acepta los de su propia lista blanca: no hay forma de usarlo para saltar a otra direccion.
 *  - Caduca a los 10 minutos y se borra al usarlo o al entrar a "Abrir caja" desde el menu (sin ?msg=sinCaja).
 *  - No toca nada de la caja: el INSERT, el turno y los candados de abrirCaja quedan exactamente igual.
 */

const VTC_SEGUNDOS = 600;

// Llamar justo antes de mandar a "Abrir caja" por falta de caja.
function recordarModuloSinCaja(): void
{
    $m = basename((string)parse_url((string)($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH));
    if (!preg_match('/^[A-Za-z0-9_]+\.php$/', $m)) return;
    // Algunas paginas cierran la sesion al empezar (session_write_close) para no bloquear peticiones: se reabre solo un instante.
    $reabrir = (session_status() !== PHP_SESSION_ACTIVE);
    if ($reabrir) {
        if (headers_sent()) return;
        @session_start();
    }
    $_SESSION['volver_tras_caja'] = ['m' => $m, 't' => time()];
    if ($reabrir) session_write_close();
}

// Devuelve el modulo anotado si sigue vigente y esta en la lista blanca de la pagina que pregunta; si no, ''.
function moduloDeRegresoTrasCaja(array $permitidos): string
{
    $v = $_SESSION['volver_tras_caja'] ?? null;
    if (!is_array($v) || !isset($v['m'], $v['t']) || !is_string($v['m'])) return '';
    if (time() - intval($v['t']) > VTC_SEGUNDOS) return '';
    return in_array($v['m'], $permitidos, true) ? $v['m'] : '';
}

function olvidarModuloDeRegreso(): void
{
    unset($_SESSION['volver_tras_caja']);
}
