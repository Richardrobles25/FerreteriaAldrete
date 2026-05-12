<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

$errores = [];
$exito   = false;

function formatearMotivoDevolucion(string $motivo): string
{
    // "Devolucion venta #43 folio:0022: No le sirvieron" → "Folio 0022 · No le sirvieron"
    if (preg_match('/Devolucion venta #\d+ folio:(\S+?):\s*(.+)/i', $motivo, $m)) {
        return 'Folio ' . $m[1] . ' · ' . $m[2];
    }
    // "Devolucion venta #41: No le sirvieron" → "Folio #41 · No le sirvieron"
    if (preg_match('/Devolucion venta #(\d+):\s*(.+)/i', $motivo, $m)) {
        return 'Folio #' . $m[1] . ' · ' . $m[2];
    }
    // "Devolución: motivo" → "motivo"
    return preg_replace('/^Devolución:\s*/i', '', $motivo);
}

function obtenerTotalesDevueltos(PDO $pdo, int $ventaId, int $sucursalId): array
{
    $totales = [];

    // [AUTOFIX] Bug A: antes usaba INNER JOIN stock_sucursal → si faltaba la fila,
    // el conteo era 0 y el producto podía devolverse de nuevo indefinidamente.
    // Ahora consultamos directamente vía devoluciones.venta_id (FK exacta).
    $stmtNew = $pdo->prepare("
        SELECT mi.producto_id, SUM(mi.cantidad) AS cantidad_devuelta
        FROM movimientos_inventario mi
        JOIN devoluciones d ON d.devolucion_id = mi.devolucion_id AND d.venta_id = ?
        WHERE mi.tipo = 'Entrada'
          AND d.cancelada_en IS NULL
        GROUP BY mi.producto_id
    ");
    $stmtNew->execute([$ventaId]);
    foreach ($stmtNew->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $totales[intval($fila['producto_id'])] = floatval($fila['cantidad_devuelta']);
    }

    // Compatibilidad con movimientos anteriores a la tabla devoluciones (sin devolucion_id)
    $stmtOld = $pdo->prepare("
        SELECT mi.producto_id, SUM(mi.cantidad) AS cantidad_devuelta
        FROM movimientos_inventario mi
        WHERE mi.tipo = 'Entrada'
          AND mi.devolucion_id IS NULL
          AND (mi.motivo LIKE ? OR mi.motivo LIKE ?)
        GROUP BY mi.producto_id
    ");
    $stmtOld->execute([
        'Devolucion venta #' . $ventaId . ':%',
        'Devolucion venta #' . $ventaId . ' %',
    ]);
    foreach ($stmtOld->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $pid = intval($fila['producto_id']);
        $totales[$pid] = ($totales[$pid] ?? 0) + floatval($fila['cantidad_devuelta']);
    }

    return $totales;
}

// Buscar venta via AJAX (por folio NNNN + mes + año)
if (isset($_GET['buscar_venta'])) {
    header('Content-Type: application/json');
    try {
        $folio_num = intval($_GET['buscar_venta']);
        $mes       = intval($_GET['mes']  ?? date('m'));
        $anio      = intval($_GET['anio'] ?? date('Y'));

        // Compatible con folio nuevo "NNNN" y folio viejo "NNNN-MM-YYYY"
        // [AUTOFIX] D-01: Filtrar por sucursal — un cajero solo puede devolver ventas de su propia sucursal
        $stmt = $pdo->prepare("
            SELECT v.*, c.nombre_completo as cliente
            FROM ventas v
            JOIN cajas ca ON v.caja_id = ca.caja_id AND ca.sucursal_id = ?
            LEFT JOIN clientes c ON v.cliente_id = c.cliente_id
            WHERE CAST(v.folio AS UNSIGNED) = ?
              AND MONTH(v.created_at) = ?
              AND YEAR(v.created_at)  = ?
              AND v.estado IN ('Completada', 'Modificado')
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['sucursal_id'], $folio_num, $mes, $anio]);
        $venta = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($venta) {
            $stmtP = $pdo->prepare("
                SELECT vp.*, p.nombre_producto, p.codigo,
                       pk.nombre AS paquete_nombre,
                       pp.cantidad AS cantidad_requerida_combo
                FROM venta_productos vp
                JOIN productos p ON vp.producto_id = p.producto_id
                LEFT JOIN paquetes pk ON vp.paquete_id = pk.paquete_id
                LEFT JOIN paquete_productos pp
                    ON pp.paquete_id = vp.paquete_id AND pp.producto_id = vp.producto_id
                WHERE vp.venta_id = ?");
            $stmtP->execute([$venta['venta_id']]);
            $venta['productos'] = $stmtP->fetchAll(PDO::FETCH_ASSOC);
            $devueltos = obtenerTotalesDevueltos($pdo, intval($venta['venta_id']), intval($_SESSION['sucursal_id']));
            foreach ($venta['productos'] as &$productoVenta) {
                $productoId = intval($productoVenta['producto_id']);
                $cantidadVendida = floatval($productoVenta['cantidad']);
                $cantidadDevuelta = $devueltos[$productoId] ?? 0;
                $productoVenta['cantidad_devuelta'] = $cantidadDevuelta;
                $productoVenta['cantidad_restante'] = max(0, $cantidadVendida - $cantidadDevuelta);
            }
            unset($productoVenta);
        }
        echo json_encode($venta ?: null);
    } catch (\Throwable $e) {
        // [AUTOFIX] SEC-04: No exponer errores tecnicos al cliente
        error_log('[Ferreteria/devoluciones] Error buscar_venta: ' . $e->getMessage());
        echo json_encode(['error' => 'Error al buscar la venta. Intenta de nuevo.']);
    }
    exit();
}

// Cancelar devolución (máx 24h)
if (isset($_GET['cancelar_dev'])) {
    // [AUTOFIX] SEC-01: Verificar CSRF token antes de accion destructiva por GET
    requerirCSRF($_GET['_token'] ?? '', 'devoluciones.php');
    $devolucion_id = intval($_GET['cancelar_dev']);
    $nota_cancel   = trim($_GET['nota'] ?? '');

    $stmtD = $pdo->prepare("
        SELECT d.*, v.total AS total_actual, v.estado AS estado_actual, v.comision_terminal
        FROM devoluciones d
        JOIN ventas v ON d.venta_id = v.venta_id
        WHERE d.devolucion_id = ?
    ");
    $stmtD->execute([$devolucion_id]);
    $dev = $stmtD->fetch(PDO::FETCH_ASSOC);

    if (!$dev)                                                  { header('Location: devoluciones.php?msg=error_cancelar');  exit(); }
    if (!empty($dev['cancelada_en']))                           { header('Location: devoluciones.php?msg=ya_cancelada');    exit(); }
    if ((time() - strtotime($dev['procesada_en'])) > 86400)    { header('Location: devoluciones.php?msg=fuera_plazo');     exit(); }

    $stmtM = $pdo->prepare("SELECT * FROM movimientos_inventario WHERE devolucion_id = ? AND tipo = 'Entrada'");
    $stmtM->execute([$devolucion_id]);
    $movimientos = $stmtM->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try {
        foreach ($movimientos as $m) {
            $stmtS = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
            $stmtS->execute([$m['producto_id'], $_SESSION['sucursal_id']]);
            $stockAnt  = floatval($stmtS->fetchColumn());
            $stockNuevo = max(0, $stockAnt - floatval($m['cantidad']));
            $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")->execute([$stockNuevo, $m['producto_id'], $_SESSION['sucursal_id']]);
            $pdo->prepare("INSERT INTO movimientos_inventario (producto_id,usuario_id,sucursal_id,tipo,cantidad,stock_anterior,stock_nuevo,motivo)
                           VALUES (?,?,?,'Salida',?,?,?,?)")
                ->execute([$m['producto_id'], $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $m['cantidad'], $stockAnt, $stockNuevo,
                           'Cancelacion devolucion #' . $devolucion_id . ($nota_cancel ? ': ' . $nota_cancel : '')]);
        }

        // [AUTOFIX] Bug C: restaurar correctamente subtotal (bruto), descuento, comisión y total.
        // Los registros nuevos almacenan subtotal_bruto_devuelto y comision_devuelta.
        // Para registros anteriores (columnas en 0) se usa total_devuelto como fallback.
        $totalDevuelto = floatval($dev['total_devuelto']);
        $subtotalBruto = floatval($dev['subtotal_bruto_devuelto'] ?? 0);
        if ($subtotalBruto < 0.001) $subtotalBruto = $totalDevuelto; // fallback registros viejos
        $comisionDev   = floatval($dev['comision_devuelta'] ?? 0);
        $descuentoDev  = max(0.0, round($subtotalBruto - $totalDevuelto, 2));

        $pdo->prepare("
            UPDATE ventas
            SET subtotal          = subtotal + ?,
                descuento         = descuento + ?,
                comision_terminal = comision_terminal + ?,
                total             = total + ?
            WHERE venta_id = ?
        ")->execute([$subtotalBruto, $descuentoDev, $comisionDev, $totalDevuelto + $comisionDev, $dev['venta_id']]);

        // Estado: si quedan otras devoluciones activas → Modificado, sino → Completada
        $stmtOtras = $pdo->prepare("SELECT COUNT(*) FROM devoluciones WHERE venta_id = ? AND devolucion_id != ? AND cancelada_en IS NULL");
        $stmtOtras->execute([$dev['venta_id'], $devolucion_id]);
        $nuevoEstado = intval($stmtOtras->fetchColumn()) > 0 ? 'Modificado' : 'Completada';
        $pdo->prepare("UPDATE ventas SET estado = ? WHERE venta_id = ?")->execute([$nuevoEstado, $dev['venta_id']]);

        // Si era crédito, restaurar saldo
        $stmtCred = $pdo->prepare("SELECT credito_id, saldo_pendiente FROM creditos WHERE venta_id = ? AND estado IN ('Activo','Liquidado')");
        $stmtCred->execute([$dev['venta_id']]);
        $cred = $stmtCred->fetch(PDO::FETCH_ASSOC);
        if ($cred) {
            $pdo->prepare("UPDATE creditos SET saldo_pendiente = saldo_pendiente + ?, estado = 'Activo' WHERE credito_id = ?")
                ->execute([$totalDevuelto, $cred['credito_id']]);
        }

        // Marcar devolución como cancelada
        $pdo->prepare("UPDATE devoluciones SET cancelada_en = NOW(), cancelada_por = ?, nota_cancelacion = ? WHERE devolucion_id = ?")
            ->execute([$_SESSION['usuario_id'], $nota_cancel ?: null, $devolucion_id]);

        $pdo->commit();
        header('Location: devoluciones.php?msg=cancelada');
        exit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        header('Location: devoluciones.php?msg=error_cancelar');
        exit();
    }
}

// Procesar devolución
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $venta_id      = intval($_POST['venta_id'] ?? 0);
    $productos_dev = json_decode($_POST['productos_devolver'] ?? '[]', true);
    $motivo        = trim($_POST['motivo'] ?? '');

    if (!$venta_id)              $errores[] = 'Selecciona una venta.';
    if (empty($productos_dev))   $errores[] = 'Selecciona al menos un producto a devolver.';
    if (!$motivo)                $errores[] = 'El motivo es obligatorio.';

    if (empty($errores)) {
        // [AUTOFIX] D-02: Verificar que la venta pertenezca a la sucursal del cajero antes de procesar
        $stmtV = $pdo->prepare("
            SELECT v.metodo_pago, v.cliente_id, v.folio
            FROM ventas v
            JOIN cajas ca ON v.caja_id = ca.caja_id AND ca.sucursal_id = ?
            WHERE v.venta_id = ?
        ");
        $stmtV->execute([$_SESSION['sucursal_id'], $venta_id]);
        $ventaInfo = $stmtV->fetch(PDO::FETCH_ASSOC);
        if (!$ventaInfo) {
            $errores[] = 'La venta no pertenece a esta sucursal o no existe.';
        }

        $stmtVendidos = $pdo->prepare("
            SELECT producto_id, SUM(cantidad) AS cantidad_vendida
            FROM venta_productos
            WHERE venta_id = ?
            GROUP BY producto_id
        ");
        $stmtVendidos->execute([$venta_id]);
        $cantidadesVendidas = [];
        foreach ($stmtVendidos->fetchAll(PDO::FETCH_ASSOC) as $filaVendida) {
            $cantidadesVendidas[intval($filaVendida['producto_id'])] = floatval($filaVendida['cantidad_vendida']);
        }

        $cantidadesDevueltas = obtenerTotalesDevueltos($pdo, $venta_id, intval($_SESSION['sucursal_id']));

        // [AUTOFIX] V-06: Cargar precios reales de la BD para validar y usar en devoluciones
        $stmtPreciosDB = $pdo->prepare("
            SELECT producto_id, precio_final
            FROM venta_productos
            WHERE venta_id = ?
        ");
        $stmtPreciosDB->execute([$venta_id]);
        $preciosRealDB = [];
        foreach ($stmtPreciosDB->fetchAll(PDO::FETCH_ASSOC) as $fp) {
            $preciosRealDB[intval($fp['producto_id'])] = floatval($fp['precio_final']);
        }

        $productosAgrupados = [];
        if (empty($errores)) foreach ($productos_dev as $prod) {
            $productoId = intval($prod['producto_id'] ?? 0);
            $cantidad = floatval($prod['cantidad'] ?? 0);
            // [AUTOFIX] V-06: Usar precio de la BD, no el que manda el cliente
            $precioUnitario = $preciosRealDB[$productoId] ?? floatval($prod['precio_unitario'] ?? 0);

            if ($productoId <= 0 || $cantidad <= 0) {
                continue;
            }

            if (!isset($productosAgrupados[$productoId])) {
                $productosAgrupados[$productoId] = [
                    'producto_id' => $productoId,
                    'cantidad' => 0,
                    'precio_unitario' => $precioUnitario,
                ];
            }

            $productosAgrupados[$productoId]['cantidad'] += $cantidad;
            $productosAgrupados[$productoId]['precio_unitario'] = $precioUnitario;
        }

        foreach ($productosAgrupados as $productoId => $detalleDevuelto) {
            $vendido = $cantidadesVendidas[$productoId] ?? 0;
            $devuelto = $cantidadesDevueltas[$productoId] ?? 0;
            if ($vendido <= 0) {
                $errores[] = 'Uno de los productos seleccionados no pertenece a la venta.';
                continue;
            }
            if ($detalleDevuelto['cantidad'] > (($vendido - $devuelto) + 0.0001)) {
                $errores[] = 'No puedes regresar mas producto del que realmente queda pendiente por devolver.';
            }
        }

        // Validar que los paquetes se devuelvan completos
        if (empty($errores)) {
            $stmtPaq = $pdo->prepare("
                SELECT paquete_id, producto_id
                FROM venta_productos
                WHERE venta_id = ? AND paquete_id IS NOT NULL
            ");
            $stmtPaq->execute([$venta_id]);
            $paqProds = [];
            foreach ($stmtPaq->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $paqProds[intval($fila['paquete_id'])][] = intval($fila['producto_id']);
            }
            $idsDevueltos = array_keys($productosAgrupados);
            foreach ($paqProds as $paqId => $prodsDePaquete) {
                $algunoEnDevolucion = count(array_intersect($idsDevueltos, $prodsDePaquete)) > 0;
                if ($algunoEnDevolucion) {
                    $faltantes = array_diff($prodsDePaquete, $idsDevueltos);
                    if (!empty($faltantes)) {
                        $errores[] = 'Si devuelves un paquete debes devolver todos sus productos juntos.';
                        break;
                    }
                }
            }
        }

        $productos_dev = array_values($productosAgrupados);
        $folioExtra = !empty($ventaInfo['folio']) ? ' folio:' . $ventaInfo['folio'] : '';
        $motivo = 'Devolucion venta #' . $venta_id . $folioExtra . ': ' . $motivo;

        if (empty($errores)) {
            // totalDevuelto = lo que el cajero le regresa al cliente (precio_final × qty devuelta)
            $totalDevuelto = round(array_sum(array_map(fn($p) => $p['cantidad'] * $p['precio_unitario'], $productos_dev)), 2);

            // [AUTOFIX] Bug B: calcular subtotal bruto, descuento proporcional y comisión proporcional.
            // ventas.subtotal es base bruta (precio_unitario); totalDevuelto es base final (precio_final).
            // Restar el mismo valor a ambos campos producía subtotal incorrecto y residuo de comisión.

            // 1) Obtener precio_unitario real de venta_productos para los productos devueltos
            $prodIdsRetorno = array_unique(array_map('intval', array_column($productos_dev, 'producto_id')));
            $precioUnitarioMap = [];
            if (!empty($prodIdsRetorno)) {
                $inPH = implode(',', array_fill(0, count($prodIdsRetorno), '?'));
                $stmtVPBruto = $pdo->prepare("
                    SELECT producto_id, precio_unitario
                    FROM venta_productos
                    WHERE venta_id = ? AND producto_id IN ($inPH)
                ");
                $stmtVPBruto->execute(array_merge([$venta_id], $prodIdsRetorno));
                foreach ($stmtVPBruto->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $precioUnitarioMap[intval($row['producto_id'])] = floatval($row['precio_unitario']);
                }
            }

            $subtotalBrutoDevuelto = 0.0;
            foreach ($productos_dev as $prod) {
                $pid = intval($prod['producto_id']);
                $precioUnit = $precioUnitarioMap[$pid] ?? floatval($prod['precio_unitario']);
                $subtotalBrutoDevuelto += floatval($prod['cantidad']) * $precioUnit;
            }
            $subtotalBrutoDevuelto = round($subtotalBrutoDevuelto, 2);
            $descuentoDevuelto     = round(max(0.0, $subtotalBrutoDevuelto - $totalDevuelto), 2);

            // 2) Comisión proporcional (Terminal / Mixto).
            // [FIX] Bug sucesivas devoluciones: antes usábamos comision_restante / precio_final_TOTAL_ORIGINAL.
            // Al haber una devolución previa, comision_restante bajó pero el denominador seguía siendo el
            // original completo → tasa de comisión distorsionada.
            // Solución: usar como denominador el precio_final de los productos AÚN NO devueltos
            // (= suma_restante). Así la tasa comision_restante/suma_restante se mantiene constante
            // sin importar cuántas devoluciones parciales haya habido.
            $stmtVComision = $pdo->prepare("
                SELECT v.comision_terminal,
                       COALESCE(SUM(vp.precio_final * GREATEST(0, vp.cantidad - COALESCE(dev.devuelta, 0))), 0) AS suma_restante
                FROM ventas v
                LEFT JOIN venta_productos vp ON vp.venta_id = v.venta_id
                LEFT JOIN (
                    SELECT mi.producto_id, SUM(mi.cantidad) AS devuelta
                    FROM movimientos_inventario mi
                    JOIN devoluciones d ON d.devolucion_id = mi.devolucion_id AND d.venta_id = ?
                    WHERE mi.tipo = 'Entrada' AND d.cancelada_en IS NULL
                    GROUP BY mi.producto_id
                ) dev ON dev.producto_id = vp.producto_id
                WHERE v.venta_id = ?
                GROUP BY v.venta_id
            ");
            $stmtVComision->execute([$venta_id, $venta_id]);
            $comData          = $stmtVComision->fetch(PDO::FETCH_ASSOC);
            $comisionTotal    = $comData ? floatval($comData['comision_terminal']) : 0.0;
            $sumaRestante     = $comData ? floatval($comData['suma_restante'])     : 0.0;
            // Tasa = comision_restante / precio_final_restante (constante entre devoluciones parciales)
            $comisionDevuelta = ($sumaRestante > 0.001 && $comisionTotal > 0.001)
                ? round($comisionTotal * min(1.0, $totalDevuelto / $sumaRestante), 2)
                : 0.0;

            $pdo->beginTransaction();
            try {
                // Registrar la devolución como grupo (permite cancelación posterior)
                $pdo->prepare("INSERT INTO devoluciones (venta_id, usuario_id, total_devuelto, subtotal_bruto_devuelto, comision_devuelta) VALUES (?,?,?,?,?)")
                    ->execute([$venta_id, $_SESSION['usuario_id'], $totalDevuelto, $subtotalBrutoDevuelto, $comisionDevuelta]);
                $devolucion_id = intval($pdo->lastInsertId());

                foreach ($productos_dev as $prod) {
                    $producto_id = intval($prod['producto_id']);
                    $cantidad    = floatval($prod['cantidad']);
                    if ($cantidad <= 0) continue;

                    $stmtS = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
                    $stmtS->execute([$producto_id, $_SESSION['sucursal_id']]);
                    $stockAnterior = floatval($stmtS->fetchColumn());
                    $stockNuevo    = $stockAnterior + $cantidad;

                    $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")->execute([$stockNuevo, $producto_id, $_SESSION['sucursal_id']]);
                    $pdo->prepare("INSERT INTO movimientos_inventario (producto_id,usuario_id,sucursal_id,tipo,cantidad,stock_anterior,stock_nuevo,motivo,devolucion_id) VALUES (?,?,?,'Entrada',?,?,?,?,?)")
                        ->execute([$producto_id, $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $cantidad, $stockAnterior, $stockNuevo, $motivo, $devolucion_id]);
                }

                // [AUTOFIX] Bug B: actualizar con bases correctas por campo
                // subtotal -= precio bruto devuelto | descuento -= descuento proporcional
                // comision_terminal -= comisión proporcional | total -= precio_final + comisión
                $pdo->prepare("
                    UPDATE ventas
                    SET subtotal          = GREATEST(0, subtotal - ?),
                        descuento         = GREATEST(0, descuento - ?),
                        comision_terminal = GREATEST(0, comision_terminal - ?),
                        total             = GREATEST(0, total - ?)
                    WHERE venta_id = ?
                ")->execute([$subtotalBrutoDevuelto, $descuentoDevuelto, $comisionDevuelta, $totalDevuelto + $comisionDevuelta, $venta_id]);

                // Actualizar estado según si fue devolución total o parcial
                $stmtNuevoTotal = $pdo->prepare("SELECT total FROM ventas WHERE venta_id = ?");
                $stmtNuevoTotal->execute([$venta_id]);
                $nuevoTotal  = floatval($stmtNuevoTotal->fetchColumn());
                $nuevoEstado = $nuevoTotal <= 0 ? 'Devuelto' : 'Modificado';
                if ($nuevoEstado === 'Devuelto') {
                    // Devolución total: limpiar todos los montos a 0 (incluyendo comision_terminal)
                    $pdo->prepare("UPDATE ventas SET estado = ?, subtotal = 0, descuento = 0, comision_terminal = 0, total = 0 WHERE venta_id = ?")
                        ->execute([$nuevoEstado, $venta_id]);
                } else {
                    $pdo->prepare("UPDATE ventas SET estado = ? WHERE venta_id = ?")
                        ->execute([$nuevoEstado, $venta_id]);
                }

                // Si era crédito, actualizar el saldo
                if ($ventaInfo['metodo_pago'] === 'Credito' && $ventaInfo['cliente_id']) {
                    $stmtCred = $pdo->prepare("SELECT credito_id, saldo_pendiente FROM creditos WHERE venta_id = ? AND estado = 'Activo'");
                    $stmtCred->execute([$venta_id]);
                    $cred = $stmtCred->fetch(PDO::FETCH_ASSOC);
                    if ($cred) {
                        $nuevoSaldo  = max(0, $cred['saldo_pendiente'] - $totalDevuelto);
                        $nuevoEstado = $nuevoSaldo <= 0 ? 'Liquidado' : 'Activo';
                        $pdo->prepare("UPDATE creditos SET saldo_pendiente = ?, estado = ? WHERE credito_id = ?")
                            ->execute([$nuevoSaldo, $nuevoEstado, $cred['credito_id']]);
                    }
                }

                $pdo->commit();
                $exito = true;
                header('Location: devoluciones.php?msg=exito');
                exit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                // [AUTOFIX] D-03: No exponer mensaje técnico al usuario
                error_log('[Ferreteria/devoluciones] Error procesar devolucion venta_id=' . $venta_id . ': ' . $e->getMessage());
                $errores[] = 'Error al procesar la devolución. Intenta de nuevo.';
            }
        }
    }
}

// Historial nuevo: devoluciones agrupadas (desde la tabla devoluciones)
$stmtHN = $pdo->prepare("
    SELECT d.devolucion_id, d.procesada_en, d.cancelada_en, d.nota_cancelacion,
           d.total_devuelto, d.venta_id, v.folio,
           COUNT(DISTINCT m.producto_id) AS num_productos
    FROM devoluciones d
    JOIN ventas v ON d.venta_id = v.venta_id
    JOIN movimientos_inventario m ON m.devolucion_id = d.devolucion_id AND m.tipo = 'Entrada'
    JOIN productos p ON m.producto_id = p.producto_id
    JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
    GROUP BY d.devolucion_id
    ORDER BY d.procesada_en DESC
    LIMIT 30
");
$stmtHN->execute([$_SESSION['sucursal_id']]);
$historialNuevo = $stmtHN->fetchAll(PDO::FETCH_ASSOC);

// Historial viejo: movimientos sin devolucion_id (antes de la nueva tabla)
$stmtHV = $pdo->prepare("
    SELECT m.*, p.nombre_producto, p.codigo
    FROM movimientos_inventario m
    JOIN productos p ON m.producto_id = p.producto_id
    JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
    WHERE m.motivo LIKE 'Devolucion venta #%'
      AND m.devolucion_id IS NULL
    ORDER BY m.created_at DESC LIMIT 20
");
$stmtHV->execute([$_SESSION['sucursal_id']]);
$historialViejo = $stmtHV->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Devoluciones — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 20px; overflow-y: auto; display: grid; grid-template-columns: 420px 1fr; gap: 16px; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 18px; margin-bottom: 14px; }
    .card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 14px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .form-group { margin-bottom: 13px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .buscar-row { display: flex; gap: 8px; }
    .buscar-row input { flex: 1; }
    .btn-buscar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .venta-info { background: #f9f9f9; border-radius: 6px; padding: 12px; margin-bottom: 13px; font-size: 13px; display: none; }
    .venta-info.visible { display: block; }
    .venta-info h4 { font-size: 14px; color: #333; margin: 0 0 8px; }
    .prod-devolver { border: 0.5px solid #eee; border-radius: 6px; overflow: hidden; margin-bottom: 13px; display: none; }
    .prod-devolver.visible { display: block; }
    .prod-dev-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; }
    .prod-dev-row:last-child { border-bottom: none; }
    .prod-dev-row input[type=number] { width: 80px; padding: 5px 8px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; text-align: center; }
    .btn-devolver { width: 100%; background: #c0392b; color: white; border: none; padding: 11px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-devolver:hover { background: #a93226; }
    .resumen-dev { background: #fff8f5; border: 1.5px solid #f0c0b0; border-radius: 8px; padding: 14px 16px; margin-bottom: 13px; display: none; }
    .resumen-dev-monto { font-size: 28px; font-weight: 700; color: #c0392b; line-height: 1.1; }
    .resumen-dev-label { font-size: 11px; color: #999; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
    .resumen-dev-metodo { font-size: 12px; color: #555; margin-top: 7px; }
    .resumen-dev-comision { font-size: 11px; color: #b06000; background: #fff3e0; border-radius: 5px; padding: 6px 10px; margin-top: 7px; line-height: 1.4; }
    .paquete-dev-group { border: 1px solid #f0c06a; border-radius: 6px; margin-bottom: 6px; overflow: hidden; }
    .paquete-dev-header { background: #fffbf0; padding: 9px 12px; border-bottom: 1px solid #f0e0a0; font-size: 13px; }
    .paquete-dev-header label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
    .paquete-dev-header input[type=checkbox] { width: 15px; height: 15px; accent-color: #e67e22; cursor: pointer; }
    .paquete-dev-items .prod-dev-row { background: #fffef7; padding-left: 26px; }
    .paquete-dev-items .prod-dev-row input[type=number] { background: #f5f5f5; color: #999; }
    .paquete-dev-items .prod-dev-row input[type=number]:not([disabled]) { background: white; color: #333; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 10px 13px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 10px 13px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
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
        <a class="menu-item active" href="devoluciones.php">Devoluciones</a>
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
            <h2>Devoluciones</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Formulario -->
        <div>
            <?php
            $msgMap = [
                'exito'         => ['ok',  'Devolución registrada y stock actualizado.'],
                'cancelada'     => ['ok',  'Devolución cancelada. El stock fue revertido correctamente.'],
                'fuera_plazo'   => ['err', 'No se puede cancelar: han pasado más de 24 horas desde la devolución.'],
                'ya_cancelada'  => ['err', 'Esta devolución ya fue cancelada anteriormente.'],
                'error_cancelar'=> ['err', 'Error al cancelar la devolución. Intenta de nuevo.'],
            ];
            if (isset($_GET['msg']) && isset($msgMap[$_GET['msg']])): [$tipo,$texto] = $msgMap[$_GET['msg']]; ?>
                <div class="msg <?= $tipo === 'ok' ? 'msg-exito' : 'errores' ?>"><?= htmlspecialchars($texto) ?></div>
            <?php endif; ?>
            <?php if (!empty($errores)): ?>
                <div class="errores"><ul><?php foreach($errores as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
            <?php endif; ?>

            <div class="card">
                <h3>Registrar devolución</h3>

                <div class="form-group">
                    <label>Buscar por folio</label>
                    <div class="buscar-row">
                        <input type="number" id="inputFolioNum" placeholder="Ej. 0042" min="1" max="9999" style="width:100px;">
                        <select id="selectMes" style="padding:9px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                            <option value="01">Enero</option><option value="02">Febrero</option>
                            <option value="03">Marzo</option><option value="04">Abril</option>
                            <option value="05">Mayo</option><option value="06">Junio</option>
                            <option value="07">Julio</option><option value="08">Agosto</option>
                            <option value="09">Septiembre</option><option value="10">Octubre</option>
                            <option value="11">Noviembre</option><option value="12">Diciembre</option>
                        </select>
                        <select id="selectAnio" style="padding:9px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                            <?php for($y=date('Y');$y>=date('Y')-2;$y--): ?>
                            <option value="<?=$y?>"><?=$y?></option>
                            <?php endfor; ?>
                        </select>
                        <button class="btn-buscar" onclick="buscarVenta()">Buscar</button>
                    </div>
                    <div style="font-size:11px;color:#aaa;margin-top:4px;">Ingresa el número de folio (ej. 42) y selecciona el mes y año de la venta.</div>
                </div>

                <div class="venta-info" id="ventaInfo">
                    <h4 id="ventaCliente"></h4>
                    <div style="font-size:12px;color:#888;" id="ventaFecha"></div>
                    <div style="font-size:13px;margin-top:6px;" id="ventaTotal"></div>
                    <div style="font-size:12px;color:#666;margin-top:6px;" id="ventaPendiente"></div>
                </div>

                <div class="prod-devolver" id="prodDevolver">
                    <div id="listaProdsDev"></div>
                </div>

                <!-- Panel resumen: se actualiza en tiempo real al seleccionar cantidades -->
                <div class="resumen-dev" id="resumenDevolucion"></div>

                <form method="POST" id="formDevolucion">
                    <input type="hidden" name="venta_id" id="inputVentaIdHidden">
                    <input type="hidden" name="productos_devolver" id="inputProdsDev">

                    <div class="form-group" id="motivoGroup" style="display:none;">
                        <label>Motivo de devolución *</label>
                        <input type="text" name="motivo" placeholder="Ej. Producto defectuoso, talla incorrecta...">
                    </div>

                    <button type="submit" class="btn-devolver" id="btnDevolver" style="display:none;" onclick="return prepararDevolucion()">
                        Registrar devolución
                    </button>
                </form>
            </div>
        </div>

        <!-- Historial -->
        <div>
            <div class="card" style="padding:0;">
                <div style="padding:16px 20px;border-bottom:0.5px solid #eee;">
                    <h3 style="margin:0;">Devoluciones recientes</h3>
                </div>

                <?php if (count($historialNuevo) > 0): ?>
                <table>
                    <thead>
                        <tr><th>Folio</th><th>Productos</th><th>Total devuelto</th><th>Fecha</th><th>Estado</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historialNuevo as $h):
                            $cancelada  = !empty($h['cancelada_en']);
                            $dentro24h  = (time() - strtotime($h['procesada_en'])) < 86400;
                            $folio      = $h['folio'] ? 'Folio ' . $h['folio'] : '#' . $h['venta_id'];
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($folio) ?></strong></td>
                            <td style="font-size:12px;color:#666;"><?= $h['num_productos'] ?> producto<?= $h['num_productos'] != 1 ? 's' : '' ?></td>
                            <td style="color:#c0392b;font-weight:700;">-$<?= number_format($h['total_devuelto'], 2) ?></td>
                            <td style="font-size:11px;color:#aaa;"><?= date('d/m/Y H:i', strtotime($h['procesada_en'])) ?></td>
                            <td>
                                <?php if ($cancelada): ?>
                                    <span style="background:#f5f5f5;color:#999;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;">Cancelada</span>
                                <?php else: ?>
                                    <span style="background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;">Activa</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$cancelada && $dentro24h): ?>
                                    <a href="#" onclick="cancelarDevolucion(<?= $h['devolucion_id'] ?>); return false;"
                                       style="font-size:11px;color:#c0392b;font-weight:600;text-decoration:none;">Cancelar</a>
                                <?php elseif (!$cancelada): ?>
                                    <span style="font-size:10px;color:#ccc;">+24h</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php elseif (empty($historialViejo)): ?>
                    <div class="sin-resultados">No hay devoluciones registradas.</div>
                <?php endif; ?>

                <?php if (count($historialViejo) > 0): ?>
                <div style="padding:8px 16px 4px;border-top:1px solid #eee;font-size:10px;color:#bbb;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">
                    Historial previo
                </div>
                <table>
                    <thead>
                        <tr><th>Producto</th><th>Cantidad</th><th>Motivo</th><th>Fecha</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historialViejo as $h): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($h['nombre_producto']) ?></strong>
                                <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($h['codigo']) ?></div>
                            </td>
                            <td style="color:#2e7d32;font-weight:700;">+<?= number_format($h['cantidad'], 2) ?></td>
                            <td style="font-size:12px;color:#888;"><?= htmlspecialchars(formatearMotivoDevolucion($h['motivo'])) ?></td>
                            <td style="font-size:11px;color:#aaa;"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// [AUTOFIX] SEC-01: Token CSRF disponible en JS para links GET destructivos
const CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';

let ventaActual = null;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

// Pre-seleccionar mes actual
document.getElementById('selectMes').value = String(new Date().getMonth()+1).padStart(2,'0');

function buscarVenta() {
    const num  = document.getElementById('inputFolioNum').value;
    const mes  = document.getElementById('selectMes').value;
    const anio = document.getElementById('selectAnio').value;
    if (!num) { alert('Ingresa el número de folio.'); return; }
    fetch(`devoluciones.php?buscar_venta=${encodeURIComponent(num)}&mes=${mes}&anio=${anio}`)
        .then(r => r.json())
        // [AUTOFIX] D-04: Verificar errores del servidor antes de usar la respuesta
        .then(data => {
            if (!data) { alert('Folio no encontrado o la venta no está completada.'); return; }
            if (data.error) { alert('Error al buscar la venta: ' + data.error); return; }
            if (!Array.isArray(data.productos)) { alert('Folio no encontrado o la venta no está disponible en esta sucursal.'); return; }
            ventaActual = data;
            document.getElementById('ventaCliente').textContent = data.cliente || 'Público general';
            document.getElementById('ventaFecha').textContent = 'Folio: ' + (data.folio || ('#'+data.venta_id));
            document.getElementById('ventaTotal').textContent = 'Total: $'+parseFloat(data.total).toFixed(2)+' · '+data.metodo_pago;
            document.getElementById('ventaInfo').classList.add('visible');
            document.getElementById('inputVentaIdHidden').value = data.venta_id;

            // Separar en individuales y paquetes
            const prodsDevolvibles = data.productos.filter(p => parseFloat(p.cantidad_restante) > 0);
            const individuales = prodsDevolvibles.filter(p => !p.paquete_id);
            const paqMap = {};
            prodsDevolvibles.filter(p => p.paquete_id).forEach(p => {
                if (!paqMap[p.paquete_id]) paqMap[p.paquete_id] = { nombre: p.paquete_nombre || ('Paquete #' + p.paquete_id), productos: [] };
                paqMap[p.paquete_id].productos.push(p);
            });

            // Pre-calcular combosRestantes por paquete (necesario para el texto y el render)
            Object.entries(paqMap).forEach(([, paq]) => {
                paq.combosRestantes = Math.floor(Math.min(
                    ...paq.productos.map(p =>
                        (parseFloat(p.cantidad_restante) || 0) / (parseFloat(p.cantidad_requerida_combo) || 1)
                    )
                ));
            });

            // Guardar paqMap en ventaActual para usarlo al preparar la devolución
            ventaActual._paqMap = paqMap;

            // Texto de pendiente
            const totalInds   = individuales.reduce((s, p) => s + (parseFloat(p.cantidad_restante) || 0), 0);
            const totalCombos = Object.values(paqMap).reduce((s, paq) => s + paq.combosRestantes, 0);
            if (!prodsDevolvibles.length) {
                document.getElementById('ventaPendiente').textContent = 'Esta venta ya no tiene productos disponibles para devolución.';
            } else {
                const partes = [];
                if (totalInds)   partes.push(totalInds   + ' producto' + (totalInds   !== 1 ? 's' : ''));
                if (totalCombos) partes.push(totalCombos + ' combo'    + (totalCombos !== 1 ? 's' : ''));
                document.getElementById('ventaPendiente').textContent = 'Pendiente por devolver: ' + partes.join(' · ');
            }

            // Renderizar lista
            const lista = document.getElementById('listaProdsDev');
            if (!prodsDevolvibles.length) {
                lista.innerHTML = '<div style="padding:12px;text-align:center;color:#aaa;font-size:13px;">Todos los productos de esta venta ya fueron devueltos.</div>';
            } else {
                let html = '';

                // Productos individuales
                individuales.forEach(p => {
                    const restante    = parseFloat(p.cantidad_restante);
                    const esDecimal   = restante % 1 !== 0;
                    const precioFinal = parseFloat(p.precio_final);
                    const precioOrig  = parseFloat(p.precio_unitario);
                    const tieneAjuste = p.nota_ajuste && p.nota_ajuste.trim() !== '';
                    const tienePromo  = !tieneAjuste && precioFinal < precioOrig - 0.001;

                    // Línea de precio: muestra tachado + precio_final si hay ajuste/promo
                    let precioHtml = '';
                    if (tieneAjuste) {
                        precioHtml = `<div style="font-size:11px;margin-top:2px;">
                            <span style="text-decoration:line-through;color:#bbb;">$${precioOrig.toFixed(2)}</span>
                            <span style="color:#e67e22;font-weight:700;margin-left:4px;">$${precioFinal.toFixed(2)}</span>
                            <span style="background:#fff3e0;color:#e67e22;border-radius:99px;padding:1px 7px;font-size:10px;font-weight:700;margin-left:4px;">&#9888; Ajuste por da&#241;o: ${p.nota_ajuste}</span>
                        </div>`;
                    } else if (tienePromo) {
                        precioHtml = `<div style="font-size:11px;margin-top:2px;">
                            <span style="text-decoration:line-through;color:#bbb;">$${precioOrig.toFixed(2)}</span>
                            <span style="color:#1565c0;font-weight:700;margin-left:4px;">$${precioFinal.toFixed(2)}</span>
                            <span style="background:#e3f2fd;color:#1565c0;border-radius:99px;padding:1px 7px;font-size:10px;font-weight:700;margin-left:4px;">Promo</span>
                        </div>`;
                    } else {
                        precioHtml = `<div style="font-size:11px;margin-top:2px;color:#888;">$${precioFinal.toFixed(2)} c/u</div>`;
                    }

                    html += `<div class="prod-dev-row" style="align-items:flex-start;padding-top:10px;padding-bottom:10px;">
                        <span style="flex:1;">
                            <span style="font-size:13px;">${p.nombre_producto}</span>
                            ${precioHtml}
                        </span>
                        <span style="color:#aaa;font-size:11px;padding-top:2px;white-space:nowrap;">Restante: ${restante.toFixed(esDecimal?2:0).replace(/\.?0+$/, '')}</span>
                        <input type="number" data-producto-id="${p.producto_id}" data-precio="${p.precio_final}" data-restante="${restante}"
                            placeholder="0" step="${esDecimal ? 'any' : '1'}" min="0" max="${restante}" value=""
                            oninput="const mx=parseFloat(this.dataset.restante);if(parseFloat(this.value)>mx)this.value=mx.toString();"
                            onchange="const mx=parseFloat(this.dataset.restante);if(parseFloat(this.value)>mx)this.value=mx.toString();">
                    </div>`;
                });

                // Paquetes: una sola fila con cantidad de combos (combosRestantes ya precalculado)
                Object.entries(paqMap).forEach(([paqId, paq]) => {
                    const combosRestantes = paq.combosRestantes;
                    if (combosRestantes <= 0) return;
                    html += `<div class="paquete-dev-group">
                        <div class="prod-dev-row" style="background:#fffde7;border-radius:8px;padding:10px 12px;">
                            <span style="flex:1;">📦 <strong>${paq.nombre}</strong></span>
                            <span style="color:#aaa;font-size:11px;">Restante: ${combosRestantes} combo${combosRestantes !== 1 ? 's' : ''}</span>
                            <input type="number" data-paquete-id="${paqId}"
                                placeholder="0" step="1" min="0" max="${combosRestantes}" value=""
                                style="width:80px;padding:5px 8px;border:1px solid #ddd;border-radius:5px;font-size:13px;text-align:center;">
                        </div>
                    </div>`;
                });

                lista.innerHTML = html;
            }

            // Enganchar actualizarResumen() a cada input de cantidad
            lista.querySelectorAll('input[type=number]').forEach(inp => {
                inp.addEventListener('input', actualizarResumen);
            });
            actualizarResumen(); // limpiar resumen si la venta cambia

            document.getElementById('prodDevolver').classList.add('visible');
            document.getElementById('motivoGroup').style.display = 'block';
            document.getElementById('btnDevolver').style.display = 'block';
        })
        // [AUTOFIX] D-05: Manejar error de red en buscarVenta
        .catch(() => {
            alert('Error de conexión. Verifica tu red e intenta de nuevo.');
        });
}

// ── Resumen en tiempo real ───────────────────────────────────────────────────
function actualizarResumen() {
    const resumen = document.getElementById('resumenDevolucion');
    if (!ventaActual) { resumen.style.display = 'none'; return; }

    const inputs  = document.querySelectorAll('#listaProdsDev input[type=number]');
    const paqMap  = ventaActual._paqMap || {};
    let totalADevolver = 0;

    inputs.forEach(inp => {
        if (inp.disabled) return;
        const qty = parseFloat(inp.value) || 0;
        if (qty <= 0) return;
        const paqId = inp.dataset.paqueteId;
        if (paqId) {
            const paq = paqMap[paqId];
            if (!paq) return;
            paq.productos.forEach(p => {
                totalADevolver += qty * (parseFloat(p.cantidad_requerida_combo) || 1) * parseFloat(p.precio_final);
            });
        } else {
            totalADevolver += qty * parseFloat(inp.dataset.precio);
        }
    });

    if (totalADevolver <= 0.001) { resumen.style.display = 'none'; return; }

    // Comisión proporcional al monto seleccionado.
    // Tasa = comision_restante / suma_precio_final_RESTANTE (usa cantidad_restante, no cantidad original).
    // Así la tasa es constante entre devoluciones parciales: tasa × totalADevolver.
    const comisionTotal  = parseFloat(ventaActual.comision_terminal || 0);
    const sumaRestante   = (ventaActual.productos || []).reduce(
        (s, p) => s + parseFloat(p.precio_final) * Math.max(0, parseFloat(p.cantidad_restante || 0)), 0
    );
    const tasaComision   = (sumaRestante > 0.001 && comisionTotal > 0.001) ? comisionTotal / sumaRestante : 0;
    const comisionProp   = Math.round(totalADevolver * tasaComision * 100) / 100;

    const metodo = ventaActual.metodo_pago || 'Efectivo';

    // Texto de método de reembolso
    const metodoTextos = {
        'Efectivo':       '&#128181; Devuelve en <strong>efectivo</strong>',
        'Terminal':       '&#128179; Devuelve por <strong>terminal o transferencia</strong>',
        'Mixto':          '&#128181; Devuelve en <strong>efectivo o transferencia</strong>',
        'Transferencia':  '&#128179; Devuelve por <strong>transferencia</strong>',
        'Credito':        '&#128203; Descuenta del <strong>saldo del crédito</strong>',
    };
    const metodoHtml = `<div class="resumen-dev-metodo">${metodoTextos[metodo] || ('Devuelve: ' + metodo)}</div>`;

    // Aviso de comisión (solo si aplica)
    const comisionHtml = comisionProp > 0
        ? `<div class="resumen-dev-comision">&#9888; La comisión de terminal (~$${comisionProp.toFixed(2)}) <strong>no se reembolsa</strong> — es cobrada por el banco y la absorbe el negocio.</div>`
        : '';

    resumen.innerHTML = `
        <div class="resumen-dev-label">Monto a devolver al cliente</div>
        <div class="resumen-dev-monto">$${totalADevolver.toFixed(2)}</div>
        ${metodoHtml}
        ${comisionHtml}
    `;
    resumen.style.display = 'block';
}

function cancelarDevolucion(id) {
    const nota = prompt('¿Motivo de la cancelación? (opcional)');
    if (nota === null) return; // usuario canceló el prompt
    if (!confirm('¿Seguro que deseas cancelar esta devolución? El stock se revertirá.')) return;
    // [AUTOFIX] SEC-01: Incluir CSRF token en el link de cancelacion
    window.location.href = `devoluciones.php?cancelar_dev=${id}&nota=${encodeURIComponent(nota)}&_token=${encodeURIComponent(CSRF_TOKEN)}`;
}

function prepararDevolucion() {
    const inputs = document.querySelectorAll('#listaProdsDev input[type=number]');
    const paqMap = (ventaActual && ventaActual._paqMap) ? ventaActual._paqMap : {};
    const prods  = [];
    let errorMsg = null;

    inputs.forEach(inp => {
        if (inp.disabled) return;
        const qty = parseFloat(inp.value) || 0;
        if (qty <= 0) return;

        const paqId = inp.dataset.paqueteId;
        if (paqId) {
            // Entrada de combo: expandir a productos individuales
            const paq = paqMap[paqId];
            if (!paq) return;
            if (qty > paq.combosRestantes + 0.001) {
                errorMsg = `No puedes devolver más de ${paq.combosRestantes} combo(s) de "${paq.nombre}".`;
                return;
            }
            paq.productos.forEach(p => {
                prods.push({
                    producto_id:    p.producto_id,
                    cantidad:       qty * (parseFloat(p.cantidad_requerida_combo) || 1),
                    precio_unitario: p.precio_final
                });
            });
        } else {
            // Producto individual
            const restante = parseFloat(inp.dataset.restante);
            if (qty > restante + 0.0001) {
                errorMsg = 'No puedes devolver más de lo que se compró (' + restante + ' restante).';
                return;
            }
            prods.push({ producto_id: inp.dataset.productoId, cantidad: qty, precio_unitario: inp.dataset.precio });
        }
    });

    if (errorMsg) { alert(errorMsg); return false; }
    if (!prods.length) { alert('Selecciona al menos un producto o paquete a devolver.'); return false; }
    document.getElementById('inputProdsDev').value = JSON.stringify(prods);
    return true;
}
</script>
</body>
</html>




