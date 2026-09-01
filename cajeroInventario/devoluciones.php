<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

// Verificar que hay caja abierta; si no, redirigir a abrirCaja
// [FIX-CONSISTENCIA] Igual que admin/cajero_devoluciones.php (FIX-ALTO-D2-05): antes exigia
// que la caja abierta fuera la del propio usuario — una guarda heredada del flujo de Cajero,
// que SI abre su propia caja para vender. Un Administrador normalmente no abre caja propia,
// asi que quedaba bloqueado para usar Devoluciones aunque hubiera una caja abierta (la del
// cajero en turno) de donde sacar el reembolso. Para Administrador se acepta CUALQUIER caja
// abierta de su sucursal; para Cajero e Inventario/Cajero se mantiene la misma restriccion
// de antes (solo la suya).
if ($_SESSION['rol'] === 'Administrador') {
    $_stmtCajaGuard = $pdo->prepare("SELECT caja_id FROM cajas WHERE sucursal_id = ? AND estado = 'Abierta' ORDER BY abierta_en DESC LIMIT 1");
    $_stmtCajaGuard->execute([$_SESSION['sucursal_id']]);
} else {
    $_stmtCajaGuard = $pdo->prepare("SELECT caja_id FROM cajas WHERE usuario_id = ? AND estado = 'Abierta' LIMIT 1");
    $_stmtCajaGuard->execute([$_SESSION['usuario_id']]);
}
$_cajaGuardId = $_stmtCajaGuard->fetchColumn();
if (!$_cajaGuardId) {
    header('Location: abrirCaja.php?msg=sinCaja');
    exit();
}
$cajaId = intval($_cajaGuardId); // [AUTOFIX] Guardado para registrar retiro de caja en devoluciones

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

// [AUTOFIX] Retorna mapa con clave compuesta "producto_id:paquete_id" (paquete_id vacío = suelto).
// Esto evita que devoluciones de componentes de paquete "contaminen" las filas sueltas
// cuando el mismo producto aparece tanto en el paquete como vendido de forma independiente.
// Los callers que necesiten el total por producto_id deben sumar ellos mismos.
function obtenerTotalesDevueltos(PDO $pdo, int $ventaId, int $sucursalId): array
{
    $totales = [];

    // [AUTOFIX] Bug A: antes usaba INNER JOIN stock_sucursal → si faltaba la fila,
    // el conteo era 0 y el producto podía devolverse de nuevo indefinidamente.
    // Ahora consultamos directamente vía devoluciones.venta_id (FK exacta).
    // Incluir paquete_id en GROUP BY para clave compuesta correcta.
    $stmtNew = $pdo->prepare("
        SELECT mi.producto_id, mi.paquete_id, SUM(mi.cantidad) AS cantidad_devuelta
        FROM movimientos_inventario mi
        JOIN devoluciones d ON d.devolucion_id = mi.devolucion_id AND d.venta_id = ?
        WHERE mi.tipo = 'Entrada'
          AND d.cancelada_en IS NULL
        GROUP BY mi.producto_id, mi.paquete_id
    ");
    $stmtNew->execute([$ventaId]);
    foreach ($stmtNew->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $mk = intval($fila['producto_id']) . ':' . ($fila['paquete_id'] ?? '');
        $totales[$mk] = floatval($fila['cantidad_devuelta']);
    }

    // Compatibilidad con movimientos anteriores a la tabla devoluciones (sin devolucion_id).
    // Registros viejos no tienen paquete_id → siempre se tratan como sueltos (clave "pid:").
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
        $mk = intval($fila['producto_id']) . ':'; // registros viejos = sueltos
        $totales[$mk] = ($totales[$mk] ?? 0) + floatval($fila['cantidad_devuelta']);
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

        // Limite de tiempo: solo se aceptan devoluciones de ventas con menos de 7 dias
        if ($venta && strtotime($venta['created_at']) < strtotime('-7 days')) {
            echo json_encode(['error' => 'esta venta tiene mas de 7 dias de antiguedad; ya no es posible registrar devoluciones.']);
            exit();
        }

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
            // [AUTOFIX] obtenerTotalesDevueltos ahora retorna clave compuesta "producto_id:paquete_id".
            // Matching directo por clave compuesta: elimina la asignación secuencial (que causaba
            // que devoluciones de paquete se asignaran a filas sueltas del mismo producto).
            $devueltos = obtenerTotalesDevueltos($pdo, intval($venta['venta_id']), intval($_SESSION['sucursal_id']));

            foreach ($venta['productos'] as &$productoVenta) {
                $pid   = intval($productoVenta['producto_id']);
                $paqId = !empty($productoVenta['paquete_id']) ? intval($productoVenta['paquete_id']) : null;
                $mk    = $pid . ':' . ($paqId ?? '');
                $devuelta        = $devueltos[$mk] ?? 0;
                $cantidadVendida = floatval($productoVenta['cantidad']);
                $productoVenta['cantidad_devuelta'] = min($devuelta, $cantidadVendida);
                $productoVenta['cantidad_restante']  = max(0, $cantidadVendida - $productoVenta['cantidad_devuelta']);
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

    // [AUTOFIX] BUG-03: Agregar filtro de sucursal para que un cajero no pueda cancelar
    // devoluciones de otra sucursal. Sin este check, un atacante que conozca el devolucion_id
    // podría corromper stock e historial financiero de otras sucursales.
    $stmtD = $pdo->prepare("
        SELECT d.*, v.total AS total_actual, v.estado AS estado_actual,
               v.comision_terminal, v.metodo_pago, v.folio, v.caja_id AS caja_id_venta
        FROM devoluciones d
        JOIN ventas v ON d.venta_id = v.venta_id
        JOIN cajas ca ON v.caja_id = ca.caja_id AND ca.sucursal_id = ?
        WHERE d.devolucion_id = ?
    ");
    $stmtD->execute([$_SESSION['sucursal_id'], $devolucion_id]);
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
            $stockAntRaw = $stmtS->fetchColumn();
            $stockAnt    = ($stockAntRaw !== false) ? floatval($stockAntRaw) : 0.0;
            $stockNuevo = max(0, $stockAnt - floatval($m['cantidad']));
            // [FIX-CONSISTENCIA] Igual que admin/cajero_devoluciones.php (FIX-ALTO-D2-04): usar
            // INSERT ... ON DUPLICATE KEY UPDATE por si la fila no existiera — evita el mismo
            // "UPDATE silencioso de 0 filas" si de todos modos faltara.
            $pdo->prepare("
                INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo)
                VALUES (?, ?, ?, 0, 0, 1)
                ON DUPLICATE KEY UPDATE stock_actual = VALUES(stock_actual)
            ")->execute([$m['producto_id'], $_SESSION['sucursal_id'], $stockNuevo]);
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

        // Monto total de los Retiros que la devolución original registro en movimientos_caja
        // (sea por Terminal/Mixto/Transferencia, por Efectivo de otra caja, o por el
        // reembolso de abonos ya pagados en una venta a crédito — ver mas abajo). Se usa
        // tanto para restaurar el saldo del crédito correctamente como para regresar el
        // efectivo exacto al cancelar, en vez de asumir que siempre es igual a $totalDevuelto.
        $stmtHuboRet = $pdo->prepare("SELECT COALESCE(SUM(monto),0) AS monto, MAX(nota) AS nota FROM movimientos_caja WHERE devolucion_id = ? AND tipo = 'Retiro'");
        $stmtHuboRet->execute([$devolucion_id]);
        $retiroInfo       = $stmtHuboRet->fetch(PDO::FETCH_ASSOC);
        $montoRetiroOrig  = floatval($retiroInfo['monto'] ?? 0);
        $notaRetiroOrig   = $retiroInfo['nota'] ?? '';
        $huboRetiro       = $montoRetiroOrig > 0.001;
        $esCreditoDev     = in_array($dev['metodo_pago'] ?? '', ['Credito', 'Crédito'], true);

        // Si era crédito, restaurar saldo.
        // [FIX] Antes siempre sumaba $totalDevuelto completo al saldo. Si la devolución
        // original habia reembolsado en efectivo un excedente (porque el cliente ya
        // habia abonado mas de lo que quedaba pendiente), sumar todo $totalDevuelto de
        // vuelta inflaba el saldo mas alla de lo que realmente se debia antes de
        // devolver. La reduccion real que sufrio el saldo fue $totalDevuelto menos lo
        // que se reembolso en efectivo (montoRetiroOrig) — eso es lo que hay que restaurar.
        $stmtCred = $pdo->prepare("SELECT credito_id, saldo_pendiente, estado FROM creditos WHERE venta_id = ? AND estado IN ('Activo','Vencido','Liquidado')");
        $stmtCred->execute([$dev['venta_id']]);
        $cred = $stmtCred->fetch(PDO::FETCH_ASSOC);
        if ($cred) {
            $reduccionOriginal = $esCreditoDev
                ? max(0.0, round($totalDevuelto - $montoRetiroOrig, 2))
                : $totalDevuelto;
            // [FIX-MORA-QUINCENAL] Al cancelar la devolucion el saldo vuelve a subir, pero eso
            // no es un pago — si ya estaba "Vencido" debe seguir viendose "Vencido", no
            // resetearse a "Activo". Solo se saca de "Liquidado" (ya no aplica con saldo > 0).
            $estadoRestaurado = $cred['estado'] === 'Liquidado' ? 'Activo' : $cred['estado'];
            $pdo->prepare("UPDATE creditos SET saldo_pendiente = saldo_pendiente + ?, estado = ? WHERE credito_id = ?")
                ->execute([$reduccionOriginal, $estadoRestaurado, $cred['credito_id']]);
        }

        // Marcar devolución como cancelada
        $pdo->prepare("UPDATE devoluciones SET cancelada_en = NOW(), cancelada_por = ?, nota_cancelacion = ? WHERE devolucion_id = ?")
            ->execute([$_SESSION['usuario_id'], $nota_cancel ?: null, $devolucion_id]);

        // Registrar ingreso de efectivo DENTRO de la transacción.
        // Al cancelar la devolución el cliente regresa el EFECTIVO (el reembolso siempre
        // fue en efectivo) — debe quedar en caja y contar en el corte como dinero fisico.
        // Se registra el Ingreso cuando:
        // - La devolución original registro un Retiro (Terminal/Mixto/Transferencia, Efectivo
        //   de otra caja, o el reembolso de abonos ya pagados en un crédito), o
        // - Fue devolución en Efectivo sin retiro (misma caja) pero la cancelación ocurre
        //   en una caja distinta: la restauracion de ventas.total cae en la caja original,
        //   asi que el efectivo que entra hoy necesita su propio registro.
        $necesitaIngreso  = $huboRetiro || (!$esCreditoDev && intval($dev['caja_id_venta']) !== $cajaId);
        $montoIngreso     = $huboRetiro ? $montoRetiroOrig : $totalDevuelto;
        if ($necesitaIngreso) {
            $folioCancelNota = $dev['folio'] ?? $dev['venta_id'];
            // Sin sufijo [Terminal]/[Transferencia]: el dinero SI entra al cajon.
            // Excepcion (compatibilidad): si el Retiro original es anterior a este cambio y
            // trae el sufijo viejo, el Ingreso lleva el mismo sufijo para que el par
            // retiro/ingreso se anule igual en corteCaja y no genere sobrante fantasma.
            $sufLegacy = '';
            if ($huboRetiro && preg_match('/(\[(?:Terminal|Transferencia)\])$/', (string)$notaRetiroOrig, $mLeg)) {
                $sufLegacy = ' ' . $mLeg[1];
            }
            $notaIngreso = 'Cancelación devolución folio #' . $folioCancelNota . ' (' . ($dev['metodo_pago'] ?? '') . ')' . $sufLegacy;
            $pdo->prepare("INSERT INTO movimientos_caja (caja_id, usuario_id, sucursal_id, tipo, monto, nota, devolucion_id) VALUES (?,?,?,'Ingreso',?,?,?)")
                ->execute([$cajaId, $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $montoIngreso, $notaIngreso, $devolucion_id]);
        }

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
    // [FIX-A1] Verificar CSRF antes de procesar la devolución
    requerirCSRF($_POST['_token'] ?? '', 'devoluciones.php');
    $venta_id      = intval($_POST['venta_id'] ?? 0);
    $productos_dev = json_decode($_POST['productos_devolver'] ?? '[]', true);
    $motivo        = trim($_POST['motivo'] ?? '');

    if (!$venta_id)              $errores[] = 'Selecciona una venta.';
    if (empty($productos_dev))   $errores[] = 'Selecciona al menos un producto a devolver.';
    if (!$motivo)                $errores[] = 'El motivo es obligatorio.';

    if (empty($errores)) {
        // [AUTOFIX] D-02: Verificar que la venta pertenezca a la sucursal del cajero antes de procesar
        // [AUTOFIX] Se agregan subtotal y descuento para calcular el factor neto proporcional al devolver
        // [FIX-CONSISTENCIA] Igual que admin/cajero_devoluciones.php (FIX-CRIT-D2-02): esta
        // consulta no filtraba por estado — permitia "devolver" ventas Canceladas o Pendientes
        // que nunca se cobraron o cuyo dinero ya se habia regresado, generando un reembolso en
        // efectivo por dinero que nunca entro a caja.
        $stmtV = $pdo->prepare("
            SELECT v.metodo_pago, v.cliente_id, v.folio, v.caja_id, v.created_at,
                   v.subtotal AS venta_subtotal, v.descuento AS venta_descuento
            FROM ventas v
            JOIN cajas ca ON v.caja_id = ca.caja_id AND ca.sucursal_id = ?
            WHERE v.venta_id = ? AND v.estado IN ('Completada', 'Modificado')
        ");
        $stmtV->execute([$_SESSION['sucursal_id'], $venta_id]);
        $ventaInfo = $stmtV->fetch(PDO::FETCH_ASSOC);
        if (!$ventaInfo) {
            $errores[] = 'La venta no pertenece a esta sucursal, no existe, o no está en un estado que admita devolución.';
        }

        // Limite de tiempo: solo se aceptan devoluciones de ventas con menos de 7 dias
        if ($ventaInfo && strtotime($ventaInfo['created_at']) < strtotime('-7 days')) {
            $errores[] = 'Solo se pueden devolver ventas con menos de 7 dias de antiguedad.';
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

        // [AUTOFIX] obtenerTotalesDevueltos retorna clave compuesta "pid:paqId".
        //           Calcular también el agregado por producto_id para validaciones de cantidad total.
        $cantidadesDevueltas = obtenerTotalesDevueltos($pdo, $venta_id, intval($_SESSION['sucursal_id']));
        $cantDevAgregado = [];
        foreach ($cantidadesDevueltas as $mk => $qty) {
            $pid = intval(explode(':', $mk, 2)[0]);
            $cantDevAgregado[$pid] = ($cantDevAgregado[$pid] ?? 0) + $qty;
        }

        // Cargar precios reales y calcular subtotalFinalVenta (suma de precio_final × cantidad)
        // subtotalFinalVenta se usa para separar descuentos por-ítem (promos/ajustes) del descuento global del cliente
        // [AUTOFIX] Clave compuesta "producto_id:paquete_id" para evitar colisión de precio cuando el mismo
        //           producto aparece como suelto y como parte de paquete en la misma venta.
        $stmtPreciosDB = $pdo->prepare("
            SELECT producto_id, paquete_id, precio_final, cantidad, subtotal
            FROM venta_productos
            WHERE venta_id = ?
        ");
        $stmtPreciosDB->execute([$venta_id]);
        $preciosRealDB     = [];
        $subtotalFinalVenta = 0.0;
        foreach ($stmtPreciosDB->fetchAll(PDO::FETCH_ASSOC) as $fp) {
            $mapKey = intval($fp['producto_id']) . ':' . ($fp['paquete_id'] ?? '');
            // [FIX-PAQUETE-SUBTOTAL-DEVOLUCION] precio_final tiene solo 2 decimales; para un
            // paquete cuyo precio no divide exacto entre sus componentes (ver
            // FIX-PAQUETE-CENTAVO en nuevaVenta.php), cantidad×precio_final pierde centavos
            // contra "subtotal" (que sí guarda el resto exacto para el último item del grupo).
            // Se usa subtotal/cantidad como precio efectivo de mayor precisión: para una
            // devolución total de la línea, esto reproduce el subtotal real exacto.
            $cantLineaFinal = floatval($fp['cantidad']);
            $preciosRealDB[$mapKey] = $cantLineaFinal > 0.0001
                ? floatval($fp['subtotal']) / $cantLineaFinal
                : floatval($fp['precio_final']);
            $subtotalFinalVenta += floatval($fp['subtotal']);
        }

        // [FIX-CANTIDAD-ENTERA-DEVOLUCION] tipo_venta de cada producto de la venta, para
        // rechazar una cantidad fraccionaria en una devolución de un producto que NO es
        // "Suelto" — verificado en vivo: sin esto se podía devolver, por ejemplo, 1.5
        // martillos (tipo "Unidad") y el stock quedaba con una fracción de pieza física
        // imposible, además de calcular un reembolso proporcional a esa fracción inventada.
        $tiposVentaProds = [];
        if (!empty($cantidadesVendidas)) {
            $idsProdsVenta = array_keys($cantidadesVendidas);
            $inPlaceholders = implode(',', array_fill(0, count($idsProdsVenta), '?'));
            $stmtTV = $pdo->prepare("SELECT producto_id, tipo_venta FROM productos WHERE producto_id IN ($inPlaceholders)");
            $stmtTV->execute($idsProdsVenta);
            foreach ($stmtTV->fetchAll(PDO::FETCH_ASSOC) as $filaTV) {
                $tiposVentaProds[intval($filaTV['producto_id'])] = $filaTV['tipo_venta'];
            }
        }

        // [AUTOFIX] Clave compuesta "producto_id:paquete_id" para diferenciar filas sueltas de filas en paquete.
        //           Así dos entradas del mismo producto con distinto paquete_id mantienen precios independientes.
        $productosAgrupados = [];
        if (empty($errores)) foreach ($productos_dev as $prod) {
            $productoId = intval($prod['producto_id'] ?? 0);
            $paqueteId  = isset($prod['paquete_id']) && $prod['paquete_id'] !== null && $prod['paquete_id'] !== ''
                          ? intval($prod['paquete_id']) : null;
            $cantidad   = floatval($prod['cantidad'] ?? 0);
            $mapKey     = $productoId . ':' . ($paqueteId ?? '');

            if ($productoId <= 0 || $cantidad <= 0) {
                continue;
            }

            if ($paqueteId === null && ($tiposVentaProds[$productoId] ?? null) !== 'Suelto' && floor($cantidad) != $cantidad) {
                $errores[] = 'La cantidad a devolver de "' . ($prod['nombre_producto'] ?? 'un producto') . '" debe ser un número entero.';
                continue;
            }

            // [FIX-CONSISTENCIA] Igual que admin/cajero_devoluciones.php (FIX-CRIT-D2-01): el
            // precio SIEMPRE debe venir de una fila real de venta_productos para esta venta.
            // Antes, si el producto_id+paquete_id enviado por el cliente no coincidía con
            // ninguna fila real (ej. un paquete_id inventado), el código caía a
            // floatval($prod['precio_unitario']) — el precio que mandó el navegador, sin ningún
            // tope. Eso permitía inflar el monto del reembolso a lo que sea. Ahora, si la
            // combinación no corresponde a una línea real de la venta, se rechaza la devolución
            // completa en vez de aceptar un precio no verificado.
            if (!isset($preciosRealDB[$mapKey])) {
                $errores[] = 'Uno de los productos seleccionados no coincide con las líneas reales de esta venta.';
                continue;
            }
            $precioUnitario = $preciosRealDB[$mapKey];

            if (!isset($productosAgrupados[$mapKey])) {
                $productosAgrupados[$mapKey] = [
                    'producto_id'   => $productoId,
                    'paquete_id'    => $paqueteId,
                    'cantidad'      => 0,
                    'precio_unitario' => $precioUnitario,
                ];
            }

            $productosAgrupados[$mapKey]['cantidad']       += $cantidad;
            $productosAgrupados[$mapKey]['precio_unitario'] = $precioUnitario;
        }

        // [AUTOFIX] Validar cantidad total por producto_id (sumando sueltos + paquete).
        //           Usar $cantDevAgregado (agrupado por producto_id) para comparar contra lo vendido total.
        $cantTotalPorProd = [];
        foreach ($productosAgrupados as $entry) {
            $pid = $entry['producto_id'];
            $cantTotalPorProd[$pid] = ($cantTotalPorProd[$pid] ?? 0) + $entry['cantidad'];
        }
        foreach ($cantTotalPorProd as $productoId => $cantTotal) {
            $vendido  = $cantidadesVendidas[$productoId] ?? 0;
            $devuelto = $cantDevAgregado[$productoId] ?? 0;
            if ($vendido <= 0) {
                $errores[] = 'Uno de los productos seleccionados no pertenece a la venta.';
                continue;
            }
            if ($cantTotal > (($vendido - $devuelto) + 0.0001)) {
                $errores[] = 'No puedes regresar mas producto del que realmente queda pendiente por devolver.';
            }
        }

        // Validar que los paquetes se devuelvan completos.
        // Si un producto se vendió tanto dentro de un paquete como suelto, se permite
        // devolverlo de forma independiente siempre que la cantidad devuelta no supere
        // lo vendido como suelto. Solo se exige el paquete completo cuando la cantidad
        // a devolver excede lo disponible fuera del paquete (se está tocando el paquete).
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

            // Cantidades vendidas fuera de cualquier paquete (líneas sueltas)
            $stmtSueltos = $pdo->prepare("
                SELECT producto_id, SUM(cantidad) AS qty
                FROM venta_productos
                WHERE venta_id = ? AND paquete_id IS NULL
                GROUP BY producto_id
            ");
            $stmtSueltos->execute([$venta_id]);
            $cantSuelta = [];
            foreach ($stmtSueltos->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $cantSuelta[intval($f['producto_id'])] = floatval($f['qty']);
            }

            // [AUTOFIX] Usar $cantTotalPorProd (suma por producto_id) y lista plana de ids devueltos.
            //           productosAgrupados ya tiene clave compuesta, así que extraemos los ids únicos.
            $idsDevueltos    = array_unique(array_column(array_values($productosAgrupados), 'producto_id'));
            $paquesConRetorno = [];
            foreach ($paqProds as $paqId => $prodsDePaquete) {
                foreach ($prodsDePaquete as $prodId) {
                    if (!isset($cantTotalPorProd[$prodId])) continue; // no se devuelve este producto
                    $qDevolviendo     = $cantTotalPorProd[$prodId];
                    $qSuelta          = $cantSuelta[$prodId] ?? 0;
                    $qYaDevuelta      = $cantDevAgregado[$prodId] ?? 0;
                    $disponibleSuelta = max(0.0, $qSuelta - $qYaDevuelta);
                    if ($qDevolviendo > $disponibleSuelta + 0.0001) {
                        // La cantidad supera lo disponible suelto → toca el paquete
                        $paquesConRetorno[$paqId] = true;
                    }
                }
            }

            // Solo exigir el paquete completo si efectivamente se está devolviendo del paquete
            foreach ($paquesConRetorno as $paqId => $_) {
                $faltantes = array_diff($paqProds[$paqId], $idsDevueltos);
                if (!empty($faltantes)) {
                    $errores[] = 'Si devuelves un paquete debes devolver todos sus productos juntos.';
                    break;
                }
            }
        }

        $productos_dev = array_values($productosAgrupados);
        $folioExtra = !empty($ventaInfo['folio']) ? ' folio:' . $ventaInfo['folio'] : '';
        $motivo = 'Devolucion venta #' . $venta_id . $folioExtra . ': ' . $motivo;

        if (empty($errores)) {
            // [AUTOFIX] Bug B: calcular subtotal bruto, descuento proporcional y comisión proporcional.
            // ventas.subtotal es base bruta (precio_unitario); totalDevuelto debe reflejar el descuento global.
            // Restar el mismo valor a ambos campos producía subtotal incorrecto y residuo de comisión.

            // 1) Obtener precio_unitario real de venta_productos para los productos devueltos
            // [AUTOFIX] Clave compuesta "producto_id:paquete_id" para precio bruto correcto por fila.
            $prodIdsRetorno = array_unique(array_map('intval', array_column($productos_dev, 'producto_id')));
            $precioUnitarioMap = [];
            if (!empty($prodIdsRetorno)) {
                $inPH = implode(',', array_fill(0, count($prodIdsRetorno), '?'));
                $stmtVPBruto = $pdo->prepare("
                    SELECT producto_id, paquete_id, precio_unitario
                    FROM venta_productos
                    WHERE venta_id = ? AND producto_id IN ($inPH)
                ");
                $stmtVPBruto->execute(array_merge([$venta_id], $prodIdsRetorno));
                foreach ($stmtVPBruto->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $mk = intval($row['producto_id']) . ':' . ($row['paquete_id'] ?? '');
                    $precioUnitarioMap[$mk] = floatval($row['precio_unitario']);
                }
            }

            $subtotalBrutoDevuelto = 0.0;
            foreach ($productos_dev as $prod) {
                $pid  = intval($prod['producto_id']);
                $paqId = isset($prod['paquete_id']) && $prod['paquete_id'] !== null && $prod['paquete_id'] !== ''
                         ? intval($prod['paquete_id']) : null;
                $mk   = $pid . ':' . ($paqId ?? '');
                // [FIX-CONSISTENCIA] Igual que admin/cajero_devoluciones.php (FIX-CRIT-D2-01):
                // nunca caer al precio del cliente — si no está en la BD, es 0 (no debería
                // ocurrir tras la validación de arriba, pero es defensa en profundidad).
                $precioUnit = $precioUnitarioMap[$mk] ?? 0.0;
                $subtotalBrutoDevuelto += floatval($prod['cantidad']) * $precioUnit;
            }
            $subtotalBrutoDevuelto = round($subtotalBrutoDevuelto, 2);

            // Calcular totalDevuelto correctamente separando dos tipos de descuento:
            //
            // • Descuentos por-ítem (promos, ajustes por daño): ya están capturados en precio_final.
            //   Usar precio_final × qty como base ya los incluye automáticamente.
            //
            // • Descuento global del cliente (porcentaje aplicado a todo el pedido): hay que
            //   calcularlo como: ventas.descuento - (subtotalBruto - subtotalFinal).
            //   factorClienteNeto = (subtotalFinal - clienteDiscount) / subtotalFinal.
            //
            // El factorNeto anterior usaba subtotalBruto como base, lo que "redistribuía"
            // los ajustes por daño sobre todos los ítems y daba montos incorrectos.

            $ventaSubtotalBruto  = floatval($ventaInfo['venta_subtotal'] ?? 0);
            $ventaDescuentoTotal = floatval($ventaInfo['venta_descuento'] ?? 0);

            $perItemDiscountVenta = max(0.0, $ventaSubtotalBruto - $subtotalFinalVenta);
            $clientDiscountVenta  = max(0.0, $ventaDescuentoTotal - $perItemDiscountVenta);
            $factorClienteNeto    = ($subtotalFinalVenta > 0.001)
                ? max(0.0, ($subtotalFinalVenta - $clientDiscountVenta) / $subtotalFinalVenta)
                : 1.0;

            // subtotalFinalDevuelto = precio_final × qty por cada ítem devuelto
            // [AUTOFIX] Usar clave compuesta para precio_final correcto (suelto vs paquete).
            $subtotalFinalDevuelto = 0.0;
            foreach ($productos_dev as $prod) {
                $pid   = intval($prod['producto_id']);
                $paqId = isset($prod['paquete_id']) && $prod['paquete_id'] !== null && $prod['paquete_id'] !== ''
                         ? intval($prod['paquete_id']) : null;
                $mk    = $pid . ':' . ($paqId ?? '');
                // [FIX-CONSISTENCIA] Igual que admin/cajero_devoluciones.php (FIX-CRIT-D2-01):
                // nunca caer al precio del cliente — mismo razonamiento que arriba.
                $subtotalFinalDevuelto += floatval($prod['cantidad']) * ($preciosRealDB[$mk] ?? 0.0);
            }
            $subtotalFinalDevuelto = round($subtotalFinalDevuelto, 2);

            $totalDevuelto     = round($subtotalFinalDevuelto * $factorClienteNeto, 2);
            $descuentoDevuelto = round(max(0.0, $subtotalBrutoDevuelto - $totalDevuelto), 2);

            // 2) Comisión proporcional (solo Terminal / Mixto).
            // [AUTOFIX] La comisión solo aplica a ventas con Terminal o Mixto.
            // Efectivo, Transferencia y Crédito no tienen comisión de terminal.
            $metodoVenta = $ventaInfo['metodo_pago'] ?? '';
            $tieneComisionTerminal = ($metodoVenta === 'Terminal' || $metodoVenta === 'Mixto');

            if ($tieneComisionTerminal) {
                // [AUTOFIX] Ratio de comisión usando precio_final en ambos lados.
                // precio_final ya captura promos y ajustes por daño, por lo que es la
                // misma escala que $subtotalFinalDevuelto (que también usa precio_final).
                // suma_restante_final = precio_final × cantidad_aún_no_devuelta.
                // ratio = subtotalFinalDevuelto / suma_restante_final → siempre en [0,1].
                // Para devolución total: ratio = 1 → se devuelve toda la comisión restante. ✓
                // [FIX-CONSISTENCIA] Igual que admin/cajero_devoluciones.php (FIX-ALTO-D2-03):
                // el subquery "dev" agrupaba lo ya devuelto solo por producto_id, y el JOIN
                // final tambien solo por producto_id. Si la venta tenia el mismo producto en
                // DOS filas de venta_productos (una suelta y otra dentro de un paquete, o el
                // mismo producto en dos paquetes distintos), el fan-out del JOIN aplicaba la
                // MISMA cantidad ya devuelta a ambas filas — la resta se contaba doble,
                // "suma_restante_final" salia mas chico de lo real, y se reembolsaba mas
                // comision de la que el cliente en realidad habia pagado. Se agrega paquete_id
                // tanto al agrupado como al JOIN, usando <=> porque puede ser NULL en sueltos.
                $stmtVComision = $pdo->prepare("
                    SELECT v.comision_terminal,
                           COALESCE(SUM(vp.precio_final * GREATEST(0, vp.cantidad - COALESCE(dev.devuelta, 0))), 0) AS suma_restante_final
                    FROM ventas v
                    LEFT JOIN venta_productos vp ON vp.venta_id = v.venta_id
                    LEFT JOIN (
                        SELECT mi.producto_id, mi.paquete_id, SUM(mi.cantidad) AS devuelta
                        FROM movimientos_inventario mi
                        JOIN devoluciones d ON d.devolucion_id = mi.devolucion_id AND d.venta_id = ?
                        WHERE mi.tipo = 'Entrada' AND d.cancelada_en IS NULL
                        GROUP BY mi.producto_id, mi.paquete_id
                    ) dev ON dev.producto_id = vp.producto_id AND dev.paquete_id <=> vp.paquete_id
                    WHERE v.venta_id = ?
                    GROUP BY v.venta_id
                ");
                $stmtVComision->execute([$venta_id, $venta_id]);
                $comData            = $stmtVComision->fetch(PDO::FETCH_ASSOC);
                $comisionTotal      = $comData ? floatval($comData['comision_terminal'])      : 0.0;
                $sumaRestanteFinal  = $comData ? floatval($comData['suma_restante_final'])    : 0.0;
                $comisionDevuelta   = ($sumaRestanteFinal > 0.001 && $comisionTotal > 0.001)
                    ? round($comisionTotal * min(1.0, $subtotalFinalDevuelto / $sumaRestanteFinal), 2)
                    : 0.0;
            } else {
                // Efectivo, Transferencia, Crédito — sin comisión de terminal
                $comisionDevuelta = 0.0;
            }

            $pdo->beginTransaction();
            try {
                // Registrar la devolución como grupo (permite cancelación posterior)
                // [AUTOFIX] Incluir subtotal_final_devuelto para auditoría y cálculo preciso de comisión
                $pdo->prepare("INSERT INTO devoluciones (venta_id, usuario_id, total_devuelto, subtotal_bruto_devuelto, subtotal_final_devuelto, comision_devuelta) VALUES (?,?,?,?,?,?)")
                    ->execute([$venta_id, $_SESSION['usuario_id'], $totalDevuelto, $subtotalBrutoDevuelto, $subtotalFinalDevuelto, $comisionDevuelta]);
                $devolucion_id = intval($pdo->lastInsertId());

                foreach ($productos_dev as $prod) {
                    $producto_id = intval($prod['producto_id']);
                    $cantidad    = floatval($prod['cantidad']);
                    // [AUTOFIX] Guardar paquete_id en movimiento para poder hacer JOIN correcto en historial.
                    $paqId = isset($prod['paquete_id']) && $prod['paquete_id'] !== null && $prod['paquete_id'] !== ''
                             ? intval($prod['paquete_id']) : null;
                    if ($cantidad <= 0) continue;

                    $stmtS = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
                    $stmtS->execute([$producto_id, $_SESSION['sucursal_id']]);
                    $stockAnterior = floatval($stmtS->fetchColumn());
                    $stockNuevo    = $stockAnterior + $cantidad;

                    // [FIX-CONSISTENCIA] Igual que admin/cajero_devoluciones.php
                    // (FIX-ALTO-D2-04): antes, si no existia fila de stock_sucursal para este
                    // producto+sucursal (producto nunca stockeado ahi, o la fila se borro), el
                    // UPDATE no afectaba ninguna fila — sin error, sin aviso. El reembolso se
                    // pagaba igual y el movimiento quedaba registrado, pero el stock devuelto
                    // nunca llegaba a existir en el inventario real. Ahora se usa INSERT ... ON
                    // DUPLICATE KEY UPDATE: si la fila no existe, se crea con el stock devuelto.
                    $pdo->prepare("
                        INSERT INTO stock_sucursal (producto_id, sucursal_id, stock_actual, stock_minimo, stock_maximo, activo)
                        VALUES (?, ?, ?, 0, 0, 1)
                        ON DUPLICATE KEY UPDATE stock_actual = VALUES(stock_actual)
                    ")->execute([$producto_id, $_SESSION['sucursal_id'], $stockNuevo]);
                    $pdo->prepare("INSERT INTO movimientos_inventario (producto_id,usuario_id,sucursal_id,tipo,cantidad,stock_anterior,stock_nuevo,motivo,devolucion_id,paquete_id) VALUES (?,?,?,'Entrada',?,?,?,?,?,?)")
                        ->execute([$producto_id, $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $cantidad, $stockAnterior, $stockNuevo, $motivo, $devolucion_id, $paqId]);
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

                // Si era crédito, actualizar el saldo.
                // [FIX] Reembolso de crédito con abonos previos: si el cliente ya habia
                // pagado (via abonos) mas de lo que la venta vale despues de esta
                // devolucion, ese excedente ya estaba en efectivo/terminal/transferencia
                // en manos del negocio y debe regresarsele. Antes solo se hacia
                // saldo_pendiente = max(0, saldo - totalDevuelto), perdiendo ese dinero
                // sin dejar ningun rastro. Ahora el excedente se reembolsa en efectivo
                // (politica: los reembolsos siempre son en efectivo) y queda registrado
                // como un Retiro igual que cualquier otra devolucion.
                $reembolsoExcedenteCredito = 0.0;
                if ($ventaInfo['metodo_pago'] === 'Credito' && $ventaInfo['cliente_id']) {
                    // Se incluye 'Vencido' ademas de 'Activo': un credito vencido tambien
                    // debe reducir su saldo (y, si aplica, reembolsar el excedente) al
                    // devolver productos de esa venta.
                    $stmtCred = $pdo->prepare("SELECT credito_id, saldo_pendiente, estado FROM creditos WHERE venta_id = ? AND estado IN ('Activo','Vencido')");
                    $stmtCred->execute([$venta_id]);
                    $cred = $stmtCred->fetch(PDO::FETCH_ASSOC);
                    if ($cred) {
                        $saldoAntesCredito = floatval($cred['saldo_pendiente']);
                        $nuevoSaldo        = max(0.0, round($saldoAntesCredito - $totalDevuelto, 2));
                        // [FIX-MORA-QUINCENAL] Una devolución reduce lo que se debe, pero no es
                        // un pago — si el crédito ya estaba Vencido, sigue Vencido mientras no
                        // quede en $0 (mismo criterio que los abonos: solo Liquidado sale de mora).
                        $nuevoEstadoCred   = $nuevoSaldo <= 0.001 ? 'Liquidado' : $cred['estado'];
                        $pdo->prepare("UPDATE creditos SET saldo_pendiente = ?, estado = ? WHERE credito_id = ?")
                            ->execute([$nuevoSaldo, $nuevoEstadoCred, $cred['credito_id']]);

                        $reembolsoExcedenteCredito = max(0.0, round($totalDevuelto - $saldoAntesCredito, 2));
                    }
                }

                // Registrar salida de efectivo en caja DENTRO de la transacción.
                // Si falla, toda la devolución se revierte — sin estados inconsistentes.
                // Politica del negocio: el reembolso SIEMPRE se entrega en efectivo (sin importar
                // el metodo de pago original), asi que el Retiro cuenta como salida de caja fisica
                // y corteCaja lo resta del efectivo esperado.
                // - Terminal/Mixto/Transferencia: siempre se registra el retiro (esas ventas no
                //   estan en el bucket de efectivo del corte, no hay doble conteo).
                // - Efectivo: solo si la venta es de OTRA caja; si es de la caja actual la
                //   reduccion de ventas.total ya la recoge corteCaja y un retiro contaria doble.
                // - Credito: normalmente no sale dinero (se descuenta del saldo pendiente), salvo
                //   el excedente calculado arriba cuando el cliente ya habia abonado de mas.
                $necesitaRetiro = in_array($metodoVenta, ['Terminal', 'Mixto', 'Transferencia'], true)
                    || ($metodoVenta === 'Efectivo' && intval($ventaInfo['caja_id']) !== $cajaId)
                    || ($metodoVenta === 'Credito' && $reembolsoExcedenteCredito > 0.001);
                if ($necesitaRetiro) {
                    $folioDevNota  = $ventaInfo['folio'] ?? $venta_id;
                    $montoRetiro   = ($metodoVenta === 'Credito') ? $reembolsoExcedenteCredito : $totalDevuelto;
                    // Sin sufijo [Terminal]/[Transferencia]: el dinero SI sale del cajon.
                    // Se anota el metodo entre parentesis solo como referencia para el cajero.
                    $notaRetiro    = ($metodoVenta === 'Credito')
                        ? 'Reembolso devolución crédito folio #' . $folioDevNota . ' (abonos ya pagados)'
                        : 'Devolución folio #' . $folioDevNota . ' (' . $metodoVenta . ')';
                    $pdo->prepare("INSERT INTO movimientos_caja (caja_id, usuario_id, sucursal_id, tipo, monto, nota, devolucion_id) VALUES (?,?,?,'Retiro',?,?,?)")
                        ->execute([$cajaId, $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $montoRetiro, $notaRetiro, $devolucion_id]);
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
    -- [FIX] Filtrar por la sucursal de la VENTA devuelta (venta → caja → sucursal);
    -- antes bastaba con que el producto existiera en esta sucursal y se veían
    -- devoluciones de otras sucursales.
    JOIN cajas ca ON v.caja_id = ca.caja_id AND ca.sucursal_id = ?
    JOIN movimientos_inventario m ON m.devolucion_id = d.devolucion_id AND m.tipo = 'Entrada'
    JOIN productos p ON m.producto_id = p.producto_id
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
    -- [FIX] Filtrar por la sucursal del movimiento; registros viejos sin sucursal (NULL) se conservan
    WHERE m.motivo LIKE 'Devolucion venta #%'
      AND m.devolucion_id IS NULL
      AND (m.sucursal_id = ? OR m.sucursal_id IS NULL)
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
                    <!-- [FIX-A1] Token CSRF para proteger el registro de devolución -->
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
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
                        <span style="color:#aaa;font-size:11px;padding-top:2px;white-space:nowrap;">Restante: ${esDecimal ? restante.toFixed(2).replace(/\.?0+$/, '') : restante.toFixed(0)}</span>
                        <input type="number" data-producto-id="${p.producto_id}" data-precio="${p.precio_final}" data-precio-orig="${p.precio_unitario}" data-restante="${restante}"
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
                            <input type="number" data-paquete-id="${paqId}" data-restante="${combosRestantes}"
                                placeholder="0" step="1" min="0" max="${combosRestantes}" value=""
                                style="width:80px;padding:5px 8px;border:1px solid #ddd;border-radius:5px;font-size:13px;text-align:center;"
                                oninput="const mx=parseFloat(this.dataset.restante);if(parseFloat(this.value)>mx)this.value=mx.toString();"
                                onchange="const mx=parseFloat(this.dataset.restante);if(parseFloat(this.value)>mx)this.value=mx.toString();">
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
            // Usar precio_final: ya incluye el descuento del paquete por ítem
            paq.productos.forEach(p => {
                totalADevolver += qty * (parseFloat(p.cantidad_requerida_combo) || 1) * parseFloat(p.precio_final);
            });
        } else {
            // Usar precio_final: ya incluye promos y ajustes por daño
            totalADevolver += qty * parseFloat(inp.dataset.precio);
        }
    });

    if (totalADevolver <= 0.001) { resumen.style.display = 'none'; return; }

    // [FIX] Guardar el monto RAW (antes del descuento global de cliente) para poder
    // calcular la comisión mas abajo con la MISMA base que usa el servidor
    // (subtotalFinalDevuelto en devoluciones.php). totalADevolver se sobreescribe
    // justo despues con el monto YA con el descuento aplicado (el que se muestra al
    // cajero), por eso hay que copiarlo antes de que eso pase.
    const subtotalFinalDevueltoRaw = totalADevolver;

    // Aplicar SOLO el descuento global del cliente (no los descuentos por-ítem que ya están en precio_final).
    // perItemDiscount = diferencia entre subtotalBruto y subtotalFinal (promos + ajustes daño ya en precio_final)
    // clientDiscount  = ventas.descuento - perItemDiscount (porcentaje de descuento global del cliente)
    // factorClienteNeto = (subtotalFinal - clientDiscount) / subtotalFinal
    const ventaSubtotalBruto  = parseFloat(ventaActual.subtotal || 0);
    const ventaDescuentoTotal = parseFloat(ventaActual.descuento || 0);
    const subtotalFinalVenta  = (ventaActual.productos || []).reduce(
        (s, p) => s + parseFloat(p.precio_final || 0) * parseFloat(p.cantidad || 0), 0
    );
    const perItemDiscount   = Math.max(0, ventaSubtotalBruto - subtotalFinalVenta);
    const clientDiscount    = Math.max(0, ventaDescuentoTotal - perItemDiscount);
    const factorClienteNeto = (subtotalFinalVenta > 0.001)
        ? Math.max(0, (subtotalFinalVenta - clientDiscount) / subtotalFinalVenta)
        : 1;
    totalADevolver = Math.round(totalADevolver * factorClienteNeto * 100) / 100;

    const metodo = ventaActual.metodo_pago || 'Efectivo';

    // [FIX] Comisión proporcional — replica EXACTAMENTE la formula que usa el servidor en
    // devoluciones.php (bloque "Comisión de terminal esperada" / seccion 2 del procesamiento):
    //
    //   sumaRestanteFinal = SUM(precio_final × restante) de TODAS las filas de la venta,
    //                       donde restante = max(0, cantidad_fila − total_ya_devuelto_del_producto)
    //                       (el "total ya devuelto" se agrupa por producto_id SIN separar
    //                       por paquete_id — igual que el SQL real: JOIN ... GROUP BY producto_id)
    //   ratio             = min(1, subtotalFinalDevuelto_raw / sumaRestanteFinal)
    //   comisionDevuelta  = comisionTotal_actual × ratio
    //
    // Antes esta funcion usaba tasaComision = comisionTotal / (venta.total − comisionTotal),
    // que en la PRIMERA devolucion de una venta coincide por casualidad con la formula real,
    // pero en la SEGUNDA (o posteriores) devolucion parcial de la misma venta con descuento
    // de cliente ya no coincide, porque esa formula solo "recupera" el % de comision original
    // en vez de calcular que proporcion del saldo restante se esta devolviendo ahora.
    const tieneComisionTerminal = (metodo === 'Terminal' || metodo === 'Mixto');
    const comisionTotal = tieneComisionTerminal ? parseFloat(ventaActual.comision_terminal || 0) : 0;

    // Total ya devuelto por producto_id (ignora paquete_id, igual que la subconsulta SQL
    // del servidor: "GROUP BY mi.producto_id"). Cada fila de ventaActual.productos ya trae
    // su propio cantidad_devuelta calculado por clave compuesta producto+paquete; sumarlos
    // por producto_id reproduce el mismo total que agrupar directamente por producto_id.
    const devueltaPorProductoId = {};
    (ventaActual.productos || []).forEach(p => {
        const pid = parseInt(p.producto_id);
        devueltaPorProductoId[pid] = (devueltaPorProductoId[pid] || 0) + (parseFloat(p.cantidad_devuelta) || 0);
    });
    const sumaRestanteFinal = (ventaActual.productos || []).reduce((s, p) => {
        const pid      = parseInt(p.producto_id);
        const restante = Math.max(0, parseFloat(p.cantidad || 0) - (devueltaPorProductoId[pid] || 0));
        return s + parseFloat(p.precio_final || 0) * restante;
    }, 0);

    const tasaComision = (sumaRestanteFinal > 0.001 && comisionTotal > 0.001)
        ? Math.min(1, subtotalFinalDevueltoRaw / sumaRestanteFinal)
        : 0;
    const comisionProp = Math.round(comisionTotal * tasaComision * 100) / 100;

    // [AUTOFIX] Las devoluciones siempre se entregan en efectivo sin importar cómo se pagó originalmente
    // Crédito es la única excepción: se descuenta del saldo pendiente
    const esCredito = (metodo === 'Crédito' || metodo === 'Credito');
    const metodoHtml = esCredito
        ? `<div class="resumen-dev-metodo">&#128203; Descuenta del <strong>saldo del crédito</strong></div>`
        : `<div class="resumen-dev-metodo">&#128181; El cliente recibirá el reembolso en <strong>efectivo</strong></div>`;

    // Aviso de comisión (solo Terminal / Mixto)
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
        // Bug #4: Validar cantidad negativa explícitamente
        if (qty < 0) {
            errorMsg = 'La cantidad a devolver no puede ser negativa.';
            inp.value = '0';
            return;
        }
        if (qty === 0) return;

        const paqId = inp.dataset.paqueteId;
        if (paqId) {
            // Entrada de combo: expandir a productos individuales
            const paq = paqMap[paqId];
            if (!paq) return;
            if (qty > paq.combosRestantes + 0.001) {
                errorMsg = `No puedes devolver más de ${paq.combosRestantes} combo(s) de "${paq.nombre}".`;
                return;
            }
            // [AUTOFIX] Incluir paquete_id en cada producto para que el backend use el precio correcto por fila.
            paq.productos.forEach(p => {
                prods.push({
                    producto_id:    p.producto_id,
                    cantidad:       qty * (parseFloat(p.cantidad_requerida_combo) || 1),
                    precio_unitario: p.precio_final,
                    paquete_id:     parseInt(paqId)
                });
            });
        } else {
            // Producto individual
            const restante = parseFloat(inp.dataset.restante);
            if (qty > restante + 0.0001) {
                errorMsg = 'No puedes devolver más de lo que se compró (' + restante + ' restante).';
                return;
            }
            // [AUTOFIX] paquete_id = null para productos sueltos (clave compuesta en backend).
            prods.push({ producto_id: inp.dataset.productoId, cantidad: qty, precio_unitario: inp.dataset.precio, paquete_id: null });
        }
    });

    if (errorMsg) { alert(errorMsg); return false; }
    // Bug #5: Mensaje claro cuando todas las cantidades son 0
    if (!prods.length) { alert('Ingresa una cantidad mayor a 0 para al menos un producto a devolver.'); return false; }
    document.getElementById('inputProdsDev').value = JSON.stringify(prods);
    return true;
}
</script>
</body>
</html>
 



