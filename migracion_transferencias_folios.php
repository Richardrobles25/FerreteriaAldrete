<?php
// Le pone FOLIO a las transferencias que ya existian (antes de la funcion de folios).
//
// Uso (despues de correr migracion_transferencias_folio_recepcion.sql):
//   php migracion_transferencias_folios.php            -> aplica los folios
//   php migracion_transferencias_folios.php --simular  -> solo muestra lo que haria, sin escribir nada
//
// Un "pedido" son las filas (productos) creadas en la misma solicitud: mismo origen, destino y solicitante, y
// creadas con segundos de diferencia (misma regla con la que el sistema ya los agrupa en pantalla). A cada pedido
// sin folio se le asigna el siguiente numero de SU mes (0001, 0002...), en orden cronologico. Es seguro correrlo
// mas de una vez: solo toca filas que todavia no tienen folio y respeta los folios que ya existan.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo se puede correr desde la linea de comandos.'); }   // nunca desde el navegador
chdir(__DIR__);
require 'config/database.php';
$simular = in_array('--simular', $argv ?? [], true);
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$cols = array_column($pdo->query("SHOW COLUMNS FROM transferencias")->fetchAll(), 'Field');
foreach (['folio', 'folio_periodo'] as $c) {
    if (!in_array($c, $cols, true)) { fwrite(STDERR, "Falta la columna $c: corre primero migracion_transferencias_folio_recepcion.sql\n"); exit(1); }
}

$filas = $pdo->query("SELECT transferencias_id, sucursal_origen_id, sucursal_destino_id, usuario_solicita_id, created_at, folio_periodo FROM transferencias WHERE folio IS NULL ORDER BY created_at ASC, transferencias_id ASC")->fetchAll();
if (!$filas) { echo "No hay transferencias sin folio. Nada que hacer.\n"; exit(0); }

// agrupar en pedidos (misma regla de tolerancia de 2 s que transfAgruparPedidos)
$pedidos = [];
foreach ($filas as $f) {
    $ts = strtotime($f['created_at']);
    $colocado = false;
    foreach ($pedidos as &$g) {
        if ($g['o'] == $f['sucursal_origen_id'] && $g['d'] == $f['sucursal_destino_id'] && $g['u'] == $f['usuario_solicita_id'] && abs($g['ts'] - $ts) <= 2) {
            $g['ids'][] = (int)$f['transferencias_id']; $colocado = true; break;
        }
    }
    unset($g);
    if (!$colocado) $pedidos[] = ['o' => $f['sucursal_origen_id'], 'd' => $f['sucursal_destino_id'], 'u' => $f['usuario_solicita_id'], 'ts' => $ts, 'periodo' => (int)$f['folio_periodo'], 'ids' => [(int)$f['transferencias_id']]];
}

// ultimo folio ya usado en cada mes (por si ya hay pedidos nuevos con folio)
$ultimo = [];
foreach ($pdo->query("SELECT folio_periodo, MAX(CAST(folio AS UNSIGNED)) AS m FROM transferencias WHERE folio IS NOT NULL GROUP BY folio_periodo")->fetchAll() as $r) $ultimo[(int)$r['folio_periodo']] = (int)$r['m'];

$upd = $pdo->prepare("UPDATE transferencias SET folio = ? WHERE transferencias_id = ? AND folio IS NULL");
if (!$simular) $pdo->beginTransaction();
$n = 0; $filasTocadas = 0;
foreach ($pedidos as $g) {
    $ultimo[$g['periodo']] = ($ultimo[$g['periodo']] ?? 0) + 1;
    $folio = str_pad((string)$ultimo[$g['periodo']], 4, '0', STR_PAD_LEFT);
    if ($simular) { echo "periodo {$g['periodo']}  folio $folio  <- filas " . implode(',', $g['ids']) . "\n"; }
    else { foreach ($g['ids'] as $id) { $upd->execute([$folio, $id]); $filasTocadas += $upd->rowCount(); } }
    $n++;
}
if (!$simular) $pdo->commit();
echo ($simular ? "SIMULACION: " : "LISTO: ") . "$n pedido(s) recibieron folio" . ($simular ? '' : " ($filasTocadas fila(s) actualizadas)") . ".\n";
