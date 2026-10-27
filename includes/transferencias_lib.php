<?php
// Logica compartida de Transferencias entre sucursales.
// La usan admin/inventario_transferencias.php y cajeroInventario/transferencias.php (mismo comportamiento en los dos roles).
//
// Modelo: cada producto de un pedido es UNA fila en `transferencias`. Todas las filas de un pedido comparten FOLIO
// (NNNN, reinicia cada mes, igual que el folio de ventas) y `folio_periodo` (anio*100+mes). Un pedido nace de dos formas:
//   - 'Solicitud': la sucursal destino lo pide, el origen lo acepta/modifica, lo envia, el destino confirma la recepcion.
//   - 'Directo'  : el origen lo arma y lo manda sin autorizacion del destino. Nace "Aprobada" (en preparacion, sin mover stock);
//                  al marcarlo enviado pasa a "En tránsito" y descuenta el origen; el destino solo confirma la recepcion.
// Al confirmar la recepcion el destino captura por producto: recibido + merma + no enviado = enviado.
//   recibido y merma SUMAN al stock del destino (la merma NO es una salida automatica: el destino la da de baja despues
//   desde Salidas); lo no enviado REGRESA al stock del origen.

// Carga la fila pedida y todas las de su mismo pedido (mismo folio; si la fila no tiene folio, el criterio antiguo:
// mismo origen, destino, solicitante y momento de creacion con tolerancia de 2 s).
function transfCargarPedido(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ?");
    $st->execute([$id]);
    $base = $st->fetch(PDO::FETCH_ASSOC);
    if (!$base) return null;
    $sel = "SELECT t.*, p.nombre_producto, p.codigo, p.tipo_venta, p.unidad_medida
            FROM transferencias t
            JOIN productos p ON p.producto_id = t.producto_id";
    if ($base['folio'] !== null && $base['folio'] !== '') {
        $st = $pdo->prepare("$sel
            WHERE t.folio_periodo = ? AND t.folio = ? AND t.sucursal_origen_id = ? AND t.sucursal_destino_id = ?
            ORDER BY t.transferencias_id ASC");
        $st->execute([$base['folio_periodo'], $base['folio'], $base['sucursal_origen_id'], $base['sucursal_destino_id']]);
    } else {
        $st = $pdo->prepare("$sel
            WHERE t.folio IS NULL AND t.sucursal_origen_id = ? AND t.sucursal_destino_id = ? AND t.usuario_solicita_id = ?
              AND t.created_at BETWEEN DATE_SUB(?, INTERVAL 2 SECOND) AND DATE_ADD(?, INTERVAL 2 SECOND)
            ORDER BY t.transferencias_id ASC");
        $st->execute([$base['sucursal_origen_id'], $base['sucursal_destino_id'], $base['usuario_solicita_id'], $base['created_at'], $base['created_at']]);
    }
    return ['base' => $base, 'filas' => $st->fetchAll(PDO::FETCH_ASSOC)];
}

// Un pedido (todas las filas de una misma solicitud) se muestra y se opera como UN solo carrito. Agrupa las filas del
// listado por folio (mismo folio_periodo + folio + origen + destino); las filas sin folio, por el criterio antiguo.
function transfAgruparPedidos(array $filas): array {
    $grupos = [];
    foreach ($filas as $f) {
        $ts = strtotime($f['created_at']);
        $tieneFolio = isset($f['folio']) && $f['folio'] !== null && $f['folio'] !== '';
        $colocado = false;
        foreach ($grupos as &$g) {
            if ($g['origen'] != $f['sucursal_origen_id'] || $g['destino'] != $f['sucursal_destino_id']) continue;
            if ($tieneFolio) {
                $mismo = $g['folio'] !== null && $g['folio'] === (string)$f['folio'] && $g['periodo'] == $f['folio_periodo'];
            } else {
                $mismo = $g['folio'] === null && $g['solicitante'] == $f['usuario_solicita_id'] && abs($g['ts'] - $ts) <= 2;
            }
            if ($mismo) { $g['filas'][] = $f; $colocado = true; break; }
        }
        unset($g);
        if (!$colocado) {
            $grupos[] = ['origen' => $f['sucursal_origen_id'], 'destino' => $f['sucursal_destino_id'],
                         'solicitante' => $f['usuario_solicita_id'], 'ts' => $ts,
                         'folio' => $tieneFolio ? (string)$f['folio'] : null,
                         'periodo' => $tieneFolio ? $f['folio_periodo'] : null,
                         'filas' => [$f]];
        }
    }
    foreach ($grupos as &$g) {
        usort($g['filas'], fn($a, $b) => intval($a['transferencias_id']) <=> intval($b['transferencias_id']));
    }
    unset($g);
    return $grupos;
}

// Cantidad sin ceros de relleno: 2.500 -> "2.5", 3.000 -> "3".
function transfFmtCant($c): string {
    $s = rtrim(rtrim(number_format((float)$c, 3, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

// ── Folio (misma mecanica que ventas: secuencial mensual NNNN con candado para que dos pedidos simultaneos no repitan) ──
function transfTomarCandadoFolio(PDO $pdo, string $ts): ?string {
    $nombre = 'folio_transferencias_' . date('Y_m', strtotime($ts));
    $ok = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($nombre) . ", 10)")->fetchColumn();
    return $ok ? $nombre : null;
}

function transfSoltarCandadoFolio(PDO $pdo, ?string $nombre): void {
    if ($nombre === null) return;
    try { $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($nombre) . ")")->fetchColumn(); } catch (\Throwable $e) { /* el candado se libera solo al cerrar la conexion */ }
}

// Siguiente folio del mes de $ts. Llamar con el candado tomado y dentro de la transaccion que inserta el pedido.
function transfSiguienteFolio(PDO $pdo, string $ts): string {
    $periodo = intval(date('Y', strtotime($ts))) * 100 + intval(date('n', strtotime($ts)));
    $st = $pdo->prepare("SELECT COALESCE(MAX(CAST(folio AS UNSIGNED)), 0) + 1 FROM transferencias WHERE folio IS NOT NULL AND folio_periodo = ?");
    $st->execute([$periodo]);
    return str_pad((string)intval($st->fetchColumn()), 4, '0', STR_PAD_LEFT);
}

// Filtro "buscar por folio" del listado: folio NNNN + mes + anio (el folio se repite cada mes). Devuelve null si no se
// busco por folio.
function transfFiltroFolio(array $get): ?array {
    $crudo = is_scalar($get['folio'] ?? null) ? trim((string)$get['folio']) : '';
    if ($crudo === '' || !ctype_digit($crudo) || strlen($crudo) > 9) return null;
    $mes  = is_scalar($get['folio_mes'] ?? null) && ctype_digit((string)$get['folio_mes']) ? intval($get['folio_mes']) : intval(date('n'));
    $anio = is_scalar($get['folio_anio'] ?? null) && ctype_digit((string)$get['folio_anio']) ? intval($get['folio_anio']) : intval(date('Y'));
    if ($mes < 1 || $mes > 12) $mes = intval(date('n'));
    if ($anio < 2000 || $anio > 2100) $anio = intval(date('Y'));
    return ['numero' => intval($crudo), 'mes' => $mes, 'anio' => $anio, 'periodo' => $anio * 100 + $mes];
}

// ── Crear una SOLICITUD (la sucursal destino pide productos al origen). Devuelve ['insertados', 'folio', 'error'] ──
function transfCrearSolicitud(PDO $pdo, int $origen, int $destino, int $usuarioId, string $notas, array $items): array {
    // [FIX-EMOJI-TRANSFERENCIAS] un emoji en las notas hacia fallar el INSERT en el servidor (modo estricto).
    if (textoConEmoji($notas)) return ['insertados' => 0, 'folio' => null, 'error' => 'Las notas no pueden llevar emojis. Quítalos e intenta de nuevo.'];
    $ts   = $pdo->query("SELECT NOW()")->fetchColumn();
    $lock = transfTomarCandadoFolio($pdo, $ts);
    if ($lock === null) {
        return ['insertados' => 0, 'folio' => null, 'error' => 'El sistema está asignando el folio de otro pedido. Intenta de nuevo en unos segundos.'];
    }
    try {
        $pdo->beginTransaction();
        $folio = transfSiguienteFolio($pdo, $ts);
        $stTV  = $pdo->prepare("SELECT tipo_venta FROM productos WHERE producto_id = ?");
        $stIns = $pdo->prepare("INSERT INTO transferencias (producto_id, sucursal_origen_id, sucursal_destino_id, usuario_solicita_id, cantidad, notas, estado, created_at, folio, tipo_pedido) VALUES (?,?,?,?,?,?,'Pendiente',?,?,'Solicitud')");
        $insertados = 0;
        foreach ($items as $item) {
            $prodId   = intval($item['id']);
            $cantidad = floatval($item['cantidad']);
            $stTV->execute([$prodId]);
            $tv = $stTV->fetch(PDO::FETCH_ASSOC);
            if ($tv && $tv['tipo_venta'] !== 'Suelto') $cantidad = floor($cantidad);
            if ($cantidad <= 0) continue;
            $stIns->execute([$prodId, $origen, $destino, $usuarioId, $cantidad, $notas, $ts, $folio]);
            $insertados++;
        }
        if ($insertados === 0) { $pdo->rollBack(); return ['insertados' => 0, 'folio' => null, 'error' => '']; }
        $pdo->commit();
        return ['insertados' => $insertados, 'folio' => $folio, 'error' => ''];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Ferreteria/transferencias] Error al crear solicitud: ' . $e->getMessage());
        return ['insertados' => 0, 'folio' => null, 'error' => 'No se pudo registrar la solicitud. Intenta de nuevo.'];
    } finally {
        transfSoltarCandadoFolio($pdo, $lock);
    }
}

// ── ENVIO DIRECTO: el origen manda productos a otra sucursal sin que ella tenga que autorizarlos. Es un proceso en dos pasos:
//   1) AQUI se arma y se guarda el pedido: queda "Aprobada" (en preparacion, ya autorizada por el propio origen) con su folio.
//      NO se mueve inventario: la mercancia todavia esta en la sucursal (el repartidor puede no haber llegado).
//   2) Cuando sale, el origen usa "Marcar pedido enviado" (accion enviar_pedido, la misma de las solicitudes): ahi se descuenta
//      su stock y pasa a "En tránsito". El destino solo confirma la recepcion.
// Valida cada producto contra el stock real (con candado) y crea todo en una transaccion. Devuelve
// ['ok'=>bool, 'errores'=>[], 'folio'=>?string, 'id'=>?int, 'n'=>int].
function transfCrearEnvioDirecto(PDO $pdo, int $origen, int $destino, int $usuarioId, string $nombreUsuario, string $notas, $items): array {
    $errores = [];
    if ($origen <= 0)                $errores[] = 'Selecciona una sucursal específica para enviar productos.';
    if ($destino <= 0)               $errores[] = 'Selecciona la sucursal destino.';
    elseif ($destino === $origen)    $errores[] = 'La sucursal destino no puede ser la misma que la tuya.';
    if (mb_strlen($notas) > 500)     $errores[] = 'Las notas no pueden pasar de 500 caracteres.';
    // [FIX-EMOJI-TRANSFERENCIAS] un emoji en las notas hacia fallar el INSERT en el servidor (modo estricto) con un aviso generico.
    if (textoConEmoji($notas))       $errores[] = 'Las notas no pueden llevar emojis. Quítalos e intenta de nuevo.';

    $agg = [];   // producto_id => cantidad total (si el mismo producto viene repetido se suma)
    if (!is_array($items)) {
        $errores[] = 'El carrito de productos tiene un formato inválido.';
    } else {
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'], $item['cantidad']) || !is_scalar($item['id']) || !is_scalar($item['cantidad'])
                || !is_numeric($item['id']) || !is_numeric($item['cantidad']) || !is_finite(floatval($item['cantidad']))) {
                $errores[] = 'El carrito de productos tiene un formato inválido.';
                $agg = [];
                break;
            }
            $pid = intval($item['id']);
            if ($pid <= 0) { $errores[] = 'El carrito de productos tiene un formato inválido.'; $agg = []; break; }
            $agg[$pid] = ($agg[$pid] ?? 0) + round(floatval($item['cantidad']), 3);
        }
        if (!$errores && !$agg) $errores[] = 'Agrega al menos un producto.';
    }
    if ($errores) return ['ok' => false, 'errores' => $errores, 'folio' => null, 'id' => null, 'n' => 0];

    $stDest = $pdo->prepare("SELECT sucursal_id FROM sucursales WHERE sucursal_id = ? AND activo = 1");
    $stDest->execute([$destino]);
    if ($stDest->fetchColumn() === false) {
        return ['ok' => false, 'errores' => ['La sucursal destino no existe o está desactivada.'], 'folio' => null, 'id' => null, 'n' => 0];
    }

    $ts   = $pdo->query("SELECT NOW()")->fetchColumn();
    $lock = transfTomarCandadoFolio($pdo, $ts);
    if ($lock === null) {
        return ['ok' => false, 'errores' => ['El sistema está asignando el folio de otro pedido. Intenta de nuevo en unos segundos.'], 'folio' => null, 'id' => null, 'n' => 0];
    }
    try {
        $pdo->beginTransaction();
        $stProd   = $pdo->prepare("SELECT nombre_producto, tipo_venta, activo FROM productos WHERE producto_id = ?");
        $stOr     = $pdo->prepare("SELECT stock_actual, activo FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
        $stDestSs = $pdo->prepare("SELECT activo FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
        $validos  = [];
        foreach ($agg as $pid => $cant) {
            $stProd->execute([$pid]);
            $p = $stProd->fetch(PDO::FETCH_ASSOC);
            if (!$p) { $errores[] = "Producto #$pid: ya no existe en el catálogo. Recarga la página e intenta de nuevo."; continue; }
            $nom = $p['nombre_producto'];
            if (!intval($p['activo'])) { $errores[] = "\"$nom\": el producto está desactivado en el catálogo."; continue; }
            if ($p['tipo_venta'] !== 'Suelto' && abs($cant - round($cant)) > 0.0005) {
                $errores[] = "\"$nom\": no es granel, la cantidad debe ser un número entero."; continue;
            }
            if ($p['tipo_venta'] !== 'Suelto') $cant = round($cant);
            if ($cant <= 0) { $errores[] = "\"$nom\": la cantidad debe ser mayor a 0."; continue; }
            $stOr->execute([$pid, $origen]);
            $ss = $stOr->fetch(PDO::FETCH_ASSOC);
            if (!$ss || !intval($ss['activo'])) { $errores[] = "\"$nom\": ya no está disponible en tu sucursal (fue dado de baja). Recarga la página e intenta de nuevo."; continue; }
            if ($cant > floatval($ss['stock_actual']) + 0.0005) {
                $errores[] = "\"$nom\": quieres enviar " . transfFmtCant($cant) . " pero tu sucursal solo tiene " . transfFmtCant($ss['stock_actual']) . " disponibles."; continue;
            }
            $stDestSs->execute([$pid, $destino]);
            $dActivo = $stDestSs->fetchColumn();
            if ($dActivo !== false && !intval($dActivo)) {
                $errores[] = "\"$nom\": la sucursal destino lo tiene dado de baja y no podría recibirlo. Actívalo allá primero."; continue;
            }
            $validos[$pid] = $cant;
        }
        if ($errores) { $pdo->rollBack(); return ['ok' => false, 'errores' => $errores, 'folio' => null, 'id' => null, 'n' => 0]; }

        $folio = transfSiguienteFolio($pdo, $ts);
        $nota  = ($notas !== '' ? $notas . "\n" : '') . 'Envío directo preparado el ' . date('d/m/Y H:i') . ' por ' . $nombreUsuario . '. No requiere aprobación de la sucursal destino.';
        $stIns = $pdo->prepare("INSERT INTO transferencias (producto_id, sucursal_origen_id, sucursal_destino_id, usuario_solicita_id, usuario_aprueba_id, cantidad, notas, estado, created_at, folio, tipo_pedido) VALUES (?,?,?,?,?,?,?,'Aprobada',?,?,'Directo')");
        $primerId = null; $n = 0;
        foreach ($validos as $pid => $cant) {
            $stIns->execute([$pid, $origen, $destino, $usuarioId, $usuarioId, $cant, $nota, $ts, $folio]);
            if ($primerId === null) $primerId = intval($pdo->lastInsertId());
            $n++;
        }
        $pdo->commit();
        return ['ok' => true, 'errores' => [], 'folio' => $folio, 'id' => $primerId, 'n' => $n];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Ferreteria/transferencias] Error al guardar envio directo: ' . $e->getMessage());
        return ['ok' => false, 'errores' => ['No se pudo guardar el pedido de envío. Intenta de nuevo.'], 'folio' => null, 'id' => null, 'n' => 0];
    } finally {
        transfSoltarCandadoFolio($pdo, $lock);
    }
}

// Ajustar un pedido de ENVIO DIRECTO que sigue en preparacion ("Aprobada"). Como el destino no autoriza nada, los cambios
// aplican de inmediato (el pedido sigue "Aprobada"); con 0 el producto sale del pedido ("Cancelada"). Todo o nada.
// $filasEd = filas Aprobadas de mi sucursal origen; $cants = cant[id] del formulario. Devuelve ['err'=>?string, 'det'=>string].
function transfEditarDirecto(PDO $pdo, array $filasEd, $cants, int $miSucursal, int $usuarioId, string $nombreUsuario): array {
    $res = ['err' => null, 'det' => ''];
    $cants = is_array($cants) ? $cants : [];
    $stStock = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
    $cambios = []; $quitar = [];
    foreach ($filasEd as $f) {
        if ($f['estado'] !== 'Aprobada' || $f['sucursal_origen_id'] != $miSucursal) continue;
        $fid = $f['transferencias_id'];
        if (!array_key_exists($fid, $cants) || !is_scalar($cants[$fid])) continue;
        $raw = trim((string)$cants[$fid]);
        if ($raw === '') continue;
        if (!is_numeric($raw) || !is_finite(floatval($raw))) { return ['err' => 'error_cantidad_pedido', 'det' => $f['nombre_producto']]; }
        $nueva = round(floatval($raw), 3);
        if ($nueva < 0) return ['err' => 'error_cantidad_pedido', 'det' => $f['nombre_producto']];
        if ($f['tipo_venta'] !== 'Suelto' && abs($nueva - round($nueva)) > 0.0005) return ['err' => 'error_cantidad_pedido', 'det' => $f['nombre_producto']];
        if ($f['tipo_venta'] !== 'Suelto') $nueva = round($nueva);
        if ($nueva == 0) { $quitar[$fid] = $f; continue; }
        if (abs($nueva - floatval($f['cantidad'])) < 0.0005) continue;
        $stStock->execute([$f['producto_id'], $miSucursal]);
        $stk = $stStock->fetchColumn();
        if ($stk === false || $nueva > floatval($stk) + 0.0005) return ['err' => 'error_stock_editar', 'det' => $f['nombre_producto']];
        $cambios[$fid] = [$f, $nueva];
    }
    if (!$cambios && !$quitar) return ['err' => 'error_pedido_sin_cambios', 'det' => ''];
    $activas = 0;
    foreach ($filasEd as $f) { if ($f['estado'] === 'Aprobada' && $f['sucursal_origen_id'] == $miSucursal) $activas++; }
    if ($activas - count($quitar) <= 0) return ['err' => 'error_pedido_vacio', 'det' => ''];

    $cuando = date('d/m/Y H:i') . ' por ' . $nombreUsuario;
    $stLock = $pdo->prepare("SELECT estado FROM transferencias WHERE transferencias_id = ? AND sucursal_origen_id = ? FOR UPDATE");
    $stMod  = $pdo->prepare("UPDATE transferencias SET cantidad = ?, notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?)) WHERE transferencias_id = ? AND estado = 'Aprobada' AND sucursal_origen_id = ?");
    $stQuit = $pdo->prepare("UPDATE transferencias SET estado = 'Cancelada', notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?)) WHERE transferencias_id = ? AND estado = 'Aprobada' AND sucursal_origen_id = ?");
    $pdo->beginTransaction();
    try {
        foreach ($cambios as $fid => [$f, $nueva]) {
            $stLock->execute([$fid, $miSucursal]);
            if ($stLock->fetchColumn() !== 'Aprobada') { $pdo->rollBack(); return ['err' => 'error_ya_no_pendiente', 'det' => '']; }
            $stMod->execute([$nueva, 'Cantidad ajustada por origen de ' . transfFmtCant($f['cantidad']) . ' a ' . transfFmtCant($nueva) . ' el ' . $cuando . '.', $fid, $miSucursal]);
            if ($stMod->rowCount() === 0) { $pdo->rollBack(); return ['err' => 'error_ya_no_pendiente', 'det' => '']; }
        }
        foreach ($quitar as $fid => $f) {
            $stLock->execute([$fid, $miSucursal]);
            if ($stLock->fetchColumn() !== 'Aprobada') { $pdo->rollBack(); return ['err' => 'error_ya_no_pendiente', 'det' => '']; }
            $stQuit->execute(['Producto retirado del pedido por origen el ' . $cuando . '.', $fid, $miSucursal]);
            if ($stQuit->rowCount() === 0) { $pdo->rollBack(); return ['err' => 'error_ya_no_pendiente', 'det' => '']; }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Ferreteria/transferencias] Error al editar envio directo: ' . $e->getMessage());
        return ['err' => 'error_pedido', 'det' => ''];
    }
    return $res;
}

// Cancelar un pedido de ENVIO DIRECTO que todavia no salio ("Aprobada"): solo el ORIGEN. No mueve inventario (aun no se
// habia descontado). Devuelve null si salio bien o la llave del mensaje de error. Todo o nada.
function transfCancelarPreparado(PDO $pdo, array $filasPedido, int $miSucursal, string $nombreUsuario): ?string {
    $filas = [];
    foreach ($filasPedido as $f) {
        if ($f['estado'] === 'Aprobada' && $f['sucursal_origen_id'] == $miSucursal && ($f['tipo_pedido'] ?? '') === 'Directo') $filas[] = $f;
    }
    if (!$filas) return 'error_ya_no_aprobada';
    $nota = 'Pedido de envío cancelado por el origen el ' . date('d/m/Y H:i') . ' por ' . $nombreUsuario . ' antes de salir.';
    $st = $pdo->prepare("UPDATE transferencias SET estado = 'Cancelada', notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?)) WHERE transferencias_id = ? AND estado = 'Aprobada' AND sucursal_origen_id = ? AND tipo_pedido = 'Directo'");
    $pdo->beginTransaction();
    try {
        foreach ($filas as $f) {
            $st->execute([$nota, $f['transferencias_id'], $miSucursal]);
            if ($st->rowCount() === 0) { $pdo->rollBack(); return 'error_ya_no_aprobada'; }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Ferreteria/transferencias] Error al cancelar envio directo preparado: ' . $e->getMessage());
        return 'error_pedido';
    }
    return null;
}

// ── Recibir UNA fila (dentro de la transaccion del llamador). $recep = ['recibida','merma','no_enviada','observaciones'];
// con null se recibe todo completo (compatibilidad). Devuelve null si todo salio bien o la llave del mensaje de error. ──
function transfRecibirFila(PDO $pdo, int $id, int $miSucursal, int $usuarioId, ?array $recep = null, string $nombreUsuario = ''): ?string {
    $stmtLock = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ? FOR UPDATE");
    $stmtLock->execute([$id]);
    $transf = $stmtLock->fetch(PDO::FETCH_ASSOC);
    if (!$transf || $transf['estado'] !== 'En tránsito' || $transf['sucursal_destino_id'] != $miSucursal) {
        return 'error_ya_no_transito';
    }
    $cant = floatval($transf['cantidad']);
    if ($recep === null) {
        $rec = $cant; $mer = 0.0; $noe = 0.0; $obs = '';
    } else {
        $rec = round(floatval($recep['recibida'] ?? 0), 3);
        $mer = round(floatval($recep['merma'] ?? 0), 3);
        $noe = round(floatval($recep['no_enviada'] ?? 0), 3);
        $obs = trim((string)($recep['observaciones'] ?? ''));
    }
    if ($rec < 0 || $mer < 0 || $noe < 0 || abs(($rec + $mer + $noe) - $cant) > 0.0005) {
        return 'error_recepcion_suma';
    }
    $llega = round($rec + $mer, 3);   // lo que fisicamente llego (la merma tambien entra al stock del destino)

    $stmtMarca = $pdo->prepare("SELECT COUNT(*) FROM movimientos_inventario WHERE tipo = 'Transferencia' AND motivo = ? AND sucursal_id = ?");
    $stmtMarca->execute(['Transferencia enviada #' . $id, $transf['sucursal_origen_id']]);
    if (intval($stmtMarca->fetchColumn()) === 0) {
        // Ruta de compatibilidad: transferencia enviada con la logica anterior (el origen descuenta hasta recibir).
        // Solo sale del origen lo que de verdad se envio (recibido + merma); lo no enviado nunca salio.
        if ($llega > 0) {
            $stmtOrigen = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
            $stmtOrigen->execute([$transf['producto_id'], $transf['sucursal_origen_id']]);
            $stockOrLegacy = $stmtOrigen->fetchColumn();
            if ($stockOrLegacy === false || floatval($stockOrLegacy) < $llega) {
                return 'error_stock_recibir';
            }
            $stockAntOrigen   = floatval($stockOrLegacy);
            $stockNuevoOrigen = round($stockAntOrigen - $llega, 3);
            $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                ->execute([$stockNuevoOrigen, $transf['producto_id'], $transf['sucursal_origen_id']]);
            $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Transferencia',?,?,?,'Transferencia enviada')")
                ->execute([$transf['producto_id'], $usuarioId, $transf['sucursal_origen_id'], $llega, $stockAntOrigen, $stockNuevoOrigen]);
        }
    } elseif ($noe > 0) {
        // Lo que venia en la lista pero no llego regresa al stock del origen (ya se le habia descontado al enviar).
        $stmtOr = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
        $stmtOr->execute([$transf['producto_id'], $transf['sucursal_origen_id']]);
        $stockOr    = $stmtOr->fetchColumn();
        $stockAntOr = ($stockOr !== false) ? floatval($stockOr) : 0.0;
        $stockNueOr = round($stockAntOr + $noe, 3);
        if ($stockOr !== false) {
            $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                ->execute([$stockNueOr, $transf['producto_id'], $transf['sucursal_origen_id']]);
        } else {
            $pdo->prepare("INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo) VALUES (?,?,?,0,0,1)")
                ->execute([$transf['producto_id'], $transf['sucursal_origen_id'], $stockNueOr]);
        }
        $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Entrada',?,?,?,?)")
            ->execute([$transf['producto_id'], $usuarioId, $transf['sucursal_origen_id'], $noe, $stockAntOr, $stockNueOr,
                       'Transferencia #' . $id . ': ' . transfFmtCant($noe) . ' no enviado(s) — stock regresado al origen']);
    }

    if ($llega > 0) {
        $stmtActivoRec = $pdo->prepare("SELECT activo FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
        $stmtActivoRec->execute([$transf['producto_id'], $transf['sucursal_destino_id']]);
        $activoDestRec = $stmtActivoRec->fetchColumn();
        if ($activoDestRec !== false && !intval($activoDestRec)) {
            return 'error_producto_inactivo_transf';
        }
        $stmtDest = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
        $stmtDest->execute([$transf['producto_id'], $transf['sucursal_destino_id']]);
        $stockDest = $stmtDest->fetchColumn();
        if ($stockDest !== false) {
            $stockAntDest   = floatval($stockDest);
            $stockNuevoDest = round($stockAntDest + $llega, 3);
            $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                ->execute([$stockNuevoDest, $transf['producto_id'], $transf['sucursal_destino_id']]);
        } else {
            $stockNuevoDest = $llega;
            $pdo->prepare("INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo) VALUES (?,?,?,0,0,1)")
                ->execute([$transf['producto_id'], $transf['sucursal_destino_id'], $stockNuevoDest]);
            $stockAntDest = 0;
        }
        $motivoRec = 'Transferencia recibida #' . $id . ($mer > 0 ? ' (incluye ' . transfFmtCant($mer) . ' de merma: dar de baja en Salidas)' : '');
        $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Transferencia',?,?,?,?)")
            ->execute([$transf['producto_id'], $usuarioId, $transf['sucursal_destino_id'], $llega, $stockAntDest, $stockNuevoDest, $motivoRec]);
    }

    $notaRec = 'Recepción confirmada el ' . date('d/m/Y H:i') . ($nombreUsuario !== '' ? ' por ' . $nombreUsuario : '')
             . ': recibido ' . transfFmtCant($rec) . ', merma ' . transfFmtCant($mer) . ', no enviado ' . transfFmtCant($noe) . '.';
    $pdo->prepare("
        UPDATE transferencias
        SET estado = 'Entregada',
            usuario_aprueba_id = COALESCE(usuario_aprueba_id, ?),
            usuario_recibe_id = ?, fecha_recepcion = NOW(),
            cantidad_recibida = ?, cantidad_merma = ?, cantidad_no_enviada = ?,
            observaciones = ?,
            notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?))
        WHERE transferencias_id = ?
    ")->execute([$usuarioId, $usuarioId, $rec, $mer, $noe, $obs !== '' ? $obs : null, $notaRec, $id]);
    return null;
}

// Recibir TODO el pedido con los datos del formulario de recepcion ($post: rec[id], mer[id], noe[id], observaciones).
// Todo o nada: el llamador abre la transaccion. Si $post no trae el arreglo rec, se recibe todo completo.
// Devuelve ['err'=>?string, 'det'=>string, 'merma'=>float, 'noenviado'=>float].
function transfRecibirPedido(PDO $pdo, array $filasPedido, int $miSucursal, int $usuarioId, string $nombreUsuario, array $post): array {
    $res = ['err' => null, 'det' => '', 'merma' => 0.0, 'noenviado' => 0.0];
    $filas = [];
    foreach ($filasPedido as $f) {
        if ($f['estado'] === 'En tránsito' && $f['sucursal_destino_id'] == $miSucursal) $filas[] = $f;
    }
    if (!$filas) { $res['err'] = 'error_ya_no_transito'; return $res; }

    // Sin el arreglo rec (ni la clave) = recepcion completa de compatibilidad; si rec viene pero no es un arreglo, el envio esta mal formado.
    $conForm = array_key_exists('rec', $post);
    if ($conForm && !is_array($post['rec'])) { $res['err'] = 'error_recepcion_datos'; return $res; }
    $rec = $conForm ? $post['rec'] : [];
    $mer = (isset($post['mer']) && is_array($post['mer'])) ? $post['mer'] : [];
    $noe = (isset($post['noe']) && is_array($post['noe'])) ? $post['noe'] : [];
    $obs = is_scalar($post['observaciones'] ?? null) ? trim((string)$post['observaciones']) : '';
    // [FIX-EMOJI-TRANSFERENCIAS] La conexion usa utf8 (3 bytes): un emoji en las observaciones hacia fallar el UPDATE final en el
    // servidor (modo estricto) y toda la recepcion se deshacia con un "Error al confirmar la recepcion" sin explicar el motivo.
    if (textoConEmoji($obs)) { $res['err'] = 'error_recepcion_emoji'; return $res; }
    $obs = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $obs) ?? '';
    if (mb_strlen($obs) > 1000) $obs = mb_substr($obs, 0, 1000);

    $num = function ($v, bool $obligatorio, ?float &$out): bool {
        if ($v === null || (is_string($v) && trim($v) === '')) { $out = 0.0; return !$obligatorio; }
        if (!is_scalar($v) || !is_numeric($v) || !is_finite(floatval($v))) return false;
        $out = round(floatval($v), 3);
        return $out >= 0;
    };
    $plan = [];
    foreach ($filas as $f) {
        $fid = $f['transferencias_id'];
        $datos = ['recibida' => floatval($f['cantidad']), 'merma' => 0.0, 'no_enviada' => 0.0, 'observaciones' => $obs];
        if ($conForm) {
            $r = $m = $n = 0.0;
            if (!array_key_exists($fid, $rec) || !$num($rec[$fid], true, $r) || !$num($mer[$fid] ?? null, false, $m) || !$num($noe[$fid] ?? null, false, $n)) {
                $res['err'] = 'error_recepcion_datos'; $res['det'] = $f['nombre_producto']; return $res;
            }
            if ($f['tipo_venta'] !== 'Suelto' && (abs($r - round($r)) > 0.0005 || abs($m - round($m)) > 0.0005 || abs($n - round($n)) > 0.0005)) {
                $res['err'] = 'error_recepcion_entero'; $res['det'] = $f['nombre_producto']; return $res;
            }
            if (abs(($r + $m + $n) - floatval($f['cantidad'])) > 0.0005) {
                $res['err'] = 'error_recepcion_suma'; $res['det'] = $f['nombre_producto']; return $res;
            }
            $datos['recibida'] = $r; $datos['merma'] = $m; $datos['no_enviada'] = $n;
        }
        $plan[] = [$f, $datos];
    }
    foreach ($plan as [$f, $datos]) {
        $err = transfRecibirFila($pdo, intval($f['transferencias_id']), $miSucursal, $usuarioId, $datos, $nombreUsuario);
        if ($err !== null) { $res['err'] = $err; $res['det'] = $f['nombre_producto']; return $res; }
        $res['merma']     += $datos['merma'];
        $res['noenviado'] += $datos['no_enviada'];
    }
    return $res;
}

// El origen cancela el envio de una fila "En tránsito" (el stock regresa al origen).
function transfCancelarTransitoFila(PDO $pdo, int $id, int $miSucursal, int $usuarioId): ?string {
    $stmtLockCancel = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ? FOR UPDATE");
    $stmtLockCancel->execute([$id]);
    $tc = $stmtLockCancel->fetch(PDO::FETCH_ASSOC);
    if (!$tc || $tc['estado'] !== 'En tránsito' || $tc['sucursal_origen_id'] != $miSucursal) {
        return 'error_ya_no_transito';
    }
    $stmtOrCancel = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
    $stmtOrCancel->execute([$tc['producto_id'], $miSucursal]);
    $stockOrCancel = $stmtOrCancel->fetchColumn();
    $stockAnt   = ($stockOrCancel !== false) ? floatval($stockOrCancel) : 0.0;
    $stockNuevo = $stockAnt + floatval($tc['cantidad']);
    if ($stockOrCancel !== false) {
        $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
            ->execute([$stockNuevo, $tc['producto_id'], $miSucursal]);
    } else {
        $pdo->prepare("INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo) VALUES (?,?,?,0,0,1)")
            ->execute([$tc['producto_id'], $miSucursal, $stockNuevo]);
    }
    $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Entrada',?,?,?,?)")
        ->execute([$tc['producto_id'], $usuarioId, $miSucursal, $tc['cantidad'], $stockAnt, $stockNuevo, 'Transferencia #' . $id . ' cancelada en tránsito — stock regresado al origen']);
    $pdo->prepare("UPDATE transferencias SET estado='Cancelada' WHERE transferencias_id=? AND estado='En tránsito' AND sucursal_origen_id=?")
        ->execute([$id, $miSucursal]);
    return null;
}

// Fecha y usuario reales del envio de un pedido: el primer movimiento "Transferencia enviada #id" del origen.
function transfInfoEnvio(PDO $pdo, array $filas): array {
    $fechaEnvio = null; $enviadoPor = null;
    $stmtMov = $pdo->prepare("
        SELECT m.created_at, u.nombre_completo
        FROM movimientos_inventario m
        LEFT JOIN usuarios u ON u.usuario_id = m.usuario_id
        WHERE m.tipo = 'Transferencia' AND m.sucursal_id = ? AND m.motivo = ?
        ORDER BY m.created_at ASC LIMIT 1
    ");
    foreach ($filas as $f) {
        if (!in_array($f['estado'], ['En tránsito', 'Entregada'], true)) continue;
        $stmtMov->execute([$f['sucursal_origen_id'], 'Transferencia enviada #' . $f['transferencias_id']]);
        $mov = $stmtMov->fetch(PDO::FETCH_ASSOC);
        $fechaFila = $mov['created_at'] ?? $f['updated_at'];
        if ($fechaEnvio === null || $fechaFila < $fechaEnvio) {
            $fechaEnvio = $fechaFila;
            $enviadoPor = $mov['nombre_completo'] ?? null;
        }
    }
    return [$fechaEnvio, $enviadoPor];
}

// Datos del ticket de envio de un pedido (JSON). $sucAcceso = sucursal que consulta; 0 = ve todas (Admin sin sucursal).
// Devuelve null si el pedido no existe o la sucursal no participa en el.
function transfDatosTicket(PDO $pdo, int $id, int $sucAcceso): ?array {
    $ped = $id > 0 ? transfCargarPedido($pdo, $id) : null;
    if (!$ped) return null;
    $base = $ped['base'];
    if (!($sucAcceso === 0 || $base['sucursal_origen_id'] == $sucAcceso || $base['sucursal_destino_id'] == $sucAcceso)) return null;

    $stmtSucO = $pdo->prepare("SELECT nombre, rfc, direccion, telefono, datos_ticket, ticket_logo, ticket_font_size, ticket_ancho_mm FROM sucursales WHERE sucursal_id = ?");
    $stmtSucO->execute([$base['sucursal_origen_id']]);
    $sucO = $stmtSucO->fetch(PDO::FETCH_ASSOC) ?: [];
    $stmtSucD = $pdo->prepare("SELECT nombre FROM sucursales WHERE sucursal_id = ?");
    $stmtSucD->execute([$base['sucursal_destino_id']]);
    $nombreDest = $stmtSucD->fetchColumn() ?: '';
    $stmtSol = $pdo->prepare("SELECT nombre_completo FROM usuarios WHERE usuario_id = ?");
    $stmtSol->execute([$base['usuario_solicita_id']]);
    $nombreSol = $stmtSol->fetchColumn() ?: '';

    $enviados = []; $noEnviados = []; $idsPedido = [];
    foreach ($ped['filas'] as $f) {
        $idsPedido[] = intval($f['transferencias_id']);
        $item = [
            'codigo'     => $f['codigo'],
            'nombre'     => $f['nombre_producto'],
            'cantidad'   => floatval($f['cantidad']),
            'unidad'     => $f['unidad_medida'] ?? '',
            'tipo_venta' => $f['tipo_venta'],
            'estado'     => $f['estado'],
        ];
        if (in_array($f['estado'], ['En tránsito', 'Entregada'], true)) $enviados[] = $item;
        else $noEnviados[] = $item;
    }
    [$fechaEnvio, $enviadoPor] = transfInfoEnvio($pdo, $ped['filas']);
    return [
        'pedido'      => min($idsPedido),
        'ids'         => $idsPedido,
        'folio'       => ($base['folio'] !== null && $base['folio'] !== '') ? (string)$base['folio'] : null,
        'directo'     => ($base['tipo_pedido'] ?? 'Solicitud') === 'Directo',
        'origen'      => [
            'nombre'           => $sucO['nombre'] ?? '',
            'rfc'              => $sucO['rfc'] ?? '',
            'direccion'        => $sucO['direccion'] ?? '',
            'telefono'         => $sucO['telefono'] ?? '',
            'datos_ticket'     => $sucO['datos_ticket'] ?? '',
            'ticket_logo'      => $sucO['ticket_logo'] ?? null,
            'ticket_font_size' => intval($sucO['ticket_font_size'] ?? 12),
            'ticket_ancho_mm'  => intval($sucO['ticket_ancho_mm'] ?? 58),
        ],
        'destino'     => $nombreDest,
        'solicitante' => $nombreSol,
        'enviado_por' => $enviadoPor,
        'fecha_envio' => $fechaEnvio ? date('d/m/Y H:i', strtotime($fechaEnvio)) : null,
        'enviados'    => $enviados,
        'no_enviados' => $noEnviados,
    ];
}

// Detalle / historial de un pedido (JSON para la ventana "Detalle"): quien lo pidio/envio/recibio, cuando, las
// cantidades recibidas / merma / no enviadas por producto, las observaciones de la recepcion y la bitacora (`notas`).
function transfDetallePedido(PDO $pdo, int $id, int $sucAcceso): ?array {
    $ped = $id > 0 ? transfCargarPedido($pdo, $id) : null;
    if (!$ped) return null;
    $base = $ped['base'];
    if (!($sucAcceso === 0 || $base['sucursal_origen_id'] == $sucAcceso || $base['sucursal_destino_id'] == $sucAcceso)) return null;
    $filas = $ped['filas'];

    $nombreSuc = function ($sid) use ($pdo) {
        $st = $pdo->prepare("SELECT nombre FROM sucursales WHERE sucursal_id = ?");
        $st->execute([$sid]);
        return (string)($st->fetchColumn() ?: '');
    };
    $nombreUsr = function ($uid) use ($pdo) {
        if (!$uid) return null;
        $st = $pdo->prepare("SELECT nombre_completo FROM usuarios WHERE usuario_id = ?");
        $st->execute([$uid]);
        $n = $st->fetchColumn();
        return $n !== false ? (string)$n : null;
    };

    // Bitacora: una linea que aparece en TODOS los productos va tal cual; si solo aplica a algunos, se antepone el producto.
    $lineasPorFila = []; $orden = []; $cuantas = [];
    foreach ($filas as $i => $f) {
        $ls = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($f['notas'] ?? ''))), fn($l) => $l !== ''));
        $lineasPorFila[$i] = $ls;
        foreach (array_unique($ls) as $l) {
            if (!isset($cuantas[$l])) { $cuantas[$l] = 0; $orden[] = $l; }
            $cuantas[$l]++;
        }
    }
    $historial = [];
    foreach ($orden as $l) {
        if ($cuantas[$l] >= count($filas)) { $historial[] = $l; continue; }
        $nombres = [];
        foreach ($filas as $i => $f) { if (in_array($l, $lineasPorFila[$i], true)) $nombres[] = $f['nombre_producto']; }
        $historial[] = implode(', ', $nombres) . ': ' . $l;
    }

    $estados = array_column($filas, 'estado');
    $orden_est = ['Modificada', 'Pendiente', 'Aprobada', 'En tránsito', 'Entregada', 'Rechazada', 'Cancelada'];
    $estadoPrincipal = 'Pendiente';
    foreach ($orden_est as $e) { if (in_array($e, $estados, true)) { $estadoPrincipal = $e; break; } }

    $obs = null; $fechaRec = null; $recibioId = null;
    foreach ($filas as $f) {
        if ($obs === null && !empty($f['observaciones'])) $obs = (string)$f['observaciones'];
        if ($fechaRec === null && !empty($f['fecha_recepcion'])) $fechaRec = $f['fecha_recepcion'];
        if ($recibioId === null && !empty($f['usuario_recibe_id'])) $recibioId = $f['usuario_recibe_id'];
    }
    [$fechaEnvio, $enviadoPor] = transfInfoEnvio($pdo, $filas);
    $aprobo = null;
    foreach ($filas as $f) { if (!empty($f['usuario_aprueba_id'])) { $aprobo = $f['usuario_aprueba_id']; break; } }
    // Pedidos entregados con la logica anterior (sin usuario_recibe_id) guardaban al RECEPTOR en usuario_aprueba_id: no atribuirlo como "autorizo".
    if ($estadoPrincipal === 'Entregada' && !$recibioId) $aprobo = null;

    $items = [];
    $tot = ['enviado' => 0.0, 'recibida' => 0.0, 'merma' => 0.0, 'no_enviada' => 0.0];
    foreach ($filas as $f) {
        $capturada = $f['cantidad_recibida'] !== null;
        $items[] = [
            'id'         => intval($f['transferencias_id']),
            'codigo'     => $f['codigo'],
            'nombre'     => $f['nombre_producto'],
            'unidad'     => $f['unidad_medida'] ?? '',
            'cantidad'   => floatval($f['cantidad']),
            'estado'     => $f['estado'],
            'recibida'   => $capturada ? floatval($f['cantidad_recibida']) : null,
            'merma'      => $capturada ? floatval($f['cantidad_merma']) : null,
            'no_enviada' => $capturada ? floatval($f['cantidad_no_enviada']) : null,
        ];
        if ($capturada) {
            $tot['enviado']    += floatval($f['cantidad']);
            $tot['recibida']   += floatval($f['cantidad_recibida']);
            $tot['merma']      += floatval($f['cantidad_merma']);
            $tot['no_enviada'] += floatval($f['cantidad_no_enviada']);
        }
    }
    return [
        'id'           => intval($base['transferencias_id']),
        'folio'        => ($base['folio'] !== null && $base['folio'] !== '') ? (string)$base['folio'] : null,
        'periodo'      => $base['folio_periodo'] ? substr((string)$base['folio_periodo'], 4, 2) . '/' . substr((string)$base['folio_periodo'], 0, 4) : null,
        'tipo'         => $base['tipo_pedido'] ?? 'Solicitud',
        'estado'       => $estadoPrincipal,
        'creado'       => date('d/m/Y H:i', strtotime($base['created_at'])),
        'origen'       => $nombreSuc($base['sucursal_origen_id']),
        'destino'      => $nombreSuc($base['sucursal_destino_id']),
        'solicito'     => $nombreUsr($base['usuario_solicita_id']),
        'autorizo'     => $nombreUsr($aprobo),
        'envio'        => $enviadoPor,
        'fecha_envio'  => $fechaEnvio ? date('d/m/Y H:i', strtotime($fechaEnvio)) : null,
        'recibio'      => $nombreUsr($recibioId),
        'fecha_recepcion' => $fechaRec ? date('d/m/Y H:i', strtotime($fechaRec)) : null,
        'observaciones' => $obs,
        'historial'    => $historial,
        'items'        => $items,
        'totales'      => $tot,
        'hay_envio'    => (bool)array_intersect($estados, ['En tránsito', 'Entregada']),
    ];
}
