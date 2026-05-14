<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

// Datos bancarios de la sucursal
$sucursalInfo = $pdo->prepare("SELECT banco, titular_cuenta, numero_cuenta, clabe_interbancaria, alias_tarjeta, comision_terminal_pct FROM sucursales WHERE sucursal_id = ?");
$sucursalInfo->execute([$_SESSION['sucursal_id']]);
$datosBanco  = $sucursalInfo->fetch(PDO::FETCH_ASSOC);
$comisionPct = floatval($datosBanco['comision_terminal_pct'] ?? 0);

$pdo->exec("UPDATE creditos SET estado='Vencido' WHERE estado='Activo' AND fecha_limite IS NOT NULL AND fecha_limite < CURDATE()");

// Caja abierta del usuario actual — si no hay, redirigir a abrirCaja
$stmtCajaCheck = $pdo->prepare("SELECT caja_id FROM cajas WHERE usuario_id = ? AND estado = 'Abierta' LIMIT 1");
$stmtCajaCheck->execute([$_SESSION['usuario_id']]);
$cajaAbiertaId = $stmtCajaCheck->fetchColumn() ?: null;
if (!$cajaAbiertaId) {
    header('Location: abrirCaja.php?msg=sinCaja');
    exit();
}

// AJAX: historial de pagos de un cliente (todos sus créditos)
if (isset($_GET['get_abonos_cliente'])) {
    header('Content-Type: application/json');
    $cliente_id = intval($_GET['get_abonos_cliente']);
    $stmt = $pdo->prepare("
        SELECT a.abono_id, a.monto, a.comision_terminal, a.metodo_pago, a.notas, a.created_at,
               u.nombre_completo AS cajero,
               COALESCE(v.folio, CONCAT('Crédito #', cr.credito_id)) AS folio
        FROM abonos a
        JOIN creditos cr ON a.credito_id = cr.credito_id
        JOIN usuarios u  ON a.usuario_id = u.usuario_id
        LEFT JOIN ventas v ON cr.venta_id = v.venta_id
        WHERE cr.cliente_id = ?
        ORDER BY a.created_at DESC
        LIMIT 60
    ");
    $stmt->execute([$cliente_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// AJAX: registrar pago (distribuye automáticamente a créditos del más antiguo al más reciente)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'registrar_abono') {
    header('Content-Type: application/json');
    try {
        // Caja abierta obligatoria
        $stmtCajaAb = $pdo->prepare("SELECT caja_id FROM cajas WHERE usuario_id = ? AND estado = 'Abierta' LIMIT 1");
        $stmtCajaAb->execute([$_SESSION['usuario_id']]);
        $cajaIdAb = $stmtCajaAb->fetchColumn();
        if (!$cajaIdAb) throw new Exception('No hay caja abierta. Abre la caja antes de registrar un pago.');

        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        $monto      = floatval($_POST['monto'] ?? 0);
        $metodo     = $_POST['metodo_pago'] ?? '';
        $notas      = trim($_POST['notas'] ?? '');
        $referencia = trim($_POST['referencia'] ?? '');
        $monto_ef   = floatval($_POST['monto_efectivo'] ?? 0);
        $monto_term = floatval($_POST['monto_terminal'] ?? 0);
        $adelantado = ($_POST['pago_adelantado'] ?? '') === '1' ? 1 : 0;

        if ($monto <= 0) throw new Exception('El monto debe ser mayor a 0.');
        if (!in_array($metodo, ['Efectivo','Terminal','Transferencia','Mixto'])) throw new Exception('Selecciona el método de pago.');
        if ($metodo === 'Transferencia' && $referencia === '') throw new Exception('Ingresa la referencia de la transferencia.');
        if ($metodo === 'Mixto' && $monto_ef <= 0) throw new Exception('Ingresa el monto en efectivo para el pago mixto.');

        $stmtCl = $pdo->prepare("SELECT nombre_completo FROM clientes WHERE cliente_id = ?");
        $stmtCl->execute([$cliente_id]);
        $clienteNombre = $stmtCl->fetchColumn();
        if (!$clienteNombre) throw new Exception('Cliente no encontrado.');

        // Créditos del cliente, del más antiguo al más reciente
        $stmtCrs = $pdo->prepare("
            SELECT credito_id, saldo_pendiente
            FROM creditos
            WHERE cliente_id = ? AND estado IN ('Activo', 'Vencido')
            ORDER BY created_at ASC
        ");
        $stmtCrs->execute([$cliente_id]);
        $creditsRows = $stmtCrs->fetchAll(PDO::FETCH_ASSOC);

        $totalPendiente = array_sum(array_column($creditsRows, 'saldo_pendiente'));
        if ($monto > $totalPendiente + 0.001) {
            throw new Exception('El monto excede el total pendiente ($'.number_format($totalPendiente,2).').');
        }

        // Calcular comisión según método
        $comisionTotal = 0;
        if ($metodo === 'Terminal' && $comisionPct > 0) {
            $comisionTotal = round($monto * $comisionPct / 100, 2);
        }
        if ($metodo === 'Mixto' && $comisionPct > 0 && $monto_ef < $monto) {
            $baseTerminal  = $monto - $monto_ef;
            $comisionTotal = round($baseTerminal * $comisionPct / 100, 2);
        }
        if ($metodo === 'Transferencia' && $referencia !== '') {
            $notas = 'Ref: ' . $referencia . ($notas !== '' ? ' — ' . $notas : '');
        }

        $pdo->beginTransaction();

        // Aplicar FIFO: liquidar créditos del más antiguo al más reciente
        $restante = $monto;
        foreach ($creditsRows as $cr) {
            if ($restante <= 0.001) break;
            $pagoEste    = min($restante, floatval($cr['saldo_pendiente']));
            $nuevoSaldo  = max(0, floatval($cr['saldo_pendiente']) - $pagoEste);
            $nuevoEstado = $nuevoSaldo <= 0.001 ? 'Liquidado' : 'Activo';
            $comisionEste = ($monto > 0 && $comisionTotal > 0) ? round($comisionTotal * $pagoEste / $monto, 2) : 0;

            $pdo->prepare("INSERT INTO abonos (credito_id, usuario_id, monto, comision_terminal, metodo_pago, notas) VALUES (?,?,?,?,?,?)")
                ->execute([$cr['credito_id'], $_SESSION['usuario_id'], $pagoEste, $comisionEste, $metodo, $notas]);
            if ($nuevoEstado === 'Liquidado') {
                $pdo->prepare("UPDATE creditos SET saldo_pendiente = 0, estado = 'Liquidado' WHERE credito_id = ?")
                    ->execute([$cr['credito_id']]);
            } else {
                $pdo->prepare("UPDATE creditos SET saldo_pendiente = ?, estado = 'Activo', fecha_limite = IF(fecha_limite <= CURDATE() OR ?, DATE_ADD(fecha_limite, INTERVAL 2 DAY), fecha_limite) WHERE credito_id = ?")
                    ->execute([$nuevoSaldo, $adelantado, $cr['credito_id']]);
            }

            $restante -= $pagoEste;
        }

        // Movimientos de caja — Mixto genera dos entradas (efectivo + terminal)
        $notaBase = 'Pago crédito — ' . $clienteNombre . ($notas !== '' ? ': ' . $notas : '');
        if ($metodo === 'Mixto') {
            if ($monto_ef > 0) {
                $pdo->prepare("INSERT INTO movimientos_caja (caja_id, usuario_id, sucursal_id, tipo, monto, nota) VALUES (?,?,?,'Ingreso',?,?)")
                    ->execute([$cajaIdAb, $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $monto_ef, $notaBase . ' [Efectivo]']);
            }
            if ($monto_term > 0) {
                $pdo->prepare("INSERT INTO movimientos_caja (caja_id, usuario_id, sucursal_id, tipo, monto, nota) VALUES (?,?,?,'Ingreso',?,?)")
                    ->execute([$cajaIdAb, $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $monto_term, $notaBase . ' [Terminal]']);
            }
        } else {
            // Terminal: registrar lo que el cliente paga (monto + comisión)
            $montoMov  = ($metodo === 'Terminal') ? $monto + $comisionTotal : $monto;
            $pdo->prepare("INSERT INTO movimientos_caja (caja_id, usuario_id, sucursal_id, tipo, monto, nota) VALUES (?,?,?,'Ingreso',?,?)")
                ->execute([$cajaIdAb, $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $montoMov, $notaBase . ' [' . $metodo . ']']);
        }

        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // [AUTOFIX] SEC-04: Loguear error real internamente, responder con mensaje generico
        error_log('[Ferreteria/creditos] Error abono: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]); // Mensajes de validacion son seguros de mostrar
    }
    exit();
}

// AJAX: créditos activos de un cliente
if (isset($_GET['get_creditos_cliente'])) {
    header('Content-Type: application/json');
    try {
        $cliente_id = intval($_GET['get_creditos_cliente']);
        $stmt = $pdo->prepare("
            SELECT cr.credito_id, cr.monto_total, cr.saldo_pendiente, cr.estado,
                   cr.created_at, cr.fecha_limite,
                   v.folio, v.total AS total_venta, v.created_at AS fecha_venta,
                   GROUP_CONCAT(
                       CONCAT(p.nombre_producto, '||', CAST(vp.cantidad AS CHAR), '||', CAST(vp.precio_unitario AS CHAR))
                       ORDER BY p.nombre_producto SEPARATOR ';;'
                   ) AS prods_raw
            FROM creditos cr
            JOIN ventas v ON cr.venta_id = v.venta_id
            LEFT JOIN venta_productos vp ON cr.venta_id = vp.venta_id
            LEFT JOIN productos p ON vp.producto_id = p.producto_id
            WHERE cr.cliente_id = ? AND cr.estado IN ('Activo', 'Vencido')
            GROUP BY cr.credito_id
            ORDER BY cr.created_at ASC
        ");
        $stmt->execute([$cliente_id]);
        $creditos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($creditos as &$cr) {
            $cr['productos'] = [];
            if (!empty($cr['prods_raw'])) {
                foreach (explode(';;', $cr['prods_raw']) as $prod) {
                    $parts = explode('||', $prod, 3);
                    if (count($parts) === 3) {
                        $cr['productos'][] = [
                            'nombre'   => $parts[0],
                            'cantidad' => floatval($parts[1]),
                            'precio'   => floatval($parts[2]),
                        ];
                    }
                }
            }
            unset($cr['prods_raw']);
        }
        echo json_encode($creditos);
    } catch (\Throwable $e) {
        // [AUTOFIX] SEC-04: No exponer errores tecnicos al cliente
        error_log('[Ferreteria/creditos] Error get_creditos: ' . $e->getMessage());
        echo json_encode(['error' => 'Error al obtener los créditos. Intenta de nuevo.']);
    }
    exit();
}

// Clientes con deuda activa
$stmt = $pdo->prepare("
    SELECT c.cliente_id, c.nombre_completo, c.telefono,
           COUNT(cr.credito_id)                                      AS num_creditos,
           SUM(cr.saldo_pendiente)                                   AS total_pendiente,
           MIN(cr.created_at)                                        AS primer_credito,
           MAX(CASE WHEN cr.estado = 'Vencido' THEN 1 ELSE 0 END)   AS tiene_vencido,
           MIN(cr.fecha_limite)                                      AS proxima_fecha
    FROM creditos cr
    JOIN clientes c ON cr.cliente_id = c.cliente_id
    WHERE cr.estado IN ('Activo', 'Vencido')
    GROUP BY c.cliente_id
    ORDER BY tiene_vencido DESC, total_pendiente DESC
");
$stmt->execute();
$clientesDeuda = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas globales
$totales = $pdo->query("
    SELECT
        COUNT(DISTINCT cr.cliente_id)                           AS clientes_con_deuda,
        COALESCE(SUM(cr.saldo_pendiente), 0)                    AS total_pendiente,
        COUNT(CASE WHEN cr.estado = 'Vencido' THEN 1 END)       AS creditos_vencidos,
        COUNT(cr.credito_id)                                    AS total_creditos
    FROM creditos cr
    WHERE cr.estado IN ('Activo', 'Vencido')
")->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Créditos — Ferretería Aldrete</title>
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
    .divider { height: 1px; background: #f0f0f0; margin: 6px 8px; }
    .menu-label { padding: 8px 16px 4px; font-size: 10px; font-weight: 700; color: #14ace7; text-transform: uppercase; letter-spacing: 0.5px; }
    .sidebar-footer { padding: 12px 16px; border-top: 1px solid #f0f0f0; font-size: 11px; color: #bbb; white-space: nowrap; }
    .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #f7f7f7; }
    .topbar { background: #14ace7; color: white; padding: 0 20px; height: 52px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .topbar-left { display: flex; align-items: center; gap: 12px; }
    .topbar h2 { font-size: 15px; font-weight: 600; }
    .toggle-btn { background: none; border: none; color: white; cursor: pointer; font-size: 20px; padding: 4px 8px; border-radius: 4px; }
    .toggle-btn:hover { background: rgba(255,255,255,0.2); }
    .topbar-right { display: flex; align-items: center; gap: 14px; font-size: 13px; }
    .logout-btn { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); color: white; padding: 5px 14px; border-radius: 5px; cursor: pointer; font-size: 12px; }
    .logout-btn:hover { background: rgba(255,255,255,0.3); }
    .content { flex: 1; padding: 20px; overflow-y: auto; }

    /* Stats */
    .stats { grid-column: 1 / -1; display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 4px; }
    .stat { background: white; border-radius: 8px; padding: 14px 16px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 0.4px; }
    .stat h3 { font-size: 20px; font-weight: 700; color: #222; margin: 0; }

    /* Tabla de clientes */
    .tabla-top { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .buscar-clientes { flex: 1; padding: 9px 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 13px; background: white; }
    .buscar-clientes:focus { outline: none; border-color: #14ace7; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    .tabla-creditos { width: 100%; border-collapse: collapse; }
    .tabla-creditos thead th { padding: 10px 14px; text-align: left; font-size: 11px; font-weight: 700; color: #999; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid #eee; background: #fafafa; white-space: nowrap; }
    .tabla-creditos tbody td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: middle; }
    .tabla-creditos tbody tr:last-child td { border-bottom: none; }
    .tabla-creditos tbody tr:hover td { background: #f9fbff; }
    .badge-vencido { display: inline-block; background: #fdecea; color: #c0392b; border-radius: 99px; padding: 2px 9px; font-size: 11px; font-weight: 700; }
    .badge-activo-tbl { display: inline-block; background: #e8f5e9; color: #2e7d32; border-radius: 99px; padding: 2px 9px; font-size: 11px; font-weight: 700; }
    .btn-cobrar { background: #14ace7; color: white; border: none; padding: 6px 14px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }
    .btn-cobrar:hover { background: #0d8fc0; }
    .sin-resultados { padding: 48px; text-align: center; color: #aaa; font-size: 14px; }

    /* Modal detalles */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 500; align-items: center; justify-content: center; }
    .modal-overlay.visible { display: flex; }
    .modal { background: white; border-radius: 10px; width: 560px; max-width: 95vw; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden; }
    .modal-header { padding: 18px 20px; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; }
    .modal-header h3 { font-size: 16px; font-weight: 700; color: #222; margin: 0; }
    .modal-header .modal-subtitulo { font-size: 12px; color: #888; margin-top: 2px; }
    .modal-close { background: none; border: none; font-size: 22px; color: #aaa; cursor: pointer; line-height: 1; }
    .modal-close:hover { color: #555; }
    .modal-body { flex: 1; overflow-y: auto; padding: 18px 20px; }
    .credito-det { border: 0.5px solid #eee; border-radius: 8px; margin-bottom: 14px; overflow: hidden; }
    .credito-det:last-child { margin-bottom: 0; }
    .credito-det-header { background: #f9f9f9; padding: 10px 14px; border-bottom: 0.5px solid #eee; display: flex; align-items: center; gap: 10px; }
    .credito-det-folio { font-size: 13px; font-weight: 700; color: #333; }
    .credito-det-fecha { font-size: 11px; color: #aaa; flex: 1; }
    .credito-det-saldo { font-size: 14px; font-weight: 700; color: #c0392b; }
    .credito-det-badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; }
    .badge-activo { background: #e8f5e9; color: #2e7d32; }
    .badge-vencido2 { background: #fdecea; color: #c0392b; }
    .credito-det-prods { padding: 10px 14px; }
    .prod-det-row { display: flex; align-items: center; justify-content: space-between; padding: 4px 0; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; color: #444; }
    .prod-det-row:last-child { border-bottom: none; }
    .prod-det-nombre { flex: 1; color: #333; }
    .prod-det-cant { color: #888; font-size: 12px; margin: 0 10px; white-space: nowrap; }
    .prod-det-precio { font-weight: 600; color: #14ace7; white-space: nowrap; }
    .credito-det-footer { padding: 8px 14px; border-top: 0.5px solid #eee; display: flex; justify-content: space-between; align-items: center; }
    .btn-abonar-modal { background: #2e7d32; color: white; border: none; padding: 6px 14px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; }
    .btn-abonar-modal:hover { background: #1b5e20; }
    .modal-sin-creditos { text-align: center; color: #aaa; padding: 32px; font-size: 13px; }
    .det-cargando { text-align: center; color: #aaa; padding: 40px; font-size: 13px; }

    /* Modal abonar */
    .modal-ab-overlay { z-index: 600; }
    .modal-ab { background: white; border-radius: 10px; width: 580px; max-width: 95vw; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; }
    .ab-info { background: #f0f9e8; border: 1px solid #c8e6c9; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
    .ab-info-cliente { font-size: 14px; font-weight: 700; color: #222; }
    .ab-info-folio { font-size: 11px; color: #666; margin-top: 2px; }
    .ab-saldo-lbl { font-size: 11px; color: #666; text-align: right; }
    .ab-saldo-val { font-size: 18px; font-weight: 700; color: #c0392b; white-space: nowrap; }
    .ab-msg-ok { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; display: none; }
    .ab-msg-err { background: #fdecea; color: #c0392b; border-left: 3px solid #c0392b; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; display: none; }
    .ab-fg { margin-bottom: 12px; }
    .ab-fg label { display: block; font-size: 12px; color: #555; margin-bottom: 5px; font-weight: 600; }
    .ab-fg input, .ab-fg select { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif; }
    .ab-fg input:focus, .ab-fg select:focus { outline: none; border-color: #2e7d32; }
    .btn-ab-submit { width: 100%; background: #2e7d32; color: white; border: none; padding: 11px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-ab-submit:hover { background: #1b5e20; }
    .btn-ab-submit:disabled { background: #aaa; cursor: not-allowed; }
    .ab-divider { border: none; border-top: 1px solid #eee; margin: 16px 0 12px; }
    .ab-hist-titulo { font-size: 12px; font-weight: 700; color: #777; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
    .tabla-abonos { width: 100%; border-collapse: collapse; }
    .tabla-abonos th { padding: 7px 10px; text-align: left; font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; border-bottom: 1px solid #eee; }
    .tabla-abonos td { padding: 7px 10px; font-size: 12px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    .tabla-abonos tr:last-child td { border-bottom: none; }
    .ab-panel { display: none; border-radius: 8px; padding: 11px 14px; margin-bottom: 12px; }
    .ab-panel.visible { display: block; }
    .ab-panel-terminal { background: #e3f2fd; border: 1px solid #90caf9; }
    .ab-panel-trans { background: #f0f9ff; border: 1px solid #b3e0f7; }
    .ab-panel h4 { font-size: 12px; font-weight: 700; margin: 0 0 8px; }
    .ab-panel-terminal h4 { color: #1565c0; }
    .ab-panel-trans h4 { color: #0077a8; }
    .ab-dato { display: flex; justify-content: space-between; font-size: 12px; color: #444; margin-bottom: 4px; }
    .ab-dato span:first-child { color: #888; }
    .ab-dato span:last-child { font-weight: 600; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-efectivo { background: #e8f5e9; color: #2e7d32; }
    .badge-terminal { background: #e3f2fd; color: #1565c0; }
    .badge-transferencia { background: #fff8e1; color: #e65100; }
    .badge-mixto { background: #f3e5f5; color: #6a1b9a; }

    /* Footer sticky del modal de detalles */
    .modal-footer-pago { padding: 12px 20px; border-top: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; background: white; }
    .modal-footer-total { font-size: 13px; color: #555; }
    .modal-footer-total strong { color: #c0392b; font-size: 15px; }

    /* Método de pago buttons */
    .ab-metodos { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-bottom: 0; }
    .ab-metodo-btn { padding: 8px; border: 2px solid #e8e8e8; border-radius: 6px; text-align: center; cursor: pointer; font-size: 13px; color: #555; background: white; transition: all 0.15s; }
    .ab-metodo-btn:hover { border-color: #2e7d32; color: #2e7d32; }
    .ab-metodo-btn.selected { border-color: #2e7d32; background: #f0f9e8; color: #2e7d32; font-weight: 600; }
    .ab-campos-pago { display: none; }
    .ab-campos-pago.visible { display: block; }
    .ab-cambio-fila { display: flex; justify-content: space-between; font-size: 13px; padding: 5px 0 2px; }
    .ab-cambio-fila span:first-child { color: #888; }
    .ab-cambio-fila span:last-child { font-weight: 600; }

    /* Lista FIFO de créditos en el modal de pago */
    .ab-fifo-titulo { font-size: 11px; font-weight: 700; color: #999; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 8px; }
    .ab-cred-row { border: 0.5px solid #eee; border-radius: 6px; margin-bottom: 6px; overflow: hidden; }
    .ab-cred-head { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #fafafa; gap: 8px; }
    .ab-cred-left { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .ab-cred-folio { font-size: 12px; font-weight: 700; color: #333; }
    .ab-cred-fecha { font-size: 11px; color: #aaa; }
    .ab-cred-saldo { font-size: 13px; font-weight: 700; color: #c0392b; white-space: nowrap; flex-shrink: 0; }
    .ab-cred-prods { padding: 0; }
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
        <a class="menu-item" href="ventasPendientes.php">Ventas pendientes</a>
        <a class="menu-item" href="devoluciones.php">Devoluciones</a>
        <div class="divider"></div>
        <div class="menu-label">Caja</div>
        <a class="menu-item" href="abrirCaja.php">Abrir caja</a>
        <a class="menu-item" href="corteCaja.php">Corte de caja</a>
        <a class="menu-item" href="historialCortes.php">Historial de cortes</a>
        <div class="divider"></div>
        <div class="menu-label">Clientes</div>
        <a class="menu-item" href="clientes.php">Clientes</a>
        <a class="menu-item active" href="creditos.php">Créditos</a>
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
        <a class="menu-item" href="transferencias.php">Transferencias</a>
        <a class="menu-item" href="promociones.php">Promociones</a>
        <a class="menu-item" href="masVendidos.php">Más vendidos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Créditos de clientes</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <div class="stats">
            <div class="stat">
                <p>Clientes con deuda</p>
                <h3><?= intval($totales['clientes_con_deuda']) ?></h3>
            </div>
            <div class="stat">
                <p>Créditos activos</p>
                <h3><?= intval($totales['total_creditos']) ?></h3>
            </div>
            <div class="stat" style="border-top-color:#c0392b;">
                <p>Créditos vencidos</p>
                <h3 style="color:<?= $totales['creditos_vencidos'] > 0 ? '#c0392b' : '#222' ?>;"><?= intval($totales['creditos_vencidos']) ?></h3>
            </div>
            <div class="stat" style="border-top-color:#e67e22;">
                <p>Total pendiente</p>
                <h3 style="color:#e67e22;">$<?= number_format($totales['total_pendiente'], 2) ?></h3>
            </div>
        </div>

        <!-- Tabla de clientes -->
        <div class="tabla-top">
            <input type="text" class="buscar-clientes" placeholder="Buscar cliente..." oninput="filtrarTabla(this.value)" autocomplete="off">
        </div>
        <div class="tabla-wrapper">
            <table class="tabla-creditos" id="tablaFiltrable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Cliente</th>
                        <th>Créditos</th>
                        <th>Total pendiente</th>
                        <th>Próximo vencimiento</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($clientesDeuda) > 0): ?>
                    <?php foreach ($clientesDeuda as $i => $cl): ?>
                    <tr data-texto="<?= htmlspecialchars(mb_strtolower($cl['nombre_completo'] . ' ' . ($cl['telefono'] ?? ''))) ?>">
                        <td style="color:#bbb;font-size:12px;"><?= $i + 1 ?></td>
                        <td>
                            <div style="font-weight:700;color:#222;"><?= htmlspecialchars($cl['nombre_completo']) ?></div>
                            <?php if ($cl['telefono']): ?>
                                <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($cl['telefono']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= intval($cl['num_creditos']) ?></td>
                        <td style="font-weight:700;color:#c0392b;">$<?= number_format($cl['total_pendiente'], 2) ?></td>
                        <td>
                            <?php if ($cl['proxima_fecha']): ?>
                                <?= date('d/m/Y', strtotime($cl['proxima_fecha'])) ?>
                            <?php else: ?>
                                <span style="color:#ccc;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($cl['tiene_vencido']): ?>
                                <span class="badge-vencido">Vencido</span>
                            <?php else: ?>
                                <span class="badge-activo-tbl">Activo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn-cobrar" onclick="abrirDetalles(<?= $cl['cliente_id'] ?>, '<?= htmlspecialchars($cl['nombre_completo'], ENT_QUOTES) ?>')">Ver / Cobrar</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="sin-resultados">No hay clientes con crédito pendiente.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<!-- Modal detalles (ver créditos) -->
<div class="modal-overlay" id="modalOverlay" onclick="if(event.target===this)cerrarModal()">
    <div class="modal">
        <div class="modal-header">
            <div>
                <h3 id="modalTitulo">Créditos</h3>
                <div class="modal-subtitulo" id="modalSubtitulo"></div>
            </div>
            <button class="modal-close" onclick="cerrarModal()">✕</button>
        </div>
        <div class="modal-body" id="modalBody">
            <div class="det-cargando">Cargando...</div>
        </div>
        <div class="modal-footer-pago" id="modalFooterPago" style="display:none;">
            <div class="modal-footer-total" id="modalFooterTotal"></div>
            <button class="btn-abonar-modal" style="padding:8px 20px;font-size:13px;" onclick="abrirAbonar()">Registrar pago →</button>
        </div>
    </div>
</div>

<!-- Modal abonar -->
<div class="modal-overlay modal-ab-overlay" id="modalAbonarOverlay" onclick="if(event.target===this)cerrarAbonar()">
    <div class="modal modal-ab">
        <div class="modal-header">
            <div>
                <h3>Registrar pago</h3>
                <div class="modal-subtitulo" id="abSubtitulo"></div>
            </div>
            <button class="modal-close" onclick="cerrarAbonar()">✕</button>
        </div>
        <div class="modal-body">
            <div class="ab-info">
                <div>
                    <div class="ab-info-cliente" id="abCliente"></div>
                    <div style="font-size:11px;color:#888;margin-top:2px;">Se liquidarán del crédito más antiguo al más reciente</div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div class="ab-saldo-lbl">Total pendiente</div>
                    <div class="ab-saldo-val" id="abSaldo"></div>
                </div>
            </div>

            <!-- Lista FIFO de créditos -->
            <div class="ab-fifo-titulo">Créditos pendientes</div>
            <div id="abCreditosList" style="margin-bottom:14px;"></div>

            <div class="ab-msg-ok" id="abMsgOk">Abono registrado correctamente. Actualizando...</div>
            <div class="ab-msg-err" id="abMsgErr"></div>

            <form id="formAbono" onsubmit="submitAbono(event)">
                <div id="abCajaCerrada" style="<?= $cajaAbiertaId ? 'display:none;' : '' ?>background:#fff3e0;border:1px solid #ffcc80;border-radius:6px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#e65100;">
                    Sin caja abierta. <a href="abrirCaja.php" style="color:#e65100;font-weight:700;">Abrir caja →</a>
                </div>

                <div class="ab-fg">
                    <label>Monto del abono *</label>
                    <input type="number" name="monto" id="abMonto" placeholder="0.00" step="0.01" min="0.01" required oninput="verificarPagoAb()">
                    <small id="abMontoError" style="color:#c0392b;font-size:11px;display:none;margin-top:3px;display:none;"></small>
                </div>

                <div class="ab-fg">
                    <label>Método de pago</label>
                    <div class="ab-metodos">
                        <button type="button" class="ab-metodo-btn" onclick="seleccionarMetodoAb('Efectivo',this)">Efectivo</button>
                        <button type="button" class="ab-metodo-btn" onclick="seleccionarMetodoAb('Terminal',this)">Terminal</button>
                        <button type="button" class="ab-metodo-btn" onclick="seleccionarMetodoAb('Transferencia',this)">Transferencia</button>
                        <button type="button" class="ab-metodo-btn" onclick="seleccionarMetodoAb('Mixto',this)">Mixto</button>
                    </div>
                    <input type="hidden" name="metodo_pago" id="abMetodoPago">
                </div>

                <!-- Efectivo -->
                <div class="ab-campos-pago" id="abCamposEfectivo">
                    <div class="ab-fg">
                        <label>Cantidad recibida</label>
                        <input type="number" id="abRecibido" placeholder="0.00" step="0.01" oninput="calcularCambioAb()">
                    </div>
                    <div class="ab-cambio-fila"><span id="abCambioLabel">Cambio</span><span id="abCambioVal" style="color:#2e7d32;">$0.00</span></div>
                </div>

                <!-- Terminal -->
                <div class="ab-panel ab-panel-terminal ab-campos-pago" id="abCamposTerminal">
                    <h4>💳 Comisión de terminal</h4>
                    <?php if ($comisionPct > 0): ?>
                        <div class="ab-dato"><span>Porcentaje</span><span style="color:#1565c0;"><?= number_format($comisionPct,2) ?>%</span></div>
                        <div class="ab-dato"><span>Comisión (cargo extra)</span><span id="abComisionMonto" style="color:#c0392b;">$0.00</span></div>
                        <div style="border-top:1px dashed #90caf9;margin:6px 0;"></div>
                        <div class="ab-dato" style="font-size:13px;"><span><strong>Total que paga el cliente</strong></span><span id="abTotalTerminal" style="color:#1565c0;font-weight:700;">$0.00</span></div>
                        <div style="font-size:11px;color:#666;margin-top:4px;">El abono al crédito es solo el monto base.</div>
                    <?php else: ?>
                        <div style="font-size:12px;color:#888;">Sin comisión de terminal configurada para esta sucursal.</div>
                    <?php endif; ?>
                </div>

                <!-- Transferencia -->
                <div class="ab-campos-pago" id="abCamposTransferencia">
                    <div class="ab-panel-trans" style="border-radius:8px;padding:11px 14px;margin-bottom:10px;">
                        <h4>📋 Datos para transferencia</h4>
                        <?php if (!empty($datosBanco['banco'])): ?>
                            <div class="ab-dato"><span>Banco</span><span><?= htmlspecialchars($datosBanco['banco']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($datosBanco['titular_cuenta'])): ?>
                            <div class="ab-dato"><span>Titular</span><span><?= htmlspecialchars($datosBanco['titular_cuenta']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($datosBanco['numero_cuenta'])): ?>
                            <div class="ab-dato"><span>N° cuenta</span><span><?= htmlspecialchars($datosBanco['numero_cuenta']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($datosBanco['clabe_interbancaria'])): ?>
                            <div class="ab-dato"><span>CLABE</span><span><?= htmlspecialchars($datosBanco['clabe_interbancaria']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($datosBanco['alias_tarjeta'])): ?>
                            <div class="ab-dato"><span>Alias / tarjeta</span><span><?= htmlspecialchars($datosBanco['alias_tarjeta']) ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="ab-fg">
                        <label>Referencia de transferencia *</label>
                        <input type="text" name="referencia" id="abCampoRef" placeholder="Número de folio o referencia..." oninput="verificarPagoAb()">
                    </div>
                </div>

                <!-- Mixto -->
                <div class="ab-campos-pago" id="abCamposMixto">
                    <div class="ab-dato" style="font-size:13px;font-weight:600;padding:6px 0 10px;border-bottom:1px solid #eee;margin-bottom:10px;">
                        <span style="color:#555;">Total del abono</span>
                        <span id="abMixtoTotalRef" style="color:#c0392b;">$0.00</span>
                    </div>
                    <div class="ab-fg">
                        <label>Monto en efectivo</label>
                        <input type="number" id="abMixtoEf" placeholder="0.00" step="0.01" oninput="calcularMixtoAb()">
                    </div>
                    <div class="ab-fg">
                        <label>Cargo a terminal <span style="font-size:11px;color:#888;font-weight:400;">(resto + comisión)</span></label>
                        <input type="number" id="abMixtoTerm" placeholder="0.00" readonly style="background:#f5f5f5;color:#555;cursor:not-allowed;">
                    </div>
                    <?php if ($comisionPct > 0): ?>
                        <div class="ab-dato" style="font-size:12px;padding:2px 0 6px;"><span>Comisión terminal</span><span id="abMixtoComision" style="color:#c0392b;">$0.00</span></div>
                    <?php endif; ?>
                </div>

                <div class="ab-fg">
                    <label>Notas (opcional)</label>
                    <input type="text" name="notas" placeholder="Observaciones del abono...">
                </div>

                <div class="ab-fg" id="abFgAdelantado" style="display:none;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;font-size:13px;">
                        <input type="checkbox" name="pago_adelantado" value="1" id="abAdelantado" style="width:auto;padding:0;border:none;">
                        Pago por adelantado (extiende fecha límite 1 día)
                    </label>
                </div>

                <input type="hidden" name="monto_efectivo" id="abHiddenEf" value="0">
                <input type="hidden" name="monto_terminal" id="abHiddenTerm" value="0">

                <button class="btn-ab-submit" type="submit" id="btnSubmitAbono" disabled>Registrar pago</button>
            </form>

            <hr class="ab-divider">
            <div class="ab-hist-titulo">Historial de abonos</div>
            <div id="historialAbonosCont"><div style="text-align:center;color:#aaa;font-size:13px;padding:12px;">Cargando...</div></div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
function normalizar(s) { return String(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,''); }

/* ── Filtro tabla clientes ── */
function filtrarTabla(q) {
    q = normalizar(q);
    document.querySelectorAll('#tablaFiltrable tbody tr').forEach(tr => {
        tr.style.display = normalizar(tr.dataset.texto || '').includes(q) ? '' : 'none';
    });
}

/* ── Modal detalles (ver créditos) ── */
let _clienteIdActual  = null;
let _creditosActuales = [];

function abrirDetalles(clienteId, nombre) {
    _clienteIdActual = clienteId;
    document.getElementById('modalTitulo').textContent    = nombre;
    document.getElementById('modalSubtitulo').textContent = 'Créditos y productos pendientes de pago';
    document.getElementById('modalBody').innerHTML        = '<div class="det-cargando">Cargando...</div>';
    document.getElementById('modalFooterPago').style.display = 'none';
    document.getElementById('modalOverlay').classList.add('visible');

    fetch('creditos.php?get_creditos_cliente=' + clienteId)
        .then(r => r.json())
        .then(creditos => {
            _creditosActuales = creditos || [];
            if (!creditos || !creditos.length) {
                document.getElementById('modalBody').innerHTML = '<div class="modal-sin-creditos">No hay créditos activos para este cliente.</div>';
                return;
            }
            let html = '';
            let totalPend = 0;
            creditos.forEach(cr => {
                const badgeClass = cr.estado === 'Vencido' ? 'badge-vencido2' : 'badge-activo';
                const folio = cr.folio ? 'Folio ' + cr.folio : 'Venta #' + cr.credito_id;
                const saldo = parseFloat(cr.saldo_pendiente);
                totalPend += saldo;
                html += `<div class="credito-det">
                    <div class="credito-det-header">
                        <div>
                            <div class="credito-det-folio">${folio}</div>
                            <div class="credito-det-fecha">${formatFecha(cr.fecha_venta)}</div>
                        </div>
                        <span class="credito-det-badge ${badgeClass}">${cr.estado}</span>
                        <div class="credito-det-saldo">$${saldo.toFixed(2)}</div>
                    </div>`;
                if (cr.productos && cr.productos.length) {
                    html += '<div class="credito-det-prods">';
                    cr.productos.forEach(p => {
                        const cant = Number.isInteger(p.cantidad) ? p.cantidad : parseFloat(p.cantidad).toFixed(2).replace(/\.?0+$/,'');
                        html += `<div class="prod-det-row">
                            <span class="prod-det-nombre">${p.nombre}</span>
                            <span class="prod-det-cant">x ${cant}</span>
                            <span class="prod-det-precio">$${(p.cantidad * p.precio).toFixed(2)}</span>
                        </div>`;
                    });
                    html += '</div>';
                }
                html += `<div class="credito-det-footer">
                    <span style="font-size:12px;color:#888;">Monto original: $${parseFloat(cr.monto_total).toFixed(2)}</span>
                    ${saldo <= 0 ? '<span style="font-size:12px;color:#2e7d32;font-weight:600;">Liquidado</span>' : ''}
                </div></div>`;
            });
            document.getElementById('modalBody').innerHTML = html;
            document.getElementById('modalFooterTotal').innerHTML =
                'Total pendiente: <strong>$' + totalPend.toFixed(2) + '</strong>';
            document.getElementById('modalFooterPago').style.display = 'flex';
        })
        .catch(() => {
            document.getElementById('modalBody').innerHTML = '<div class="modal-sin-creditos">Error al cargar los créditos.</div>';
        });
}

function cerrarModal() {
    document.getElementById('modalOverlay').classList.remove('visible');
}

function formatFecha(str) {
    if (!str) return '';
    const d = new Date(str);
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/* ── Modal Pago (FIFO automatico) ── */
const COMISION_PCT  = <?= $comisionPct ?>;
const CAJA_ABIERTA  = <?= $cajaAbiertaId ? 'true' : 'false' ?>;
let   _metodoPagoAb = null;

function abrirAbonar() {
    const totalPend = _creditosActuales.reduce((s, c) => s + parseFloat(c.saldo_pendiente||0), 0);
    const nombre    = document.getElementById('modalTitulo').textContent;

    document.getElementById('abCliente').textContent      = nombre;
    document.getElementById('abSubtitulo').textContent    = nombre;
    document.getElementById('abSaldo').textContent        = '$' + totalPend.toFixed(2);
    document.getElementById('abMonto').max                = totalPend;
    document.getElementById('abMsgOk').style.display      = 'none';
    document.getElementById('abMsgErr').style.display     = 'none';
    document.getElementById('formAbono').reset();
    document.querySelectorAll('.ab-metodo-btn').forEach(b => b.classList.remove('selected'));
    document.querySelectorAll('.ab-campos-pago').forEach(c => c.classList.remove('visible'));
    document.getElementById('abMetodoPago').value         = '';
    document.getElementById('abHiddenEf').value           = '0';
    document.getElementById('abHiddenTerm').value         = '0';
    document.getElementById('abCajaCerrada').style.display = CAJA_ABIERTA ? 'none' : 'block';
    document.getElementById('btnSubmitAbono').disabled    = true;
    document.getElementById('btnSubmitAbono').textContent = 'Registrar pago';
    _metodoPagoAb = null;

    // Lista FIFO de creditos con productos
    let listHtml = '';
    _creditosActuales.forEach(cr => {
        const folio      = cr.folio ? 'Folio ' + cr.folio : 'Credito #' + cr.credito_id;
        const badgeClass = cr.estado === 'Vencido' ? 'badge-vencido2' : 'badge-activo';
        listHtml += `<div class="ab-cred-row">
            <div class="ab-cred-head">
                <div class="ab-cred-left">
                    <span class="ab-cred-folio">${folio}</span>
                    <span class="credito-det-badge ${badgeClass}">${cr.estado}</span>
                    <span class="ab-cred-fecha">${formatFecha(cr.fecha_venta)}</span>
                </div>
                <span class="ab-cred-saldo">$${parseFloat(cr.saldo_pendiente).toFixed(2)}</span>
            </div>`;
        if (cr.productos && cr.productos.length) {
            listHtml += '<div class="credito-det-prods">';
            cr.productos.forEach(p => {
                const cant = Number.isInteger(p.cantidad) ? p.cantidad : parseFloat(p.cantidad).toFixed(2).replace(/\.?0+$/,'');
                listHtml += `<div class="prod-det-row">
                    <span class="prod-det-nombre">${p.nombre}</span>
                    <span class="prod-det-cant">× ${cant}</span>
                    <span class="prod-det-precio">$${(p.cantidad * p.precio).toFixed(2)}</span>
                </div>`;
            });
            listHtml += '</div>';
        }
        listHtml += '</div>';
    });
    document.getElementById('abCreditosList').innerHTML = listHtml;

    const tieneActivo = _creditosActuales.some(c => c.estado !== 'Vencido');
    const fgAd = document.getElementById('abFgAdelantado');
    fgAd.style.display = tieneActivo ? 'block' : 'none';
    if (!tieneActivo) document.getElementById('abAdelantado').checked = false;

    document.getElementById('modalAbonarOverlay').classList.add('visible');
    cargarHistorialPagos(_clienteIdActual);
    setTimeout(() => document.getElementById('abMonto').focus(), 100);
}

function cerrarAbonar() {
    document.getElementById('modalAbonarOverlay').classList.remove('visible');
}

function cargarHistorialPagos(clienteId) {
    const cont = document.getElementById('historialAbonosCont');
    cont.innerHTML = '<div style="text-align:center;color:#aaa;font-size:13px;padding:12px;">Cargando...</div>';
    fetch('creditos.php?get_abonos_cliente=' + clienteId)
        .then(r => r.json())
        .then(abonos => {
            if (!abonos.length) {
                cont.innerHTML = '<div style="text-align:center;color:#aaa;font-size:12px;padding:12px;">Sin pagos registrados.</div>';
                return;
            }
            const badges = { Efectivo:'badge-efectivo', Terminal:'badge-terminal', Transferencia:'badge-transferencia', Mixto:'badge-mixto' };
            cont.innerHTML = '<table class="tabla-abonos"><thead><tr><th>Monto</th><th>Metodo</th><th>Folio</th><th>Cajero</th><th>Fecha</th></tr></thead><tbody>' +
                abonos.map(a => {
                    const com = parseFloat(a.comision_terminal||0);
                    return `<tr>
                        <td><span style="font-weight:700;color:#2e7d32;">$${parseFloat(a.monto).toFixed(2)}</span>${com>0?'<br><span style="font-size:11px;color:#1565c0;">+$'+com.toFixed(2)+' com.</span>':''}</td>
                        <td><span class="badge ${badges[a.metodo_pago]||''}">${a.metodo_pago}</span></td>
                        <td style="font-size:11px;color:#888;">${a.folio||'&mdash;'}</td>
                        <td style="font-size:11px;">${a.cajero}</td>
                        <td style="font-size:11px;color:#aaa;">${formatFecha(a.created_at)}</td>
                    </tr>`;
                }).join('') + '</tbody></table>';
        })
        .catch(() => { cont.innerHTML = '<div style="text-align:center;color:#c0392b;font-size:12px;padding:12px;">Error al cargar.</div>'; });
}

function seleccionarMetodoAb(metodo, btn) {
    _metodoPagoAb = metodo;
    document.getElementById('abMetodoPago').value = metodo;
    document.querySelectorAll('.ab-metodo-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    document.querySelectorAll('.ab-campos-pago').forEach(c => c.classList.remove('visible'));
    document.getElementById('abHiddenEf').value   = '0';
    document.getElementById('abHiddenTerm').value = '0';
    if (metodo === 'Efectivo')      document.getElementById('abCamposEfectivo').classList.add('visible');
    if (metodo === 'Terminal')      document.getElementById('abCamposTerminal').classList.add('visible');
    if (metodo === 'Transferencia') document.getElementById('abCamposTransferencia').classList.add('visible');
    if (metodo === 'Mixto') {
        document.getElementById('abCamposMixto').classList.add('visible');
        document.getElementById('abMixtoEf').value   = '';
        document.getElementById('abMixtoTerm').value = '0.00';
    }
    actualizarComisionAb();
    verificarPagoAb();
}

function calcularCambioAb() {
    const monto    = parseFloat(document.getElementById('abMonto').value) || 0;
    const recibido = parseFloat(document.getElementById('abRecibido').value) || 0;
    const falta    = monto - recibido;
    const labelEl  = document.getElementById('abCambioLabel');
    const valEl    = document.getElementById('abCambioVal');
    if (recibido <= 0) {
        labelEl.textContent = 'Cambio'; valEl.textContent = '$0.00'; valEl.style.color = '#2e7d32';
    } else if (falta > 0.001) {
        labelEl.textContent = 'Falta'; valEl.textContent = '$' + falta.toFixed(2); valEl.style.color = '#c0392b';
    } else {
        labelEl.textContent = 'Cambio'; valEl.textContent = '$' + Math.abs(falta).toFixed(2); valEl.style.color = '#2e7d32';
    }
    document.getElementById('abHiddenEf').value = recibido.toFixed(2);
    verificarPagoAb();
}

function calcularMixtoAb() {
    const monto = parseFloat(document.getElementById('abMonto').value) || 0;
    const ef    = parseFloat(document.getElementById('abMixtoEf').value) || 0;
    let termDisplay = 0, com = 0;
    if (ef > 0 && ef < monto) {
        const resto = monto - ef;
        com         = resto * (COMISION_PCT / 100);
        termDisplay = resto + com;
    }
    document.getElementById('abMixtoTerm').value = termDisplay.toFixed(2);
    const totalRefEl = document.getElementById('abMixtoTotalRef');
    if (totalRefEl) totalRefEl.textContent = '$' + monto.toFixed(2);
    const comEl = document.getElementById('abMixtoComision');
    if (comEl) comEl.textContent = '$' + com.toFixed(2);
    document.getElementById('abHiddenEf').value   = ef.toFixed(2);
    document.getElementById('abHiddenTerm').value = termDisplay.toFixed(2);
    // Update button directly — do NOT call verificarPagoAb (evita recursión infinita)
    const maxMonto = parseFloat(document.getElementById('abMonto').max) || 0;
    document.getElementById('btnSubmitAbono').disabled =
        !CAJA_ABIERTA || monto <= 0 || ef <= 0 || ef >= monto || (maxMonto > 0 && monto > maxMonto + 0.001);
}

function actualizarComisionAb() {
    if (COMISION_PCT <= 0 || _metodoPagoAb !== 'Terminal') return;
    const monto = parseFloat(document.getElementById('abMonto').value) || 0;
    const com   = Math.round(monto * COMISION_PCT / 100 * 100) / 100;
    const comEl = document.getElementById('abComisionMonto');
    const totEl = document.getElementById('abTotalTerminal');
    if (comEl) comEl.textContent = '$' + com.toFixed(2);
    if (totEl) totEl.textContent = '$' + (monto + com).toFixed(2);
}

function verificarPagoAb() {
    actualizarComisionAb();
    const btn      = document.getElementById('btnSubmitAbono');
    const errEl    = document.getElementById('abMontoError');
    const monto    = parseFloat(document.getElementById('abMonto').value) || 0;
    const maxMonto = parseFloat(document.getElementById('abMonto').max) || 0;

    // Validar que no exceda el total pendiente
    if (maxMonto > 0 && monto > maxMonto + 0.001) {
        if (errEl) { errEl.textContent = 'Excede el total pendiente ($' + maxMonto.toFixed(2) + ')'; errEl.style.display = 'block'; }
        btn.disabled = true;
        return;
    }
    if (errEl) errEl.style.display = 'none';

    if (!CAJA_ABIERTA || !_metodoPagoAb || monto <= 0) { btn.disabled = true; return; }

    if (_metodoPagoAb === 'Efectivo') {
        const recibido = parseFloat(document.getElementById('abRecibido').value) || 0;
        btn.disabled = recibido < monto - 0.001;
        return;
    }
    if (_metodoPagoAb === 'Transferencia') {
        btn.disabled = String(document.getElementById('abCampoRef').value || '').trim() === '';
        return;
    }
    if (_metodoPagoAb === 'Mixto') {
        calcularMixtoAb(); // seguro — calcularMixtoAb ya no llama verificarPagoAb
        return;
    }
    btn.disabled = false; // Terminal
}

function submitAbono(e) {
    e.preventDefault();
    const btn  = document.getElementById('btnSubmitAbono');
    const data = new FormData(document.getElementById('formAbono'));
    data.append('action', 'registrar_abono');
    data.append('cliente_id', _clienteIdActual);
    btn.disabled = true;
    btn.textContent = 'Registrando...';
    document.getElementById('abMsgErr').style.display = 'none';

    fetch('creditos.php', { method: 'POST', body: data })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                document.getElementById('abMsgOk').style.display = 'block';
                setTimeout(() => location.reload(), 1400);
            } else {
                btn.disabled = false;
                btn.textContent = 'Registrar pago';
                document.getElementById('abMsgErr').textContent = res.error || 'Error al registrar.';
                document.getElementById('abMsgErr').style.display = 'block';
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = 'Registrar pago';
            document.getElementById('abMsgErr').textContent = 'Error de conexion.';
            document.getElementById('abMsgErr').style.display = 'block';
        });
}

/* Cerrar modales con Escape */
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (document.getElementById('modalAbonarOverlay').classList.contains('visible')) { cerrarAbonar(); return; }
    cerrarModal();
});
</script>
</body>
</html>
