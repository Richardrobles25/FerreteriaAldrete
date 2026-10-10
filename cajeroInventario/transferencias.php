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

// [FEATURE-PEDIDO-TRANSFERENCIA 2026-10-06] Un pedido (todas las filas de una misma solicitud) se
// muestra y se opera como UN solo carrito: una fila en el listado, un boton por accion para todo el
// pedido. Agrupa las filas del listado (mismo origen, destino, solicitante y momento de creacion).
function transfAgruparPedidos(array $filas): array {
    $grupos = [];
    foreach ($filas as $f) {
        $ts = strtotime($f['created_at']);
        $colocado = false;
        foreach ($grupos as &$g) {
            if ($g['origen'] == $f['sucursal_origen_id'] && $g['destino'] == $f['sucursal_destino_id']
                && $g['solicitante'] == $f['usuario_solicita_id'] && abs($g['ts'] - $ts) <= 2) {
                $g['filas'][] = $f;
                $colocado = true;
                break;
            }
        }
        unset($g);
        if (!$colocado) {
            $grupos[] = ['origen' => $f['sucursal_origen_id'], 'destino' => $f['sucursal_destino_id'],
                         'solicitante' => $f['usuario_solicita_id'], 'ts' => $ts, 'filas' => [$f]];
        }
    }
    foreach ($grupos as &$g) {
        usort($g['filas'], fn($a, $b) => intval($a['transferencias_id']) <=> intval($b['transferencias_id']));
    }
    unset($g);
    return $grupos;
}

// [FEATURE-PEDIDO-TRANSFERENCIA] Misma logica de 'recibir' (una fila) pero reutilizable dentro de la
// transaccion de 'recibir_pedido'. Devuelve null si todo salio bien o la llave de mensaje de error.
function transfRecibirFila(PDO $pdo, int $id, int $miSucursal, int $usuarioId): ?string {
    $stmtLock = $pdo->prepare("SELECT * FROM transferencias WHERE transferencias_id = ? FOR UPDATE");
    $stmtLock->execute([$id]);
    $transf = $stmtLock->fetch(PDO::FETCH_ASSOC);
    if (!$transf || $transf['estado'] !== 'En tránsito' || $transf['sucursal_destino_id'] != $miSucursal) {
        return 'error_ya_no_transito';
    }
    $stmtMarca = $pdo->prepare("SELECT COUNT(*) FROM movimientos_inventario WHERE tipo = 'Transferencia' AND motivo = ? AND sucursal_id = ?");
    $stmtMarca->execute(['Transferencia enviada #' . $id, $transf['sucursal_origen_id']]);
    if (intval($stmtMarca->fetchColumn()) === 0) {
        // Ruta de compatibilidad: transferencia enviada con la logica anterior (descuenta el origen al recibir).
        $stmtOrigen = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
        $stmtOrigen->execute([$transf['producto_id'], $transf['sucursal_origen_id']]);
        $stockOrLegacy = $stmtOrigen->fetchColumn();
        if ($stockOrLegacy === false || floatval($stockOrLegacy) < floatval($transf['cantidad'])) {
            return 'error_stock_recibir';
        }
        $stockAntOrigen   = floatval($stockOrLegacy);
        $stockNuevoOrigen = $stockAntOrigen - floatval($transf['cantidad']);
        $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
            ->execute([$stockNuevoOrigen, $transf['producto_id'], $transf['sucursal_origen_id']]);
        $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Transferencia',?,?,?,'Transferencia enviada')")
            ->execute([$transf['producto_id'], $usuarioId, $transf['sucursal_origen_id'], $transf['cantidad'], $stockAntOrigen, $stockNuevoOrigen]);
    }
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
        $stockNuevoDest = $stockAntDest + floatval($transf['cantidad']);
        $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")
            ->execute([$stockNuevoDest, $transf['producto_id'], $transf['sucursal_destino_id']]);
    } else {
        $stockNuevoDest = floatval($transf['cantidad']);
        $pdo->prepare("INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo) VALUES (?,?,?,0,0,1)")
            ->execute([$transf['producto_id'], $transf['sucursal_destino_id'], $stockNuevoDest]);
        $stockAntDest = 0;
    }
    $pdo->prepare("INSERT INTO movimientos_inventario (producto_id, usuario_id, sucursal_id, tipo, cantidad, stock_anterior, stock_nuevo, motivo) VALUES (?,?,?,'Transferencia',?,?,?,?)")
        ->execute([$transf['producto_id'], $usuarioId, $transf['sucursal_destino_id'], $transf['cantidad'], $stockAntDest, $stockNuevoDest, 'Transferencia recibida #' . $id]);
    $pdo->prepare("UPDATE transferencias SET estado='Entregada', usuario_aprueba_id=? WHERE transferencias_id=?")
        ->execute([$usuarioId, $id]);
    return null;
}

// [FEATURE-PEDIDO-TRANSFERENCIA] Misma logica de 'cancelar' (una fila En transito, regresa el stock al
// origen) reutilizable dentro de la transaccion de 'cancelar_pedido'.
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
        // [FEATURE-PEDIDO-TRANSFERENCIA] Confirmar la recepcion de TODO el pedido (todo o nada, una transaccion).
        $ped = transfCargarPedido($pdo, $id);
        $filasAcc = [];
        if ($ped) {
            foreach ($ped['filas'] as $f) {
                if ($f['estado'] === 'En tránsito' && $f['sucursal_destino_id'] == $miSucursal) $filasAcc[] = $f;
            }
        }
        if (!$filasAcc) { header('Location: transferencias.php?msg=error_ya_no_transito'); exit(); }
        $pdo->beginTransaction();
        try {
            foreach ($filasAcc as $f) {
                $err = transfRecibirFila($pdo, intval($f['transferencias_id']), intval($miSucursal), intval($_SESSION['usuario_id']));
                if ($err !== null) {
                    $pdo->rollBack();
                    header('Location: transferencias.php?msg=' . $err . '&det=' . urlencode($f['nombre_producto'])); exit();
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Ferreteria/transferencias] Error al recibir pedido (fila base #' . $id . '): ' . $e->getMessage());
            header('Location: transferencias.php?msg=error_recibir'); exit();
        }
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
        // [FEATURE-PEDIDO-TRANSFERENCIA] Mismo momento de creacion para todas las filas: identifica al pedido.
        $tsPedido = $pdo->query("SELECT NOW()")->fetchColumn();
        foreach ($items as $item) {
            $prodId   = intval($item['id']);
            $cantidad = floatval($item['cantidad']);
            $stmtTV3  = $pdo->prepare("SELECT tipo_venta FROM productos WHERE producto_id = ?");
            $stmtTV3->execute([$prodId]);
            $tvRow3 = $stmtTV3->fetch(PDO::FETCH_ASSOC);
            if ($tvRow3 && $tvRow3['tipo_venta'] !== 'Suelto') { $cantidad = floor($cantidad); }
            if ($cantidad <= 0) continue;
            $pdo->prepare("INSERT INTO transferencias (producto_id, sucursal_origen_id, sucursal_destino_id, usuario_solicita_id, cantidad, notas, estado, created_at) VALUES (?,?,?,?,?,?,'Pendiente',?)")
                ->execute([$prodId, $sucursal_origen_id, $_SESSION['sucursal_id'], $_SESSION['usuario_id'], $cantidad, $notas, $tsPedido]);
            $insertados++;
        }
        if ($insertados > 0) {
            header('Location: transferencias.php?msg=solicitado'); exit();
        }
        $errores[] = 'Ningún producto tenía una cantidad válida después de ajustar por tipo de venta. Revisa las cantidades e intenta de nuevo.';
    }
}

// Filtro de fechas para el historial
// El historial abre mostrando desde DOS DIAS ANTES de hoy (antes: desde el dia 1 del mes).
$filtroDesde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 days'));
$filtroHasta = $_GET['hasta'] ?? date('Y-m-d');
$filtroEstado = trim(is_scalar($_GET['estado_f'] ?? null) ? (string)$_GET['estado_f'] : '');

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
                    'aprobar_pedido'        => 'Pedido aceptado. Prepara el envío de todos los productos y márcalo como enviado cuando salga.',
                    'rechazar_pedido'       => 'Pedido rechazado.',
                    'cancelar_solicitud_pedido' => 'Pedido cancelado.',
                    'editar_pedido'         => 'Pedido modificado. Los productos con cantidad distinta esperan la confirmación de la sucursal destino; el resto quedó aceptado.',
                    'aceptar_modificacion_pedido'  => 'Cambios aceptados. El pedido continúa como Aprobado.',
                    'rechazar_modificacion_pedido' => 'Cambios rechazados. Los productos modificados volvieron a Pendiente.',
                    'recibir_pedido'        => 'Recepción confirmada. El stock de todos los productos del pedido fue sumado a tu sucursal.',
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
                                ];
                            }
                            $jsonEdicion = htmlspecialchars(json_encode($datosEdicion, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
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
                                <div class="ped-id">#<?= $idRep ?></div>
                                <div class="ped-meta"><?= date('d/m/Y', strtotime($t['created_at'])) ?></div>
                                <div class="ped-meta">Pidió: <?= htmlspecialchars($t['solicitante']) ?></div>
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
                                <span class="badge <?= $badgeMap[$estadoPrincipal] ?>"><?= htmlspecialchars($estadoPrincipal) ?></span>
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
                                            <button class="bt bt-ok" type="button" onclick="return ejecutarAccionTransf('recibir_pedido', <?= $idRep ?>, '¿Confirmar la recepción del pedido completo? Esto moverá el stock de todos sus productos en ambas sucursales.')">Confirmar recepción</button>
                                        <?php endif; ?>
                                        <?php if ($esMiOrigen && $hayModif): $hayAcciones = true; ?>
                                            <span class="bt-espera">Esperando confirmación del destino</span>
                                        <?php endif; ?>
                                        <?php if ($esMiOrigen && ($hayPend || $hayAprob)): $hayAcciones = true; ?>
                                            <button class="bt bt-warn" type="button" data-id="<?= $idRep ?>" data-filas="<?= $jsonEdicion ?>" onclick="abrirModalEditarPedido(this)">Editar pedido</button>
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
    const textoCrudo = document.getElementById('busquedaProd').value.trim();
    let q = textoCrudo.toLowerCase();
    if (!origenId || !prodsBySucursal[origenId]) { hideSug(); return; }

    let prods = [...prodsBySucursal[origenId]].sort((a, b) => {
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
                <span class="sug-stock-row sug-stock-orig">Origen: ${stockFmt}</span>
                <span class="sug-stock-row sug-stock-dest">Tu sucursal: ${miStockFmt}</span>
            </div>
        </div>`;
    }).join('');
    div.style.display = 'block';
}

function hideSug() { document.getElementById('sugerencias').style.display = 'none'; }

// [FEATURE-BUSQUEDA-CODIGO] Captura rapida con Enter en el buscador: "4*codigo" agrega 4 piezas de ese producto
// a la lista (se SUMAN a lo que ya estuviera pedido); un codigo exacto solo elige el producto para capturar la
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
        BusquedaProductos.aviso('La sucursal origen solo tiene ' + stockFmt + ' disponible(s) de "' + p.nombre + '"' +
            (existe ? ' (ya pediste ' + existe.cantidad + ').' : '.'), true, 5000);
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

document.getElementById('busquedaProd').addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    if (BusquedaProductos.hayResaltado(document.getElementById('sugerencias'))) return;
    const val = this.value.trim();
    if (!val) return;
    const origenId = parseInt(document.getElementById('selOrigen').value) || 0;
    if (!origenId || !prodsBySucursal[origenId]) return;
    const lista = prodsBySucursal[origenId];
    const r = BusquedaProductos.resolverRapido(val, c => BusquedaProductos.buscarExacto(lista, c, p => p.codigo));
    if (!r) return;                       // no es un codigo exacto ni cantidad*codigo: todo como siempre
    e.preventDefault();
    if (r.noExiste) {
        BusquedaProductos.aviso('No existe ningún producto con el código "' + r.noExiste + '" en la sucursal origen.', true, 4000);
        return;
    }
    const p = r.item;
    if (!r.explicita) {                   // codigo exacto sin cantidad: se elige el producto y se captura la cantidad
        seleccionarProd(p.id, p.nombre, p.codigo, p.stock, p.mi_stock, p.bajo, p.tipo_venta || 'Unidad');
        return;
    }
    agregarRapidoTransf(p, r.cantidad);
});

function seleccionarProd(id, nombre, codigo, stock, mi_stock, bajo, tipoVenta, cantidadRapida) {
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
    // [FEATURE-BUSQUEDA-CODIGO] cantidad pedida con "4*codigo": se precarga (agregarItem valida entero y stock)
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

// ── Editar pedido de transferencia (todos sus productos en una sola pantalla) ────────────────
function fmtCantPed(n, suelto) {
    return suelto ? parseFloat(n).toFixed(3).replace(/\.?0+$/, '') : String(Math.floor(n));
}

function abrirModalEditarPedido(btn) {
    let filas = [];
    try { filas = JSON.parse(btn.getAttribute('data-filas') || '[]'); } catch (e) { filas = []; }
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
        det.textContent = 'Pedido: ' + fmtCantPed(f.cantidad, f.suelto) + (editable ? ' · Stock en origen: ' + fmtCantPed(f.stock, f.suelto) : '');
        info.appendChild(nom); info.appendChild(cod); info.appendChild(det);
        fila.appendChild(info);
        if (editable) {
            const inp = document.createElement('input');
            inp.type = 'number';
            inp.name = 'cant[' + f.id + ']';
            inp.value = fmtCantPed(f.cantidad, f.suelto);
            inp.step = f.suelto ? '0.001' : '1';
            inp.min = '0';
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
            if (inp.dataset.estado !== 'Pendiente') { alert('"' + nombre + '" ya fue aceptado: la cantidad mínima es 1. Si no lo vas a enviar, cancela el pedido.'); inp.focus(); return; }
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
    if (cambios === 0) { alert('No cambiaste ninguna cantidad. Si todo está bien, cierra esta ventana y usa "Aceptar pedido".'); return; }
    if (quedan === 0 && !hayModificadas) { alert('No puedes retirar todos los productos del pedido. Si no vas a enviar nada, usa "Rechazar pedido".'); return; }
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
        <div style="font-size:12px;color:#888;background:#fff8e1;border:1px solid #ffe082;border-radius:6px;padding:10px 12px;margin-bottom:10px;">
            <?= icono('triangle-alert') ?> Los productos con <strong>cantidad distinta</strong> pasarán a <strong>Modificada</strong> y la sucursal destino deberá aceptar el cambio; los demás quedarán aceptados. Pon <strong>0</strong> en un producto Pendiente para retirarlo del pedido.
        </div>
        <div id="modalEditPedidoLista" style="overflow-y:auto;flex:1;min-height:0;"></div>
        <div style="display:flex;gap:8px;margin-top:14px;">
            <button type="button" onclick="confirmarEditarPedido()" style="flex:1;background:#14ace7;color:#fff;border:none;padding:10px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">Guardar cambios</button>
            <button type="button" onclick="cerrarModalEditarPedido()" style="background:#f0f0f0;color:#555;border:none;padding:10px 16px;border-radius:6px;font-size:13px;cursor:pointer;">Cancelar</button>
        </div>
    </div>
</div>

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
</body>
</html>
