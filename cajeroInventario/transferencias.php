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

// [FEATURE-TICKET-ENVIO-TRANSF] Cada producto de una solicitud de transferencia es UNA fila en
// `transferencias` (todas creadas en el mismo INSERT-loop: mismo origen, destino, solicitante y
// segundo de creacion). "Pedido" = ese conjunto de filas. Esta funcion carga la fila pedida y todas
// las de su mismo pedido (tolerancia de 2 s por si el loop cruza el cambio de segundo).
function transfCargarPedido(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ?");
    $st->execute([$id]);
    $base = $st->fetch(PDO::FETCH_ASSOC);
    if (!$base) return null;
    $st = $pdo->prepare("
        SELECT t.*, p.nombre_producto, p.codigo, p.tipo_venta, p.unidad_medida
        FROM transferencias t
        JOIN productos p ON p.producto_id = t.producto_id
        WHERE t.sucursal_origen_id = ? AND t.sucursal_destino_id = ? AND t.usuario_solicita_id = ?
          AND t.created_at BETWEEN DATE_SUB(?, INTERVAL 2 SECOND) AND DATE_ADD(?, INTERVAL 2 SECOND)
        ORDER BY t.transferencias_id ASC
    ");
    $st->execute([$base['sucursal_origen_id'], $base['sucursal_destino_id'], $base['usuario_solicita_id'], $base['created_at'], $base['created_at']]);
    return ['base' => $base, 'filas' => $st->fetchAll(PDO::FETCH_ASSOC)];
}

// [FEATURE-TICKET-ENVIO-TRANSF] Datos del ticket de envio de un pedido: lista de lo enviado (para
// que la sucursal destino verifique que llego completo) y, aparte, lo que NO se envio.
if (isset($_GET['ticket_envio'])) {
    header('Content-Type: application/json');
    try {
        $idTk  = intval(is_scalar($_GET['ticket_envio'] ?? null) ? $_GET['ticket_envio'] : 0);
        $ped   = $idTk > 0 ? transfCargarPedido($pdo, $idTk) : null;
        $sucTk = intval($_SESSION['sucursal_id']);
        $base  = $ped ? $ped['base'] : null;
        // Solo las sucursales involucradas (origen o destino) pueden ver el ticket.
        if (!$ped || !($base['sucursal_origen_id'] == $sucTk || $base['sucursal_destino_id'] == $sucTk)) { echo json_encode(null); exit(); }

        $stmtSucO = $pdo->prepare("SELECT nombre, rfc, direccion, telefono, datos_ticket, ticket_logo, ticket_font_size, ticket_ancho_mm FROM sucursales WHERE sucursal_id = ?");
        $stmtSucO->execute([$base['sucursal_origen_id']]);
        $sucO = $stmtSucO->fetch(PDO::FETCH_ASSOC) ?: [];
        $stmtSucD = $pdo->prepare("SELECT nombre FROM sucursales WHERE sucursal_id = ?");
        $stmtSucD->execute([$base['sucursal_destino_id']]);
        $nombreDest = $stmtSucD->fetchColumn() ?: '';
        $stmtSol = $pdo->prepare("SELECT nombre_completo FROM usuarios WHERE usuario_id = ?");
        $stmtSol->execute([$base['usuario_solicita_id']]);
        $nombreSol = $stmtSol->fetchColumn() ?: '';

        $enviados = [];
        $noEnviados = [];
        $fechaEnvio = null;
        $enviadoPor = null;
        $idsPedido  = [];
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
            if (in_array($f['estado'], ['En tránsito', 'Entregada'], true)) {
                $enviados[] = $item;
                // Fecha y usuario reales del envio: el movimiento "Transferencia enviada #id" del origen.
                $stmtMov = $pdo->prepare("
                    SELECT m.created_at, u.nombre_completo
                    FROM movimientos_inventario m
                    LEFT JOIN usuarios u ON u.usuario_id = m.usuario_id
                    WHERE m.tipo = 'Transferencia' AND m.sucursal_id = ? AND m.motivo = ?
                    ORDER BY m.created_at ASC LIMIT 1
                ");
                $stmtMov->execute([$f['sucursal_origen_id'], 'Transferencia enviada #' . $f['transferencias_id']]);
                $mov = $stmtMov->fetch(PDO::FETCH_ASSOC);
                $fechaFila = $mov['created_at'] ?? $f['updated_at'];
                if ($fechaEnvio === null || $fechaFila < $fechaEnvio) {
                    $fechaEnvio = $fechaFila;
                    $enviadoPor = $mov['nombre_completo'] ?? null;
                }
            } else {
                $noEnviados[] = $item;
            }
        }
        echo json_encode([
            'pedido'      => min($idsPedido),
            'ids'         => $idsPedido,
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
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[Ferreteria/transferencias] Error ticket_envio: ' . $e->getMessage());
        echo json_encode(['error' => 'No se pudo cargar el ticket de envío.']);
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
        $stmt = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ?");
        $stmt->execute([$id]);
        $transf = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($transf && $transf['estado'] === 'En tránsito' && $transf['sucursal_destino_id'] == $miSucursal) {
            $pdo->beginTransaction();
            try {
                // [FIX-C8] Volver a leer y bloquear la transferencia dentro de la transaccion.
                // El chequeo de arriba (linea 116-120) se hizo sin candado; si dos peticiones
                // (doble clic/doble envio) llegan casi juntas, ambas lo pasarian y ambas sumarian
                // el stock al destino. Al relockear aqui con FOR UPDATE y revalidar el estado,
                // la segunda peticion encuentra la transferencia ya "Entregada" y no hace nada.
                $stmtLock = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ? FOR UPDATE");
                $stmtLock->execute([$id]);
                $transf = $stmtLock->fetch(PDO::FETCH_ASSOC);
                if (!$transf || $transf['estado'] !== 'En tránsito' || $transf['sucursal_destino_id'] != $miSucursal) {
                    $pdo->rollBack();
                    // [FIX-RECIBIR-IDOR] Antes redirigia a "msg=recibir" (mensaje de exito) aunque
                    // la revalidacion fallara — reutiliza "error_ya_no_transito" que ya existe con
                    // el texto correcto ("la transferencia ya no esta En transito").
                    header('Location: transferencias.php?msg=error_ya_no_transito'); exit();
                }

                // ¿El origen ya desconto su stock al enviar? (transferencias nuevas lo hacen;
                // las que quedaron "En tránsito" antes del cambio no, y hay que descontarlo aqui)
                $stmtMarca = $pdo->prepare("
                    SELECT COUNT(*) FROM movimientos_inventario
                    WHERE tipo = 'Transferencia' AND motivo = ? AND sucursal_id = ?
                ");
                $stmtMarca->execute(['Transferencia enviada #' . $id, $transf['sucursal_origen_id']]);
                $origenYaDescontado = intval($stmtMarca->fetchColumn()) > 0;

                if (!$origenYaDescontado) {
                    // Ruta de compatibilidad: transferencia enviada con la logica anterior
                    $stmtOrigen = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
                    $stmtOrigen->execute([$transf['producto_id'], $transf['sucursal_origen_id']]);
                    $stockOrLegacy = $stmtOrigen->fetchColumn();

                    if ($stockOrLegacy === false || floatval($stockOrLegacy) < floatval($transf['cantidad'])) {
                        $pdo->rollBack();
                        header('Location: transferencias.php?msg=error_stock_recibir'); exit();
                    }
                    $stockAntOrigen   = floatval($stockOrLegacy);
                    $stockNuevoOrigen = $stockAntOrigen - floatval($transf['cantidad']);
                    $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                        ->execute([$stockNuevoOrigen, $transf['producto_id'], $transf['sucursal_origen_id']]);
                    $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Transferencia',?,?,?,'Transferencia enviada')")
                        ->execute([$transf['producto_id'], $_SESSION['usuario_id'], $transf['sucursal_origen_id'], $transf['cantidad'], $stockAntOrigen, $stockNuevoOrigen]);
                }

                // [FIX-TRANSF-ACTIVO-NO-REVALIDADO 2026-09-20] El producto pudo darse de baja
                // de la sucursal DESTINO mientras la transferencia seguia "En transito" -- solo
                // bloquea si YA existe una fila inactiva; si el producto nunca ha llegado a esta
                // sucursal (sin fila todavia), abajo se crea una nueva activa, como siempre.
                $stmtActivoRec = $pdo->prepare("SELECT activo FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ?");
                $stmtActivoRec->execute([$transf['producto_id'], $transf['sucursal_destino_id']]);
                $activoDestRec = $stmtActivoRec->fetchColumn();
                if ($activoDestRec !== false && !intval($activoDestRec)) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=error_producto_inactivo_transf'); exit();
                }

                // Sumar stock al destino — mismo producto_id (catálogo compartido)
                $stmtDest = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
                $stmtDest->execute([$transf['producto_id'], $transf['sucursal_destino_id']]);
                $stockDest = $stmtDest->fetchColumn();

                if ($stockDest !== false) {
                    $stockAntDest   = floatval($stockDest);
                    $stockNuevoDest = $stockAntDest + floatval($transf['cantidad']);
                    $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
                        ->execute([$stockNuevoDest, $transf['producto_id'], $transf['sucursal_destino_id']]);
                } else {
                    // Primera vez que este producto llega a la sucursal destino
                    $stockNuevoDest = floatval($transf['cantidad']);
                    $pdo->prepare("INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo) VALUES (?,?,?,0,0,1)")
                        ->execute([$transf['producto_id'], $transf['sucursal_destino_id'], $stockNuevoDest]);
                    $stockAntDest = 0;
                }
                $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Transferencia',?,?,?,?)")
                    ->execute([$transf['producto_id'], $_SESSION['usuario_id'], $transf['sucursal_destino_id'], $transf['cantidad'], $stockAntDest, $stockNuevoDest, 'Transferencia recibida #' . $id]);

                $pdo->prepare("UPDATE transferencias SET estado='Entregada', usuario_aprueba_id=? WHERE transferencias_id=?")
                    ->execute([$_SESSION['usuario_id'], $id]);

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                error_log('[Ferreteria/transferencias] Error al recibir #' . $id . ': ' . $e->getMessage());
                header('Location: transferencias.php?msg=error_recibir'); exit();
            }
        } else {
            // [FIX-RECIBIR-IDOR] Mismo caso que arriba pero en el chequeo SIN candado previo a la
            // transaccion: si no hay fila (no eres destino, o el estado ya no es 'En transito'),
            // no caer al "header(...msg=$accion)" generico del final que mostraba exito.
            header('Location: transferencias.php?msg=error_ya_no_transito'); exit();
        }
    }
    // [FEATURE-TICKET-ENVIO-TRANSF] Tras enviar (uno o todo el pedido) se abre el ticket de envio.
    $ticketQs = in_array($accion, ['enviar', 'enviar_pedido'], true) ? '&abrir_ticket=' . $id : '';
    header('Location: transferencias.php?msg='.$accion . $ticketQs); exit();
}

// Nueva solicitud — multi-producto, YO soy el DESTINO (quien pide), ORIGEN = otra sucursal
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        // [FIX-MEDIO-C-13] (portado de admin/inventario_transferencias.php): antes se
        // redirigia SIEMPRE a "Solicitud enviada" sin comprobar si de verdad se inserto
        // alguna fila — si todos los items terminaban con cantidad <= 0 tras redondear
        // (p. ej. un producto no-granel pedido en 0.5), el "continue" los saltaba a todos
        // y la solicitud quedaba vacia, pero igual se mostraba exito.
        $insertados = 0;
        foreach ($items as $item) {
            $prodId   = intval($item['id']);
            $cantidad = floatval($item['cantidad']);
            $stmtTV3  = $pdo->prepare("SELECT tipo_venta FROM productos WHERE producto_id = ?");
            $stmtTV3->execute([$prodId]);
            $tvRow3 = $stmtTV3->fetch(PDO::FETCH_ASSOC);
            if ($tvRow3 && $tvRow3['tipo_venta'] !== 'Suelto') { $cantidad = floor($cantidad); }
            if ($cantidad <= 0) continue;
            $pdo->prepare("INSERT INTO transferencias (producto_id, sucursal_origen_id, sucursal_destino_id, usuario_solicita_id, cantidad, notas, estado) VALUES (?,?,?,?,?,?,'Pendiente')")
                ->execute([$prodId, $sucursal_origen_id, $_SESSION['sucursal_id'], $_SESSION['usuario_id'], $cantidad, $notas]);
            $insertados++;
        }
        if ($insertados > 0) {
            header('Location: transferencias.php?msg=solicitado'); exit();
        }
        $errores[] = 'Ningún producto tenía una cantidad válida después de ajustar por tipo de venta. Revisa las cantidades e intenta de nuevo.';
    }
}

// Filtro de fechas para el historial
$filtroDesde = $_GET['desde'] ?? date('Y-m-01');
$filtroHasta = $_GET['hasta'] ?? date('Y-m-d');
$filtroEstado = trim(is_scalar($_GET['estado_f'] ?? null) ? (string)$_GET['estado_f'] : '');

$whereExtra = '';
$paramsExtra = [];
if ($filtroDesde) { $whereExtra .= ' AND DATE(t.created_at) >= ?'; $paramsExtra[] = $filtroDesde; }
if ($filtroHasta) { $whereExtra .= ' AND DATE(t.created_at) <= ?'; $paramsExtra[] = $filtroHasta; }
if ($filtroEstado) { $whereExtra .= ' AND t.estado = ?'; $paramsExtra[] = $filtroEstado; }

// Transferencias de esta sucursal (como origen o destino)
$stmt = $pdo->prepare("
    SELECT t.*,
        p.nombre_producto, p.codigo, p.tipo_venta,
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transferencias — Ferretería Aldrete</title>
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
    @media (max-width: 768px) {
        body { overflow-x: hidden; }
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
                    'error_ya_no_transito'  => 'No se pudo completar: la transferencia ya no esta "En transito" (alguien mas ya la modifico).',
                    'error_ya_no_pendiente' => 'No se pudo completar: la solicitud ya no esta pendiente (alguien mas ya la aprobo, rechazo, o no eres la sucursal origen).',
                    'error_ya_no_aprobada'  => 'No se pudo completar: la transferencia ya no esta "Aprobada" (alguien mas ya la modifico, o no eres la sucursal origen).',
                    'error_ya_no_modificada' => 'No se pudo completar: la transferencia ya no tiene un cambio de cantidad pendiente de confirmar (alguien mas ya lo acepto/rechazo, o no eres la sucursal destino).',
                    'error_producto_inactivo_transf' => 'No se pudo completar: el producto ya no esta activo en la sucursal correspondiente (fue dado de baja). Revisalo antes de continuar.',
                ]; ?>
                <?php $msgKeyTransf = is_scalar($_GET['msg'] ?? null) ? $_GET['msg'] : ''; ?>
                <?php $esMsgError = str_starts_with($msgKeyTransf, 'error'); ?>
                <div class="msg <?= $esMsgError ? 'errores' : 'msg-exito' ?>"><?= htmlspecialchars($msgs[$msgKeyTransf] ?? '') ?><?php $detMsgTransf = is_scalar($_GET['det'] ?? null) ? mb_substr((string)$_GET['det'], 0, 150) : ''; if ($esMsgError && $detMsgTransf !== ''): ?> Producto: <strong><?= htmlspecialchars($detMsgTransf) ?></strong><?php endif; ?></div>
            <?php endif; ?>

            <!-- Filtro de fechas -->
            <div class="card" style="margin-bottom:10px;">
                <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                    <div>
                        <label style="display:block;font-size:11px;color:#888;margin-bottom:3px;font-weight:600;">Desde</label>
                        <input type="date" name="desde" value="<?= htmlspecialchars($filtroDesde) ?>"
                               style="padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:#888;margin-bottom:3px;font-weight:600;">Hasta</label>
                        <input type="date" name="hasta" value="<?= htmlspecialchars($filtroHasta) ?>"
                               style="padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;color:#888;margin-bottom:3px;font-weight:600;">Estado</label>
                        <select name="estado_f" style="padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                            <option value="">Todos</option>
                            <?php foreach (['Pendiente','Aprobada','Modificada','En tránsito','Entregada','Rechazada','Cancelada'] as $est): ?>
                            <option value="<?= $est ?>" <?= $filtroEstado === $est ? 'selected' : '' ?>><?= $est ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" style="background:#14ace7;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">Filtrar</button>
                    <a class="btn-limpiar" href="transferencias.php">Limpiar</a>
                </form>
            </div>

            <div class="card" style="padding:0;">
                <?php if (count($transferencias) > 0): ?>
                <table>
                    <thead>
                        <tr><th>Producto</th><th>Cant.</th><th>Origen → Destino</th><th>Estado</th><th>Solicitante</th><th>Fecha</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transferencias as $t):
                            $esMiOrigen = $t['sucursal_origen_id'] == $_SESSION['sucursal_id'];
                            $badgeMap = [
                                'Pendiente'   => 'badge-pendiente',
                                'Aprobada'    => 'badge-aprobada',
                                'Modificada'  => 'badge-modificada',
                                'En tránsito' => 'badge-transito',
                                'Entregada'   => 'badge-entregada',
                                'Rechazada'   => 'badge-rechazada',
                                'Cancelada'   => 'badge-rechazada',
                            ];
                            $bc = $badgeMap[$t['estado']] ?? 'badge-pendiente';
                            // [FEATURE-CANCELAR-TRANSFERENCIA] / [FEATURE-TICKET-ENVIO-TRANSF]
                            $_sucMiaTransf = intval($_SESSION['sucursal_id']);
                            $esMiDestino   = $t['sucursal_destino_id'] == $_sucMiaTransf;
                            $puedeCancelarSolic = $_sucMiaTransf !== 0 && $t['estado'] === 'Pendiente' && $esMiDestino;
                            $mostrarTicketTransf = in_array($t['estado'], ['En tránsito','Entregada'], true);
                            // ¿Hay mas de un producto Aprobado en este mismo pedido? (mismo origen, destino,
                            // solicitante y momento de creacion) -> se ofrece enviar todo el pedido junto.
                            $hayLoteAprobado = false;
                            if ($t['estado'] === 'Aprobada' && $esMiOrigen) {
                                $nLote = 0;
                                foreach ($transferencias as $uT) {
                                    if ($uT['estado'] === 'Aprobada'
                                        && $uT['sucursal_origen_id'] == $t['sucursal_origen_id']
                                        && $uT['sucursal_destino_id'] == $t['sucursal_destino_id']
                                        && $uT['usuario_solicita_id'] == $t['usuario_solicita_id']
                                        && abs(strtotime($uT['created_at']) - strtotime($t['created_at'])) <= 2) {
                                        $nLote++;
                                    }
                                }
                                $hayLoteAprobado = $nLote > 1;
                            }
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($t['nombre_producto']) ?></strong>
                                <div style="font-size:10px;color:#aaa;"><?= htmlspecialchars($t['codigo']) ?></div>
                                <span class="direction-badge <?= $esMiOrigen ? 'dir-enviada' : 'dir-recibida' ?>">
                                    <?= $esMiOrigen ? 'Enviada' : 'Recibida' ?>
                                </span>
                            </td>
                            <td><?= number_format($t['cantidad'], 2) ?></td>
                            <td style="font-size:11px;">
                                <?= htmlspecialchars($t['sucursal_origen']) ?> → <?= htmlspecialchars($t['sucursal_destino']) ?>
                            </td>
                            <td><span class="badge <?= $bc ?>"><?= htmlspecialchars($t['estado']) ?></span></td>
                            <td style="font-size:11px;"><?= htmlspecialchars($t['solicitante']) ?></td>
                            <td style="color:#aaa;font-size:11px;"><?= date('d/m/Y', strtotime($t['created_at'])) ?></td>
                            <td>
                                <div class="acciones">
                                    <?php
                                    $tvJs = $t['tipo_venta'] === 'Suelto' ? 'Suelto' : 'Unidad';
                                ?>
                                <?php
                                    $stockOrigenJs = floatval($t['stock_origen'] ?? 0);
                                ?>
                                <?php if ($t['estado'] === 'Pendiente' && $esMiOrigen): ?>
                                        <!-- [FIX-MEDIO-C-15] (portado de admin/inventario_transferencias.php): las acciones
                                             destructivas iban por link GET con el token CSRF en la URL (queda en el historial
                                             del navegador, en logs del servidor, y viaja en el header Referer). Ahora van por
                                             POST via el formulario oculto formAccionTransf. -->
                                        <button class="btn-accion btn-aprobar" type="button" onclick="return ejecutarAccionTransf('aprobar', <?= $t['transferencias_id'] ?>, '¿Aprobar esta solicitud y comprometerse a enviar los productos?')">Aprobar</button>
                                        <button class="btn-accion btn-rechazar" type="button" onclick="return ejecutarAccionTransf('rechazar', <?= $t['transferencias_id'] ?>, '¿Rechazar esta solicitud de transferencia?')">Rechazar</button>
                                        <button class="btn-accion" type="button" style="background:#fff8e1;color:#e65100;border:none;cursor:pointer;" onclick="abrirModalEditarCantidad(<?= $t['transferencias_id'] ?>, <?= $t['cantidad'] ?>, '<?= $tvJs ?>', <?= $stockOrigenJs ?>)">Editar cantidad</button>
                                    <?php elseif ($t['estado'] === 'Aprobada' && $esMiOrigen): ?>
                                        <button class="btn-accion" type="button" style="background:#fff8e1;color:#e65100;border:none;cursor:pointer;" onclick="abrirModalEditarCantidad(<?= $t['transferencias_id'] ?>, <?= $t['cantidad'] ?>, '<?= $tvJs ?>', <?= $stockOrigenJs ?>)">Editar cantidad</button>
                                        <button class="btn-accion btn-enviar" type="button" onclick="return ejecutarAccionTransf('enviar', <?= $t['transferencias_id'] ?>, '¿Confirmar que ya enviaste los productos?')">Marcar enviado</button>
                                        <?php if ($hayLoteAprobado): ?>
                                        <button class="btn-accion btn-enviar" type="button" style="background:#e1f5fe;" onclick="return ejecutarAccionTransf('enviar_pedido', <?= $t['transferencias_id'] ?>, '¿Confirmar que ya enviaste TODOS los productos aprobados de este pedido? Se descontará el stock de cada uno y se generará el ticket con la lista.')">Marcar todo el pedido enviado</button>
                                        <?php endif; ?>
                                    <?php elseif ($t['estado'] === 'Modificada' && !$esMiOrigen): ?>
                                        <button class="btn-accion btn-aceptar-mod" type="button"
                                           onclick="return ejecutarAccionTransf('aceptar_modificacion', <?= $t['transferencias_id'] ?>, '¿Aceptar la nueva cantidad de <?= number_format($t['cantidad'], 2) ?>? La transferencia continuara como Aprobada.')">
                                            <?= icono('circle-check-big') ?> Aceptar cantidad
                                        </button>
                                        <button class="btn-accion btn-rechazar-mod" type="button"
                                           onclick="return ejecutarAccionTransf('rechazar_modificacion', <?= $t['transferencias_id'] ?>, '¿Rechazar el cambio de cantidad? La transferencia volvera a Pendiente.')">
                                            <?= icono('x') ?> Rechazar cambio
                                        </button>
                                    <?php elseif ($t['estado'] === 'Modificada' && $esMiOrigen): ?>
                                        <span style="color:#283593;font-size:11px;font-style:italic;">Esperando confirmacion del destino</span>
                                    <?php elseif ($t['estado'] === 'En tránsito' && !$esMiOrigen): ?>
                                        <button class="btn-accion btn-recibir" type="button" onclick="return ejecutarAccionTransf('recibir', <?= $t['transferencias_id'] ?>, '¿Confirmar recepcion? Esto movera el stock en ambas sucursales.')">Confirmar recepcion</button>
                                    <?php elseif ($t['estado'] === 'En tránsito' && $esMiOrigen): ?>
                                        <button class="btn-accion" type="button" style="background:#fdecea;color:#c0392b;border:none;cursor:pointer;" onclick="return ejecutarAccionTransf('cancelar', <?= $t['transferencias_id'] ?>, '¿Cancelar esta transferencia? El stock regresara a tu sucursal. Solo hazlo si de verdad recuperaste la mercancia enviada.')">Cancelar</button>
                                    <?php elseif (!$puedeCancelarSolic && !$mostrarTicketTransf): ?>
                                        <span style="color:#aaa;font-size:11px;">—</span>
                                    <?php endif; ?>
                                    <?php if ($puedeCancelarSolic): ?>
                                        <!-- [FEATURE-CANCELAR-TRANSFERENCIA] Solo para corregir un error: la solicitud sigue Pendiente. -->
                                        <button class="btn-accion" type="button" style="background:#fdecea;color:#c0392b;border:none;cursor:pointer;" onclick="return ejecutarAccionTransf('cancelar_solicitud', <?= $t['transferencias_id'] ?>, '¿Cancelar esta solicitud? Aún no la ha aprobado la otra sucursal, así que no pasa nada con el inventario; quedará como Cancelada.')">Cancelar</button>
                                    <?php endif; ?>
                                    <?php if ($mostrarTicketTransf): ?>
                                        <!-- [FEATURE-TICKET-ENVIO-TRANSF] Lista de lo enviado en este pedido. -->
                                        <button class="btn-accion" type="button" style="background:#eef8ff;color:#1565c0;border:none;cursor:pointer;" onclick="abrirTicketEnvio(<?= $t['transferencias_id'] ?>)"><?= icono('printer') ?> Ticket</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay transferencias registradas.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario: solicitar productos de otra sucursal -->
        <div>
            <div class="card">
                <h3>Solicitar productos</h3>
                <p style="font-size:12px;color:#aaa;margin:-8px 0 14px;">Pide productos de otra sucursal a la tuya.</p>

                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach ($errores as $e):?><li><?= htmlspecialchars($e) ?></li><?php endforeach;?></ul></div>
                <?php endif; ?>

                <form method="POST" id="formTransf">
                    <!-- [FIX-A1] Token CSRF para proteger la nueva solicitud de transferencia -->
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="items_transf" id="inputItemsTransf">

                    <div class="form-group">
                        <label>Sucursal origen *</label>
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
                        <div class="hint-bajo" id="hintBajo" style="display:none;">&#9650; Productos marcados en rojo tienen stock bajo en tu sucursal.</div>
                    </div>

                    <div class="prod-seleccionado" id="prodSeleccionado">
                        <div class="prod-sel-nombre" id="prodSelNombre"></div>
                        <div class="prod-sel-stock" id="prodSelStock"></div>
                        <div class="cant-row">
                            <input type="number" id="cantProd" placeholder="Cantidad a pedir" step="1" min="1">
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

                    <button class="btn-guardar" type="submit" onclick="return prepararEnvio()">
                        Enviar solicitud
                    </button>
                    <button type="button" onclick="limpiarFormularioTransf()" style="width:100%;margin-top:8px;background:none;border:none;color:#aaa;font-size:12px;cursor:pointer;">Limpiar formulario</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const prodsBySucursal = <?= json_encode($prodsBySucursal) ?>;
// [FIX-BORRADOR-TRANSF] Igual que nuevaVenta.php: si venimos de enviar la solicitud con exito
// (?msg=solicitado), limpiar el borrador ANTES de restaurarlo.
(function() {
    if (new URLSearchParams(window.location.search).get('msg') === 'solicitado') {
        localStorage.removeItem('itemsTransfDraft');
        localStorage.removeItem('transfExtra');
    }
})();
let itemsTransf = (function() {
    // [FIX-CARRITO-CROSS-SUCURSAL 2026-09-12] (espejo de admin/inventario_transferencias.php)
    // misma clave de localStorage compartida en el mismo origen con la version de admin, que
    // SI puede cambiar de sucursal a medio armado de un borrador.
    try {
        const miSuc = <?= intval($_SESSION['sucursal_id']) ?>;
        const sucGuardada = parseInt(localStorage.getItem('itemsTransfDraft_sucursal_id'));
        if (sucGuardada !== miSuc) return [];
        const guardado = JSON.parse(localStorage.getItem('itemsTransfDraft'));
        return Array.isArray(guardado) ? guardado : [];
    } catch (e) {
        return [];
    }
})();
let prodSelId    = null;
let prodSelTipo  = 'Unidad'; // 'Suelto' o 'Unidad'
let prodSelStock = 0;        // stock disponible en sucursal origen del producto seleccionado

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

function onOrigenChange(val) {
    const input = document.getElementById('busquedaProd');
    input.value = '';
    input.disabled = !val;
    input.placeholder = val ? 'Escribe nombre o código...' : 'Selecciona primero la sucursal origen...';
    document.getElementById('prodSeleccionado').style.display = 'none';
    prodSelId = null;
    hideSug();
    if (val) { setTimeout(() => { input.focus(); }, 50); }
    guardarEstadoTransf();
}

// [FIX-BORRADOR-TRANSF] Persistencia de la sucursal origen — mismo patrón que
// guardarEstadoVenta() en nuevaVenta.php (los items ya se guardan en renderItems()).
function guardarEstadoTransf() {
    localStorage.setItem('transfExtra', JSON.stringify({
        sucursalOrigenId: document.getElementById('selOrigen')?.value || '',
        notas:            document.getElementById('notasTransf')?.value || ''
    }));
}

// [FIX-BORRADOR-TRANSF] Botón "Limpiar formulario" — reinicia lista, sucursal origen y notas
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
    const q = document.getElementById('busquedaProd').value.toLowerCase().trim();
    if (!origenId || !prodsBySucursal[origenId]) { hideSug(); return; }

    let prods = [...prodsBySucursal[origenId]].sort((a, b) => {
        if (a.bajo !== b.bajo) return b.bajo ? 1 : -1;
        return a.nombre.localeCompare(b.nombre);
    });

    const filtered = q === ''
        ? prods
        : prods.filter(p => p.nombre.toLowerCase().includes(q) || p.codigo.toLowerCase().includes(q));

    document.getElementById('hintBajo').style.display = filtered.some(p => p.bajo) ? 'block' : 'none';
    renderSug(filtered);
}

function renderSug(prods) {
    const div = document.getElementById('sugerencias');
    if (!prods.length) { hideSug(); return; }
    div.innerHTML = prods.map(p => {
        const esSuelto = p.tipo_venta === 'Suelto';
        const stockFmt = esSuelto ? parseFloat(p.stock).toFixed(3).replace(/\.?0+$/,'') : Math.floor(p.stock);
        const miStockFmt = esSuelto ? parseFloat(p.mi_stock).toFixed(3).replace(/\.?0+$/,'') : Math.floor(p.mi_stock);
        return `
        <div class="sug-item" onclick="seleccionarProd(${p.id}, '${escAtribJs(p.nombre)}', '${escAtribJs(p.codigo)}', ${p.stock}, ${p.mi_stock}, ${p.bajo}, '${p.tipo_venta||'Unidad'}')">
            <div>
                <span class="sug-nombre">${esc(p.nombre)}</span>
                <span class="sug-codigo">${esc(p.codigo)}</span>
                ${p.bajo ? '<span class="badge-bajo">Stock bajo</span>' : ''}
                ${esSuelto ? '<span style="font-size:10px;color:#888;margin-left:4px;">granel</span>' : ''}
            </div>
            <div class="sug-stocks">
                <span class="sug-stock-row sug-stock-orig">Origen: ${stockFmt}</span>
                <span class="sug-stock-row sug-stock-dest">Tu sucursal: ${miStockFmt}</span>
            </div>
        </div>`;
    }).join('');
    div.style.display = 'block';
}

function hideSug() { document.getElementById('sugerencias').style.display = 'none'; }

function seleccionarProd(id, nombre, codigo, stock, mi_stock, bajo, tipoVenta) {
    prodSelId    = id;
    prodSelTipo  = tipoVenta || 'Unidad';
    prodSelStock = parseFloat(stock) || 0;
    const esSuelto = prodSelTipo === 'Suelto';

    document.getElementById('busquedaProd').value = nombre;
    hideSug();
    document.getElementById('prodSelNombre').textContent = nombre + ' (' + codigo + ')';

    const stockFmt   = esSuelto ? parseFloat(stock).toFixed(3).replace(/\.?0+$/,'')    : Math.floor(stock);
    const miStockFmt = esSuelto ? parseFloat(mi_stock).toFixed(3).replace(/\.?0+$/,'') : Math.floor(mi_stock);
    document.getElementById('prodSelStock').innerHTML =
        'Origen: <strong style="color:#1565c0;">' + stockFmt + '</strong>' +
        ' &nbsp;|&nbsp; Destino (tu sucursal): <strong style="color:#2e7d32;">' + miStockFmt + '</strong>' +
        (bajo ? ' <span style="color:#c0392b;font-size:11px;">(stock bajo)</span>' : '') +
        (esSuelto ? ' <span style="font-size:11px;color:#888;">&nbsp;— granel (acepta decimales)</span>' : '');

    const inpCant = document.getElementById('cantProd');
    inpCant.step        = esSuelto ? '0.001' : '1';
    inpCant.min         = esSuelto ? '0.001' : '1';
    inpCant.inputMode   = esSuelto ? 'decimal' : 'numeric';
    inpCant.placeholder = esSuelto ? 'Cantidad (ej. 2.5)' : 'Cantidad (número entero)';
    inpCant.value = '';

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
    // Validar que la cantidad no supere el stock disponible en la sucursal origen
    if (cant > prodSelStock) {
        const stockFmt = esSuelto
            ? parseFloat(prodSelStock).toFixed(3).replace(/\.?0+$/, '')
            : Math.floor(prodSelStock);
        alert('No se puede pedir ' + cant + ' unidades. La sucursal origen solo tiene ' + stockFmt + ' disponibles.');
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
    localStorage.setItem('itemsTransfDraft', JSON.stringify(itemsTransf));
    localStorage.setItem('itemsTransfDraft_sucursal_id', String(<?= intval($_SESSION['sucursal_id']) ?>));
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
    // [AUTOFIX] BUG-07: Validar explícitamente que se haya seleccionado una sucursal de origen
    const origen = document.getElementById('selOrigen').value;
    if (!origen) {
        alert('Selecciona la sucursal de origen antes de enviar la solicitud.');
        document.getElementById('selOrigen').focus();
        return false;
    }
    if (!itemsTransf.length) { alert('Agrega al menos un producto.'); return false; }
    document.getElementById('inputItemsTransf').value = JSON.stringify(
        itemsTransf.map(i => ({ id: i.id, cantidad: i.cantidad }))
    );
    return true;
}

// Cerrar sugerencias al perder foco (delay para permitir click en una sugerencia)
document.getElementById('busquedaProd').addEventListener('blur', function() {
    setTimeout(hideSug, 200);
});

// ── Editar cantidad transferencia ────────────────────────────────────────────
let _modalEditTipoVenta  = 'Unidad';
let _modalEditStockOrigen = 0;

function abrirModalEditarCantidad(id, cantActual, tipoVenta, stockOrigen) {
    _modalEditTipoVenta   = tipoVenta   || 'Unidad';
    _modalEditStockOrigen = parseFloat(stockOrigen) || 0;
    const esSuelto = _modalEditTipoVenta === 'Suelto';

    const cantFmt = esSuelto
        ? parseFloat(cantActual).toFixed(3).replace(/\.?0+$/,'')
        : Math.floor(cantActual);
    document.getElementById('modalEditCantidadActual').textContent = cantFmt;

    // Mostrar stock disponible en el modal
    const stockFmt = esSuelto
        ? _modalEditStockOrigen.toFixed(3).replace(/\.?0+$/, '')
        : Math.floor(_modalEditStockOrigen);
    const stockDiv = document.getElementById('modalEditStockDisponible');
    if (stockDiv) {
        stockDiv.textContent = 'Stock disponible en origen: ' + stockFmt;
        stockDiv.style.display = 'block';
    }

    const inp = document.getElementById('inputNuevaCantidadModal');
    inp.step        = esSuelto ? '0.001' : '1';
    inp.min         = esSuelto ? '0.001' : '1';
    inp.max         = _modalEditStockOrigen > 0 ? _modalEditStockOrigen : '';
    inp.inputMode   = esSuelto ? 'decimal' : 'numeric';
    inp.placeholder = esSuelto ? 'Ej. 2.5' : 'Ej. 5';
    inp.value       = '';

    // Mostrar/ocultar aviso granel
    document.getElementById('modalEditGranelHint').style.display = esSuelto ? 'block' : 'none';

    document.getElementById('inputEditarTransfId').value      = id;
    document.getElementById('inputEditarNuevaCantidad').value  = '';
    document.getElementById('modalEditCantidad').style.display = 'flex';
    setTimeout(() => inp.focus(), 80);
}

function cerrarModalEditarCantidad() {
    document.getElementById('modalEditCantidad').style.display = 'none';
}

function confirmarEditarCantidad() {
    const esSuelto = _modalEditTipoVenta === 'Suelto';
    let val = parseFloat(document.getElementById('inputNuevaCantidadModal').value);
    if (!val || val <= 0) { alert('Ingresa una cantidad válida mayor a 0.'); return; }
    if (!esSuelto) {
        if (val !== Math.floor(val)) {
            alert('Este producto no es granel. La cantidad debe ser un número entero (sin decimales).');
            return;
        }
        val = Math.floor(val);
    }
    // Validar contra stock disponible en origen
    if (_modalEditStockOrigen > 0 && val > _modalEditStockOrigen) {
        const stockFmt = esSuelto
            ? _modalEditStockOrigen.toFixed(3).replace(/\.?0+$/, '')
            : Math.floor(_modalEditStockOrigen);
        alert('No se puede guardar: la cantidad (' + val + ') supera el stock disponible en la sucursal origen (' + stockFmt + ').');
        return;
    }
    document.getElementById('inputEditarNuevaCantidad').value = val;
    document.getElementById('formEditarCantidadTransf').submit();
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalEditarCantidad();
    }
});

// [FIX-BORRADOR-TRANSF] Restaurar productos/sucursal origen en curso, mismo patrón que
// restaurarEstadoVenta() en nuevaVenta.php. Se lee "extra" ANTES de renderItems() por si
// acaso esa función llegara a disparar algun guardado propio en el futuro.
(function restaurarEstadoTransf() {
    let extra = null;
    try { extra = JSON.parse(localStorage.getItem('transfExtra')); } catch (e) {}

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
        html += `<div class="t-centro" style="margin-bottom:6px;"><img src="../${esc(logoTicket)}" style="max-width:${maxW};max-height:50px;object-fit:contain;"></div>`;
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
        <div class="t-fila"><span>Pedido:</span><span>#${esc(d.pedido)}</span></div>
        <div class="t-fila"><span>Fecha envío:</span><span>${esc(d.fecha_envio || '—')}</span></div>
        <div class="t-fila"><span>Origen:</span><span style="text-align:right;">${esc(o.nombre || '')}</span></div>
        <div class="t-fila"><span>Destino:</span><span style="text-align:right;">${esc(d.destino || '')}</span></div>
        <div class="t-fila"><span>Solicitó:</span><span style="text-align:right;">${esc(d.solicitante || '—')}</span></div>
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
        <div style="margin-top:8px;">Observaciones:</div>
        <div style="margin-top:16px;border-top:1px solid #000;"></div>
        <div style="margin-top:16px;border-top:1px solid #000;"></div>
        <div style="margin-top:16px;border-top:1px solid #000;"></div>
        <div style="margin-top:16px;border-top:1px solid #000;"></div>
        <div style="margin-top:16px;border-top:1px solid #000;"></div>
        <div style="margin-top:16px;border-top:1px solid #000;"></div>
        <div class="t-linea"></div>
        <div class="t-centro" style="font-size:10px;margin-top:4px;">Conserve este comprobante</div>`;

    el.innerHTML = html;
}

function abrirTicketEnvio(id) {
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
        abrirTicketEnvio(t);
        p.delete('abrir_ticket');
        try { history.replaceState(null, '', window.location.pathname + (p.toString() ? '?' + p.toString() : '')); } catch (e) {}
    }
})();
</script>

<!-- Form editar cantidad -->
<form id="formEditarCantidadTransf" method="POST" action="transferencias.php" style="display:none;">
    <!-- [FIX-A1] Token CSRF para proteger la edicion de cantidad -->
    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="accion" value="editar_cantidad">
    <input type="hidden" id="inputEditarTransfId" name="id">
    <input type="hidden" id="inputEditarNuevaCantidad" name="nueva_cantidad">
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

<!-- Modal editar cantidad — event listener aquí porque el script principal corre antes del HTML -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('modalEditCantidad').addEventListener('click', function(e) {
        if (e.target === this) cerrarModalEditarCantidad();
    });
});
</script>
<div id="modalEditCantidad" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;padding:22px;width:320px;box-shadow:0 8px 32px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <span style="font-size:14px;font-weight:700;color:#333;">Modificar cantidad a enviar</span>
            <button onclick="cerrarModalEditarCantidad()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#888;line-height:1;">×</button>
        </div>
        <div style="font-size:13px;color:#555;margin-bottom:8px;">
            Cantidad actual: <strong id="modalEditCantidadActual" style="color:#1565c0;"></strong>
        </div>
        <div id="modalEditStockDisponible" style="display:none;font-size:12px;color:#2e7d32;font-weight:600;margin-bottom:12px;"></div>
        <div style="font-size:12px;color:#888;background:#fff8e1;border:1px solid #ffe082;border-radius:6px;padding:10px 12px;margin-bottom:14px;">
            <?= icono('triangle-alert') ?> Al guardar, la transferencia pasará a estado <strong>Modificada</strong> y la sucursal destino deberá aceptar o rechazar el cambio.
        </div>
        <div id="modalEditGranelHint" style="display:none;font-size:12px;color:#555;background:#e8f5e9;border:1px solid #c8e6c9;border-radius:6px;padding:8px 12px;margin-bottom:12px;">
            <?= icono('wheat') ?> Producto a granel — acepta decimales (ej. 2.5 kg)
        </div>
        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:12px;color:#555;font-weight:600;margin-bottom:6px;">Nueva cantidad *</label>
            <input type="number" id="inputNuevaCantidadModal" step="1" min="1"
                   placeholder="Ej. 5"
                   style="width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;"
                   onkeydown="if(event.key==='Enter') confirmarEditarCantidad()">
        </div>
        <div style="display:flex;gap:8px;">
            <button onclick="confirmarEditarCantidad()"
                    style="flex:1;background:#14ace7;color:#fff;border:none;padding:10px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">
                Guardar cambio
            </button>
            <button onclick="cerrarModalEditarCantidad()"
                    style="background:#f0f0f0;color:#555;border:none;padding:10px 16px;border-radius:6px;font-size:13px;cursor:pointer;">
                Cancelar
            </button>
        </div>
    </div>
</div>

<script src="../includes/dropdown_keynav.js"></script>
<script>
// [FEATURE-DROPDOWN-KEYNAV] Navegar los resultados de búsqueda con flechas y Enter.
attachDropdownKeyNav(
    document.getElementById('busquedaProd'),
    document.getElementById('sugerencias'),
    hideSug
);
</script>
</body>
</html>
