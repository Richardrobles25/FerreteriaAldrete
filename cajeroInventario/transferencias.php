<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../includes/icons.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Inventario', 'Inventario/Cajero']);

require_once '../includes/transferencias_lib.php';   // helpers compartidos: folio, envio directo, recepcion con merma, ticket y detalle

// [FEATURE-TICKET-ENVIO-TRANSF] Datos del ticket de envio de un pedido: lista de lo enviado (para
// que la sucursal destino verifique que llego completo) y, aparte, lo que NO se envio.
if (isset($_GET['ticket_envio'])) {
    header('Content-Type: application/json');
    try {
        $idTk = intval(is_scalar($_GET['ticket_envio'] ?? null) ? $_GET['ticket_envio'] : 0);
        // Solo las sucursales involucradas (origen o destino) pueden ver el ticket.
        echo json_encode(transfDatosTicket($pdo, $idTk, intval($_SESSION['sucursal_id'])), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[Ferreteria/transferencias] Error ticket_envio: ' . $e->getMessage());
        echo json_encode(['error' => 'No se pudo cargar el ticket de envío.']);
    }
    exit();
}

// [FEATURE-FOLIO-TRANSF] Detalle / historial de un pedido (folio, quien lo pidio/envio/recibio, cantidades recibidas,
// merma, no enviado, observaciones y bitacora). Solo lo ven las sucursales involucradas.
if (isset($_GET['detalle_pedido'])) {
    header('Content-Type: application/json');
    try {
        $idDet = intval(is_scalar($_GET['detalle_pedido'] ?? null) ? $_GET['detalle_pedido'] : 0);
        echo json_encode(transfDetallePedido($pdo, $idDet, intval($_SESSION['sucursal_id'])), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[Ferreteria/transferencias] Error detalle_pedido: ' . $e->getMessage());
        echo json_encode(['error' => 'No se pudo cargar el detalle del pedido.']);
    }
    exit();
}

// Acciones sobre transferencia existente
$_accionData = $_POST + $_GET;
if (isset($_accionData['accion']) && (isset($_accionData['id']) || isset($_GET['id']))) {
    // [FIX-A1] Verificar CSRF una sola vez: todas las acciones de este bloque
    // (aprobar, rechazar, enviar, recibir, editar_cantidad, aceptar/rechazar_modificacion)
    // pasan por aqui antes de llegar a su respectivo elseif.
    requerirCSRF($_accionData['_token'] ?? '', 'transferencias.php');
    $idRaw      = $_accionData['id'] ?? $_GET['id'] ?? 0;
    $id         = intval(is_scalar($idRaw) ? $idRaw : 0);
    $accion     = is_scalar($_accionData['accion'] ?? null) ? $_accionData['accion'] : '';
    $miSucursal = $_SESSION['sucursal_id'];
    $extraQs    = '';   // parametros extra del redirect final (avisos de merma / no enviado)

    if ($accion === 'editar_cantidad') {
        $nuevaCantidad = floatval(is_scalar($_POST['nueva_cantidad'] ?? null) ? $_POST['nueva_cantidad'] : 0);
        if ($nuevaCantidad > 0) {
            // Validar tipo_venta y obtener stock actual del origen en un solo query
            $stmtTV = $pdo->prepare("
                SELECT p.tipo_venta, ss.stock_actual AS stock_origen
                FROM transferencias t
                JOIN productos p ON t.producto_id = p.producto_id
                LEFT JOIN stock_sucursal ss ON ss.producto_id = t.producto_id AND ss.sucursal_id = t.sucursal_origen_id
                WHERE t.transferencias_id = ?
            ");
            $stmtTV->execute([$id]);
            $tvRow = $stmtTV->fetch(PDO::FETCH_ASSOC);
            if ($tvRow && $tvRow['tipo_venta'] !== 'Suelto') {
                $nuevaCantidad = floor($nuevaCantidad);
            }
            if ($nuevaCantidad <= 0) { header('Location: transferencias.php?msg=cantidad_editada'); exit(); }
            // Validar que la nueva cantidad no supere el stock disponible en origen
            if ($tvRow && $tvRow['stock_origen'] !== null && $nuevaCantidad > floatval($tvRow['stock_origen'])) {
                header('Location: transferencias.php?msg=error_stock_editar'); exit();
            }

            // Obtener cantidad anterior para la nota
            $stmtOld = $pdo->prepare("SELECT cantidad FROM transferencias WHERE transferencias_id = ? AND estado IN ('Pendiente','Aprobada') AND sucursal_origen_id = ?");
            $stmtOld->execute([$id, $miSucursal]);
            $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                $notaEdicion = 'Cantidad modificada por origen de ' . number_format($old['cantidad'], 2) . ' a ' . number_format($nuevaCantidad, 2) . ' el ' . date('d/m/Y H:i') . ' por ' . $_SESSION['nombre_completo'] . '. Pendiente de confirmacion por destino.';
                $stmtUpd = $pdo->prepare("UPDATE transferencias SET cantidad = ?, estado = 'Modificada', notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?)) WHERE transferencias_id = ? AND estado IN ('Pendiente','Aprobada') AND sucursal_origen_id = ?");
                $stmtUpd->execute([$nuevaCantidad, $notaEdicion, $id, $miSucursal]);
                // [FIX-EDITAR-CANTIDAD-IDOR] Igual que aprobar/rechazar: si el UPDATE no afecto
                // ninguna fila (no eres la sucursal origen, o el estado ya cambio), no mostrar
                // "Cantidad modificada" como si hubiera funcionado.
                if ($stmtUpd->rowCount() === 0) {
                    header('Location: transferencias.php?msg=error_ya_no_pendiente'); exit();
                }
            } else {
                header('Location: transferencias.php?msg=error_ya_no_pendiente'); exit();
            }
        }
        header('Location: transferencias.php?msg=cantidad_editada'); exit();

    } elseif ($accion === 'aceptar_modificacion') {
        // [FIX-MODIFICACION-IDOR] Igual que aprobar/rechazar/editar_cantidad: verificar que el
        // UPDATE realmente afecto una fila — si alguien mas ya la acepto/rechazo, o quien llama
        // no es la sucursal destino, no mostrar "Cambio aceptado" como si hubiera funcionado.
        $stmtAcept = $pdo->prepare("UPDATE transferencias SET estado = 'Aprobada' WHERE transferencias_id = ? AND estado = 'Modificada' AND sucursal_destino_id = ?");
        $stmtAcept->execute([$id, $miSucursal]);
        if ($stmtAcept->rowCount() === 0) {
            header('Location: transferencias.php?msg=error_ya_no_modificada'); exit();
        }
        header('Location: transferencias.php?msg=aceptar_modificacion'); exit();

    } elseif ($accion === 'rechazar_modificacion') {
        // [FIX-MODIFICACION-IDOR] Mismo patron que aceptar_modificacion.
        $notaRechazo = 'Modificacion de cantidad rechazada por destino el ' . date('d/m/Y H:i') . ' por ' . $_SESSION['nombre_completo'] . '.';
        $stmtRechMod = $pdo->prepare("UPDATE transferencias SET estado = 'Pendiente', notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?)) WHERE transferencias_id = ? AND estado = 'Modificada' AND sucursal_destino_id = ?");
        $stmtRechMod->execute([$notaRechazo, $id, $miSucursal]);
        if ($stmtRechMod->rowCount() === 0) {
            header('Location: transferencias.php?msg=error_ya_no_modificada'); exit();
        }
        header('Location: transferencias.php?msg=rechazar_modificacion'); exit();

    } elseif ($accion === 'aprobar') {
        // [FIX-TRANSF-ACTIVO-NO-REVALIDADO 2026-09-20] Ni el listado ni las acciones de esta
        // seccion revisaban que el producto siguiera activo en la sucursal origen — a
        // diferencia de entradas.php/salidas.php/compras.php, que ya revalidan esto desde la
        // ronda 3. Confirmado en vivo: una transferencia se podia aprobar/enviar igual contra
        // un producto ya dado de baja de la sucursal origen a medio proceso, sin ningun aviso.
        $stmtProdTAprob = $pdo->prepare("SELECT producto_id FROM transferencias WHERE transferencias_id = ? AND estado = 'Pendiente' AND sucursal_origen_id = ?");
        $stmtProdTAprob->execute([$id, $miSucursal]);
        $prodIdAprob = $stmtProdTAprob->fetchColumn();
        if ($prodIdAprob) {
            $stmtActivoAprob = $pdo->prepare("SELECT activo FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
            $stmtActivoAprob->execute([$prodIdAprob, $miSucursal]);
            if (!$stmtActivoAprob->fetchColumn()) {
                header('Location: transferencias.php?msg=error_producto_inactivo_transf'); exit();
            }
        }

        // [FIX-CONSISTENCIA] Igual que admin/inventario_transferencias.php (FIX-MEDIO-C-07):
        // verificar que el UPDATE realmente afecto una fila — si alguien mas ya
        // aprobo/rechazo la misma solicitud (o no era el origen), no mostrar exito falso.
        $notaAprobacion = 'Aceptada el ' . date('d/m/Y H:i') . ' por ' . $_SESSION['nombre_completo'] . '. Preparar envio a sucursal destino.';
        $stmtAprob = $pdo->prepare("
            UPDATE transferencias
            SET estado='Aprobada',
                usuario_aprueba_id=?,
                notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?))
            WHERE transferencias_id=? AND estado='Pendiente' AND sucursal_origen_id=?
        ");
        $stmtAprob->execute([$_SESSION['usuario_id'], $notaAprobacion, $id, $miSucursal]);
        if ($stmtAprob->rowCount() === 0) {
            header('Location: transferencias.php?msg=error_ya_no_pendiente'); exit();
        }
    } elseif ($accion === 'rechazar') {
        $stmtRech = $pdo->prepare("UPDATE transferencias SET estado='Rechazada', usuario_aprueba_id=? WHERE transferencias_id=? AND estado='Pendiente' AND sucursal_origen_id=?");
        $stmtRech->execute([$_SESSION['usuario_id'], $id, $miSucursal]);
        if ($stmtRech->rowCount() === 0) {
            header('Location: transferencias.php?msg=error_ya_no_pendiente'); exit();
        }
    } elseif ($accion === 'cancelar') {
        // [FIX-CONSISTENCIA] Igual que admin/inventario_transferencias.php (FIX-MEDIO-C-11):
        // faltaba por completo una ruta de cancelacion para una transferencia "En tránsito" —
        // si nunca se confirmaba la recepcion, el stock quedaba descontado del origen para
        // siempre sin llegar a ningun lado ni poder revertirse. Solo el ORIGEN puede cancelar
        // (es quien fisicamente puede recuperar la mercancia enviada), y solo mientras siga
        // "En tránsito" (aun no fue recibida).
        $stmt = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ? AND estado = 'En tránsito' AND sucursal_origen_id = ?");
        $stmt->execute([$id, $miSucursal]);
        $transfCancel = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($transfCancel) {
            $pdo->beginTransaction();
            try {
                $stmtLockCancel = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ? FOR UPDATE");
                $stmtLockCancel->execute([$id]);
                $transfCancel = $stmtLockCancel->fetch(PDO::FETCH_ASSOC);
                if (!$transfCancel || $transfCancel['estado'] !== 'En tránsito' || $transfCancel['sucursal_origen_id'] != $miSucursal) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=error_ya_no_transito'); exit();
                }

                $stmtOrCancel = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
                $stmtOrCancel->execute([$transfCancel['producto_id'], $miSucursal]);
                $stockOrCancel = $stmtOrCancel->fetchColumn();
                $stockAntCancel = ($stockOrCancel !== false) ? floatval($stockOrCancel) : 0.0;
                $stockNuevoCancel = $stockAntCancel + floatval($transfCancel['cantidad']);
                if ($stockOrCancel !== false) {
                    $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                        ->execute([$stockNuevoCancel, $transfCancel['producto_id'], $miSucursal]);
                } else {
                    $pdo->prepare("INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo) VALUES (?,?,?,0,0,1)")
                        ->execute([$transfCancel['producto_id'], $miSucursal, $stockNuevoCancel]);
                }
                $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Entrada',?,?,?,?)")
                    ->execute([$transfCancel['producto_id'], $_SESSION['usuario_id'], $miSucursal, $transfCancel['cantidad'], $stockAntCancel, $stockNuevoCancel, 'Transferencia #' . $id . ' cancelada en tránsito — stock regresado al origen']);

                $pdo->prepare("UPDATE transferencias SET estado='Cancelada' WHERE transferencias_id=? AND estado='En tránsito' AND sucursal_origen_id=?")
                    ->execute([$id, $miSucursal]);

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                error_log('[Ferreteria/transferencias] Error al cancelar #' . $id . ': ' . $e->getMessage());
                header('Location: transferencias.php?msg=error_cancelar'); exit();
            }
        } else {
            header('Location: transferencias.php?msg=error_ya_no_transito'); exit();
        }
    } elseif ($accion === 'cancelar_solicitud') {
        // [FEATURE-CANCELAR-TRANSFERENCIA] Cancelar una solicitud por error: SOLO la sucursal que la
        // pidio (destino) y SOLO mientras sigue Pendiente (el origen aun no la aprueba). Una vez
        // aprobada, modificada o enviada ya no se puede cancelar desde aqui (la sucursal origen
        // tiene "Rechazar" mientras esta Pendiente, y una "En tránsito" se cancela con 'cancelar').
        // Cancelar no mueve inventario: el stock del origen se descuenta hasta "Marcar enviado".
        $notaCancelSol = 'Cancelada el ' . date('d/m/Y H:i') . ' por ' . $_SESSION['nombre_completo'] . '.';
        $stmtCancelSol = $pdo->prepare("
            UPDATE transferencias
            SET estado = 'Cancelada',
                notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?))
            WHERE transferencias_id = ?
              AND estado = 'Pendiente'
              AND sucursal_destino_id = ?
        ");
        $stmtCancelSol->execute([$notaCancelSol, $id, $miSucursal]);
        if ($stmtCancelSol->rowCount() === 0) {
            header('Location: transferencias.php?msg=error_ya_no_cancelable'); exit();
        }
    } elseif ($accion === 'aprobar_pedido' || $accion === 'rechazar_pedido') {
        // [FEATURE-PEDIDO-TRANSFERENCIA] Aceptar o rechazar TODO el pedido (todas sus filas Pendientes) de un
        // jalon; si un producto ya no se puede aceptar (dado de baja), no se acepta ninguno.
        $ped = transfCargarPedido($pdo, $id);
        $filasAcc = [];
        if ($ped) {
            foreach ($ped['filas'] as $f) {
                if ($f['estado'] === 'Pendiente' && $f['sucursal_origen_id'] == $miSucursal) $filasAcc[] = $f;
            }
        }
        if (!$filasAcc) { header('Location: transferencias.php?msg=error_ya_no_pendiente'); exit(); }
        if ($accion === 'aprobar_pedido') {
            $stmtActPed = $pdo->prepare("SELECT activo FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
            foreach ($filasAcc as $f) {
                $stmtActPed->execute([$f['producto_id'], $miSucursal]);
                if (!$stmtActPed->fetchColumn()) {
                    header('Location: transferencias.php?msg=error_producto_inactivo_transf&det=' . urlencode($f['nombre_producto'])); exit();
                }
            }
            $notaPed = 'Aceptada el ' . date('d/m/Y H:i') . ' por ' . $_SESSION['nombre_completo'] . '. Preparar envio a sucursal destino.';
            $stmtUpPed = $pdo->prepare("
                UPDATE transferencias
                SET estado='Aprobada', usuario_aprueba_id=?,
                    notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?))
                WHERE transferencias_id=? AND estado='Pendiente' AND sucursal_origen_id=?
            ");
            $argsPed = fn($f) => [$_SESSION['usuario_id'], $notaPed, $f['transferencias_id'], $miSucursal];
        } else {
            $stmtUpPed = $pdo->prepare("UPDATE transferencias SET estado='Rechazada', usuario_aprueba_id=? WHERE transferencias_id=? AND estado='Pendiente' AND sucursal_origen_id=?");
            $argsPed = fn($f) => [$_SESSION['usuario_id'], $f['transferencias_id'], $miSucursal];
        }
        $pdo->beginTransaction();
        try {
            foreach ($filasAcc as $f) {
                $stmtUpPed->execute($argsPed($f));
                if ($stmtUpPed->rowCount() === 0) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=error_ya_no_pendiente'); exit();
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Ferreteria/transferencias] Error en ' . $accion . ' (fila base #' . $id . '): ' . $e->getMessage());
            header('Location: transferencias.php?msg=error_pedido'); exit();
        }
    } elseif ($accion === 'cancelar_solicitud_pedido') {
        // [FEATURE-PEDIDO-TRANSFERENCIA] La sucursal que pidio cancela TODO el pedido mientras sigue Pendiente.
        $ped = transfCargarPedido($pdo, $id);
        $filasAcc = [];
        if ($ped) {
            foreach ($ped['filas'] as $f) {
                if ($f['estado'] === 'Pendiente' && $f['sucursal_destino_id'] == $miSucursal) $filasAcc[] = $f;
            }
        }
        if (!$filasAcc) { header('Location: transferencias.php?msg=error_ya_no_cancelable'); exit(); }
        $notaCancelPed = 'Cancelada el ' . date('d/m/Y H:i') . ' por ' . $_SESSION['nombre_completo'] . '.';
        $stmtCancPed = $pdo->prepare("
            UPDATE transferencias
            SET estado = 'Cancelada',
                notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?))
            WHERE transferencias_id = ? AND estado = 'Pendiente' AND sucursal_destino_id = ?
        ");
        $pdo->beginTransaction();
        try {
            foreach ($filasAcc as $f) {
                $stmtCancPed->execute([$notaCancelPed, $f['transferencias_id'], $miSucursal]);
                if ($stmtCancPed->rowCount() === 0) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=error_ya_no_cancelable'); exit();
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Ferreteria/transferencias] Error al cancelar pedido (fila base #' . $id . '): ' . $e->getMessage());
            header('Location: transferencias.php?msg=error_pedido'); exit();
        }
    } elseif ($accion === 'editar_pedido') {
        // [FEATURE-PEDIDO-TRANSFERENCIA] El origen revisa TODO el pedido en una sola pantalla: los productos con
        // cantidad distinta quedan "Modificada" (el destino debe confirmar), los que no cambian quedan
        // aceptados y un producto Pendiente con cantidad 0 se retira del pedido (Rechazada). Todo o nada.
        $ped = transfCargarPedido($pdo, $id);
        $cants = (isset($_POST['cant']) && is_array($_POST['cant'])) ? $_POST['cant'] : [];
        $filasEd = [];
        $otrasActivas = 0;
        if ($ped) {
            foreach ($ped['filas'] as $f) {
                if (in_array($f['estado'], ['Pendiente', 'Aprobada'], true) && $f['sucursal_origen_id'] == $miSucursal) $filasEd[] = $f;
                elseif ($f['estado'] === 'Modificada') $otrasActivas++;
            }
        }
        if (!$filasEd) { header('Location: transferencias.php?msg=error_ya_no_pendiente'); exit(); }
        if (($ped['base']['tipo_pedido'] ?? '') === 'Directo') {
            // [FEATURE-ENVIO-DIRECTO] El destino no autoriza nada en un envio directo: el origen ajusta su pedido en
            // preparacion y los cambios aplican al guardar (sigue "Aprobada"); con 0 el producto sale del pedido.
            $resEd = transfEditarDirecto($pdo, $filasEd, $cants, intval($miSucursal), intval($_SESSION['usuario_id']), (string)$_SESSION['nombre_completo']);
            if ($resEd['err'] !== null) {
                header('Location: transferencias.php?msg=' . $resEd['err'] . ($resEd['det'] !== '' ? '&det=' . urlencode($resEd['det']) : '')); exit();
            }
            header('Location: transferencias.php?msg=editar_pedido_directo'); exit();
        }
        $stmtStockEd = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
        $cambios = [];   // id => [fila, nueva]
        $quitar  = [];   // filas Pendientes que se retiran
        foreach ($filasEd as $f) {
            $fid = $f['transferencias_id'];
            if (!array_key_exists($fid, $cants) || !is_scalar($cants[$fid])) continue;
            $raw = trim((string)$cants[$fid]);
            if ($raw === '') continue;
            if (!is_numeric($raw)) { header('Location: transferencias.php?msg=error_cantidad_pedido&det=' . urlencode($f['nombre_producto'])); exit(); }
            $nueva = floatval($raw);
            if ($f['tipo_venta'] !== 'Suelto') $nueva = floor($nueva);
            if ($nueva < 0) { header('Location: transferencias.php?msg=error_cantidad_pedido&det=' . urlencode($f['nombre_producto'])); exit(); }
            if ($nueva == 0) {
                // Un producto Aprobado ya no se retira (el destino ya cuenta con el); solo uno Pendiente.
                if ($f['estado'] !== 'Pendiente') { header('Location: transferencias.php?msg=error_cantidad_pedido&det=' . urlencode($f['nombre_producto'])); exit(); }
                $quitar[$fid] = $f;
                continue;
            }
            if (abs($nueva - floatval($f['cantidad'])) < 0.0005) continue;
            $stmtStockEd->execute([$f['producto_id'], $miSucursal]);
            $stockEd = $stmtStockEd->fetchColumn();
            if ($stockEd === false || $nueva > floatval($stockEd)) {
                header('Location: transferencias.php?msg=error_stock_editar&det=' . urlencode($f['nombre_producto'])); exit();
            }
            $cambios[$fid] = [$f, $nueva];
        }
        if (!$cambios && !$quitar) { header('Location: transferencias.php?msg=error_pedido_sin_cambios'); exit(); }
        if (count($filasEd) - count($quitar) + $otrasActivas <= 0) { header('Location: transferencias.php?msg=error_pedido_vacio'); exit(); }

        $fechaEd = date('d/m/Y H:i') . ' por ' . $_SESSION['nombre_completo'];
        $stmtMod = $pdo->prepare("UPDATE transferencias SET cantidad = ?, estado = 'Modificada', notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?)) WHERE transferencias_id = ? AND estado IN ('Pendiente','Aprobada') AND sucursal_origen_id = ?");
        $stmtQuit = $pdo->prepare("UPDATE transferencias SET estado = 'Rechazada', usuario_aprueba_id = ?, notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?)) WHERE transferencias_id = ? AND estado = 'Pendiente' AND sucursal_origen_id = ?");
        $stmtOk = $pdo->prepare("UPDATE transferencias SET estado = 'Aprobada', usuario_aprueba_id = ?, notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?)) WHERE transferencias_id = ? AND estado = 'Pendiente' AND sucursal_origen_id = ?");
        $pdo->beginTransaction();
        try {
            foreach ($filasEd as $f) {
                $fid = $f['transferencias_id'];
                if (isset($cambios[$fid])) {
                    [$fila, $nueva] = $cambios[$fid];
                    $nota = 'Cantidad modificada por origen de ' . number_format($fila['cantidad'], 2) . ' a ' . number_format($nueva, 2) . ' el ' . $fechaEd . '. Pendiente de confirmacion por destino.';
                    $stmtMod->execute([$nueva, $nota, $fid, $miSucursal]);
                    $afectadas = $stmtMod->rowCount();
                } elseif (isset($quitar[$fid])) {
                    $stmtQuit->execute([$_SESSION['usuario_id'], 'Producto retirado del pedido por origen el ' . $fechaEd . '.', $fid, $miSucursal]);
                    $afectadas = $stmtQuit->rowCount();
                } elseif ($f['estado'] === 'Pendiente') {
                    // Sin cambios en este producto: queda aceptado junto con el resto de la respuesta del origen.
                    $stmtOk->execute([$_SESSION['usuario_id'], 'Aceptada el ' . $fechaEd . '. Preparar envio a sucursal destino.', $fid, $miSucursal]);
                    $afectadas = $stmtOk->rowCount();
                } else {
                    continue;
                }
                if ($afectadas === 0) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=error_ya_no_pendiente'); exit();
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Ferreteria/transferencias] Error al editar pedido (fila base #' . $id . '): ' . $e->getMessage());
            header('Location: transferencias.php?msg=error_pedido'); exit();
        }
    } elseif ($accion === 'aceptar_modificacion_pedido' || $accion === 'rechazar_modificacion_pedido') {
        // [FEATURE-PEDIDO-TRANSFERENCIA] El destino acepta o rechaza TODOS los cambios de cantidad del pedido.
        $ped = transfCargarPedido($pdo, $id);
        $filasAcc = [];
        if ($ped) {
            foreach ($ped['filas'] as $f) {
                if ($f['estado'] === 'Modificada' && $f['sucursal_destino_id'] == $miSucursal) $filasAcc[] = $f;
            }
        }
        if (!$filasAcc) { header('Location: transferencias.php?msg=error_ya_no_modificada'); exit(); }
        if ($accion === 'aceptar_modificacion_pedido') {
            $stmtModPed = $pdo->prepare("UPDATE transferencias SET estado = 'Aprobada' WHERE transferencias_id = ? AND estado = 'Modificada' AND sucursal_destino_id = ?");
            $argsMod = fn($f) => [$f['transferencias_id'], $miSucursal];
        } else {
            $notaRechPed = 'Modificacion de cantidad rechazada por destino el ' . date('d/m/Y H:i') . ' por ' . $_SESSION['nombre_completo'] . '.';
            $stmtModPed = $pdo->prepare("UPDATE transferencias SET estado = 'Pendiente', notas = TRIM(CONCAT(COALESCE(notas, ''), CASE WHEN COALESCE(notas, '') = '' THEN '' ELSE '\n' END, ?)) WHERE transferencias_id = ? AND estado = 'Modificada' AND sucursal_destino_id = ?");
            $argsMod = fn($f) => [$notaRechPed, $f['transferencias_id'], $miSucursal];
        }
        $pdo->beginTransaction();
        try {
            foreach ($filasAcc as $f) {
                $stmtModPed->execute($argsMod($f));
                if ($stmtModPed->rowCount() === 0) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=error_ya_no_modificada'); exit();
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Ferreteria/transferencias] Error en ' . $accion . ' (fila base #' . $id . '): ' . $e->getMessage());
            header('Location: transferencias.php?msg=error_pedido'); exit();
        }
    } elseif ($accion === 'recibir_pedido') {
        // [FEATURE-RECEPCION-TRANSF] Confirmar la recepcion de TODO el pedido (todo o nada, una transaccion) con el
        // formulario de recepcion: por producto, recibido + merma + no enviado = enviado. Recibido y merma suman al
        // stock de esta sucursal (la merma NO sale sola: se da de baja despues desde Salidas); lo no enviado regresa al origen.
        $ped = transfCargarPedido($pdo, $id);
        if (!$ped) { header('Location: transferencias.php?msg=error_ya_no_transito'); exit(); }
        $pdo->beginTransaction();
        try {
            $resRec = transfRecibirPedido($pdo, $ped['filas'], intval($miSucursal), intval($_SESSION['usuario_id']), (string)$_SESSION['nombre_completo'], $_POST);
            if ($resRec['err'] !== null) {
                $pdo->rollBack();
                header('Location: transferencias.php?msg=' . $resRec['err'] . ($resRec['det'] !== '' ? '&det=' . urlencode($resRec['det']) : '')); exit();
            }
            $pdo->commit();
            if ($resRec['merma'] > 0)     $extraQs .= '&conmerma=1';
            if ($resRec['noenviado'] > 0) $extraQs .= '&sinenviar=1';
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Ferreteria/transferencias] Error al recibir pedido (fila base #' . $id . '): ' . $e->getMessage());
            header('Location: transferencias.php?msg=error_recibir'); exit();
        }
    } elseif ($accion === 'cancelar_preparado_pedido') {
        // [FEATURE-ENVIO-DIRECTO] El origen cancela un envio directo que todavia no salio (en preparacion): no mueve inventario.
        $ped = transfCargarPedido($pdo, $id);
        $errCanc = $ped ? transfCancelarPreparado($pdo, $ped['filas'], intval($miSucursal), (string)$_SESSION['nombre_completo']) : 'error_ya_no_aprobada';
        if ($errCanc !== null) { header('Location: transferencias.php?msg=' . $errCanc); exit(); }
    } elseif ($accion === 'cancelar_pedido') {
        // [FEATURE-PEDIDO-TRANSFERENCIA] El origen cancela el envio de TODO el pedido En transito (el stock regresa).
        $ped = transfCargarPedido($pdo, $id);
        $filasAcc = [];
        if ($ped) {
            foreach ($ped['filas'] as $f) {
                if ($f['estado'] === 'En tránsito' && $f['sucursal_origen_id'] == $miSucursal) $filasAcc[] = $f;
            }
        }
        if (!$filasAcc) { header('Location: transferencias.php?msg=error_ya_no_transito'); exit(); }
        $pdo->beginTransaction();
        try {
            foreach ($filasAcc as $f) {
                $err = transfCancelarTransitoFila($pdo, intval($f['transferencias_id']), intval($miSucursal), intval($_SESSION['usuario_id']));
                if ($err !== null) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=' . $err . '&det=' . urlencode($f['nombre_producto'])); exit();
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Ferreteria/transferencias] Error al cancelar envio de pedido (fila base #' . $id . '): ' . $e->getMessage());
            header('Location: transferencias.php?msg=error_cancelar'); exit();
        }
    } elseif ($accion === 'enviar_pedido') {
        // [FEATURE-TICKET-ENVIO-TRANSF] "Marcar enviado" para TODOS los productos Aprobados del mismo
        // pedido de una sola vez (misma logica que 'enviar', fila por fila, dentro de UNA transaccion:
        // si algun producto no se puede enviar, no se envia ninguno) y despues se abre el ticket con
        // la lista completa.
        $ped = transfCargarPedido($pdo, $id);
        $idsEnviar = [];
        $nombresPed = [];
        if ($ped && $ped['base']['sucursal_origen_id'] == $miSucursal) {
            foreach ($ped['filas'] as $f) {
                if ($f['estado'] === 'Aprobada' && $f['sucursal_origen_id'] == $miSucursal) {
                    $idsEnviar[] = intval($f['transferencias_id']);
                    $nombresPed[intval($f['transferencias_id'])] = $f['nombre_producto'];
                }
            }
        }
        // [FEATURE-PEDIDO-TRANSFERENCIA] El pedido sale completo: si aun tiene productos sin aceptar o con un cambio
        // de cantidad pendiente de confirmar, no se envia parte del carrito.
        if ($ped) {
            foreach ($ped['filas'] as $f) {
                if (in_array($f['estado'], ['Pendiente', 'Modificada'], true) && $f['sucursal_origen_id'] == $miSucursal) {
                    header('Location: transferencias.php?msg=error_pedido_incompleto'); exit();
                }
            }
        }
        if (!$idsEnviar) {
            header('Location: transferencias.php?msg=error_ya_no_aprobada'); exit();
        }
        $pdo->beginTransaction();
        try {
            foreach ($idsEnviar as $idEnv) {
                $stmtLockEnvP = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ? FOR UPDATE");
                $stmtLockEnvP->execute([$idEnv]);
                $transfP = $stmtLockEnvP->fetch(PDO::FETCH_ASSOC);
                if (!$transfP || $transfP['estado'] !== 'Aprobada' || $transfP['sucursal_origen_id'] != $miSucursal) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=error_ya_no_aprobada'); exit();
                }
                $stmtActivoEnvP = $pdo->prepare("SELECT activo FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
                $stmtActivoEnvP->execute([$transfP['producto_id'], $miSucursal]);
                if (!$stmtActivoEnvP->fetchColumn()) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=error_producto_inactivo_transf&det=' . urlencode($nombresPed[$idEnv] ?? '')); exit();
                }
                $stmtOrP = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
                $stmtOrP->execute([$transfP['producto_id'], $miSucursal]);
                $stockOrP = $stmtOrP->fetchColumn();
                if ($stockOrP === false || floatval($stockOrP) < floatval($transfP['cantidad'])) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=error_stock_envio&det=' . urlencode($nombresPed[$idEnv] ?? '')); exit();
                }
                $stockAntOrP   = floatval($stockOrP);
                $stockNuevoOrP = $stockAntOrP - floatval($transfP['cantidad']);
                $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                    ->execute([$stockNuevoOrP, $transfP['producto_id'], $miSucursal]);
                // Misma marca "#id" que usa 'enviar': 'recibir' la usa para no descontar doble.
                $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Transferencia',?,?,?,?)")
                    ->execute([$transfP['producto_id'], $_SESSION['usuario_id'], $miSucursal, $transfP['cantidad'], $stockAntOrP, $stockNuevoOrP, 'Transferencia enviada #' . $idEnv]);
                $pdo->prepare("UPDATE transferencias SET estado='En tránsito' WHERE transferencias_id=? AND estado='Aprobada' AND sucursal_origen_id=?")
                    ->execute([$idEnv, $miSucursal]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Ferreteria/transferencias] Error al enviar pedido (fila base #' . $id . '): ' . $e->getMessage());
            header('Location: transferencias.php?msg=error_envio'); exit();
        }
    } elseif ($accion === 'enviar') {
        // El stock del origen se descuenta AL ENVIAR (antes se descontaba hasta que el
        // destino confirmaba recepcion, lo que permitia al origen seguir vendiendo
        // mercancia que ya iba en camino y dejaba transferencias atoradas en transito).
        $stmt = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ? AND estado = 'Aprobada' AND sucursal_origen_id = ?");
        $stmt->execute([$id, $miSucursal]);
        $transf = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($transf) {
            $pdo->beginTransaction();
            try {
                // [FIX-A2] Volver a leer y bloquear la transferencia dentro de la transaccion
                // (mismo patron que en 'recibir'). El chequeo de arriba (linea 77-79) se hizo
                // sin candado; un doble clic/doble envio podia pasar el chequeo dos veces y
                // descontar el stock de origen por duplicado.
                $stmtLockEnv = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ? FOR UPDATE");
                $stmtLockEnv->execute([$id]);
                $transf = $stmtLockEnv->fetch(PDO::FETCH_ASSOC);
                if (!$transf || $transf['estado'] !== 'Aprobada' || $transf['sucursal_origen_id'] != $miSucursal) {
                    $pdo->rollBack();
                    // [FIX-ENVIAR-IDOR] Antes redirigia a "msg=enviar" (mensaje de exito) aunque
                    // la revalidacion fallara — quien no era la sucursal origen, o una transferencia
                    // que ya cambio de estado, veia "Productos enviados" sin que nada pasara.
                    header('Location: transferencias.php?msg=error_ya_no_aprobada'); exit();
                }

                // [FIX-TRANSF-ACTIVO-NO-REVALIDADO 2026-09-20] Mismo candado que ya tienen
                // entradas.php/salidas.php/compras.php: el producto pudo darse de baja de esta
                // sucursal DESPUES de aprobar la transferencia (ej. otra pestana) -- revisar
                // antes de mover stock real.
                $stmtActivoEnv = $pdo->prepare("SELECT activo FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
                $stmtActivoEnv->execute([$transf['producto_id'], $miSucursal]);
                if (!$stmtActivoEnv->fetchColumn()) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=error_producto_inactivo_transf'); exit();
                }

                // Candado sobre la fila de stock para evitar que una venta simultanea
                // descuente el mismo stock mientras se procesa el envio
                $stmtOr = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
                $stmtOr->execute([$transf['producto_id'], $miSucursal]);
                $stockOr = $stmtOr->fetchColumn();

                if ($stockOr === false || floatval($stockOr) < floatval($transf['cantidad'])) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=error_stock_envio'); exit();
                }

                $stockAntOrigen   = floatval($stockOr);
                $stockNuevoOrigen = $stockAntOrigen - floatval($transf['cantidad']);
                $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                    ->execute([$stockNuevoOrigen, $transf['producto_id'], $miSucursal]);
                // El "#id" en el motivo marca que esta transferencia YA desconto el origen
                // al enviar — recibir usa esa marca para no descontar doble (compatibilidad
                // con transferencias enviadas antes de este cambio).
                $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Transferencia',?,?,?,?)")
                    ->execute([$transf['producto_id'], $_SESSION['usuario_id'], $miSucursal, $transf['cantidad'], $stockAntOrigen, $stockNuevoOrigen, 'Transferencia enviada #' . $id]);

                $pdo->prepare("UPDATE transferencias SET estado='En tránsito' WHERE transferencias_id=? AND estado='Aprobada' AND sucursal_origen_id=?")
                    ->execute([$id, $miSucursal]);

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                error_log('[Ferreteria/transferencias] Error al enviar #' . $id . ': ' . $e->getMessage());
                header('Location: transferencias.php?msg=error_envio'); exit();
            }
        } else {
            // [FIX-ENVIAR-IDOR] Mismo caso que arriba pero en el chequeo SIN candado previo a la
            // transaccion: si no hay fila (no eres origen, o el estado ya no es 'Aprobada'), no
            // caer al "header(...msg=$accion)" generico del final (linea ~295) que mostraba exito.
            header('Location: transferencias.php?msg=error_ya_no_aprobada'); exit();
        }
    } elseif ($accion === 'recibir') {
        // Recepcion de UNA fila, completa (sin formulario). La pantalla usa 'recibir_pedido'; esta ruta se conserva por
        // compatibilidad y usa la misma logica de includes/transferencias_lib.php.
        $pdo->beginTransaction();
        try {
            $errRec = transfRecibirFila($pdo, $id, intval($miSucursal), intval($_SESSION['usuario_id']), null, (string)$_SESSION['nombre_completo']);
            if ($errRec !== null) {
                $pdo->rollBack();
                header('Location: transferencias.php?msg=' . $errRec); exit();
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Ferreteria/transferencias] Error al recibir #' . $id . ': ' . $e->getMessage());
            header('Location: transferencias.php?msg=error_recibir'); exit();
        }
    }
    // [FEATURE-TICKET-ENVIO-TRANSF] Tras enviar (uno o todo el pedido) se abre el ticket de envio.
    // Al marcar enviado se abre el ticket y se manda a IMPRIMIR solo (imprimir_ticket=1): sale con la mercancia.
    $ticketQs = in_array($accion, ['enviar', 'enviar_pedido'], true) ? '&abrir_ticket=' . $id . '&imprimir_ticket=1' : '';
    header('Location: transferencias.php?msg='.$accion . $ticketQs . $extraQs); exit();
}

// Nueva solicitud — multi-producto, YO soy el DESTINO (quien pide), ORIGEN = otra sucursal
// [FEATURE-ENVIO-DIRECTO] El formulario tiene dos modos: 'solicitud' (pido productos y el origen los acepta) y
// 'directo' (yo, como ORIGEN, armo un envio para otra sucursal sin que ella lo autorice; queda "Aprobada" = en preparacion
// y sale hasta que lo marco enviado, cuando ya llego el repartidor).
$modoForm = (is_scalar($_POST['modo'] ?? null) && $_POST['modo'] === 'directo') ? 'directo' : 'solicitud';
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $modoForm === 'directo') {
    requerirCSRF($_POST['_token'] ?? '', 'transferencias.php');
    $destinoDirecto = intval(is_scalar($_POST['sucursal_destino_id'] ?? null) ? $_POST['sucursal_destino_id'] : 0);
    $notasDirecto   = trim(is_scalar($_POST['notas'] ?? null) ? (string)$_POST['notas'] : '');
    $itemsDirecto   = json_decode(is_scalar($_POST['items_transf'] ?? null) ? (string)$_POST['items_transf'] : '[]', true);
    $resDirecto = transfCrearEnvioDirecto($pdo, intval($_SESSION['sucursal_id']), $destinoDirecto, intval($_SESSION['usuario_id']), (string)$_SESSION['nombre_completo'], $notasDirecto, $itemsDirecto);
    if ($resDirecto['ok']) {
        header('Location: transferencias.php?msg=directo_guardado&nuevo_folio=' . urlencode($resDirecto['folio'])); exit();
    }
    $errores = $resDirecto['errores'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $modoForm === 'solicitud') {
    // [FIX-A1] Verificar CSRF antes de procesar la nueva solicitud de transferencia
    requerirCSRF($_POST['_token'] ?? '', 'transferencias.php');
    $sucursal_origen_id = intval(is_scalar($_POST['sucursal_origen_id'] ?? null) ? $_POST['sucursal_origen_id'] : 0);
    $notas              = trim(is_scalar($_POST['notas'] ?? null) ? (string)$_POST['notas'] : '');
    $itemsTransfRaw     = is_scalar($_POST['items_transf'] ?? null) ? $_POST['items_transf'] : '[]';
    $items              = json_decode($itemsTransfRaw ?? '[]', true);

    if (!$sucursal_origen_id)                              $errores[] = 'Selecciona la sucursal de origen.';
    if ($sucursal_origen_id == $_SESSION['sucursal_id'])   $errores[] = 'La sucursal origen no puede ser la misma que la tuya.';

    // [FIX-MEDIO-C-14] (portado de admin/inventario_transferencias.php): antes no se
    // validaba que $items fuera realmente un array de objetos con las llaves esperadas: un
    // items_transf malformado (JSON invalido, un array de escalares, objetos sin
    // "id"/"cantidad") pasaba el "empty($items)" sin problema y podia tronar mas adelante
    // al tratar de indexar un valor que no es array.
    if (!is_array($items)) {
        $errores[] = 'El carrito de productos tiene un formato inválido.';
        $items = [];
    } else {
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'], $item['cantidad']) || !is_numeric($item['id']) || !is_numeric($item['cantidad'])) {
                $errores[] = 'El carrito de productos tiene un formato inválido.';
                $items = [];
                break;
            }
        }
    }
    if (empty($items) && empty($errores))                  $errores[] = 'Agrega al menos un producto.';

    if (empty($errores)) {
        foreach ($items as $item) {
            $prodId   = intval($item['id']);
            $cantidad = floatval($item['cantidad']);
            // Validar tipo_venta en backend
            $stmtTV2 = $pdo->prepare("SELECT p.tipo_venta, p.nombre_producto, ss.stock_actual, ss.activo AS ss_activo FROM productos p LEFT JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ? WHERE p.producto_id = ?");
            $stmtTV2->execute([$sucursal_origen_id, $prodId]);
            $tvRow2 = $stmtTV2->fetch(PDO::FETCH_ASSOC);
            // [FIX-TRANSF-CREACION-ACTIVO 2026-09-20] El selector de producto del formulario
            // solo carga productos activos en la sucursal origen AL CARGAR LA PAGINA -- si el
            // producto se da de baja de esa sucursal despues (ej. otra pestana), esta consulta
            // nunca lo revisaba antes de crear la solicitud. Confirmado en vivo.
            if (!$tvRow2 || !intval($tvRow2['ss_activo'] ?? 0)) {
                $nombreProd = $tvRow2['nombre_producto'] ?? "Producto #$prodId";
                $errores[] = "\"$nombreProd\": ya no está disponible en la sucursal origen (fue dado de baja). Recarga la página e intenta de nuevo.";
                continue;
            }
            if ($tvRow2['tipo_venta'] !== 'Suelto') {
                $cantidad = floor($cantidad);
            }
            if ($cantidad <= 0) continue;
            // Validar que la cantidad no supere el stock real en la sucursal origen
            $stockOrigen = floatval($tvRow2['stock_actual']);
            if ($cantidad > $stockOrigen) {
                $nombreProd  = $tvRow2['nombre_producto'];
                $errores[] = "\"$nombreProd\": se solicitaron " . number_format($cantidad, 2) . " pero la sucursal origen solo tiene " . number_format($stockOrigen, 2) . " disponibles.";
            }
        }
    }

    if (empty($errores)) {
        // [FEATURE-FOLIO-TRANSF] Crear el pedido con su folio mensual (candado + transaccion en includes/transferencias_lib.php).
        // [FIX-MEDIO-C-13] Solo se muestra exito si de verdad se inserto alguna fila.
        $resSol = transfCrearSolicitud($pdo, $sucursal_origen_id, intval($_SESSION['sucursal_id']), intval($_SESSION['usuario_id']), $notas, $items);
        if ($resSol['insertados'] > 0) {
            header('Location: transferencias.php?msg=solicitado&nuevo_folio=' . urlencode($resSol['folio'])); exit();
        }
        $errores[] = $resSol['error'] !== '' ? $resSol['error'] : 'Ningún producto tenía una cantidad válida después de ajustar por tipo de venta. Revisa las cantidades e intenta de nuevo.';
    }
}

// Filtro de fechas para el historial
// El historial abre mostrando desde DOS DIAS ANTES de hoy (antes: desde el dia 1 del mes).
$filtroDesde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 days'));
$filtroHasta = $_GET['hasta'] ?? date('Y-m-d');
$filtroEstado = trim(is_scalar($_GET['estado_f'] ?? null) ? (string)$_GET['estado_f'] : '');
$filtroFolio  = transfFiltroFolio($_GET);   // [FEATURE-FOLIO-TRANSF] buscar un pedido por su folio (NNNN + mes + anio)

$whereExtra = '';
$paramsExtra = [];
// [FIX-FILTRO-PEDIDOS-EN-PROCESO 2026-10-10] Con las fechas predeterminadas (hoy - 2 dias a hoy) un pedido que sigue
// EN PROCESO (Pendiente, Aprobada, Modificada o En transito) no debe esconderse solo por ser mas viejo: la tarjeta del
// inicio cuenta todos los pendientes y la lista los ocultaba. Si el usuario escribe otras fechas, se respetan tal cual.
$estadosEnProceso = ['Pendiente', 'Aprobada', 'Modificada', 'En tránsito'];
$fechasPredeterminadas = ($filtroDesde === date('Y-m-d', strtotime('-2 days')) && $filtroHasta === date('Y-m-d'));
if ($fechasPredeterminadas && !$filtroEstado) {
    $whereExtra .= " AND (DATE(t.created_at) >= ? OR t.estado IN ('Pendiente','Aprobada','Modificada','En tránsito'))";
    $paramsExtra[] = $filtroDesde;
} elseif ($fechasPredeterminadas && in_array($filtroEstado, $estadosEnProceso, true)) {
    // filtrando por un estado en proceso con las fechas de siempre: se muestran todos, sin importar la antiguedad
} else {
    if ($filtroDesde) { $whereExtra .= ' AND DATE(t.created_at) >= ?'; $paramsExtra[] = $filtroDesde; }
    if ($filtroHasta) { $whereExtra .= ' AND DATE(t.created_at) <= ?'; $paramsExtra[] = $filtroHasta; }
}
if ($filtroEstado) { $whereExtra .= ' AND t.estado = ?'; $paramsExtra[] = $filtroEstado; }
// Buscando por folio se ignoran fechas y estado: el pedido debe aparecer sin importar que tan viejo sea.
if ($filtroFolio) {
    $whereExtra  = ' AND t.folio_periodo = ? AND CAST(t.folio AS UNSIGNED) = ?';
    $paramsExtra = [$filtroFolio['periodo'], $filtroFolio['numero']];
}

// Transferencias de esta sucursal (como origen o destino)
$stmt = $pdo->prepare("
    SELECT t.*,
        p.nombre_producto, p.codigo, p.tipo_venta, p.unidad_medida,
        so.nombre AS sucursal_origen,
        sd.nombre AS sucursal_destino,
        us.nombre_completo AS solicitante,
        ua.nombre_completo AS aprobador,
        (SELECT ss.stock_actual FROM stock_sucursal ss WHERE ss.producto_id = t.producto_id AND ss.sucursal_id = t.sucursal_origen_id LIMIT 1) AS stock_origen
    FROM transferencias t
    JOIN productos p ON t.producto_id = p.producto_id
    JOIN sucursales so ON t.sucursal_origen_id = so.sucursal_id
    JOIN sucursales sd ON t.sucursal_destino_id = sd.sucursal_id
    JOIN usuarios us ON t.usuario_solicita_id = us.usuario_id
    LEFT JOIN usuarios ua ON t.usuario_aprueba_id = ua.usuario_id
    WHERE (t.sucursal_origen_id = ? OR t.sucursal_destino_id = ?)
    $whereExtra
    ORDER BY t.created_at DESC
    LIMIT 100
");
$stmt->execute(array_merge([$_SESSION['sucursal_id'], $_SESSION['sucursal_id']], $paramsExtra));
$transferencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sucursales origen disponibles
$stmt = $pdo->prepare("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1 AND sucursal_id != ?");
$stmt->execute([$_SESSION['sucursal_id']]);
$sucursalesOrigen = $stmt->fetchAll(PDO::FETCH_ASSOC);

// INNER JOIN por codigo: solo productos que existen en AMBAS sucursales
// "bajo" = mi sucursal tiene ese producto con stock < minimo (y minimo > 0)
$stmt = $pdo->prepare("
    SELECT
        p.producto_id,
        p.codigo,
        p.nombre_producto,
        p.tipo_venta,
        ss_origen.sucursal_id,
        ss_origen.stock_actual,
        ss_mia.stock_actual AS mi_stock,
        (ss_mia.stock_actual < ss_mia.stock_minimo AND ss_mia.stock_minimo > 0) AS bajo
    FROM productos p
    INNER JOIN stock_sucursal ss_origen ON ss_origen.producto_id = p.producto_id
        AND ss_origen.sucursal_id != ? AND ss_origen.activo = 1 AND ss_origen.stock_actual > 0
    INNER JOIN stock_sucursal ss_mia ON ss_mia.producto_id = p.producto_id
        AND ss_mia.sucursal_id = ?
    WHERE p.activo = 1
    ORDER BY bajo DESC, p.nombre_producto ASC
");
$stmt->execute([$_SESSION['sucursal_id'], $_SESSION['sucursal_id']]);

$prodsBySucursal = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $prodsBySucursal[$p['sucursal_id']][] = [
        'id'         => intval($p['producto_id']),
        'codigo'     => $p['codigo'],
        'nombre'     => $p['nombre_producto'],
        'stock'      => floatval($p['stock_actual']),
        'mi_stock'   => floatval($p['mi_stock']),
        'bajo'       => (bool)$p['bajo'],
        'tipo_venta' => $p['tipo_venta'],
    ];
}
// [FEATURE-ENVIO-DIRECTO] Para "Enviar productos": MIS productos activos con stock y, por cada sucursal destino, el stock
// que ella ya tiene del mismo producto. Se excluyen los que el destino tiene dados de baja (no podria recibirlos).
// 'stock' = lo que tengo yo (tope de lo que puedo enviar); 'mi_stock' = lo que ya tiene el destino; 'bajo' = destino bajo minimo.
$prodsEnvio = [];
$sucEnvio = intval($_SESSION['sucursal_id']);
if ($sucEnvio !== 0) {
    $stmt = $pdo->prepare("
        SELECT p.producto_id, p.codigo, p.nombre_producto, p.tipo_venta,
               ss_mia.stock_actual AS mi_stock_real,
               s.sucursal_id AS destino_id,
               COALESCE(ss_dest.stock_actual, 0) AS dest_stock,
               (ss_dest.stock_actual < ss_dest.stock_minimo AND ss_dest.stock_minimo > 0) AS bajo
        FROM productos p
        INNER JOIN stock_sucursal ss_mia ON ss_mia.producto_id = p.producto_id
            AND ss_mia.sucursal_id = ? AND ss_mia.activo = 1 AND ss_mia.stock_actual > 0
        CROSS JOIN sucursales s
        LEFT JOIN stock_sucursal ss_dest ON ss_dest.producto_id = p.producto_id AND ss_dest.sucursal_id = s.sucursal_id
        WHERE p.activo = 1 AND s.activo = 1 AND s.sucursal_id != ?
          AND (ss_dest.producto_id IS NULL OR ss_dest.activo = 1)
        ORDER BY bajo DESC, p.nombre_producto ASC
    ");
    $stmt->execute([$sucEnvio, $sucEnvio]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $prodsEnvio[$p['destino_id']][] = [
            'id'         => intval($p['producto_id']),
            'codigo'     => $p['codigo'],
            'nombre'     => $p['nombre_producto'],
            'stock'      => floatval($p['mi_stock_real']),
            'mi_stock'   => floatval($p['dest_stock']),
            'bajo'       => (bool)$p['bajo'],
            'tipo_venta' => $p['tipo_venta'],
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transferencias — Ferretería Aldrete</title>
<link rel="stylesheet" href="../includes/ticket_impresion.css" media="print">
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
    .menu-label { padding: 8px 16px 4px; font-size: 10px; font-weight: 700; color: #14ace7; text-transform: uppercase; letter-spacing: 0.5px; }
    .divider { height: 1px; background: #f0f0f0; margin: 6px 8px; }
    .sidebar-footer { padding: 12px 16px; border-top: 1px solid #f0f0f0; font-size: 11px; color: #bbb; white-space: nowrap; }
    .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #f7f7f7; }
    .topbar { background: #14ace7; color: white; padding: 0 20px; height: 52px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .topbar-left { display: flex; align-items: center; gap: 12px; }
    .topbar h2 { font-size: 15px; font-weight: 600; }
    .toggle-btn { background: none; border: none; color: white; cursor: pointer; font-size: 20px; padding: 4px 8px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; }
    .toggle-btn:hover { background: rgba(255,255,255,0.2); }
    .topbar-right { display: flex; align-items: center; gap: 14px; font-size: 13px; }
    .logout-btn { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); color: white; padding: 5px 14px; border-radius: 5px; cursor: pointer; font-size: 12px; }
    .logout-btn:hover { background: rgba(255,255,255,0.3); }
    .content { flex: 1; padding: 20px; overflow-y: auto; display: grid; grid-template-columns: 1fr 370px; gap: 16px; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 18px; margin-bottom: 14px; }
    .card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 14px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; display: inline-block; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 10px 12px; text-align: left; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 10px 12px; font-size: 12px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-pendiente  { background: #e3f2fd; color: #1565c0; }
    .badge-aprobada   { background: #e8f5e9; color: #2e7d32; }
    .badge-rechazada  { background: #fdecea; color: #c0392b; }
    .badge-transito   { background: #fff8e1; color: #e65100; }
    .badge-entregada  { background: #f0f0f0; color: #666; }
    .badge-modificada { background: #e8eaf6; color: #283593; }
    .btn-aceptar-mod  { background: #e8f5e9; color: #2e7d32; }
    .btn-rechazar-mod { background: #fdecea; color: #c0392b; }
    .direction-badge { font-size: 10px; padding: 2px 6px; border-radius: 99px; font-weight: 600; }
    .dir-enviada { background: #fdecea; color: #c0392b; }
    .dir-recibida { background: #e8f5e9; color: #2e7d32; }
    .acciones { display: flex; gap: 5px; flex-wrap: wrap; }
    .btn-accion { padding: 4px 10px; border-radius: 5px; font-size: 11px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-aprobar { background: #e8f5e9; color: #2e7d32; }
    .btn-rechazar { background: #fdecea; color: #c0392b; }
    .btn-enviar   { background: #e3f2fd; color: #1565c0; }
    .btn-recibir  { background: #f3e5f5; color: #6a1b9a; }
    /* [DISENO-PEDIDOS] Tabla de pedidos de transferencia: una fila limpia por pedido */
    .tabla-pedidos { width: 100%; border-collapse: collapse; }
    .tabla-pedidos th { padding: 11px 14px; }
    .tabla-pedidos td { padding: 14px; vertical-align: top; border-bottom: 1px solid #eef0f2; }
    .tabla-pedidos tr:hover td { background: #fcfdfe; }
    .tabla-pedidos tr:last-child td { border-bottom: none; }
    .tabla-pedidos .c-ped { width: 150px; }
    .tabla-pedidos .c-ruta { width: 200px; }
    .tabla-pedidos .c-est { width: 150px; }
    .tabla-pedidos .c-acc { width: 178px; }
    .ped-id { font-size: 16px; font-weight: 700; color: #1d2733; line-height: 1.2; }
    .ped-meta { font-size: 11px; color: #8a96a3; margin-top: 3px; line-height: 1.4; }
    .ped-rol { display: inline-block; margin-top: 8px; font-size: 10px; font-weight: 700; padding: 2px 9px; border-radius: 99px; letter-spacing: .02em; }
    .rol-envia { background: #fff3e0; color: #c25b00; }
    .rol-recibe { background: #e3f2fd; color: #1565c0; }
    .ruta { display: flex; gap: 8px; align-items: baseline; font-size: 12.5px; line-height: 1.35; margin-bottom: 6px; color: #1d2733; }
    .ruta-l { flex: none; width: 18px; font-size: 10px; font-weight: 700; color: #9aa5b1; text-transform: uppercase; }
    .ped-n { font-size: 11px; color: #8a96a3; margin-bottom: 5px; font-weight: 600; }
    .ped-items { list-style: none; margin: 0; padding: 0; }
    .ped-items li { display: flex; gap: 10px; align-items: baseline; padding: 3px 0; font-size: 12.5px; line-height: 1.35; color: #1d2733; }
    .ped-items .q { flex: none; min-width: 38px; text-align: right; font-weight: 700; color: #1565c0; font-variant-numeric: tabular-nums; }
    .ped-items .nm { flex: 1; min-width: 0; }
    .ped-items .badge { font-size: 10px; padding: 1px 7px; flex: none; }
    .ped-mas summary { cursor: pointer; font-size: 11.5px; font-weight: 600; color: #1565c0; margin-top: 4px; list-style: none; }
    .ped-mas summary::-webkit-details-marker { display: none; }
    .ped-mas[open] summary { margin-bottom: 2px; }
    .est-otros { font-size: 11px; color: #8a96a3; margin-top: 6px; line-height: 1.45; }
    .c-acc .acciones { flex-direction: column; align-items: stretch; gap: 6px; }
    .bt { display: block; width: 100%; text-align: center; padding: 8px 10px; border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1.5px solid transparent; font-family: inherit; line-height: 1.2; }
    .bt svg { margin-right: 3px; }
    .bt-ok { background: #1f9d55; color: #fff; }
    .bt-ok:hover { background: #17824a; }
    .bt-azul { background: #14ace7; color: #fff; }
    .bt-azul:hover { background: #0e93c9; }
    .bt-warn { background: #fff; color: #c25b00; border-color: #f0b27a; }
    .bt-warn:hover { background: #fff3e0; }
    .bt-mal { background: #fff; color: #c0392b; border-color: #e6a5a0; }
    .bt-mal:hover { background: #fdecea; }
    .bt-tk { background: #fff; color: #1565c0; border-color: #a9cdf0; }
    .bt-tk:hover { background: #e3f2fd; }
    .bt-espera { font-size: 11.5px; color: #283593; font-style: italic; text-align: center; padding: 4px 2px; }
    .sin-acc { color: #b4bcc5; text-align: center; font-size: 12px; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    .form-group { margin-bottom: 13px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 600; }
    .form-group select, .form-group input, .form-group textarea { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group select:focus, .form-group input:focus { outline: none; border-color: #14ace7; }
    .search-wrap { position: relative; }
    .search-input { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .search-input:focus { outline: none; border-color: #14ace7; }
    .sugerencias { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ddd; border-top: none; border-radius: 0 0 6px 6px; z-index: 100; max-height: 220px; overflow-y: auto; display: none; box-shadow: 0 4px 12px rgba(0,0,0,.08); }
    .sug-item { padding: 9px 12px; cursor: pointer; font-size: 13px; display: flex; justify-content: space-between; align-items: center; border-bottom: 0.5px solid #f5f5f5; }
    .sug-item:last-child { border-bottom: none; }
    .sug-item:hover { background: #eef8ff; }
    .sug-nombre { color: #333; font-weight: 500; }
    .sug-codigo { font-size: 11px; color: #aaa; margin-left: 6px; }
    .sug-stocks { display: flex; flex-direction: column; align-items: flex-end; gap: 1px; white-space: nowrap; margin-left: 8px; }
    .sug-stock-row { font-size: 11px; }
    .sug-stock-orig { color: #1565c0; }
    .sug-stock-dest { color: #2e7d32; }
    .badge-bajo { background: #fdecea; color: #c0392b; font-size: 10px; padding: 1px 6px; border-radius: 99px; font-weight: 700; margin-left: 6px; }
    .prod-seleccionado { background: #eef8ff; border: 1px solid #bbdefb; border-radius: 6px; padding: 10px 12px; margin-bottom: 10px; display: none; }
    .prod-sel-nombre { font-size: 13px; font-weight: 600; color: #1565c0; }
    .prod-sel-stock { font-size: 12px; color: #555; margin-top: 2px; }
    .cant-row { display: flex; gap: 8px; margin-top: 8px; }
    .cant-row input { flex: 1; padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .cant-row input:focus { outline: none; border-color: #14ace7; }
    .btn-add { background: #14ace7; color: white; border: none; padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
    .lista-items { border: 0.5px solid #eee; border-radius: 6px; min-height: 46px; overflow: hidden; margin-bottom: 13px; }
    .transf-item { display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; }
    .transf-item:last-child { border-bottom: none; }
    .transf-item-nombre { font-weight: 500; color: #333; }
    .transf-item-cant { font-size: 12px; color: #14ace7; font-weight: 700; }
    .btn-quitar { background: none; border: none; color: #c0392b; cursor: pointer; font-size: 18px; line-height: 1; padding: 0 4px; }
    .items-vacio { text-align: center; color: #aaa; font-size: 12px; padding: 14px; }
    .btn-guardar { width: 100%; background: #14ace7; color: white; border: none; padding: 11px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; margin-top: 4px; }
    .btn-guardar:hover { background: #1196cb; }
    .hint-bajo { font-size: 11px; color: #c0392b; margin-top: 4px; }
    .ped-tipo { display: inline-block; margin-top: 6px; font-size: 10px; font-weight: 700; padding: 2px 9px; border-radius: 99px; background: #fff3e0; color: #c25b00; letter-spacing: .02em; }
    .modo-tabs { display: flex; gap: 4px; background: #eef1f4; border-radius: 8px; padding: 3px; margin-bottom: 12px; }
    .modo-tab { flex: 1; border: none; background: transparent; padding: 8px 6px; border-radius: 6px; font-size: 12.5px; font-weight: 600; color: #6b7785; cursor: pointer; font-family: inherit; }
    .modo-tab.activo { background: #fff; color: #14ace7; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
    .aviso-directo { font-size: 12px; color: #5d4a00; background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px; padding: 9px 12px; margin-bottom: 13px; line-height: 1.5; }
    /* Filtros del listado: dos filas (fechas y estado / busqueda por folio), mismos alturas y alineacion */
    .tf-fil { margin-bottom: 10px; padding: 0; overflow: hidden; }
    .tf-fil-fila { display: grid; grid-template-columns: 118px 1fr; gap: 6px 14px; align-items: center; padding: 12px 16px; margin: 0; }
    .tf-fil-fila + .tf-fil-fila { border-top: 1px solid #eef1f4; background: #fafbfc; }
    .tf-fil-folio.tf-fil-activa { background: #eef8ff; box-shadow: inset 3px 0 0 #14ace7; }
    .tf-fil-apagada .tf-fil-campos { opacity: .5; }
    .tf-fil-tit { font-size: 11px; font-weight: 700; color: #7b8794; text-transform: uppercase; letter-spacing: .04em; line-height: 1.3; }
    .tf-fil-campos { display: flex; flex-wrap: wrap; gap: 10px 12px; align-items: flex-end; }
    .tf-fil-campo { display: flex; flex-direction: column; gap: 4px; }
    .tf-fil-campo label, .tf-fil-campos .filtro-group label { font-size: 11px; font-weight: 600; color: #8a96a3; text-transform: none; letter-spacing: 0; margin: 0; }
    .tf-fil-campos .filtro-group { gap: 4px; }
    .tf-fil-campos .filtro-group select { width: 180px; text-overflow: ellipsis; }
    .tf-fil-campo input, .tf-fil-campo select, .tf-fil-campos .filtro-group select { box-sizing: border-box; height: 36px; padding: 0 10px; border: 1px solid #d5dbe1; border-radius: 6px; font-size: 13px; font-family: inherit; color: #333; background: #fff; }
    .tf-fil-campo input:focus, .tf-fil-campo select:focus, .tf-fil-campos .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .tf-fil-campo .w-fecha { width: 142px; }
    .tf-fil-campo .w-estado { width: 126px; }
    .tf-fil-campo .w-folio { width: 100px; }
    .tf-fil-campo .w-mes { width: 128px; }
    .tf-fil-campo .w-anio { width: 86px; }
    .tf-fil-btn { box-sizing: border-box; height: 36px; padding: 0 15px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-family: inherit; border: 1px solid transparent; }
    .tf-fil-btn-azul { background: #14ace7; color: #fff; }
    .tf-fil-btn-azul:hover { background: #0e93c9; }
    .tf-fil-btn-gris { background: #fff; color: #666; border-color: #d5dbe1; }
    .tf-fil-btn-gris:hover { background: #f3f5f7; }
    .tf-fil-nota { font-size: 11.5px; color: #8a96a3; align-self: center; }
    @media (max-width: 768px) {
        body { overflow-x: hidden; }
        .tf-fil-fila { grid-template-columns: 1fr; padding: 12px; }
        .tf-fil-campo .w-fecha, .tf-fil-campo .w-estado, .tf-fil-campo .w-mes { width: 100%; }
        .tf-fil-campo { flex: 1 1 120px; }
        .tf-fil-nota { flex-basis: 100%; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100%; z-index: 300; width: 0; transition: width 0.3s; }
        .sidebar.collapsed { width: 260px; box-shadow: 4px 0 16px rgba(0,0,0,.15); }
        .main { width: 100%; }
        .topbar { padding: 0 12px; height: 48px; }
        .topbar h2 { font-size: 13px; }
        .topbar-right { gap: 8px; font-size: 12px; }
        .topbar-right > span { display: none; }
        .content { padding: 12px !important; display: block !important; }
        .content > div + div { margin-top: 12px; }
        .card { overflow-x: auto; }
        th, td { padding: 8px 10px; font-size: 12px; }
        .form-group input, .form-group select, .form-group textarea { font-size: 16px; }
        .logout-btn { padding: 5px 10px; font-size: 11px; }
    }
    </style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>Ferretería Aldrete</h3>
        <p>Cajero / Inventario</p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item" href="inicioCajeroInventario.php">Inicio</a>
        <div class="divider"></div>

        <div class="menu-label">Ventas</div>
        <a class="menu-item" href="nuevaVenta.php">Nueva venta</a>
        <a class="menu-item" href="historialVentas.php">Historial de ventas</a>
        <a class="menu-item" href="ventasPendientes.php">Ventas a Domicilio</a>
        <a class="menu-item" href="devoluciones.php">Devoluciones</a>
        <div class="divider"></div>

        <div class="menu-label">Caja</div>
        <a class="menu-item" href="abrirCaja.php">Abrir caja</a>
        <a class="menu-item" href="corteCaja.php">Corte de caja</a>
        <a class="menu-item" href="historialCortes.php">Historial de cortes</a>
        <div class="divider"></div>

        <div class="menu-label">Clientes</div>
        <a class="menu-item" href="clientes.php">Clientes</a>
        <a class="menu-item" href="creditos.php">Créditos</a>
        <div class="divider"></div>

        <div class="menu-label">Inventario</div>
        <a class="menu-item" href="productos.php">Productos</a>
        <a class="menu-item" href="categorias.php">Categorías</a>
        <a class="menu-item" href="unidades.php">Unidades de medida</a>
        <a class="menu-item" href="entradas.php">Entradas</a>
        <a class="menu-item" href="salidas.php">Salidas y mermas</a>
        <a class="menu-item" href="historial.php">Movimientos</a>
        <div class="divider"></div>

        <div class="menu-label">Proveedores</div>
        <a class="menu-item" href="proveedores.php">Proveedores</a>
        <a class="menu-item" href="compras.php">Compras</a>
        <div class="divider"></div>

        <div class="menu-label">Más</div>
        <a class="menu-item" href="paquetes.php">Paquetes</a>
        <a class="menu-item active" href="transferencias.php">Transferencias</a>
        <a class="menu-item" href="promociones.php">Promociones</a>
        <a class="menu-item" href="masVendidos.php">Más vendidos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>
<script>
// Restaurar el scroll del sidebar ANTES de que se pinte el resto de la pagina — si se
// hace hasta el <script> del final, primero se ve el sidebar en 0 y luego "salta" a la
// posicion guardada, dando sensacion de lag. Aqui corre justo despues del sidebar, antes
// de que el navegador tenga que parsear el resto del contenido de la pagina.
(function() {
    var menu = document.querySelector('.sidebar-menu');
    if (!menu) return;
    var saved = sessionStorage.getItem('cajeroInvSidebarScroll');
    if (saved !== null) menu.scrollTop = parseInt(saved, 10) || 0;
    menu.addEventListener('scroll', function() {
        sessionStorage.setItem('cajeroInvSidebarScroll', menu.scrollTop);
    });
})();
</script>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()"><?= icono('menu') ?></button>
            <h2>Transferencias entre sucursales</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Lista de transferencias -->
        <div>
            <?php if (isset($_GET['msg'])): ?>
                <?php $msgs = [
                    'solicitado'      => 'Solicitud enviada. La sucursal origen debe aprobarla.',
                    'directo_guardado' => 'Pedido de envío guardado: queda en preparación y todavía NO se descuenta tu stock. Cuando salga con el repartidor, márcalo como enviado desde el botón "Marcar pedido enviado" (ahí se descuenta y se imprime el ticket).',
                    'editar_pedido_directo' => 'Pedido actualizado. Los cambios ya aplican; la sucursal destino no tiene que confirmar nada.',
                    'cancelar_preparado_pedido' => 'Pedido de envío cancelado. No se movió inventario porque aún no había salido.',
                    'aprobar'         => 'Transferencia aprobada. Se agrego una nota para la sucursal que recibira el pedido.',
                    'rechazar'        => 'Transferencia rechazada.',
                    'enviar'          => 'Productos enviados. El stock ya se desconto de tu sucursal; la sucursal destino debe confirmar la recepcion.',
                    'recibir'         => 'Recepcion confirmada. El stock fue sumado a tu sucursal.',
                    'cantidad_editada'       => 'Cantidad modificada. La sucursal destino debe confirmar el cambio.',
                    'aceptar_modificacion'  => 'Cambio de cantidad aceptado. La transferencia continua como Aprobada.',
                    'rechazar_modificacion' => 'Cambio de cantidad rechazado. La transferencia volvio a estado Pendiente.',
                    'error_stock_editar'    => 'No se puede guardar: la cantidad ingresada supera el stock disponible en la sucursal origen.',
                    'error_stock_envio'     => 'No se puede enviar: ya no hay stock suficiente en tu sucursal para esta transferencia. Edita la cantidad o rechazala.',
                    'error_envio'           => 'Error al registrar el envio. Intenta de nuevo.',
                    'error_stock_recibir'   => 'No se puede confirmar: la sucursal origen ya no tiene stock suficiente registrado. Contacta a la sucursal origen.',
                    'error_recibir'         => 'Error al confirmar la recepcion. Intenta de nuevo.',
                    'cancelar'              => 'Transferencia cancelada. El stock regreso a tu sucursal.',
                    'error_cancelar'        => 'Error al cancelar la transferencia. Intenta de nuevo.',
                    'cancelar_solicitud'    => 'Solicitud cancelada.',
                    'error_ya_no_cancelable' => 'No se pudo cancelar: solo se puede cancelar una solicitud propia mientras sigue Pendiente (la otra sucursal aun no la aprueba). Si ya fue aprobada, ya no se puede cancelar.',
                    'enviar_pedido'         => 'Pedido enviado: todos los productos aprobados se marcaron como enviados y el stock ya se desconto de tu sucursal. Imprime el ticket para que la sucursal destino verifique el pedido.',
                    'aprobar_pedido'        => 'Pedido aceptado. Prepara el envío de todos los productos y márcalo como enviado cuando salga.',
                    'rechazar_pedido'       => 'Pedido rechazado.',
                    'cancelar_solicitud_pedido' => 'Pedido cancelado.',
                    'editar_pedido'         => 'Pedido modificado. Los productos con cantidad distinta esperan la confirmación de la sucursal destino; el resto quedó aceptado.',
                    'aceptar_modificacion_pedido'  => 'Cambios aceptados. El pedido continúa como Aprobado.',
                    'rechazar_modificacion_pedido' => 'Cambios rechazados. Los productos modificados volvieron a Pendiente.',
                    'recibir_pedido'        => 'Recepción confirmada. Recibido y merma se sumaron al stock de tu sucursal.',
                    'error_recepcion_suma'  => 'No se pudo confirmar la recepción: en un producto, Recibido + Merma + No enviado no es igual a lo Enviado.',
                    'error_recepcion_datos' => 'No se pudo confirmar la recepción: captura una cantidad válida (0 o mayor) en Recibido, Merma y No enviado de todos los productos.',
                    'error_recepcion_entero' => 'No se pudo confirmar la recepción: ese producto no es granel, sus cantidades deben ser números enteros.',
                    'error_recepcion_emoji' => 'No se pudo confirmar la recepción: las observaciones no pueden llevar emojis. Quítalos e intenta de nuevo.',
                    'cancelar_pedido'       => 'Envío cancelado. El stock de todos los productos regresó a tu sucursal.',
                    'error_pedido'          => 'No se pudo completar la operación sobre el pedido. Intenta de nuevo.',
                    'error_pedido_incompleto' => 'No se puede enviar el pedido todavía: tiene productos sin aceptar o con un cambio de cantidad pendiente de confirmar por la sucursal destino.',
                    'error_cantidad_pedido' => 'No se pudo guardar: revisa las cantidades (no pueden ser negativas y solo un producto Pendiente se puede retirar con 0).',
                    'error_pedido_vacio'    => 'No se pudo guardar: no puedes retirar todos los productos del pedido. Si no vas a enviar nada, usa "Rechazar pedido".',
                    'error_pedido_sin_cambios' => 'No cambiaste ninguna cantidad. Si todo está bien, usa "Aceptar pedido".',
                    'error_ya_no_transito'  => 'No se pudo completar: la transferencia ya no esta "En transito" (alguien mas ya la modifico).',
                    'error_ya_no_pendiente' => 'No se pudo completar: la solicitud ya no esta pendiente (alguien mas ya la aprobo, rechazo, o no eres la sucursal origen).',
                    'error_ya_no_aprobada'  => 'No se pudo completar: la transferencia ya no esta "Aprobada" (alguien mas ya la modifico, o no eres la sucursal origen).',
                    'error_ya_no_modificada' => 'No se pudo completar: la transferencia ya no tiene un cambio de cantidad pendiente de confirmar (alguien mas ya lo acepto/rechazo, o no eres la sucursal destino).',
                    'error_producto_inactivo_transf' => 'No se pudo completar: el producto ya no esta activo en la sucursal correspondiente (fue dado de baja). Revisalo antes de continuar.',
                ]; ?>
                <?php $msgKeyTransf = is_scalar($_GET['msg'] ?? null) ? $_GET['msg'] : ''; ?>
                <?php $esMsgError = str_starts_with($msgKeyTransf, 'error'); ?>
                <div class="msg <?= $esMsgError ? 'errores' : 'msg-exito' ?>"><?= htmlspecialchars($msgs[$msgKeyTransf] ?? '') ?><?php
                    // (el folio del aviso viaja como nuevo_folio: el parametro 'folio' es el de la BUSQUEDA por folio)
                    $folioMsgT = (is_scalar($_GET['nuevo_folio'] ?? null) && ctype_digit((string)$_GET['nuevo_folio']) && strlen((string)$_GET['nuevo_folio']) <= 9) ? (string)$_GET['nuevo_folio'] : '';
                    if (!$esMsgError && $folioMsgT !== '' && in_array($msgKeyTransf, ['solicitado', 'directo_guardado'], true)): ?> Folio: <strong><?= htmlspecialchars($folioMsgT) ?></strong>.<?php endif;
                    if ($msgKeyTransf === 'recibir_pedido'):
                        if (($_GET['conmerma'] ?? '') === '1'): ?> La merma ya cuenta en tu stock: dala de baja desde <strong>Salidas y mermas</strong>.<?php endif;
                        if (($_GET['sinenviar'] ?? '') === '1'): ?> Lo que no llegó regresó al stock de la sucursal origen.<?php endif;
                    endif;
                    $detMsgTransf = is_scalar($_GET['det'] ?? null) ? mb_substr((string)$_GET['det'], 0, 150) : ''; if ($esMsgError && $detMsgTransf !== ''): ?> Producto: <strong><?= htmlspecialchars($detMsgTransf) ?></strong><?php endif; ?></div>
            <?php endif; ?>

            <!-- Filtros: fechas y estado, y aparte la busqueda por folio -->
            <div class="card tf-fil">
                <form method="GET" class="tf-fil-fila<?= $filtroFolio ? ' tf-fil-apagada' : '' ?>">
                    <div class="tf-fil-tit">Fechas y estado</div>
                    <div class="tf-fil-campos">
                        <div class="tf-fil-campo">
                            <label>Desde</label>
                            <input type="date" name="desde" class="w-fecha" value="<?= htmlspecialchars($filtroDesde) ?>">
                        </div>
                        <div class="tf-fil-campo">
                            <label>Hasta</label>
                            <input type="date" name="hasta" class="w-fecha" value="<?= htmlspecialchars($filtroHasta) ?>">
                        </div>
                        <div class="tf-fil-campo">
                            <label>Estado</label>
                            <select name="estado_f" class="w-estado">
                                <option value="">Todos</option>
                                <?php foreach (['Pendiente','Aprobada','Modificada','En tránsito','Entregada','Rechazada','Cancelada'] as $est): ?>
                                <option value="<?= $est ?>" <?= $filtroEstado === $est ? 'selected' : '' ?>><?= $est ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="tf-fil-btn tf-fil-btn-azul">Filtrar</button>
                        <a class="tf-fil-btn tf-fil-btn-gris" href="transferencias.php">Limpiar</a>
                    </div>
                </form>
                <form method="GET" class="tf-fil-fila tf-fil-folio<?= $filtroFolio ? ' tf-fil-activa' : '' ?>">
                    <div class="tf-fil-tit">Buscar por folio</div>
                    <div class="tf-fil-campos">
                        <div class="tf-fil-campo">
                            <label>Folio</label>
                            <input type="number" name="folio" class="w-folio" min="1" max="999999999" required placeholder="Ej. 0012" value="<?= $filtroFolio ? intval($filtroFolio['numero']) : '' ?>">
                        </div>
                        <div class="tf-fil-campo">
                            <label>Mes</label>
                            <select name="folio_mes" class="w-mes">
                                <?php foreach ([1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'] as $mNum => $mNom): ?>
                                <option value="<?= $mNum ?>" <?= ($filtroFolio ? $filtroFolio['mes'] : intval(date('n'))) === $mNum ? 'selected' : '' ?>><?= $mNom ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tf-fil-campo">
                            <label>Año</label>
                            <select name="folio_anio" class="w-anio">
                                <?php for ($yF = intval(date('Y')); $yF >= intval(date('Y')) - 3; $yF--): ?>
                                <option value="<?= $yF ?>" <?= ($filtroFolio ? $filtroFolio['anio'] : intval(date('Y'))) === $yF ? 'selected' : '' ?>><?= $yF ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <button type="submit" class="tf-fil-btn tf-fil-btn-azul">Buscar</button>
                        <span class="tf-fil-nota">El folio se repite cada mes: elige el mes y el año del pedido. Al buscar por folio se ignoran las fechas y el estado.</span>
                    </div>
                </form>
            </div>

            <div class="card" style="padding:0;overflow-x:auto;">
                <?php if (count($transferencias) > 0): ?>
                <?php
                $_sucMiaTransf = intval($_SESSION['sucursal_id']);
                $pedidos = transfAgruparPedidos($transferencias);
                $badgeMap = [
                    'Pendiente'   => 'badge-pendiente',
                    'Aprobada'    => 'badge-aprobada',
                    'Modificada'  => 'badge-modificada',
                    'En tránsito' => 'badge-transito',
                    'Entregada'   => 'badge-entregada',
                    'Rechazada'   => 'badge-rechazada',
                    'Cancelada'   => 'badge-rechazada',
                ];
                $ordenEstados = ['Modificada', 'Pendiente', 'Aprobada', 'En tránsito', 'Entregada', 'Rechazada', 'Cancelada'];
                $plural = ['Modificada' => 'Modificadas', 'Pendiente' => 'Pendientes', 'Aprobada' => 'Aprobadas', 'En tránsito' => 'En tránsito',
                           'Entregada' => 'Entregadas', 'Rechazada' => 'Rechazadas', 'Cancelada' => 'Canceladas'];
                $nombreCorto = fn($n) => trim(preg_replace('/^Ferremateriales\s+Aldrete\s*/iu', '', (string)$n)) ?: (string)$n;
                $fmtCantPed = fn($c) => ($s = rtrim(rtrim(number_format((float)$c, 3, '.', ''), '0'), '.')) === '' ? '0' : $s;
                ?>
                <table class="tabla-pedidos">
                    <thead>
                        <tr><th>Pedido</th><th>Ruta</th><th>Productos</th><th>Estado</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pedidos as $g):
                            // [FEATURE-PEDIDO-TRANSFERENCIA] Un pedido = UNA fila: todos sus productos juntos y
                            // un solo juego de botones que actua sobre el pedido completo.
                            $filasG      = $g['filas'];
                            $t           = $filasG[0];
                            $idRep       = intval($t['transferencias_id']);
                            $esMiOrigen  = intval($t['sucursal_origen_id']) === $_sucMiaTransf;
                            $esMiDestino = intval($t['sucursal_destino_id']) === $_sucMiaTransf;
                            $conteo = [];
                            foreach ($filasG as $f) { $conteo[$f['estado']] = ($conteo[$f['estado']] ?? 0) + 1; }
                            $estadoPrincipal = 'Pendiente';
                            foreach ($ordenEstados as $est) { if (isset($conteo[$est])) { $estadoPrincipal = $est; break; } }
                            $hayPend  = isset($conteo['Pendiente']);
                            $hayAprob = isset($conteo['Aprobada']);
                            $hayModif = isset($conteo['Modificada']);
                            $hayTrans = isset($conteo['En tránsito']);
                            $mostrarTicketTransf = $hayTrans || isset($conteo['Entregada']);
                            $datosEdicion = [];
                            foreach ($filasG as $f) {
                                $datosEdicion[] = [
                                    'id'       => intval($f['transferencias_id']),
                                    'nombre'   => $f['nombre_producto'],
                                    'codigo'   => $f['codigo'],
                                    'cantidad' => floatval($f['cantidad']),
                                    'estado'   => $f['estado'],
                                    'suelto'   => $f['tipo_venta'] === 'Suelto',
                                    'stock'    => floatval($f['stock_origen'] ?? 0),
                                    'directo'  => (($t['tipo_pedido'] ?? 'Solicitud') === 'Directo'),
                                ];
                            }
                            $jsonEdicion = htmlspecialchars(json_encode($datosEdicion, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            // [FEATURE-RECEPCION-TRANSF] Datos para el formulario de recepcion (solo las filas En transito).
                            $esDirecto = (($t['tipo_pedido'] ?? 'Solicitud') === 'Directo');
                            $folioTxt  = ($t['folio'] !== null && $t['folio'] !== '') ? (string)$t['folio'] : '';
                            $datosRecepcion = [];
                            foreach ($filasG as $f) {
                                if ($f['estado'] !== 'En tránsito') continue;
                                $datosRecepcion[] = [
                                    'id'       => intval($f['transferencias_id']),
                                    'nombre'   => $f['nombre_producto'],
                                    'codigo'   => $f['codigo'],
                                    'cantidad' => floatval($f['cantidad']),
                                    'suelto'   => $f['tipo_venta'] === 'Suelto',
                                    'unidad'   => $f['unidad_medida'] ?? '',
                                ];
                            }
                            $jsonRecepcion = htmlspecialchars(json_encode($datosRecepcion, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            $visibles = array_slice($filasG, 0, 3);
                            $resto    = array_slice($filasG, 3);
                            $pintarItem = function ($f) use ($estadoPrincipal, $badgeMap, $fmtCantPed) { ?>
                                <li>
                                    <span class="q"><?= $fmtCantPed($f['cantidad']) ?></span>
                                    <span class="nm" title="Código: <?= htmlspecialchars($f['codigo']) ?>"><?= htmlspecialchars($f['nombre_producto']) ?></span>
                                    <?php if ($f['estado'] !== $estadoPrincipal): ?><span class="badge <?= $badgeMap[$f['estado']] ?? 'badge-pendiente' ?>"><?= htmlspecialchars($f['estado']) ?></span><?php endif; ?>
                                </li>
                            <?php };
                        ?>
                        <tr>
                            <td class="c-ped">
                                <div class="ped-id"><?= $folioTxt !== '' ? 'Folio ' . htmlspecialchars($folioTxt) : '#' . $idRep ?></div>
                                <div class="ped-meta"><?= date('d/m/Y', strtotime($t['created_at'])) ?></div>
                                <div class="ped-meta"><?= $esDirecto ? 'Envió' : 'Pidió' ?>: <?= htmlspecialchars($t['solicitante']) ?></div>
                                <?php if ($esDirecto): ?><span class="ped-tipo">Envío directo</span><?php endif; ?>
                                <?php if ($esMiOrigen || $esMiDestino): ?>
                                <span class="ped-rol <?= $esMiOrigen ? 'rol-envia' : 'rol-recibe' ?>"><?= $esMiOrigen ? 'Tú envías' : 'Tú recibes' ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="c-ruta">
                                <div class="ruta"><span class="ruta-l">De</span><span><?= htmlspecialchars($nombreCorto($t['sucursal_origen'])) ?></span></div>
                                <div class="ruta"><span class="ruta-l">A</span><span><?= htmlspecialchars($nombreCorto($t['sucursal_destino'])) ?></span></div>
                            </td>
                            <td class="c-prod">
                                <div class="ped-n"><?= count($filasG) ?> producto<?= count($filasG) === 1 ? '' : 's' ?></div>
                                <ul class="ped-items">
                                    <?php foreach ($visibles as $f) { $pintarItem($f); } ?>
                                </ul>
                                <?php if ($resto): ?>
                                <details class="ped-mas">
                                    <summary>Ver <?= count($resto) ?> más</summary>
                                    <ul class="ped-items">
                                        <?php foreach ($resto as $f) { $pintarItem($f); } ?>
                                    </ul>
                                </details>
                                <?php endif; ?>
                            </td>
                            <td class="c-est">
                                <span class="badge <?= $badgeMap[$estadoPrincipal] ?>"><?= htmlspecialchars($esDirecto && $estadoPrincipal === 'Aprobada' ? 'En preparación' : $estadoPrincipal) ?></span>
                                <?php
                                $otros = [];
                                foreach ($ordenEstados as $est) {
                                    if ($est === $estadoPrincipal || !isset($conteo[$est])) continue;
                                    $otros[] = $conteo[$est] . ' ' . ($conteo[$est] === 1 ? $est : $plural[$est]);
                                }
                                if ($otros): ?>
                                <div class="est-otros">Además: <?= htmlspecialchars(implode(', ', $otros)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="c-acc">
                                <div class="acciones">
                                    <?php $hayAcciones = false; ?>
                                    <?php if ($_sucMiaTransf === 0): ?>
                                        <span class="bt-espera">Elige una sucursal para operar</span>
                                        <?php $hayAcciones = true; ?>
                                    <?php else: ?>
                                        <?php if ($esMiOrigen && $hayPend): $hayAcciones = true; ?>
                                            <button class="bt bt-ok" type="button" onclick="return ejecutarAccionTransf('aprobar_pedido', <?= $idRep ?>, '¿Aceptar este pedido completo y comprometerse a enviar todos sus productos?')">Aceptar pedido</button>
                                        <?php endif; ?>
                                        <?php if ($esMiOrigen && $hayAprob && !$hayPend && !$hayModif): $hayAcciones = true; ?>
                                            <button class="bt bt-azul" type="button" onclick="return ejecutarAccionTransf('enviar_pedido', <?= $idRep ?>, '¿Confirmar que ya enviaste TODOS los productos de este pedido? Se descontará el stock de cada uno y se generará el ticket con la lista.')">Marcar pedido enviado</button>
                                        <?php endif; ?>
                                        <?php if ($esMiDestino && $hayModif): $hayAcciones = true; ?>
                                            <button class="bt bt-ok" type="button" onclick="return ejecutarAccionTransf('aceptar_modificacion_pedido', <?= $idRep ?>, '¿Aceptar las nuevas cantidades del pedido? El pedido continuará como Aprobado.')">Aceptar cambios</button>
                                        <?php endif; ?>
                                        <?php if ($esMiDestino && $hayTrans): $hayAcciones = true; ?>
                                            <button class="bt bt-ok" type="button" data-id="<?= $idRep ?>" data-folio="<?= htmlspecialchars($folioTxt) ?>" data-origen="<?= htmlspecialchars($nombreCorto($t['sucursal_origen'])) ?>" data-filas="<?= $jsonRecepcion ?>" onclick="abrirModalRecepcion(this)">Confirmar recepción</button>
                                        <?php endif; ?>
                                        <?php if ($esMiOrigen && $hayModif): $hayAcciones = true; ?>
                                            <span class="bt-espera">Esperando confirmación del destino</span>
                                        <?php endif; ?>
                                        <?php if ($esMiOrigen && ($hayPend || $hayAprob)): $hayAcciones = true; ?>
                                            <button class="bt bt-warn" type="button" data-id="<?= $idRep ?>" data-filas="<?= $jsonEdicion ?>" onclick="abrirModalEditarPedido(this)">Editar pedido</button>
                                        <?php endif; ?>
                                        <?php if ($esMiOrigen && $esDirecto && $hayAprob): $hayAcciones = true; ?>
                                            <!-- Envio directo que aun no sale: el origen puede cancelarlo sin mover inventario. -->
                                            <button class="bt bt-mal" type="button" onclick="return ejecutarAccionTransf('cancelar_preparado_pedido', <?= $idRep ?>, '¿Cancelar este pedido de envío? Aún no ha salido, así que no se mueve inventario; quedará como Cancelado.')">Cancelar pedido</button>
                                        <?php endif; ?>
                                        <?php if ($esMiDestino && $esDirecto && $hayAprob): $hayAcciones = true; ?>
                                            <span class="bt-espera">El origen lo está preparando; aún no sale</span>
                                        <?php endif; ?>
                                        <?php if ($esMiOrigen && $hayPend): $hayAcciones = true; ?>
                                            <button class="bt bt-mal" type="button" onclick="return ejecutarAccionTransf('rechazar_pedido', <?= $idRep ?>, '¿Rechazar este pedido completo de transferencia?')">Rechazar pedido</button>
                                        <?php endif; ?>
                                        <?php if ($esMiDestino && $hayModif): $hayAcciones = true; ?>
                                            <button class="bt bt-mal" type="button" onclick="return ejecutarAccionTransf('rechazar_modificacion_pedido', <?= $idRep ?>, '¿Rechazar los cambios de cantidad? Los productos modificados volverán a Pendiente.')">Rechazar cambios</button>
                                        <?php endif; ?>
                                        <?php if ($esMiDestino && $hayPend): $hayAcciones = true; ?>
                                            <!-- Solo para corregir un error: el pedido sigue Pendiente (el origen aun no lo acepta). -->
                                            <button class="bt bt-mal" type="button" onclick="return ejecutarAccionTransf('cancelar_solicitud_pedido', <?= $idRep ?>, '¿Cancelar este pedido completo? Aún no lo ha aceptado la otra sucursal, así que no pasa nada con el inventario; quedará como Cancelado.')">Cancelar pedido</button>
                                        <?php endif; ?>
                                        <?php if ($esMiOrigen && $hayTrans): $hayAcciones = true; ?>
                                            <button class="bt bt-mal" type="button" onclick="return ejecutarAccionTransf('cancelar_pedido', <?= $idRep ?>, '¿Cancelar el envío de este pedido completo? El stock de todos los productos regresará a tu sucursal. Solo hazlo si de verdad recuperaste la mercancía enviada.')">Cancelar envío</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php $hayAcciones = true; ?>
                                    <!-- [FEATURE-FOLIO-TRANSF] Detalle / historial del pedido (folio, recepcion, merma, observaciones). -->
                                    <button class="bt bt-tk" type="button" onclick="abrirDetallePedido(<?= $idRep ?>)">Detalle</button>
                                    <?php if ($mostrarTicketTransf): $hayAcciones = true; ?>
                                        <!-- [FEATURE-TICKET-ENVIO-TRANSF] Lista de lo enviado en este pedido. -->
                                        <button class="bt bt-tk" type="button" onclick="abrirTicketEnvio(<?= $idRep ?>)"><?= icono('printer', '', 14) ?> Ticket</button>
                                    <?php endif; ?>
                                    <?php if (!$hayAcciones): ?><span class="sin-acc">—</span><?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($filtroFolio && count($pedidos) === 1): ?>
                <script>document.addEventListener('DOMContentLoaded', function () { abrirDetallePedido(<?= intval($pedidos[0]['filas'][0]['transferencias_id']) ?>); });</script>
                <?php endif; ?>
                <?php else: ?>
                    <div class="sin-resultados"><?= $filtroFolio ? 'No se encontró ningún pedido con el folio ' . str_pad((string)$filtroFolio['numero'], 4, '0', STR_PAD_LEFT) . ' de ' . str_pad((string)$filtroFolio['mes'], 2, '0', STR_PAD_LEFT) . '/' . $filtroFolio['anio'] . ' en esta sucursal. Revisa el número, el mes y el año.' : 'No hay transferencias registradas.' ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario: solicitar productos de otra sucursal -->
        <div>
            <div class="card">
                <div class="modo-tabs">
                    <button type="button" class="modo-tab activo" id="tabSolicitud" onclick="cambiarModoTransf('solicitud')">Solicitar productos</button>
                    <button type="button" class="modo-tab" id="tabDirecto" onclick="cambiarModoTransf('directo')">Enviar productos</button>
                </div>
                <p id="modoDescripcion" style="font-size:12px;color:#aaa;margin:0 0 14px;">Pide productos de otra sucursal a la tuya. Ella debe aceptar el pedido.</p>

                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach ($errores as $e):?><li><?= htmlspecialchars($e) ?></li><?php endforeach;?></ul></div>
                <?php endif; ?>

                <form method="POST" id="formTransf">
                    <!-- [FIX-A1] Token CSRF para proteger la nueva solicitud de transferencia -->
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="items_transf" id="inputItemsTransf">
                    <input type="hidden" name="modo" id="inputModo" value="solicitud">
                    <div class="aviso-directo" id="avisoDirecto" style="display:none;">Envío directo: la otra sucursal <strong>no tiene que autorizarlo</strong>. Guardas el pedido para <em>prepararlo</em> (todavía no se descuenta tu stock); cuando llegue el repartidor lo marcas como <strong>enviado</strong> desde la lista: ahí se descuenta y queda <em>En tránsito</em>. Ella solo confirma la recepción (con lo recibido, merma y lo que no llegó).</div>

                    <div class="form-group">
                        <label id="lblSucursal">Sucursal origen *</label>
                        <select name="sucursal_origen_id" id="selOrigen" onchange="onOrigenChange(this.value)">
                            <option value="">-- Selecciona sucursal --</option>
                            <?php foreach ($sucursalesOrigen as $s): ?>
                                <option value="<?= $s['sucursal_id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Buscar producto *</label>
                        <div class="search-wrap">
                            <input type="text" id="busquedaProd" class="search-input"
                                   placeholder="Selecciona primero la sucursal origen..."
                                   autocomplete="off"
                                   oninput="buscarProducto()"
                                   onfocus="buscarProducto()"
                                   disabled>
                            <div class="sugerencias" id="sugerencias"></div>
                        </div>
                        <div class="hint-bajo" id="hintBajo" style="display:none;">&#9650; <span id="hintBajoTxt">Productos marcados en rojo tienen stock bajo en tu sucursal.</span></div>
                    </div>

                    <div class="prod-seleccionado" id="prodSeleccionado">
                        <div class="prod-sel-nombre" id="prodSelNombre"></div>
                        <div class="prod-sel-stock" id="prodSelStock"></div>
                        <div class="cant-row">
                            <input type="number" id="cantProd" placeholder="Cantidad" step="1" min="1">
                            <button type="button" class="btn-add" onclick="agregarItem()">+ Agregar</button>
                        </div>
                    </div>

                    <div class="lista-items" id="listaItems">
                        <div class="items-vacio">Sin productos agregados</div>
                    </div>

                    <div class="form-group">
                        <label>Notas (opcional)</label>
                        <input type="text" name="notas" id="notasTransf" placeholder="Motivo o indicaciones..." oninput="guardarEstadoTransf()">
                    </div>

                    <button class="btn-guardar" type="submit" id="btnGuardarTransf" onclick="return prepararEnvio()">Enviar solicitud</button>
                    <button type="button" onclick="limpiarFormularioTransf()" style="width:100%;margin-top:8px;background:none;border:none;color:#aaa;font-size:12px;cursor:pointer;">Limpiar formulario</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const prodsBySucursal = <?= json_encode($prodsBySucursal) ?>;
// [FEATURE-ENVIO-DIRECTO] Productos de MI sucursal por sucursal destino. Misma forma que prodsBySucursal:
// 'stock' = lo que tengo yo (tope de lo que puedo enviar) y 'mi_stock' = lo que ya tiene la sucursal destino.
const prodsEnvio = <?= json_encode($prodsEnvio) ?>;
const MI_SUCURSAL = <?= intval($_SESSION['sucursal_id']) ?>;

// El mismo formulario sirve para dos cosas: SOLICITAR productos a otra sucursal (ella debe aceptarlos) y ENVIAR
// productos directo (sin autorizacion del destino). Cada modo tiene su propio borrador en localStorage.
const MODOS = {
    solicitud: {
        data: prodsBySucursal, selName: 'sucursal_origen_id', lblSuc: 'Sucursal origen *',
        selPlaceholder: 'Selecciona primero la sucursal origen...',
        lblA: 'Origen', lblB: 'Tu sucursal', lblBSel: 'Destino (tu sucursal)',
        btn: 'Enviar solicitud', verbo: 'pedir', quien: 'La sucursal origen', yaCuenta: 'ya pediste',
        desc: 'Pide productos de otra sucursal a la tuya. Ella debe aceptar el pedido.',
        hintBajo: 'Productos marcados en rojo tienen stock bajo en tu sucursal.',
        draft: 'itemsTransfDraft', draftSuc: 'itemsTransfDraft_sucursal_id', extra: 'transfExtra', exito: 'solicitado',
        vacio: 'Selecciona la sucursal de origen antes de enviar la solicitud.'
    },
    directo: {
        data: prodsEnvio, selName: 'sucursal_destino_id', lblSuc: 'Sucursal destino *',
        selPlaceholder: 'Selecciona primero la sucursal destino...',
        lblA: 'Tu sucursal', lblB: 'Destino', lblBSel: 'Destino',
        btn: 'Guardar pedido de envío', verbo: 'enviar', quien: 'Tu sucursal', yaCuenta: 'ya agregaste',
        desc: 'Arma un envío para otra sucursal sin que ella tenga que autorizarlo.',
        hintBajo: 'Productos marcados en rojo tienen stock bajo en la sucursal destino.',
        draft: 'itemsEnvioDraft', draftSuc: 'itemsEnvioDraft_sucursal_id', extra: 'envioExtra', exito: 'directo_guardado',
        vacio: 'Selecciona la sucursal destino antes de enviar los productos.'
    }
};
// Al volver de un guardado exitoso se limpia el borrador de ESE modo (antes de restaurarlo).
(function() {
    try {
        const msg = new URLSearchParams(window.location.search).get('msg');
        Object.keys(MODOS).forEach(function(k) {
            if (msg === MODOS[k].exito) {
                localStorage.removeItem(MODOS[k].draft);
                localStorage.removeItem(MODOS[k].extra);
                localStorage.setItem('transfModo', k);
            }
        });
    } catch (e) {}
})();
let modoTransf = (function() {
    if (<?= ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errores)) ? 'true' : 'false' ?>) return <?= json_encode($modoForm) ?>;
    try { const g = localStorage.getItem('transfModo'); if (g === 'directo' || g === 'solicitud') return g; } catch (e) {}
    return 'solicitud';
})();
function M() { return MODOS[modoTransf]; }

function cargarItemsBorrador(modo) {
    // [FIX-CARRITO-CROSS-SUCURSAL 2026-09-12] El borrador solo se restaura si es de ESTA sucursal: el
    // Administrador puede cambiar de sucursal a medio armado, y el stock de referencia guardado seria de la otra.
    try {
        const m = MODOS[modo];
        const sucGuardada = parseInt(localStorage.getItem(m.draftSuc));
        if (sucGuardada !== MI_SUCURSAL) return [];
        const guardado = JSON.parse(localStorage.getItem(m.draft));
        return Array.isArray(guardado) ? guardado : [];
    } catch (e) {
        return [];
    }
}
let itemsTransf = cargarItemsBorrador(modoTransf);
let prodSelId    = null;
let prodSelTipo  = 'Unidad'; // 'Suelto' o 'Unidad'
let prodSelStock = 0;        // stock disponible (origen en una solicitud; mi sucursal en un envio) del producto seleccionado

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
// [FIX-C1] Escapa un valor para insertarlo dentro de un string JS de comillas simples
// que a su vez va dentro de un atributo HTML (onclick="...('...')"). esc() por si solo
// no basta ahi (ni siquiera escapa comillas simples): un nombre con apostrofe rompia el
// string de JS y tronaba el onclick completo. Mismo criterio que ya usa nuevaVenta.php.
function escAtribJs(s) {
    return esc(String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'"));
}

// Pinta las etiquetas/placeholders segun el modo (solicitar o enviar).
function aplicarModoUI() {
    const m = M();
    document.getElementById('tabSolicitud').classList.toggle('activo', modoTransf === 'solicitud');
    document.getElementById('tabDirecto').classList.toggle('activo', modoTransf === 'directo');
    document.getElementById('inputModo').value = modoTransf;
    document.getElementById('modoDescripcion').textContent = m.desc;
    document.getElementById('lblSucursal').textContent = m.lblSuc;
    document.getElementById('selOrigen').name = m.selName;
    document.getElementById('btnGuardarTransf').textContent = m.btn;
    document.getElementById('hintBajoTxt').textContent = m.hintBajo;
    document.getElementById('avisoDirecto').style.display = modoTransf === 'directo' ? 'block' : 'none';
}

function cambiarModoTransf(modo) {
    if (modo === modoTransf || !MODOS[modo]) return;
    guardarEstadoTransf();                       // deja guardado el estado del modo que se abandona
    modoTransf = modo;
    try { localStorage.setItem('transfModo', modo); } catch (e) {}
    itemsTransf = cargarItemsBorrador(modo);
    prodSelId = null;
    document.getElementById('prodSeleccionado').style.display = 'none';
    document.getElementById('busquedaProd').value = '';
    hideSug();
    aplicarModoUI();
    // restaurar sucursal y notas guardadas de ESTE modo
    let extra = null;
    try { extra = JSON.parse(localStorage.getItem(M().extra)); } catch (e) {}
    const sel = document.getElementById('selOrigen');
    const quiere = (extra && extra.sucursalOrigenId && Array.from(sel.options).some(o => o.value === extra.sucursalOrigenId)) ? extra.sucursalOrigenId : '';
    sel.value = quiere;
    document.getElementById('notasTransf').value = (extra && extra.notas) ? extra.notas : '';
    onOrigenChange(quiere);
    renderItems();
}

function onOrigenChange(val) {
    const input = document.getElementById('busquedaProd');
    input.value = '';
    input.disabled = !val;
    input.placeholder = val ? 'Escribe nombre o código...' : M().selPlaceholder;
    document.getElementById('prodSeleccionado').style.display = 'none';
    prodSelId = null;
    hideSug();
    if (val) { setTimeout(() => { input.focus(); }, 50); }
    guardarEstadoTransf();
}

// [FIX-BORRADOR-TRANSF] Persistencia de la sucursal elegida y las notas — mismo patrón que
// guardarEstadoVenta() en nuevaVenta.php (los items ya se guardan en renderItems()).
function guardarEstadoTransf() {
    localStorage.setItem(M().extra, JSON.stringify({
        sucursalOrigenId: document.getElementById('selOrigen')?.value || '',
        notas:            document.getElementById('notasTransf')?.value || ''
    }));
}

// [FIX-BORRADOR-TRANSF] Botón "Limpiar formulario" — reinicia lista, sucursal y notas
// por si el usuario quiere empezar de cero sin recargar la página.
function limpiarFormularioTransf() {
    if (itemsTransf.length > 0 && !confirm('¿Reiniciar el formulario? Se perderán los productos agregados.')) return;
    itemsTransf = [];
    renderItems();
    document.getElementById('notasTransf').value = '';
    document.getElementById('selOrigen').value = '';
    onOrigenChange('');
}

function buscarProducto() {
    const origenId = parseInt(document.getElementById('selOrigen').value) || 0;
    const textoCrudo = document.getElementById('busquedaProd').value.trim();
    let q = textoCrudo.toLowerCase();
    const data = M().data;
    if (!origenId || !data[origenId]) { hideSug(); return; }

    let prods = [...data[origenId]].sort((a, b) => {
        if (a.bajo !== b.bajo) return b.bajo ? 1 : -1;
        return a.nombre.localeCompare(b.nombre);
    });

    // [FEATURE-BUSQUEDA-CODIGO] "4*1": se busca por el codigo (1) y la cantidad (4) se precarga al elegir.
    // Si el texto completo ya es un codigo exacto, no se interpreta como cantidad.
    let cantRapida = null;
    const rapido = BusquedaProductos.parsearCantidadPorCodigo(textoCrudo);
    if (rapido && !BusquedaProductos.buscarExacto(prods, textoCrudo, p => p.codigo)) {
        cantRapida = rapido.cantidad;
        q = rapido.codigo.toLowerCase().trim();
    }

    const filtered = q === ''
        ? prods
        : BusquedaProductos.ordenar(prods, q, p => p.codigo, p => p.nombre);   // [FEATURE-BUSQUEDA-CODIGO] codigo exacto primero

    document.getElementById('hintBajo').style.display = filtered.some(p => p.bajo) ? 'block' : 'none';
    renderSug(filtered, cantRapida);
}

function renderSug(prods, cantRapida) {
    const div = document.getElementById('sugerencias');
    if (!prods.length) { hideSug(); return; }
    const m = M();
    div.innerHTML = (cantRapida ? BusquedaProductos.htmlAvisoCantidad(cantRapida) : '') + prods.map(p => {
        const esSuelto = p.tipo_venta === 'Suelto';
        const stockFmt = esSuelto ? parseFloat(p.stock).toFixed(3).replace(/\.?0+$/,'') : Math.floor(p.stock);
        const miStockFmt = esSuelto ? parseFloat(p.mi_stock).toFixed(3).replace(/\.?0+$/,'') : Math.floor(p.mi_stock);
        return `
        <div class="sug-item" onclick="seleccionarProd(${p.id}, '${escAtribJs(p.nombre)}', '${escAtribJs(p.codigo)}', ${p.stock}, ${p.mi_stock}, ${p.bajo}, '${p.tipo_venta||'Unidad'}'${cantRapida ? ', ' + cantRapida : ''})">
            <div>
                <span class="sug-nombre">${esc(p.nombre)}</span>
                <span class="sug-codigo">${esc(p.codigo)}</span>
                ${p.bajo ? '<span class="badge-bajo">Stock bajo</span>' : ''}
                ${esSuelto ? '<span style="font-size:10px;color:#888;margin-left:4px;">granel</span>' : ''}
            </div>
            <div class="sug-stocks">
                <span class="sug-stock-row sug-stock-orig">${m.lblA}: ${stockFmt}</span>
                <span class="sug-stock-row sug-stock-dest">${m.lblB}: ${miStockFmt}</span>
            </div>
        </div>`;
    }).join('');
    div.style.display = 'block';
}

function hideSug() { document.getElementById('sugerencias').style.display = 'none'; }

// [FEATURE-BUSQUEDA-CODIGO] Captura rapida con Enter en el buscador: "4*codigo" agrega 4 piezas de ese producto
// a la lista (se SUMAN a lo que ya estuviera en ella); un codigo exacto solo elige el producto para capturar la
// cantidad. Si hay un resultado resaltado con las flechas, Enter lo elige (lo maneja dropdown_keynav.js).
function agregarRapidoTransf(p, cant) {
    const esSuelto = p.tipo_venta === 'Suelto';
    if (!esSuelto && !Number.isInteger(cant)) {
        BusquedaProductos.aviso('Este producto no es granel: la cantidad debe ser un número entero.', true, 4000);
        return;
    }
    cant = parseFloat(cant.toFixed(3));
    const existe = itemsTransf.find(i => i.id === p.id);
    const total  = parseFloat(((existe ? existe.cantidad : 0) + cant).toFixed(3));
    const stock  = parseFloat(p.stock) || 0;
    if (total > stock + 0.0005) {
        const stockFmt = esSuelto ? parseFloat(stock.toFixed(3)) : Math.floor(stock);
        const m = M();
        BusquedaProductos.aviso((modoTransf === 'directo' ? 'Tu sucursal solo tiene ' : 'La sucursal origen solo tiene ') + stockFmt + ' disponible(s) de "' + p.nombre + '"' +
            (existe ? ' (' + m.yaCuenta + ' ' + existe.cantidad + ').' : '.'), true, 5000);
        return;
    }
    if (existe) { existe.cantidad = total; existe.tipo_venta = p.tipo_venta || 'Unidad'; }
    else { itemsTransf.push({ id: p.id, nombre: p.nombre + ' (' + p.codigo + ')', cantidad: total, tipo_venta: p.tipo_venta || 'Unidad' }); }
    prodSelId = null;
    document.getElementById('busquedaProd').value = '';
    document.getElementById('prodSeleccionado').style.display = 'none';
    document.getElementById('hintBajo').style.display = 'none';
    hideSug();
    renderItems();
    BusquedaProductos.aviso(cant + ' × ' + p.nombre + ' agregado' + (existe ? ' (total ' + total + ')' : ''), false, 2500);
}

const _busquedaProdEl = document.getElementById('busquedaProd');
if (_busquedaProdEl) {
    _busquedaProdEl.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        if (BusquedaProductos.hayResaltado(document.getElementById('sugerencias'))) return;
        const val = this.value.trim();
        if (!val) return;
        const origenId = parseInt(document.getElementById('selOrigen').value) || 0;
        const data = M().data;
        if (!origenId || !data[origenId]) return;
        const lista = data[origenId];
        const r = BusquedaProductos.resolverRapido(val, c => BusquedaProductos.buscarExacto(lista, c, p => p.codigo));
        if (!r) return;                       // no es un codigo exacto ni cantidad*codigo: todo como siempre
        e.preventDefault();
        if (r.noExiste) {
            BusquedaProductos.aviso('No existe ningún producto con el código "' + r.noExiste + '" ' + (modoTransf === 'directo' ? 'disponible en tu sucursal para enviar.' : 'en la sucursal origen.'), true, 4000);
            return;
        }
        const p = r.item;
        if (!r.explicita) {                   // codigo exacto sin cantidad: se elige el producto y se captura la cantidad
            seleccionarProd(p.id, p.nombre, p.codigo, p.stock, p.mi_stock, p.bajo, p.tipo_venta || 'Unidad');
            return;
        }
        agregarRapidoTransf(p, r.cantidad);
    });
}

function seleccionarProd(id, nombre, codigo, stock, mi_stock, bajo, tipoVenta, cantidadRapida) {
    prodSelId    = id;
    prodSelTipo  = tipoVenta || 'Unidad';
    prodSelStock = parseFloat(stock) || 0;
    const esSuelto = prodSelTipo === 'Suelto';
    const m = M();

    document.getElementById('busquedaProd').value = nombre;
    hideSug();
    document.getElementById('prodSelNombre').textContent = nombre + ' (' + codigo + ')';

    const stockFmt   = esSuelto ? parseFloat(stock).toFixed(3).replace(/\.?0+$/,'')    : Math.floor(stock);
    const miStockFmt = esSuelto ? parseFloat(mi_stock).toFixed(3).replace(/\.?0+$/,'') : Math.floor(mi_stock);
    document.getElementById('prodSelStock').innerHTML =
        m.lblA + ': <strong style="color:#1565c0;">' + stockFmt + '</strong>' +
        ' &nbsp;|&nbsp; ' + m.lblBSel + ': <strong style="color:#2e7d32;">' + miStockFmt + '</strong>' +
        (bajo ? ' <span style="color:#c0392b;font-size:11px;">(stock bajo)</span>' : '') +
        (esSuelto ? ' <span style="font-size:11px;color:#888;">&nbsp;— granel (acepta decimales)</span>' : '');

    const inpCant = document.getElementById('cantProd');
    inpCant.step        = esSuelto ? '0.001' : '1';
    inpCant.min         = esSuelto ? '0.001' : '1';
    inpCant.inputMode   = esSuelto ? 'decimal' : 'numeric';
    inpCant.max         = String(prodSelStock);   // la flechita no pasa de lo disponible
    inpCant.placeholder = esSuelto ? 'Cantidad (ej. 2.5)' : 'Cantidad (número entero)';
    // [FEATURE-BUSQUEDA-CODIGO] cantidad con "4*codigo": se precarga (agregarItem valida entero y stock)
    inpCant.value = (cantidadRapida !== undefined && cantidadRapida !== null) ? cantidadRapida : '';

    document.getElementById('prodSeleccionado').style.display = 'block';
    inpCant.focus();
}

function agregarItem() {
    if (!prodSelId) { alert('Selecciona un producto primero.'); return; }
    const esSuelto = prodSelTipo === 'Suelto';
    let cant = parseFloat(document.getElementById('cantProd').value) || 0;
    if (cant <= 0) { alert('Ingresa una cantidad mayor a 0.'); return; }
    if (!esSuelto) {
        if (!Number.isInteger(cant) || cant !== Math.floor(cant)) {
            alert('Este producto no es granel. La cantidad debe ser un número entero (sin decimales).');
            return;
        }
        cant = Math.floor(cant);
    }
    // Validar que la cantidad no supere el stock disponible (en origen al solicitar; en mi sucursal al enviar)
    if (cant > prodSelStock) {
        const stockFmt = esSuelto
            ? parseFloat(prodSelStock).toFixed(3).replace(/\.?0+$/, '')
            : Math.floor(prodSelStock);
        const m = M();
        alert('No se puede ' + m.verbo + ' ' + cant + ' unidades. ' + m.quien + ' solo tiene ' + stockFmt + ' disponibles.');
        return;
    }

    const nombre = document.getElementById('prodSelNombre').textContent;
    const existe = itemsTransf.find(i => i.id === prodSelId);
    if (existe) { existe.cantidad = cant; existe.tipo_venta = prodSelTipo; }
    else { itemsTransf.push({ id: prodSelId, nombre, cantidad: cant, tipo_venta: prodSelTipo }); }

    prodSelId = null;
    document.getElementById('busquedaProd').value = '';
    document.getElementById('prodSeleccionado').style.display = 'none';
    document.getElementById('hintBajo').style.display = 'none';
    renderItems();
}

function renderItems() {
    // [FIX-BORRADOR-TRANSF] Persistir la lista en cada render, igual que nuevaVenta.php.
    localStorage.setItem(M().draft, JSON.stringify(itemsTransf));
    localStorage.setItem(M().draftSuc, String(MI_SUCURSAL));
    const div = document.getElementById('listaItems');
    if (!itemsTransf.length) {
        div.innerHTML = '<div class="items-vacio">Sin productos agregados</div>';
        return;
    }
    div.innerHTML = itemsTransf.map((item, idx) => `
        <div class="transf-item">
            <span class="transf-item-nombre">${esc(item.nombre)}</span>
            <div style="display:flex;align-items:center;gap:8px;">
                <span class="transf-item-cant">x${item.cantidad}</span>
                <button class="btn-quitar" type="button" onclick="quitarItem(${idx})">x</button>
            </div>
        </div>
    `).join('');
}

function quitarItem(idx) {
    itemsTransf.splice(idx, 1);
    renderItems();
}

function prepararEnvio() {
    // [AUTOFIX] BUG-07: Validar explícitamente que se haya seleccionado una sucursal
    const sel = document.getElementById('selOrigen');
    if (!sel.value) {
        alert(M().vacio);
        sel.focus();
        return false;
    }
    if (!itemsTransf.length) { alert('Agrega al menos un producto.'); return false; }
    document.getElementById('inputItemsTransf').value = JSON.stringify(
        itemsTransf.map(i => ({ id: i.id, cantidad: i.cantidad }))
    );
    return true;
}

// Cerrar sugerencias al perder foco (delay para permitir click en una sugerencia)
if (_busquedaProdEl) {
    _busquedaProdEl.addEventListener('blur', function() {
        setTimeout(hideSug, 200);
    });
}

// ── Editar pedido de transferencia (todos sus productos en una sola pantalla) ────────────────
function fmtCantPed(n, suelto) {
    return suelto ? parseFloat(n).toFixed(3).replace(/\.?0+$/, '') : String(Math.floor(n));
}

let _mepDirecto = false;
function abrirModalEditarPedido(btn) {
    let filas = [];
    try { filas = JSON.parse(btn.getAttribute('data-filas') || '[]'); } catch (e) { filas = []; }
    // [FEATURE-ENVIO-DIRECTO] En un envio directo el destino no autoriza nada: los cambios aplican de inmediato.
    _mepDirecto = filas.length > 0 && !!filas[0].directo;
    const aviso = document.getElementById('mepAviso');
    if (aviso) {
        if (!aviso.dataset.original) aviso.dataset.original = aviso.innerHTML;
        aviso.innerHTML = _mepDirecto
            ? 'Este es un <strong>envío directo</strong>: la otra sucursal no tiene que aceptar los cambios, se aplican al guardar. Pon <strong>0</strong> en un producto para quitarlo del pedido. El stock se descuenta hasta que lo marques como enviado.'
            : aviso.dataset.original;
    }
    const lista = document.getElementById('modalEditPedidoLista');
    lista.innerHTML = '';
    filas.forEach(function (f) {
        const editable = (f.estado === 'Pendiente' || f.estado === 'Aprobada');
        const fila = document.createElement('div');
        fila.className = 'mep-fila';
        const info = document.createElement('div');
        info.className = 'mep-info';
        const nom = document.createElement('strong');
        nom.textContent = f.nombre;
        const cod = document.createElement('span');
        cod.className = 'mep-cod';
        cod.textContent = f.codigo + (f.estado === 'Modificada' ? ' · esperando confirmación del destino' : '');
        const det = document.createElement('div');
        det.className = 'mep-det';
        det.textContent = (f.directo ? 'En el pedido: ' : 'Pedido: ') + fmtCantPed(f.cantidad, f.suelto) + (editable ? (f.directo ? ' · Stock en tu sucursal: ' : ' · Stock en origen: ') + fmtCantPed(f.stock, f.suelto) : '');
        info.appendChild(nom); info.appendChild(cod); info.appendChild(det);
        fila.appendChild(info);
        if (editable) {
            const inp = document.createElement('input');
            inp.type = 'number';
            inp.name = 'cant[' + f.id + ']';
            inp.value = fmtCantPed(f.cantidad, f.suelto);
            inp.step = f.suelto ? '0.001' : '1';
            inp.min = '0';
            inp.max = String(f.stock);   // la flechita no pasa del stock disponible
            inp.inputMode = f.suelto ? 'decimal' : 'numeric';
            inp.className = 'mep-input';
            inp.dataset.suelto = f.suelto ? '1' : '0';
            inp.dataset.stock = String(f.stock);
            inp.dataset.orig = String(f.cantidad);
            inp.dataset.estado = f.estado;
            inp.dataset.nombre = f.nombre;
            fila.appendChild(inp);
        }
        lista.appendChild(fila);
    });
    document.getElementById('inputEditarPedidoId').value = btn.getAttribute('data-id');
    document.getElementById('modalEditPedido').style.display = 'flex';
    const primero = lista.querySelector('input');
    if (primero) setTimeout(function () { primero.focus(); primero.select(); }, 80);
}

function cerrarModalEditarPedido() {
    document.getElementById('modalEditPedido').style.display = 'none';
}

function confirmarEditarPedido() {
    const inputs = document.querySelectorAll('#modalEditPedidoLista .mep-input');
    let cambios = 0, quedan = 0;
    for (const inp of inputs) {
        const suelto = inp.dataset.suelto === '1';
        const nombre = inp.dataset.nombre;
        const stock  = parseFloat(inp.dataset.stock) || 0;
        const orig   = parseFloat(inp.dataset.orig) || 0;
        const raw    = inp.value.trim();
        if (raw === '') { quedan++; continue; }
        let val = parseFloat(raw);
        if (isNaN(val) || val < 0) { alert('"' + nombre + '": ingresa una cantidad válida (0 o mayor).'); inp.focus(); return; }
        if (!suelto && val !== Math.floor(val)) { alert('"' + nombre + '" no es granel: la cantidad debe ser un número entero.'); inp.focus(); return; }
        if (val === 0) {
            if (inp.dataset.estado !== 'Pendiente' && !_mepDirecto) { alert('"' + nombre + '" ya fue aceptado: la cantidad mínima es 1. Si no lo vas a enviar, cancela el pedido.'); inp.focus(); return; }
            cambios++;
            continue;
        }
        quedan++;
        if (Math.abs(val - orig) > 0.0004) {
            if (stock > 0 && val > stock) { alert('"' + nombre + '": la cantidad (' + fmtCantPed(val, suelto) + ') supera el stock disponible en tu sucursal (' + fmtCantPed(stock, suelto) + ').'); inp.focus(); return; }
            if (stock <= 0) { alert('"' + nombre + '": no hay stock disponible en tu sucursal para esa cantidad.'); inp.focus(); return; }
            cambios++;
        }
    }
    const hayModificadas = document.querySelectorAll('#modalEditPedidoLista .mep-fila').length > inputs.length;
    if (cambios === 0) { alert(_mepDirecto ? 'No cambiaste ninguna cantidad.' : 'No cambiaste ninguna cantidad. Si todo está bien, cierra esta ventana y usa "Aceptar pedido".'); return; }
    if (quedan === 0 && !hayModificadas) { alert(_mepDirecto ? 'No puedes quitar todos los productos del pedido. Si no vas a enviar nada, usa "Cancelar pedido".' : 'No puedes retirar todos los productos del pedido. Si no vas a enviar nada, usa "Rechazar pedido".'); return; }
    const frm = document.getElementById('formEditarPedido');
    frm.querySelectorAll('input[data-cant-copia]').forEach(function (x) { x.remove(); });
    inputs.forEach(function (inp) {
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = inp.name;
        h.value = inp.value.trim();
        h.setAttribute('data-cant-copia', '1');
        frm.appendChild(h);
    });
    frm.submit();
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalEditarPedido();
    }
});

// [FIX-BORRADOR-TRANSF] Restaurar modo, productos y sucursal en curso, mismo patrón que
// restaurarEstadoVenta() en nuevaVenta.php. Se lee "extra" ANTES de renderItems() por si
// acaso esa función llegara a disparar algun guardado propio en el futuro.
(function restaurarEstadoTransf() {
    if (!document.getElementById('formTransf')) return;   // vista sin formulario (Admin en "todas las sucursales")
    aplicarModoUI();
    let extra = null;
    try { extra = JSON.parse(localStorage.getItem(M().extra)); } catch (e) {}

    renderItems();

    if (extra && extra.sucursalOrigenId) {
        const sel = document.getElementById('selOrigen');
        if (Array.from(sel.options).some(o => o.value === extra.sucursalOrigenId)) {
            sel.value = extra.sucursalOrigenId;
            onOrigenChange(extra.sucursalOrigenId);
        }
    }
    if (extra && extra.notas) document.getElementById('notasTransf').value = extra.notas;
})();
</script>

<!-- [FEATURE-TICKET-ENVIO-TRANSF] Ticket de envio: lista de productos enviados en un pedido, para que
     la sucursal destino verifique que llego completo. -->
<style>
@page { size: 80mm auto; margin: 0; }
@media print {
    html, body { height: auto !important; margin: 0 !important; padding: 0 !important; overflow: hidden !important; }
    body > * { display: none !important; }
    #ticketImprimir { display: block !important; page-break-after: avoid; break-after: avoid; }
}
#ticketImprimir { display: none; font-family: 'Courier New', monospace; font-size: 11px; width: 72mm; margin: 0; padding: 3mm 4mm 2mm; color: #000; }
#ticketImprimir .t-centro, #ticketEnvioContenido .t-centro { text-align: center; }
#ticketImprimir .t-linea  { border-top: 1px dashed #000; margin: 4px 0; }
#ticketEnvioContenido .t-linea { border-top: 1px dashed #aaa; margin: 4px 0; }
#ticketImprimir .t-fila, #ticketEnvioContenido .t-fila { display: flex; justify-content: space-between; gap: 8px; }
#ticketImprimir .t-bold, #ticketEnvioContenido .t-bold { font-weight: bold; }
#ticketImprimir .t-grande, #ticketEnvioContenido .t-grande { font-size: 13px; font-weight: bold; }
#ticketEnvioContenido { font-family: 'Courier New', monospace; font-size: 12px; color: #222; }
</style>
<div id="modalTicketEnvio" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px;" aria-hidden="true">
    <div style="background:#fff;border-radius:8px;padding:22px;width:340px;max-width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #e8e8e8;">
            <h3 style="margin:0;font-size:15px;color:#333;">Ticket de envío</h3>
            <button type="button" onclick="cerrarTicketEnvio()" style="background:none;border:none;font-size:24px;color:#aaa;cursor:pointer;line-height:1;">×</button>
        </div>
        <div id="ticketEnvioContenido" style="margin-bottom:14px;"></div>
        <div style="display:flex;gap:10px;justify-content:center;border-top:1px solid #e8e8e8;padding-top:14px;">
            <button type="button" onclick="imprimirTicketEnvio()" style="background:#14ace7;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:700;"><?= icono('printer') ?> Imprimir</button>
            <button type="button" onclick="cerrarTicketEnvio()" style="background:#f0f0f0;color:#666;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600;">Cerrar</button>
        </div>
    </div>
</div>
<div id="ticketImprimir"></div>
<script>
// [FEATURE-TICKET-ENVIO-TRANSF]
let _ticketEnvioAncho = 58;

function fmtCantEnvio(c) {
    const n = parseFloat(c) || 0;
    return Number.isInteger(n) ? String(n) : n.toFixed(3).replace(/\.?0+$/, '');
}

function generarTicketEnvioHTML(d) {
    const o  = d.origen || {};
    const el = document.getElementById('ticketImprimir');
    _ticketEnvioAncho = o.ticket_ancho_mm || 58;
    el.style.fontSize = (o.ticket_font_size || 12) + 'px';
    el.style.width    = _ticketEnvioAncho + 'mm';

    let html = '';
    // Logo: el de la sucursal origen; si esa sucursal no tiene uno configurado se usa el logo
    // de la empresa (logoIcono.png, el mismo icono de la casa), para que el ticket nunca salga sin logo.
    {
        const logoTicket = o.ticket_logo || 'logoIcono.png';
        const maxW = _ticketEnvioAncho >= 80 ? '140px' : '100px';
        html += `<div class="t-centro" style="margin-bottom:6px;"><img src="../${esc(logoTicket)}" style="height:24mm;width:auto;max-width:70%;object-fit:contain;"></div>`;
    }
    html += `<div class="t-centro t-bold t-grande">${esc(o.nombre || 'Ferretería Aldrete')}</div>`;
    if (o.datos_ticket) {
        html += `<div class="t-centro" style="white-space:pre-line;font-size:11px;">${esc(o.datos_ticket)}</div>`;
    } else {
        if (o.rfc)       html += `<div class="t-centro">RFC: ${esc(o.rfc)}</div>`;
        if (o.direccion) html += `<div class="t-centro">${esc(o.direccion)}</div>`;
        if (o.telefono)  html += `<div class="t-centro">Tel: ${esc(o.telefono)}</div>`;
    }
    html += `
        <div class="t-linea"></div>
        <div class="t-centro t-bold">ENVÍO A SUCURSAL</div>
        <div class="t-linea"></div>
        <div class="t-fila"><span>Folio:</span><span class="t-bold">${esc(d.folio || ('#' + d.pedido))}</span></div>
        ${d.directo ? '<div class="t-centro t-bold">** ENVÍO DIRECTO **</div>' : ''}
        <div class="t-fila"><span>Fecha envío:</span><span>${esc(d.fecha_envio || '—')}</span></div>
        <div class="t-fila"><span>Origen:</span><span style="text-align:right;">${esc(o.nombre || '')}</span></div>
        <div class="t-fila"><span>Destino:</span><span style="text-align:right;">${esc(d.destino || '')}</span></div>
        ${d.directo ? '' : `<div class="t-fila"><span>Solicitó:</span><span style="text-align:right;">${esc(d.solicitante || '—')}</span></div>`}
        <div class="t-fila"><span>Envió:</span><span style="text-align:right;">${esc(d.enviado_por || '—')}</span></div>
        <div class="t-linea"></div>
        <div class="t-fila t-bold"><span>Producto enviado</span><span>Cant.</span></div>
        <div class="t-linea"></div>`;

    (d.enviados || []).forEach(p => {
        html += `<div>[&nbsp;&nbsp;] ${esc(p.nombre)}</div>
            <div class="t-fila" style="font-size:10px;"><span>${esc(p.codigo)}</span><span class="t-bold" style="font-size:12px;">${fmtCantEnvio(p.cantidad)}${p.unidad ? ' ' + esc(p.unidad) : ''}</span></div>`;
    });
    html += `<div class="t-linea"></div>
        <div class="t-fila t-bold"><span>Total de productos:</span><span>${(d.enviados || []).length}</span></div>`;

    const noEnv = d.no_enviados || [];
    if (noEnv.length) {
        const etiqueta = e => (e === 'Rechazada' ? 'Rechazado' : (e === 'Cancelada' ? 'Cancelado' : 'Pendiente de envío'));
        html += `<div class="t-linea"></div>
            <div class="t-bold">NO ENVIADOS EN ESTE PEDIDO</div>`;
        noEnv.forEach(p => {
            html += `<div style="font-size:11px;">- ${esc(p.nombre)} (${fmtCantEnvio(p.cantidad)}) — ${esc(etiqueta(p.estado))}</div>`;
        });
    }

    html += `
        <div class="t-linea"></div>
        <div style="margin-top:6px;">¿Pedido completo?  [ ] Sí   [ ] No</div>
        <div class="t-linea"></div>
        <div class="t-centro" style="font-size:10px;margin-top:4px;">Conserve este comprobante</div>`;

    el.innerHTML = html;
}

function abrirTicketEnvio(id, imprimirSolo) {
    fetch('transferencias.php?ticket_envio=' + encodeURIComponent(id))
        .then(r => r.json())
        .then(d => {
            if (!d || d.error) { alert((d && d.error) || 'No se pudo cargar el ticket de envío.'); return; }
            if (!d.enviados || !d.enviados.length) { alert('Este pedido todavía no tiene productos enviados.'); return; }
            generarTicketEnvioHTML(d);
            document.getElementById('ticketEnvioContenido').innerHTML = document.getElementById('ticketImprimir').innerHTML;
            const m = document.getElementById('modalTicketEnvio');
            m.style.display = 'flex';
            m.setAttribute('aria-hidden', 'false');
            if (imprimirSolo) setTimeout(imprimirTicketEnvio, 250);   // recien marcado enviado: impresion automatica
        })
        .catch(() => alert('No se pudo cargar el ticket de envío.'));
}

function cerrarTicketEnvio() {
    const m = document.getElementById('modalTicketEnvio');
    m.style.display = 'none';
    m.setAttribute('aria-hidden', 'true');
}

function imprimirTicketEnvio() {
    cerrarTicketEnvio();
    const _doImprimirEnv = () => {
        let estilo = document.getElementById('__ticketPageStyleEnv');
        if (!estilo) {
            estilo = document.createElement('style');
            estilo.id = '__ticketPageStyleEnv';
            document.head.appendChild(estilo);
        }
        estilo.textContent = `@page { size: ${_ticketEnvioAncho}mm auto; margin: 0; }`;
        setTimeout(() => window.print(), 150);
    };
    const img = document.querySelector('#ticketImprimir img');
    if (img && !img.complete) { img.onload = _doImprimirEnv; img.onerror = _doImprimirEnv; }
    else { _doImprimirEnv(); }
}

// Al volver de "Marcar enviado" / "Enviar pedido" (?abrir_ticket=ID) se abre solo el ticket del pedido.
// (No usar el nombre ticket_envio: ese parametro es el endpoint JSON que devuelve los datos.)
(function() {
    const p = new URLSearchParams(window.location.search);
    const t = parseInt(p.get('abrir_ticket') || '0', 10);
    if (t > 0) {
        abrirTicketEnvio(t, p.get('imprimir_ticket') === '1');
        p.delete('abrir_ticket');
        p.delete('imprimir_ticket');
        try { history.replaceState(null, '', window.location.pathname + (p.toString() ? '?' + p.toString() : '')); } catch (e) {}
    }
})();
</script>

<!-- [FEATURE-PEDIDO-TRANSFERENCIA] Editar todo el pedido: las cantidades viajan como cant[id]. -->
<form id="formEditarPedido" method="POST" action="transferencias.php" style="display:none;">
    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="accion" value="editar_pedido">
    <input type="hidden" id="inputEditarPedidoId" name="id">
</form>

<!-- [FIX-MEDIO-C-15] (portado de admin/inventario_transferencias.php): formulario reutilizable
     para aprobar/rechazar/enviar/recibir/cancelar/aceptar_modificacion/rechazar_modificacion —
     antes cada accion era un link GET con el token CSRF en la URL. -->
<form id="formAccionTransf" method="POST" action="transferencias.php" style="display:none;">
    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" id="inputAccionTransfAccion" name="accion">
    <input type="hidden" id="inputAccionTransfId" name="id">
</form>
<script>
function ejecutarAccionTransf(accion, id, mensajeConfirm) {
    if (!confirm(mensajeConfirm)) return false;
    document.getElementById('inputAccionTransfAccion').value = accion;
    document.getElementById('inputAccionTransfId').value = id;
    document.getElementById('formAccionTransf').submit();
    return false;
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('modalEditPedido').addEventListener('click', function(e) {
        if (e.target === this) cerrarModalEditarPedido();
    });
});
</script>
<style>
    .mep-fila { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
    .mep-fila:last-child { border-bottom: none; }
    .mep-info { min-width: 0; font-size: 13px; color: #333; }
    .mep-cod { font-size: 10px; color: #aaa; margin-left: 6px; }
    .mep-det { font-size: 11px; color: #777; margin-top: 2px; }
    .mep-input { width: 100px; flex-shrink: 0; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; text-align: right; }
</style>
<div id="modalEditPedido" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:8px;padding:22px;width:560px;max-width:100%;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <span style="font-size:14px;font-weight:700;color:#333;">Editar pedido</span>
            <button type="button" onclick="cerrarModalEditarPedido()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888;line-height:1;">×</button>
        </div>
        <div id="mepAviso" style="font-size:12px;color:#888;background:#fff8e1;border:1px solid #ffe082;border-radius:6px;padding:10px 12px;margin-bottom:10px;">
            <?= icono('triangle-alert') ?> Los productos con <strong>cantidad distinta</strong> pasarán a <strong>Modificada</strong> y la sucursal destino deberá aceptar el cambio; los demás quedarán aceptados. Pon <strong>0</strong> en un producto Pendiente para retirarlo del pedido.
        </div>
        <div id="modalEditPedidoLista" style="overflow-y:auto;flex:1;min-height:0;"></div>
        <div style="display:flex;gap:8px;margin-top:14px;">
            <button type="button" onclick="confirmarEditarPedido()" style="flex:1;background:#14ace7;color:#fff;border:none;padding:10px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">Guardar cambios</button>
            <button type="button" onclick="cerrarModalEditarPedido()" style="background:#f0f0f0;color:#555;border:none;padding:10px 16px;border-radius:6px;font-size:13px;cursor:pointer;">Cancelar</button>
        </div>
    </div>
</div>

<?php $transfPagina = 'transferencias.php'; include '../includes/transferencias_modales.php'; ?>
<script src="../includes/busqueda_productos.js"></script>
<script src="../includes/dropdown_keynav.js"></script>
<script>
// [FEATURE-DROPDOWN-KEYNAV] Navegar los resultados de búsqueda con flechas y Enter.
attachDropdownKeyNav(
    document.getElementById('busquedaProd'),
    document.getElementById('sugerencias'),
    hideSug
);
</script>
<script src="../includes/seleccionar_cantidad.js"></script>
<script src="../includes/anti_doble_envio.js"></script>
</body>
</html>
