<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

$stmt = $pdo->prepare("SELECT * FROM cajas WHERE usuario_id = ? AND estado = 'Abierta' ORDER BY abierta_en DESC LIMIT 1");
$stmt->execute([$_SESSION['usuario_id']]);
$caja = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$caja) {
    header('Location: abrirCaja.php?msg=sinCaja');
    exit();
}

$sucursales = $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);

// ── Movimiento de caja (retiro / ingreso) ────────────────────────────────────
// SQL para crear la tabla si no existe:
// CREATE TABLE movimientos_caja (
//   movimiento_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
//   caja_id       INT UNSIGNED NOT NULL,
//   usuario_id    INT UNSIGNED NOT NULL,
//   sucursal_id   INT UNSIGNED NOT NULL,
//   tipo          ENUM('Retiro','Ingreso') NOT NULL,
//   monto         DECIMAL(10,2) NOT NULL,
//   nota          TEXT NOT NULL,
//   created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//   KEY idx_caja (caja_id), KEY idx_suc (sucursal_id), KEY idx_fecha (created_at)
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
$msgMovCaja = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['movimiento_caja'])) {
    // [AUTOFIX] BUG-04: Verificar CSRF token en handler de movimiento de caja
    requerirCSRF($_POST['_token'] ?? '', 'nuevaVenta.php');
    $tipoMov  = in_array($_POST['tipo_mov'] ?? '', ['Retiro','Ingreso']) ? $_POST['tipo_mov'] : null;
    $montoMov = floatval($_POST['monto_mov'] ?? 0);
    $notaMov  = trim($_POST['nota_mov'] ?? '');
    if ($tipoMov && $montoMov > 0.004 && $notaMov !== '') {
        $pdo->prepare("INSERT INTO movimientos_caja (caja_id, usuario_id, sucursal_id, tipo, monto, nota) VALUES (?,?,?,?,?,?)")
            ->execute([$caja['caja_id'], $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $tipoMov, $montoMov, $notaMov]);
        header('Location: nuevaVenta.php?msg_mov=' . $tipoMov);
        exit();
    } else {
        $msgMovCaja = 'error';
    }
}
if (isset($_GET['msg_mov'])) {
    $msgMovCaja = $_GET['msg_mov'] === 'Retiro' ? 'retiro_ok' : 'ingreso_ok';
}

// Datos de la sucursal para el ticket
$stmtSuc = $pdo->prepare("SELECT * FROM sucursales WHERE sucursal_id = ?");
$stmtSuc->execute([$_SESSION['sucursal_id']]);
$sucursalTicket = $stmtSuc->fetch(PDO::FETCH_ASSOC);

// Promociones activas para esta sucursal (indexed by producto_id)
$stmtPromos = $pdo->prepare("
    SELECT pr.promocion_id, pr.producto_id, pr.precio_promocional, pr.descripcion
    FROM promociones pr
    JOIN productos p ON pr.producto_id = p.producto_id
    JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
    WHERE pr.activo = 1
      AND CURDATE() BETWEEN pr.fecha_inicio AND pr.fecha_fin
    ORDER BY pr.precio_promocional ASC
");
$stmtPromos->execute([$_SESSION['sucursal_id']]);
$promosList = $stmtPromos->fetchAll(PDO::FETCH_ASSOC);
$promosByProdId = [];
foreach ($promosList as $promo) {
    // Si hay varias promociones para el mismo producto, queda la de menor precio
    $promosByProdId[$promo['producto_id']] = [
        'promo_id'    => intval($promo['promocion_id']),
        'precio_promo'=> floatval($promo['precio_promocional']),
        'descripcion' => $promo['descripcion'] ?? '',
    ];
}

// ── AJAX: paquetes activos ───────────────────────────────────────────────────
// ── AJAX: todos los productos de la sucursal (para búsqueda local en JS) ─────
if (isset($_GET['get_productos_all'])) {
    $stmt = $pdo->prepare("
        SELECT p.producto_id, p.codigo, p.nombre_producto, p.precio_venta,
               p.precio_mayoreo, p.precio_compra, ss.stock_actual, p.tipo_venta, p.unidad_medida
        FROM productos p
        INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
        WHERE p.activo = 1 AND ss.activo = 1
        ORDER BY p.nombre_producto ASC
    ");
    $stmt->execute([$_SESSION['sucursal_id']]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

if (isset($_GET['get_paquetes'])) {
    $stmtPaq = $pdo->prepare("
        SELECT pk.paquete_id, pk.codigo, pk.nombre, pk.precio_paquete,
               pp.producto_id, pp.cantidad AS cantidad_requerida,
               pr.nombre_producto, ss.stock_actual
        FROM paquetes pk
        JOIN paquete_productos pp ON pk.paquete_id = pp.paquete_id
        JOIN productos pr ON pp.producto_id = pr.producto_id
        JOIN stock_sucursal ss ON ss.producto_id = pr.producto_id AND ss.sucursal_id = ?
        WHERE pk.activo = 1
    ");
    $stmtPaq->execute([$_SESSION['sucursal_id']]);
    $filas = $stmtPaq->fetchAll(PDO::FETCH_ASSOC);
    $agrupados = [];
    foreach ($filas as $f) {
        $pid = intval($f['paquete_id']);
        if (!isset($agrupados[$pid])) {
            $agrupados[$pid] = [
                'paquete_id'     => $pid,
                'codigo'         => $f['codigo'],
                'nombre'         => $f['nombre'],
                'precio_paquete' => floatval($f['precio_paquete']),
                'productos'      => []
            ];
        }
        $agrupados[$pid]['productos'][] = [
            'producto_id'        => intval($f['producto_id']),
            'nombre_producto'    => $f['nombre_producto'],
            'cantidad_requerida' => floatval($f['cantidad_requerida']),
            'stock_actual'       => floatval($f['stock_actual'])
        ];
    }
    header('Content-Type: application/json');
    echo json_encode(array_values($agrupados));
    exit();
}

// ── AJAX: buscar paquete ─────────────────────────────────────────────────────
if (isset($_GET['buscar_paquete'])) {
    $termino = trim($_GET['buscar_paquete']);
    $stmt = $pdo->prepare("
        SELECT pk.paquete_id, pk.codigo, pk.nombre, pk.precio_paquete,
               pp.producto_id, pp.cantidad AS cantidad_req,
               ss.stock_actual, pr.nombre_producto
        FROM paquetes pk
        JOIN paquete_productos pp ON pk.paquete_id = pp.paquete_id
        JOIN productos pr ON pp.producto_id = pr.producto_id
        JOIN stock_sucursal ss ON ss.producto_id = pr.producto_id AND ss.sucursal_id = ?
        WHERE pk.activo = 1
          AND (pk.codigo LIKE ? OR pk.nombre LIKE ?)
    ");
    $stmt->execute([$_SESSION['sucursal_id'], '%'.$termino.'%', '%'.$termino.'%']);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $resultado = [];
    foreach ($filas as $f) {
        $pid = intval($f['paquete_id']);
        if (!isset($resultado[$pid])) {
            $resultado[$pid] = [
                'paquete_id'    => $pid,
                'codigo'        => $f['codigo'],
                'nombre'        => $f['nombre'],
                'precio_paquete'=> floatval($f['precio_paquete']),
                'disponible'    => true,
                'productos'     => []
            ];
        }
        $resultado[$pid]['productos'][] = [
            'producto_id'     => intval($f['producto_id']),
            'nombre_producto' => $f['nombre_producto'],
            'cantidad_req'    => floatval($f['cantidad_req']),
            'stock_actual'    => floatval($f['stock_actual'])
        ];
        if (floatval($f['stock_actual']) < floatval($f['cantidad_req'])) {
            $resultado[$pid]['disponible'] = false;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(array_values($resultado));
    exit();
}

// ── AJAX: búsqueda combinada (producto + paquete en un solo request) ─────────
if (isset($_GET['buscar_combo'])) {
    $termino     = trim($_GET['buscar_combo']);
    // [AUTOFIX] SEC-05: Ignorar sucursal_id del cliente, siempre usar la de la sesion
    $sucursal_id = intval($_SESSION['sucursal_id']);
    $like        = '%' . $termino . '%';

    // Productos
    $stmtP = $pdo->prepare("
        SELECT p.producto_id, p.codigo, p.nombre_producto, p.precio_venta,
               p.precio_mayoreo, p.precio_compra, ss.stock_actual, p.tipo_venta, p.unidad_medida
        FROM productos p
        INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
        WHERE p.activo = 1 AND ss.activo = 1
          AND (p.codigo LIKE ? OR p.nombre_producto LIKE ?)
        LIMIT 10
    ");
    $stmtP->execute([$sucursal_id, $like, $like]);
    $productos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // Paquetes
    $stmtPaq = $pdo->prepare("
        SELECT pk.paquete_id, pk.codigo, pk.nombre, pk.precio_paquete,
               pp.producto_id, pp.cantidad AS cantidad_req,
               ss.stock_actual, pr.nombre_producto
        FROM paquetes pk
        JOIN paquete_productos pp ON pk.paquete_id = pp.paquete_id
        JOIN productos pr ON pp.producto_id = pr.producto_id
        JOIN stock_sucursal ss ON ss.producto_id = pr.producto_id AND ss.sucursal_id = ?
        WHERE pk.activo = 1
          AND (pk.codigo LIKE ? OR pk.nombre LIKE ?)
    ");
    $stmtPaq->execute([$sucursal_id, $like, $like]);
    $filasPaq = $stmtPaq->fetchAll(PDO::FETCH_ASSOC);

    $paquetes = [];
    foreach ($filasPaq as $f) {
        $pid = intval($f['paquete_id']);
        if (!isset($paquetes[$pid])) {
            $paquetes[$pid] = [
                'paquete_id'     => $pid,
                'codigo'         => $f['codigo'],
                'nombre'         => $f['nombre'],
                'precio_paquete' => floatval($f['precio_paquete']),
                'disponible'     => true,
                'productos'      => []
            ];
        }
        $paquetes[$pid]['productos'][] = [
            'producto_id'     => intval($f['producto_id']),
            'nombre_producto' => $f['nombre_producto'],
            'cantidad_req'    => floatval($f['cantidad_req']),
            'stock_actual'    => floatval($f['stock_actual'])
        ];
        if (floatval($f['stock_actual']) < floatval($f['cantidad_req'])) {
            $paquetes[$pid]['disponible'] = false;
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['productos' => $productos, 'paquetes' => array_values($paquetes)]);
    exit();
}

// ── AJAX: buscar producto ────────────────────────────────────────────────────
if (isset($_GET['buscar_producto'])) {
    $termino     = trim($_GET['buscar_producto']);
    // [AUTOFIX] SEC-05: Ignorar sucursal_id del cliente, siempre usar la de la sesion
    $sucursal_id = intval($_SESSION['sucursal_id']);
    $stmt = $pdo->prepare("
        SELECT p.producto_id, p.codigo, p.nombre_producto, p.precio_venta,
               p.precio_mayoreo, p.precio_compra, ss.stock_actual, p.tipo_venta, p.unidad_medida
        FROM productos p
        INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
        WHERE p.activo = 1 AND ss.activo = 1
          AND (p.codigo LIKE ? OR p.nombre_producto LIKE ?)
        LIMIT 10
    ");
    $stmt->execute([$sucursal_id, '%'.$termino.'%', '%'.$termino.'%']);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// ── AJAX: buscar producto por código exacto (scanner) ───────────────────────
if (isset($_GET['scan_codigo'])) {
    $codigo      = trim($_GET['scan_codigo']);
    $sucursal_id = $_SESSION['sucursal_id'];
    $stmt = $pdo->prepare("
        SELECT p.producto_id, p.codigo, p.nombre_producto, p.precio_venta,
               ss.stock_actual, p.tipo_venta
        FROM productos p
        INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id AND ss.sucursal_id = ?
        WHERE p.activo = 1 AND ss.activo = 1 AND p.codigo = ?
        LIMIT 1
    ");
    $stmt->execute([$sucursal_id, $codigo]);
    $prod = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($prod ?: null);
    exit();
}

// ── AJAX: inventario por sucursal ────────────────────────────────────────────
if (isset($_GET['inventario_sucursal'])) {
    $sucursal_id = intval($_GET['inventario_sucursal']);
    $buscar      = trim($_GET['buscar_inv'] ?? '');
    $where       = "WHERE p.activo = 1 AND ss.activo = 1 AND ss.sucursal_id = ?";
    $params      = [$sucursal_id];
    if ($buscar) { $where .= " AND (p.nombre_producto LIKE ? OR p.codigo LIKE ?)"; $params[] = '%'.$buscar.'%'; $params[] = '%'.$buscar.'%'; }
    $stmt = $pdo->prepare("SELECT p.producto_id, p.codigo, p.nombre_producto, ss.stock_actual, p.precio_venta, p.precio_compra, p.precio_mayoreo, p.tipo_venta, p.unidad_medida FROM productos p INNER JOIN stock_sucursal ss ON ss.producto_id = p.producto_id $where ORDER BY p.nombre_producto ASC LIMIT 50");
    $stmt->execute($params);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// ── AJAX: todos los clientes (para búsqueda local en JS) ────────────────────
if (isset($_GET['get_clientes_all'])) {
    $stmt = $pdo->query("
        SELECT cliente_id, nombre_completo, telefono,
               descuento_fijo, credito_autorizado
        FROM clientes WHERE activo = 1
        ORDER BY nombre_completo ASC
    ");
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// ── AJAX: buscar cliente (mantenido por compatibilidad) ──────────────────────
if (isset($_GET['buscar_cliente'])) {
    $termino = trim($_GET['buscar_cliente']);
    $stmt = $pdo->prepare("
        SELECT cliente_id, nombre_completo, telefono,
               descuento_fijo, credito_autorizado
        FROM clientes
        WHERE activo = 1 AND nombre_completo LIKE ?
        LIMIT 8
    ");
    $stmt->execute(['%'.$termino.'%']);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// ── AJAX: obtener detalle de venta para ticket ───────────────────────────────
if (isset($_GET['ticket_venta'])) {
    $venta_id = intval($_GET['ticket_venta']);
    // [AUTOFIX] BUG-02: Agregar filtro de sucursal para evitar que un cajero vea tickets de otra sucursal
    $stmtV = $pdo->prepare("
        SELECT v.*, c.nombre_completo AS cliente, c.telefono AS tel_cliente
        FROM ventas v
        JOIN cajas ca ON v.caja_id = ca.caja_id AND ca.sucursal_id = ?
        LEFT JOIN clientes c ON v.cliente_id = c.cliente_id
        WHERE v.venta_id = ?
    ");
    $stmtV->execute([$_SESSION['sucursal_id'], $venta_id]);
    $venta = $stmtV->fetch(PDO::FETCH_ASSOC);
    if ($venta) {
        // Formatear fecha en servidor (evita problemas de zona horaria en JS)
        $venta['fecha_formateada'] = date('d/m/Y H:i', strtotime($venta['created_at']));
    }

    $stmtP = $pdo->prepare("
        SELECT vp.producto_id, vp.cantidad, vp.precio_unitario, vp.precio_final, vp.subtotal,
               vp.nota_ajuste, vp.paquete_id, p.nombre_producto, p.codigo,
               pk.nombre AS paquete_nombre, pk.precio_paquete
        FROM venta_productos vp
        JOIN productos p ON vp.producto_id = p.producto_id
        LEFT JOIN paquetes pk ON vp.paquete_id = pk.paquete_id
        WHERE vp.venta_id = ?
    ");
    $stmtP->execute([$venta_id]);
    $venta['productos'] = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // [AUTOFIX] descuento_display = descuento almacenado directamente (ya es el valor correcto)
    if ($venta) {
        $venta['descuento_display'] = floatval($venta['descuento']);
    }

    header('Content-Type: application/json');
    echo json_encode($venta);
    exit();
}

// ── Procesar venta ───────────────────────────────────────────────────────────
$errorVenta = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_venta'])) {
    $items             = json_decode($_POST['items'], true);
    $cliente_id        = intval($_POST['cliente_id'] ?? 0) ?: null;
    $metodo_pago       = $_POST['metodo_pago'] ?? '';
    $notas_venta       = trim($_POST['notas_venta'] ?? '');
    $monto_efectivo    = floatval($_POST['monto_efectivo'] ?? 0);
    $monto_terminal    = floatval($_POST['monto_terminal'] ?? 0);
    $comision_terminal = floatval($_POST['comision_terminal'] ?? 0);
    $descuento         = floatval($_POST['descuento'] ?? 0);
    $subtotal          = floatval($_POST['subtotal'] ?? 0);
    $total             = floatval($_POST['total'] ?? 0);
    $cambio            = floatval($_POST['cambio'] ?? 0);

    $referencia_transferencia = ($metodo_pago === 'Transferencia') ? trim($_POST['referencia_transferencia'] ?? '') : null;

    // [AUTOFIX] N-02: Validar metodo_pago contra whitelist permitido
    $metodosPermitidos = ['Efectivo', 'Terminal', 'Mixto', 'Credito', 'Transferencia'];
    if (!in_array($metodo_pago, $metodosPermitidos, true)) {
        if (!empty($_POST['_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Método de pago inválido.']);
            exit();
        }
        $errorVenta = 'Método de pago inválido.';
    }

    // [AUTOFIX] N-03: Validar que credito requiere cliente
    if (!$errorVenta && $metodo_pago === 'Credito' && !$cliente_id) {
        if (!empty($_POST['_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'El pago a crédito requiere seleccionar un cliente.']);
            exit();
        }
        $errorVenta = 'El pago a crédito requiere seleccionar un cliente.';
    }

    // [AUTOFIX] V-05: Validar que items sea un array valido antes de procesar
    if (!$errorVenta && (!is_array($items) || empty($items))) {
        if (!empty($_POST['_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'El carrito está vacío o tiene un formato inválido.']);
            exit();
        }
        $errorVenta = 'El carrito está vacío o tiene un formato inválido.';
    }

    // [AUTOFIX] N-02/N-03: Solo procesar si no hay errores de validacion previos
    if (!$errorVenta && !empty($items) && $metodo_pago) {
        // Mutex por mes/año: evita folios duplicados cuando dos cajeros venden simultáneamente
        $mesFolio      = date('m');
        $anioFolio     = date('Y');
        $folioLock     = 'folio_ventas_' . $anioFolio . '_' . $mesFolio;
        $lockAdquirido = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($folioLock) . ", 10)")->fetchColumn();

        if (!$lockAdquirido) {
            $errorVenta = 'El sistema está procesando otra venta. Intenta de nuevo en unos segundos.';
        } else {
        $pdo->beginTransaction();
        try {
            // Validar límite de crédito antes de generar folio
            if ($metodo_pago === 'Credito' && $cliente_id) {
                $stmtLimite = $pdo->prepare("SELECT credito_autorizado, limite_credito FROM clientes WHERE cliente_id = ? AND activo = 1 FOR UPDATE");
                $stmtLimite->execute([$cliente_id]);
                $clienteCredito = $stmtLimite->fetch(PDO::FETCH_ASSOC);
                if (!$clienteCredito) {
                    throw new Exception('Cliente no encontrado o inactivo.');
                }
                if (!$clienteCredito['credito_autorizado']) {
                    throw new Exception('El cliente no tiene crédito autorizado.');
                }
                if (floatval($clienteCredito['limite_credito']) <= 0) {
                    throw new Exception('El límite de crédito del cliente no está configurado.');
                }
                $stmtDeuda = $pdo->prepare("SELECT COALESCE(SUM(saldo_pendiente), 0) FROM creditos WHERE cliente_id = ? AND estado = 'Activo'");
                $stmtDeuda->execute([$cliente_id]);
                $deudaActual = floatval($stmtDeuda->fetchColumn());
                $disponible  = floatval($clienteCredito['limite_credito']) - $deudaActual;
                if ($deudaActual + $total > floatval($clienteCredito['limite_credito']) + 0.005) {
                    throw new Exception('La venta excede el límite de crédito. Disponible: $' . number_format(max(0, $disponible), 2));
                }
            }

            // Generar folio secuencial mensual: NNNN (reinicia cada mes)
            $stmtFolio = $pdo->prepare("
                SELECT COALESCE(MAX(CAST(folio AS UNSIGNED)), 0) + 1
                FROM ventas
                WHERE folio IS NOT NULL
                  AND MONTH(created_at) = ? AND YEAR(created_at) = ?
            ");
            $stmtFolio->execute([$mesFolio, $anioFolio]);
            $numFolio = intval($stmtFolio->fetchColumn());
            $folio = str_pad($numFolio, 4, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("
                INSERT INTO ventas
                (folio,caja_id,cliente_id,usuario_id,subtotal,descuento,comision_terminal,
                 total,metodo_pago,monto_efectivo,monto_terminal,cambio,estado,notas,referencia_transferencia)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'Completada',?,?)
            ");
            $stmt->execute([$folio,$caja['caja_id'],$cliente_id,$_SESSION['usuario_id'],
                            $subtotal,$descuento,$comision_terminal,$total,
                            $metodo_pago,$monto_efectivo,$monto_terminal,$cambio,$notas_venta,
                            $referencia_transferencia]);
            $venta_id = $pdo->lastInsertId();

            foreach ($items as $item) {
                $precioFinal  = floatval($item['precio']);
                $precioOrig   = floatval($item['precio_orig'] ?? $precioFinal);
                $subtotalItem = $item['cantidad'] * $precioFinal;
                $notaAjuste   = trim($item['nota_ajuste'] ?? '');
                $paqId        = (!empty($item['paquete_id']) && intval($item['paquete_id']) > 0) ? intval($item['paquete_id']) : null;

                // [AUTOFIX] SEC-02: Validar precio contra precio_compra de la BD
                // El precio final no puede ser menor al 50% del precio de compra (umbral de seguridad)
                if (empty($paqId)) {
                    $stmtPrecioComp = $pdo->prepare("SELECT precio_compra FROM productos WHERE producto_id = ?");
                    $stmtPrecioComp->execute([$item['producto_id']]);
                    $precioCompraDB = floatval($stmtPrecioComp->fetchColumn());
                    if ($precioCompraDB > 0 && $precioFinal < ($precioCompraDB * 0.5)) {
                        throw new Exception("Precio inválido para el producto. Verifica el carrito.");
                    }
                }

                $pdo->prepare("INSERT INTO venta_productos (venta_id,producto_id,cantidad,precio_unitario,precio_final,descuento,subtotal,paquete_id,nota_ajuste) VALUES (?,?,?,?,?,0,?,?,?)")
                    ->execute([$venta_id,$item['producto_id'],$item['cantidad'],$precioOrig,$precioFinal,$subtotalItem,$paqId,$notaAjuste ?: null]);

                // Bloquear la fila del stock para evitar condición de carrera
                $stmtS = $pdo->prepare("SELECT stock_actual FROM stock_sucursal WHERE producto_id = ? AND sucursal_id = ? FOR UPDATE");
                $stmtS->execute([$item['producto_id'], $_SESSION['sucursal_id']]);
                $stockAnterior = floatval($stmtS->fetchColumn());

                // Validar stock en servidor antes de descontar
                if ($stockAnterior < $item['cantidad']) {
                    throw new Exception("Stock insuficiente para uno de los productos. Verifica el carrito e intenta de nuevo.");
                }

                $stockNuevo = $stockAnterior - $item['cantidad'];
                $pdo->prepare("UPDATE stock_sucursal SET stock_actual = ? WHERE producto_id = ? AND sucursal_id = ?")->execute([$stockNuevo, $item['producto_id'], $_SESSION['sucursal_id']]);

                $motivoMovimiento = 'Venta';
                if ($notaAjuste) {
                    $motivoMovimiento = 'Venta - Ajuste por daño: ' . mb_substr($notaAjuste, 0, 120);
                }
                $pdo->prepare("INSERT INTO movimientos_inventario (producto_id,usuario_id,sucursal_id,tipo,cantidad,stock_anterior,stock_nuevo,motivo) VALUES (?,?,?,'Salida',?,?,?,?)")
                    ->execute([$item['producto_id'],$_SESSION['usuario_id'],$_SESSION['sucursal_id'],$item['cantidad'],$stockAnterior,$stockNuevo,$motivoMovimiento]);
            }

            if ($metodo_pago === 'Credito' && $cliente_id) {
                // [AUTOFIX] BUG-05: Cambiado de INTERVAL 1 DAY (valor de prueba) a INTERVAL 3 DAY
                $pdo->prepare("INSERT INTO creditos (cliente_id,venta_id,monto_total,saldo_pendiente,estado,fecha_limite) VALUES (?,?,?,?,'Activo',DATE_ADD(CURDATE(), INTERVAL 3 DAY))")
                    ->execute([$cliente_id,$venta_id,$total,$total]);
            }

            $pdo->commit();
            $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($folioLock) . ")");
            // AJAX: retornar JSON en lugar de redirigir
            if (!empty($_POST['_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'venta_id' => $venta_id]);
                exit();
            }
            header('Location: nuevaVenta.php?msg=exito&venta_id='.$venta_id);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($folioLock) . ")");
            // [AUTOFIX] SEC-04: Loguear error real; algunos mensajes de excepcion son de negocio (stock) y son seguros
            error_log('[Ferreteria/nuevaVenta] Error venta: ' . $e->getMessage());
            if (!empty($_POST['_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                exit();
            }
            $errorVenta = $e->getMessage();
        }
        } // fin if lockAdquirido
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Venta — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 14px; overflow-y: auto; display: grid; grid-template-columns: 1fr 360px; gap: 12px; }
    .panel-izq { display: flex; flex-direction: column; gap: 10px; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; }
    .card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 10px; }
    .busqueda-wrap { position: relative; }
    .busqueda-wrap input { width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
    .busqueda-wrap input:focus { outline: none; border-color: #14ace7; }
    .dropdown-resultados { display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #e8e8e8; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 100; max-height: 280px; overflow-y: auto; margin-top: 2px; }
    .dropdown-resultados.visible { display: block; }
    .resultado-item { padding: 10px 14px; cursor: pointer; border-bottom: 0.5px solid #f5f5f5; display: flex; justify-content: space-between; align-items: center; }
    .resultado-item:hover { background: #eef8ff; }
    .resultado-item:last-child { border-bottom: none; }
    .resultado-nombre { font-size: 13px; color: #333; font-weight: 600; }
    .resultado-codigo { font-size: 11px; color: #999; }
    .resultado-precio { font-size: 13px; color: #14ace7; font-weight: 700; }
    .stock-badge { font-size: 11px; padding: 2px 7px; border-radius: 99px; font-weight: 600; }
    .stock-ok { background: #e8f5e9; color: #2e7d32; }
    .stock-bajo { background: #fdecea; color: #c0392b; }
    .scanner-row { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
    .scanner-row input { flex: 1; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: monospace; }
    .scanner-row input:focus { outline: none; border-color: #2e7d32; border-width: 2px; }
    .btn-scan-mode { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; white-space: nowrap; }
    .btn-scan-mode.activo { background: #2e7d32; color: white; border-color: #2e7d32; }
    .scan-feedback { font-size: 12px; padding: 4px 10px; border-radius: 99px; display: none; }
    .scan-ok { background: #e8f5e9; color: #2e7d32; }
    .scan-err { background: #fdecea; color: #c0392b; }
    .carrito-tabla { width: 100%; border-collapse: collapse; }
    .carrito-tabla th { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; padding: 7px 8px; text-align: left; border-bottom: 1px solid #eee; }
    .carrito-tabla td { padding: 8px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; color: #444; vertical-align: middle; }
    .carrito-tabla tr:last-child td { border-bottom: none; }
    .qty-input { width: 65px; padding: 5px 8px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; text-align: center; }
    .qty-input:focus { outline: none; border-color: #14ace7; }
    .btn-eliminar-item { background: #fdecea; color: #c0392b; border: none; padding: 4px 9px; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 600; }
    .carrito-vacio { text-align: center; color: #aaa; padding: 24px; font-size: 13px; }
    .paq-row td { background: #fffde7 !important; }
    .rec-paquetes { display: none; background: #fff8e1; border: 1px solid #90caf9; border-radius: 8px; padding: 12px 14px; }
    .rec-titulo { font-size: 12px; font-weight: 700; color: #1565c0; margin-bottom: 8px; }
    .rec-item { background: white; border-radius: 6px; padding: 10px; margin-bottom: 8px; border: 0.5px solid #90caf9; }
    .rec-item:last-child { margin-bottom: 0; }
    .rec-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
    .rec-nombre { font-size: 13px; font-weight: 600; color: #333; }
    .rec-precio { color: #14ace7; font-weight: 700; font-size: 13px; }
    .rec-barra-bg { height: 4px; background: #f0f0f0; border-radius: 99px; margin-bottom: 4px; }
    .rec-barra { height: 4px; border-radius: 99px; }
    .rec-desc { font-size: 11px; color: #888; margin-bottom: 7px; }
    .rec-btn { width: 100%; border: none; padding: 7px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; color: white; }
    .cliente-seleccionado { display: none; background: #eef8ff; border: 1px solid #bbdefb; border-radius: 6px; padding: 8px 12px; font-size: 13px; margin-top: 8px; justify-content: space-between; align-items: center; }
    .btn-quitar-cliente { background: none; border: none; color: #c0392b; cursor: pointer; font-size: 12px; font-weight: 600; }
    .panel-der { display: flex; flex-direction: column; gap: 10px; }
    .resumen { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 16px; }
    .resumen h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 12px; }
    .resumen-fila { display: flex; justify-content: space-between; font-size: 13px; color: #555; margin-bottom: 8px; }
    .resumen-fila.total { border-top: 1px solid #eee; margin-top: 8px; padding-top: 10px; font-size: 18px; font-weight: 700; color: #222; }
    .resumen-fila.cambio-fila { color: #2e7d32; font-weight: 600; }
    .form-group-sm { margin-bottom: 10px; }
    .form-group-sm label { display: block; font-size: 11px; color: #888; margin-bottom: 4px; font-weight: 600; text-transform: uppercase; }
    .form-group-sm input { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .form-group-sm input:focus { outline: none; border-color: #14ace7; }
    .metodos { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-bottom: 10px; }
    .metodo-btn { padding: 8px; border: 2px solid #e8e8e8; border-radius: 6px; text-align: center; cursor: pointer; font-size: 13px; color: #555; background: white; transition: all 0.15s; }
    .metodo-btn:hover { border-color: #14ace7; color: #14ace7; }
    .metodo-btn.selected { border-color: #14ace7; background: #eef8ff; color: #14ace7; font-weight: 600; }
    .campos-pago { display: none; }
    .campos-pago.visible { display: block; }
    .btn-cobrar { width: 100%; background: #14ace7; color: white; border: none; padding: 13px; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; margin-top: 8px; }
    .btn-cobrar:hover { background: #1196cb; }
    .btn-cobrar:disabled { background: #ddd; cursor: not-allowed; }
    .btn-cancelar-venta { width: 100%; background: white; color: #888; border: 1px solid #ddd; padding: 9px; border-radius: 8px; font-size: 13px; cursor: pointer; margin-top: 6px; }
    .btn-mov-caja { width: 100%; background: white; color: #555; border: 1px solid #ddd; padding: 8px; border-radius: 8px; font-size: 12px; cursor: pointer; margin-top: 5px; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .btn-mov-caja:hover { background: #f5f5f5; border-color: #bbb; color: #333; }
    /* Modal movimiento de caja */
    .modal-mov-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 500; align-items: center; justify-content: center; }
    .modal-mov-overlay.visible { display: flex; }
    .modal-mov { background: white; border-radius: 10px; width: 92%; max-width: 400px; box-shadow: 0 20px 60px rgba(0,0,0,0.25); overflow: hidden; }
    .modal-mov-header { padding: 16px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
    .modal-mov-header h3 { font-size: 15px; font-weight: 700; color: #222; margin: 0; }
    .modal-mov-header button { background: none; border: none; font-size: 20px; cursor: pointer; color: #aaa; line-height: 1; }
    .modal-mov-header button:hover { color: #333; }
    .modal-mov-body { padding: 20px; }
    .tipo-toggle { display: flex; gap: 8px; margin-bottom: 16px; }
    .tipo-btn { flex: 1; padding: 10px; border-radius: 8px; border: 2px solid #ddd; font-size: 14px; font-weight: 700; cursor: pointer; background: white; color: #888; transition: all 0.15s; }
    .tipo-btn.ingreso.selected { border-color: #2e7d32; background: #e8f5e9; color: #2e7d32; }
    .tipo-btn.retiro.selected  { border-color: #c0392b; background: #fdecea; color: #c0392b; }
    .tipo-btn:not(.selected):hover { background: #f5f5f5; }
    .mov-field { margin-bottom: 14px; }
    .mov-field label { display: block; font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 5px; }
    .mov-field input, .mov-field textarea { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; font-family: Arial, sans-serif; resize: vertical; }
    .mov-field input:focus, .mov-field textarea:focus { outline: none; border-color: #14ace7; }
    .mov-field textarea { min-height: 70px; }
    .modal-mov-footer { display: flex; gap: 8px; margin-top: 4px; }
    .modal-mov-footer .btn-cancel-mov { flex: 1; background: white; color: #666; border: 1px solid #ddd; padding: 10px; border-radius: 6px; font-size: 13px; cursor: pointer; }
    .modal-mov-footer .btn-confirm-mov { flex: 2; padding: 10px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; border: none; color: white; transition: background 0.15s; }
    .btn-confirm-mov.ingreso { background: #2e7d32; }
    .btn-confirm-mov.ingreso:hover { background: #256128; }
    .btn-confirm-mov.retiro  { background: #c0392b; }
    .btn-confirm-mov.retiro:hover  { background: #a93226; }
    .msg-mov-ok { background: #e8f5e9; color: #2e7d32; padding: 9px 14px; border-radius: 6px; font-size: 12px; border-left: 3px solid #2e7d32; margin-bottom: 10px; }
    .msg-mov-ok.retiro { background: #fdecea; color: #c0392b; border-left-color: #c0392b; }
    .msg-mov-err { background: #fdecea; color: #c0392b; padding: 9px 14px; border-radius: 6px; font-size: 12px; border-left: 3px solid #c0392b; margin-bottom: 10px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; padding: 12px 16px; border-radius: 6px; font-size: 13px; border-left: 3px solid #2e7d32; grid-column: span 2; display: flex; justify-content: space-between; align-items: center; }
    .btn-print-ticket { background: #2e7d32; color: white; border: none; padding: 5px 11px; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 600; margin-left: 10px; }
    #_notifVentaOk { padding: 8px 12px !important; font-size: 12px !important; gap: 0; }
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 200; align-items: center; justify-content: center; }
    .modal-overlay.visible { display: flex; }
    .modal { background: white; border-radius: 10px; width: 90%; max-width: 700px; max-height: 80vh; display: flex; flex-direction: column; overflow: hidden; }
    .modal-header { padding: 16px 20px; border-bottom: 0.5px solid #eee; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h3 { font-size: 16px; font-weight: 600; color: #222; margin: 0; }
    .modal-header button { background: none; border: none; font-size: 20px; cursor: pointer; color: #888; }
    .modal-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
    .modal-filtros { display: flex; gap: 10px; margin-bottom: 14px; }
    .modal-filtros input { flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .modal-filtros input:focus { outline: none; border-color: #14ace7; }
    .modal-filtros select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .inv-tabla { width: 100%; border-collapse: collapse; }
    .inv-tabla th { font-size: 11px; color: #888; text-transform: uppercase; padding: 8px 10px; text-align: left; border-bottom: 1px solid #eee; }
    .inv-tabla td { padding: 9px 10px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    .inv-tabla tr:last-child td { border-bottom: none; }
    .btn-agregar-inv { background: #e3f2fd; color: #1565c0; border: none; padding: 4px 10px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; }
    .btn-agregar-inv:disabled { background: #f5f5f5; color: #aaa; cursor: not-allowed; }
    .sucursal-diferente { font-size: 11px; color: #1565c0; background: #e3f2fd; padding: 2px 7px; border-radius: 99px; margin-left: 5px; }

    /* ── Ticket de impresión ── */
    @page {
        size: 80mm auto;
        margin: 0;
    }
    @media print {
        html, body {
            height: auto !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
        }
        body > * { display: none !important; }
        @page {
            margin: 0;
            size: 58mm auto;
        }
        body {
            margin: 0 !important;
            padding: 0 !important;
        }
        #ticketImprimir {
            display: block !important;
            page-break-after: avoid;
            break-after: avoid;
        }
    }
    #ticketImprimir {
        display: none;
        font-family: 'Courier New', monospace;
        font-size: 11px;
        width: 72mm;
        margin: 0;
        padding: 3mm 4mm 2mm;
    }
    #ticketImprimir .t-centro { text-align: center; }
    #ticketImprimir .t-linea  { border-top: 1px dashed #000; margin: 4px 0; }
    #ticketImprimir .t-fila   { display: flex; justify-content: space-between; }
    #ticketImprimir .t-bold   { font-weight: bold; }
    #ticketImprimir .t-grande { font-size: 13px; font-weight: bold; }
</style>

<!-- Ticket oculto para impresión -->
<div id="ticketImprimir"></div>

<!-- Modal: Movimiento de caja -->
<div class="modal-mov-overlay" id="modalMovCaja">
    <div class="modal-mov">
        <div class="modal-mov-header">
            <h3 id="modalMovTitulo">Movimiento de caja</h3>
            <button onclick="cerrarModalMovCaja()">&#x2715;</button>
        </div>
        <div class="modal-mov-body">
            <form method="POST" onsubmit="return validarMovCaja()">
                <input type="hidden" name="movimiento_caja" value="1">
                <input type="hidden" name="tipo_mov" id="inputTipoMov" value="Ingreso">

                <!-- [AUTOFIX] BUG-04: Token CSRF para proteger el form de movimiento de caja -->
                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="tipo-toggle">
                    <button type="button" id="btnTipoIngreso" class="tipo-btn ingreso selected" onclick="seleccionarTipoMov('Ingreso')">
                        &#8593; Ingreso
                    </button>
                    <button type="button" id="btnTipoRetiro" class="tipo-btn retiro" onclick="seleccionarTipoMov('Retiro')">
                        &#8595; Retiro
                    </button>
                </div>

                <div class="mov-field">
                    <label>Monto *</label>
                    <input type="number" name="monto_mov" id="montoMov" step="0.01" min="0.01" placeholder="0.00" required>
                </div>
                <div class="mov-field">
                    <label>Nota / Motivo * <span style="font-weight:400;color:#aaa;font-size:10px;">(obligatorio)</span></label>
                    <textarea name="nota_mov" id="notaMov" placeholder="Describe el motivo del movimiento..." required></textarea>
                </div>
                <div id="errMovCaja" style="display:none;color:#c0392b;font-size:12px;margin-bottom:10px;"></div>
                <div class="modal-mov-footer">
                    <button type="button" class="btn-cancel-mov" onclick="cerrarModalMovCaja()">Cancelar</button>
                    <button type="submit" class="btn-confirm-mov ingreso" id="btnConfirmarMov">Confirmar ingreso</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>Ferretería Aldrete</h3>
        <p>Cajero / Inventario</p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item" href="inicioCajeroInventario.php">Inicio</a>
        <div class="divider"></div>

        <div class="menu-label">Ventas</div>
        <a class="menu-item active" href="nuevaVenta.php">Nueva venta</a>
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
            <h2>Nueva Venta — Turno #<?= $caja['numero_turno'] ?></h2>
        </div>
        <div class="topbar-right">
            <span>
                <?= htmlspecialchars($_SESSION['nombre_completo']) ?>
                <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span>
            </span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="content">
        <?php if ($errorVenta): ?>
            <div style="background:#fdecea;color:#c0392b;padding:12px 16px;border-radius:6px;font-size:13px;border-left:3px solid #c0392b;grid-column:span 2;margin-bottom:4px;">
                ⚠ <?= htmlspecialchars($errorVenta) ?>
            </div>
        <?php endif; ?>

        <div class="panel-izq">
            <!-- Buscar producto / scanner -->
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <h3 style="margin:0;">Agregar producto</h3>
                    <div style="display:flex;gap:6px;">
                        <button type="button" id="btnModoScanner"
                            class="btn-scan-mode"
                            onclick="toggleModoScanner()">
                            📷 Scanner
                        </button>
                        <button type="button" onclick="abrirInventario()"
                            style="background:#e3f2fd;color:#1565c0;border:none;padding:5px 12px;border-radius:5px;font-size:12px;font-weight:600;cursor:pointer;">
                            Ver inventario
                        </button>
                    </div>
                </div>

                <!-- Búsqueda normal -->
                <div id="modoNormal">
                    <div class="busqueda-wrap">
                        <input type="text" id="inputProducto"
                            placeholder="Escribe código o nombre del producto..."
                            autocomplete="off">
                        <div class="dropdown-resultados" id="dropdownProductos"></div>
                    </div>
                </div>

                <!-- Modo scanner -->
                <div id="modoScanner" style="display:none;">
                    <div class="scanner-row">
                        <input type="text" id="inputScanner"
                            placeholder="Escanea el código de barras..."
                            autocomplete="off"
                            autofocus>
                        <span class="scan-feedback" id="scanFeedback"></span>
                    </div>
                    <div style="font-size:11px;color:#aaa;">
                        El campo se limpia automáticamente después de cada scan. Presiona Enter o escanea para agregar.
                    </div>
                </div>
            </div>

            <!-- Carrito -->
            <div class="card" style="flex:1;">
                <h3>Carrito</h3>
                <div class="carrito-vacio" id="carritoVacio">El carrito está vacío</div>
                <table class="carrito-tabla" id="carritoTabla" style="display:none;">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Precio</th>
                            <th>Cant.</th>
                            <th>Disp.</th>
                            <th>Subtotal</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="carritoBody"></tbody>
                </table>

                <!-- Recomendación paquetes -->
                <div class="rec-paquetes" id="recPaquetes" style="margin-top:10px;">
                    <div class="rec-titulo">📦 Paquetes disponibles con estos productos</div>
                    <div id="recLista"></div>
                </div>
            </div>

        </div>

        <!-- Panel derecho: resumen y pago -->
        <div class="panel-der">
            <div class="resumen">
                <h3>Resumen</h3>
                <div class="resumen-fila"><span>Subtotal</span><span id="resSubtotal">$0.00</span></div>
                <div class="resumen-fila" id="resAhorroRow" style="display:none;">
                    <span style="color:#2e7d32;">Ahorro en promociones</span>
                    <span id="resAhorroPromo" style="color:#2e7d32;">-$0.00</span>
                </div>
                <div class="resumen-fila"><span>Descuento</span><span id="resDescuento" style="color:#2e7d32;">-$0.00</span></div>
                <div class="resumen-fila"><span>Comisión terminal</span><span id="resComision">$0.00</span></div>
                <div class="resumen-fila total"><span>Total</span><span id="resTotal">$0.00</span></div>
                <div class="resumen-ahorro-total" style="display:none;background:#e8f5e9;border-radius:6px;padding:10px 14px;margin-top:10px;text-align:center;">
                    <div style="font-size:11px;color:#2e7d32;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Ahorro total del cliente</div>
                    <div style="font-size:22px;font-weight:700;color:#2e7d32;" id="resAhorroTotal">$0.00</div>
                </div>

                <div style="margin-top:14px;">
                    <div class="form-group-sm">
                        <label>Método de pago</label>
                        <div class="metodos">
                            <button type="button" class="metodo-btn" onclick="seleccionarMetodo('Efectivo',this)">Efectivo</button>
                            <button type="button" class="metodo-btn" onclick="seleccionarMetodo('Terminal',this)">Terminal</button>
                            <button type="button" class="metodo-btn" onclick="seleccionarMetodo('Transferencia',this)">Transferencia</button>
                            <button type="button" class="metodo-btn" onclick="seleccionarMetodo('Mixto',this)">Mixto</button>
                            <button type="button" class="metodo-btn" id="btnCredito" onclick="seleccionarMetodo('Credito',this)" style="display:none;">Crédito</button>
                        </div>
                    </div>

                    <div class="campos-pago" id="camposEfectivo">
                        <div class="form-group-sm">
                            <label>Cantidad recibida</label>
                            <input type="number" id="montoEfectivo" placeholder="0.00" step="0.01" oninput="calcularCambio()">
                        </div>
                        <div class="resumen-fila cambio-fila"><span>Cambio</span><span id="resCambio">$0.00</span></div>
                    </div>

                    <div class="campos-pago" id="camposTerminal">
                        <div class="form-group-sm">
                            <label>Comisión terminal (%)</label>
                            <input type="number" id="porcComision" value="<?= number_format(floatval($sucursalTicket['comision_terminal_pct'] ?? 0), 2) ?>" readonly style="background:#f5f5f5;color:#888;cursor:not-allowed;">
                        </div>
                    </div>

                    <div class="campos-pago" id="camposTransferencia">
                        <div class="form-group-sm">
                            <label>Referencia bancaria *</label>
                            <input type="text" id="transferReferencia" placeholder="Folio o número de referencia" oninput="verificarCobrar()">
                        </div>
                        <div class="form-group-sm">
                            <label>Datos para la transferencia</label>
                            <?php
                            $tb = trim($sucursalTicket['banco']               ?? '');
                            $tt = trim($sucursalTicket['titular_cuenta']      ?? '');
                            $tc = trim($sucursalTicket['numero_cuenta']       ?? '');
                            $tl = trim($sucursalTicket['clabe_interbancaria'] ?? '');
                            $ta = trim($sucursalTicket['alias_tarjeta']       ?? '');
                            ?>
                            <div style="font-size:12px;line-height:1.8;background:#f0f8ff;border:1px solid #b3e0f7;border-radius:6px;padding:10px 12px;color:#333;">
                                <?php if ($tb || $tt || $tc || $tl): ?>
                                    <?php if ($tb): ?><div><strong>Banco:</strong> <?= htmlspecialchars($tb) ?></div><?php endif; ?>
                                    <?php if ($tt): ?><div><strong>Titular:</strong> <?= htmlspecialchars($tt) ?></div><?php endif; ?>
                                    <?php if ($tc): ?><div><strong>No. cuenta:</strong> <?= htmlspecialchars($tc) ?></div><?php endif; ?>
                                    <?php if ($tl): ?><div><strong>CLABE:</strong> <?= htmlspecialchars($tl) ?></div><?php endif; ?>
                                    <?php if ($ta): ?><div><strong>Alias:</strong> <?= htmlspecialchars($ta) ?></div><?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#aaa;">Sin datos bancarios configurados.<br>Agrégalos en Configuración → Sucursal.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="campos-pago" id="camposMixto">
                        <div class="form-group-sm">
                            <label>Monto efectivo</label>
                            <input type="number" id="mixtoEfectivo" placeholder="0.00" step="0.01" class="js-zero-default" oninput="calcularMixto()">
                        </div>
                        <div class="form-group-sm">
                            <label>Cargo a terminal <span style="font-size:11px;color:#888;font-weight:400;">(resto + comisión)</span></label>
                            <input type="number" id="mixtoTerminal" placeholder="0.00" step="0.01" readonly
                                style="background:#f5f5f5;color:#555;cursor:not-allowed;">
                        </div>
                        <div class="form-group-sm">
                            <label>Comisión terminal (%)</label>
                            <input type="number" id="mixtoComision" value="<?= number_format(floatval($sucursalTicket['comision_terminal_pct'] ?? 0), 2) ?>" readonly style="background:#f5f5f5;color:#888;cursor:not-allowed;">
                        </div>
                    </div>
                </div>

                <form method="POST" id="formVenta">
                    <input type="hidden" name="confirmar_venta" value="1">
                    <input type="hidden" name="items" id="inputItems">
                    <input type="hidden" name="cliente_id" id="inputClienteId">
                    <input type="hidden" name="metodo_pago" id="inputMetodoPago">
                    <input type="hidden" name="monto_efectivo" id="inputMontoEfectivo">
                    <input type="hidden" name="monto_terminal" id="inputMontoTerminal">
                    <input type="hidden" name="comision_terminal" id="inputComisionTerminal">
                    <input type="hidden" name="referencia_transferencia" id="inputReferenciaTransferencia">
                    <input type="hidden" name="descuento" id="inputDescuento">
                    <input type="hidden" name="subtotal" id="inputSubtotal">
                    <input type="hidden" name="total" id="inputTotal">
                    <input type="hidden" name="cambio" id="inputCambio">
                    <input type="hidden" name="notas_venta" id="inputNotasVenta">
                    <button type="submit" class="btn-cobrar" id="btnCobrar" disabled onclick="return prepararVenta()">
                        Cobrar
                    </button>
                </form>
                <button class="btn-cancelar-venta" onclick="limpiarVenta()">Cancelar venta</button>
                <button class="btn-mov-caja" type="button" onclick="abrirModalMovCaja()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12l7-7 7 7"/></svg>
                    Movimiento de caja
                </button>

                <?php if ($msgMovCaja === 'ingreso_ok'): ?>
                    <div class="msg-mov-ok" id="msgMovBanner">Ingreso registrado correctamente.</div>
                <?php elseif ($msgMovCaja === 'retiro_ok'): ?>
                    <div class="msg-mov-ok retiro" id="msgMovBanner">Retiro registrado correctamente.</div>
                <?php elseif ($msgMovCaja === 'error'): ?>
                    <div class="msg-mov-err" id="msgMovBanner">Completa todos los campos correctamente.</div>
                <?php endif; ?>

                <!-- Panel: Ajuste por daño -->
                <div style="border-top:1px solid #eee;margin-top:10px;padding-top:10px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#555;font-weight:600;user-select:none;">
                        <input type="checkbox" id="chkAjusteDano" onchange="togglePanelAjuste(this.checked)"
                            style="width:15px;height:15px;accent-color:#e65100;cursor:pointer;">
                        ⚠ Ajuste de precio por daño
                    </label>
                    <div id="panelAjusteDano" style="display:none;margin-top:10px;padding:12px;background:#fff8f0;border:1px solid #f0c080;border-radius:8px;">
                        <div style="font-size:12px;color:#888;margin-bottom:8px;">Selecciona el producto del carrito al que deseas aplicar el ajuste:</div>
                        <select id="selProductoAjuste" onchange="seleccionarProductoAjuste()"
                            style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;margin-bottom:10px;">
                            <option value="">— Elige un producto —</option>
                        </select>
                        <div id="panelCamposAjuste" style="display:none;">
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px;align-items:end;">
                                <div>
                                    <label style="font-size:11px;color:#888;font-weight:600;display:block;margin-bottom:3px;">% DESCUENTO</label>
                                    <input type="number" id="inputPctAjuste" min="0" max="100" step="0.1"
                                        placeholder="Ej. 20"
                                        style="width:100%;padding:7px 8px;border:1px solid #ddd;border-radius:6px;font-size:13px;"
                                        oninput="ajustePctCambia(this.value)">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#888;font-weight:600;display:block;margin-bottom:3px;">PRECIO AJUSTADO</label>
                                    <input type="number" id="inputPrecioAjuste" min="0" step="0.01"
                                        placeholder="Ej. 80.00"
                                        style="width:100%;padding:7px 8px;border:1px solid #ddd;border-radius:6px;font-size:13px;"
                                        oninput="ajustePrecioCambia(this.value)">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#888;font-weight:600;display:block;margin-bottom:3px;">CANT. DAÑADA</label>
                                    <input type="number" id="inputCantAjuste" min="1" step="1"
                                        placeholder="Ej. 2"
                                        style="width:100%;padding:7px 8px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                                </div>
                            </div>
                            <div id="infoMinPrecio" style="font-size:11px;color:#888;margin-bottom:8px;"></div>
                            <label style="font-size:11px;color:#888;font-weight:600;display:block;margin-bottom:3px;">NOTA OBLIGATORIA</label>
                            <textarea id="textareaNotaAjuste" rows="2"
                                placeholder="¿Por qué se ajusta el precio? Ej: Producto golpeado, caja dañada..."
                                style="width:100%;padding:7px 8px;border:1px solid #ddd;border-radius:6px;font-size:12px;resize:none;"
                                oninput="actualizarNotaAjuste(this.value)"></textarea>
                            <button type="button" onclick="aplicarAjuste()"
                                style="width:100%;margin-top:8px;background:#e65100;color:white;border:none;padding:9px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">
                                Aplicar ajuste
                            </button>
                            <button type="button" onclick="quitarAjusteProducto()"
                                style="width:100%;margin-top:4px;background:white;color:#888;border:1px solid #ddd;padding:7px;border-radius:6px;font-size:12px;cursor:pointer;">
                                Quitar ajuste de este producto
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cliente -->
            <div class="card">
                <h3>Cliente (opcional)</h3>
                <div class="busqueda-wrap">
                    <input type="text" id="inputCliente"
                        placeholder="Buscar por nombre..."
                        autocomplete="off">
                    <div class="dropdown-resultados" id="dropdownClientes"></div>
                </div>
                <div class="cliente-seleccionado" id="clienteSeleccionado">
                    <div>
                        <strong id="clienteNombre"></strong>
                        <span id="clienteTelefono" style="font-size:12px;color:#999;margin-left:8px;"></span>
                        <span id="clienteDescuento" style="font-size:12px;color:#14ace7;margin-left:8px;"></span>
                    </div>
                    <button class="btn-quitar-cliente" onclick="quitarCliente()">Quitar</button>
                </div>
                <div id="clienteDescuentoConfig" style="display:none;margin-top:10px;padding:10px 12px;background:#f8fbff;border:1px solid #dbeafe;border-radius:6px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#444;margin-bottom:8px;">
                        <input type="checkbox" id="aplicarDescCliente" checked onchange="recalcularTodo()">
                        Aplicar descuento del cliente
                    </label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <span style="font-size:12px;color:#666;">Porcentaje a aplicar</span>
                        <input type="number" id="porcDescCliente" value="" placeholder="0" min="0" step="0.1" style="width:90px;padding:7px 9px;border:1px solid #ddd;border-radius:6px;" oninput="ajustarDescuentoCliente()" onblur="clampearDescuentoCliente()">
                        <span id="clienteDescMax" style="font-size:12px;color:#888;"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal inventario -->
<div class="modal-overlay" id="modalInventario">
    <div class="modal">
        <div class="modal-header">
            <h3>Consultar inventario</h3>
            <button onclick="cerrarInventario()">&#x2715;</button>
        </div>
        <div class="modal-body">
            <div class="modal-filtros">
                <input type="text" id="invBuscar" placeholder="Buscar producto..." oninput="buscarInventario()">
                <select id="invSucursal" onchange="buscarInventario()">
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?= $s['sucursal_id'] ?>" <?= $s['sucursal_id']==$_SESSION['sucursal_id']?'selected':'' ?>>
                            <?= htmlspecialchars($s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="invResultados">
                <p style="text-align:center;color:#aaa;font-size:13px;padding:20px;">Busca un producto para ver el inventario.</p>
            </div>
        </div>
    </div>
</div>

<script>
// ── Estado global ────────────────────────────────────────────────────────────
let carrito           = [];
let clienteActual     = null;
let metodoPago        = null;
let paquetesGlobales  = [];
let productosGlobales = [];
let clientesGlobales  = [];
let modoScannerActivo = false;
let scannerTimer      = null;

const miSucursalId    = <?= intval($_SESSION['sucursal_id']) ?>;
const promoByProdId   = <?= json_encode($promosByProdId) ?>;
const datosTicket  = <?= json_encode([
    'nombre'           => $sucursalTicket['nombre']           ?? 'Ferretería Aldrete',
    'rfc'              => $sucursalTicket['rfc']              ?? '',
    'direccion'        => $sucursalTicket['direccion']        ?? '',
    'telefono'         => $sucursalTicket['telefono']         ?? '',
    'datos_ticket'     => $sucursalTicket['datos_ticket']     ?? '',
    'ticket_logo'      => $sucursalTicket['ticket_logo']      ?? null,
    'ticket_font_size' => intval($sucursalTicket['ticket_font_size'] ?? 12),
    'ticket_ancho_mm'  => intval($sucursalTicket['ticket_ancho_mm']  ?? 58),
    'ticket_pie'       => $sucursalTicket['ticket_pie']       ?? '',
]) ?>;
const cajeroNombre = <?= json_encode($_SESSION['nombre_completo']) ?>;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

// ── Movimiento de caja ────────────────────────────────────────────────────────
function abrirModalMovCaja() {
    seleccionarTipoMov('Ingreso');
    document.getElementById('montoMov').value = '';
    document.getElementById('notaMov').value  = '';
    document.getElementById('errMovCaja').style.display = 'none';
    document.getElementById('modalMovCaja').classList.add('visible');
    setTimeout(() => document.getElementById('montoMov').focus(), 100);
}
function cerrarModalMovCaja() {
    document.getElementById('modalMovCaja').classList.remove('visible');
}
function seleccionarTipoMov(tipo) {
    document.getElementById('inputTipoMov').value = tipo;
    const btnI = document.getElementById('btnTipoIngreso');
    const btnR = document.getElementById('btnTipoRetiro');
    const btnC = document.getElementById('btnConfirmarMov');
    btnI.classList.toggle('selected', tipo === 'Ingreso');
    btnR.classList.toggle('selected', tipo === 'Retiro');
    btnC.className = 'btn-confirm-mov ' + (tipo === 'Ingreso' ? 'ingreso' : 'retiro');
    btnC.textContent = tipo === 'Ingreso' ? 'Confirmar ingreso' : 'Confirmar retiro';
    document.getElementById('modalMovTitulo').textContent =
        tipo === 'Ingreso' ? 'Ingreso a caja' : 'Retiro de caja';
}
function validarMovCaja() {
    const monto = parseFloat(document.getElementById('montoMov').value || 0);
    const nota  = document.getElementById('notaMov').value.trim();
    const err   = document.getElementById('errMovCaja');
    if (!monto || monto <= 0) {
        err.textContent = 'Ingresa un monto válido mayor a cero.';
        err.style.display = 'block';
        return false;
    }
    if (!nota) {
        err.textContent = 'La nota es obligatoria. Describe el motivo del movimiento.';
        err.style.display = 'block';
        return false;
    }
    return true;
}
document.getElementById('modalMovCaja').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalMovCaja();
});
// Auto-ocultar mensaje de resultado tras 5 segundos
window.addEventListener('DOMContentLoaded', () => {
    const banner = document.getElementById('msgMovBanner');
    if (banner) setTimeout(() => { banner.style.transition='opacity .5s'; banner.style.opacity='0'; setTimeout(()=>banner.remove(),500); }, 5000);
});

// ── Normalizar texto: minúsculas + sin acentos (ñ→n, é→e, etc.) ─────────────
function normalizar(str) {
    return String(str || '').toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

// ── Cargar productos, paquetes y clientes en paralelo al iniciar ─────────────
(function() {
    const inputProd = document.getElementById('inputProducto');
    const placeholderOrig = inputProd.placeholder;
    inputProd.disabled = true;
    inputProd.placeholder = 'Cargando catálogo...';

    Promise.all([
        fetch('nuevaVenta.php?get_productos_all=1').then(r => r.json()),
        fetch('nuevaVenta.php?get_paquetes=1').then(r => r.json()),
        fetch('nuevaVenta.php?get_clientes_all=1').then(r => r.json())
    ]).then(([prods, paqs, clis]) => {
        productosGlobales = prods.map(p => ({
            ...p,
            producto_id:   parseInt(p.producto_id),
            precio_venta:  parseFloat(p.precio_venta),
            precio_compra: parseFloat(p.precio_compra || 0),
            stock_actual:  parseFloat(p.stock_actual)
        }));
        paquetesGlobales = paqs.map(paq => ({
            ...paq,
            paquete_id:     parseInt(paq.paquete_id),
            precio_paquete: parseFloat(paq.precio_paquete),
            productos: paq.productos.map(p => ({
                ...p,
                producto_id:        parseInt(p.producto_id),
                cantidad_requerida: parseFloat(p.cantidad_requerida),
                stock_actual:       parseFloat(p.stock_actual)
            }))
        }));
        clientesGlobales = clis.map(c => ({
            ...c,
            cliente_id:         parseInt(c.cliente_id),
            descuento_fijo:     parseFloat(c.descuento_fijo),
            credito_autorizado: parseInt(c.credito_autorizado)
        }));

        // Habilitar input y re-disparar búsqueda si el usuario ya escribió algo
        inputProd.disabled = false;
        inputProd.placeholder = placeholderOrig;
        if (inputProd.value.trim()) {
            inputProd.dispatchEvent(new Event('input'));
        }
    }).catch(() => {
        inputProd.disabled = false;
        inputProd.placeholder = placeholderOrig;
    });
})();

// ── Modo scanner ─────────────────────────────────────────────────────────────
function toggleModoScanner() {
    modoScannerActivo = !modoScannerActivo;
    document.getElementById('modoNormal').style.display  = modoScannerActivo ? 'none' : 'block';
    document.getElementById('modoScanner').style.display = modoScannerActivo ? 'block' : 'none';
    document.getElementById('btnModoScanner').classList.toggle('activo', modoScannerActivo);
    if (modoScannerActivo) {
        document.getElementById('inputScanner').focus();
    }
}

// Scanner: detecta Enter (mayoría de lectores envían Enter al final)
document.getElementById('inputScanner').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const codigo = this.value.trim();
        if (!codigo) return;
        procesarScan(codigo);
        this.value = '';
    }
});

// Scanner: también detecta cuando deja de recibir input (lectura rápida del scanner)
document.getElementById('inputScanner').addEventListener('input', function() {
    clearTimeout(scannerTimer);
    const val = this.value.trim();
    if (!val) return;
    // Si el input recibe muchos caracteres muy rápido (scanner físico), procesar automáticamente
    scannerTimer = setTimeout(() => {
        if (document.getElementById('inputScanner').value.trim().length > 3) {
            procesarScan(document.getElementById('inputScanner').value.trim());
            document.getElementById('inputScanner').value = '';
        }
    }, 450);
});

function procesarScan(codigo) {
    const feedback = document.getElementById('scanFeedback');
    feedback.style.display = 'inline';
    feedback.className     = 'scan-feedback';
    feedback.textContent   = '⏳ Buscando...';

    fetch(`nuevaVenta.php?scan_codigo=${encodeURIComponent(codigo)}`)
        .then(r => r.json())
        .then(prod => {
            if (!prod) {
                feedback.className   = 'scan-feedback scan-err';
                feedback.textContent = `❌ No encontrado: ${codigo}`;
                setTimeout(() => { feedback.style.display = 'none'; }, 2000);
                return;
            }
            if (prod.stock_actual <= 0) {
                feedback.className   = 'scan-feedback scan-err';
                feedback.textContent = `❌ Sin stock: ${prod.nombre_producto}`;
                setTimeout(() => { feedback.style.display = 'none'; }, 2000);
                return;
            }
            agregarProducto(prod.producto_id, prod.nombre_producto, prod.precio_venta, prod.stock_actual, prod.tipo_venta);
            feedback.className   = 'scan-feedback scan-ok';
            feedback.textContent = `✅ ${prod.nombre_producto}`;
            setTimeout(() => { feedback.style.display = 'none'; }, 1500);
        })
        .catch(() => {
            feedback.className   = 'scan-feedback scan-err';
            feedback.textContent = '❌ Error de conexión';
            setTimeout(() => { feedback.style.display = 'none'; }, 2000);
        });
}

// ── Búsqueda local: filtra productosGlobales y paquetesGlobales en JS ────────
let toProd;
document.getElementById('inputProducto').addEventListener('input', function() {
    clearTimeout(toProd);
    const val = this.value.trim();
    if (!val) { document.getElementById('dropdownProductos').classList.remove('visible'); return; }

    // Sin debounce pesado: 60ms solo para no disparar en cada tecla de un paste
    toProd = setTimeout(() => {
        const q = normalizar(val);

        // Filtrar productos localmente
        const productos = productosGlobales
            .filter(p => normalizar(p.codigo).includes(q) || normalizar(p.nombre_producto).includes(q))
            .slice(0, 10);

        // Filtrar paquetes localmente y calcular disponibilidad
        const paquetes = paquetesGlobales
            .filter(paq => normalizar(paq.codigo).includes(q) || normalizar(paq.nombre).includes(q))
            .map(paq => {
                const maxCombos = calcularStockCombo(paq.productos);
                return {
                    ...paq,
                    disponible: maxCombos > 0,
                    maxCombos,
                    productos: paq.productos.map(pp => ({ ...pp, cantidad_req: pp.cantidad_requerida }))
                };
            });

        mostrarResultadosCombinados(productos, paquetes);
    }, 60);
});

function mostrarResultadosCombinados(productos, paquetes) {
    const drop = document.getElementById('dropdownProductos');
    let html   = '';

    paquetes.forEach(paq => {
        const cls   = paq.disponible ? 'stock-ok' : 'stock-bajo';
        const label = paq.disponible
            ? `${paq.maxCombos} combo${paq.maxCombos !== 1 ? 's' : ''} disponible${paq.maxCombos !== 1 ? 's' : ''}`
            : 'Sin stock';
        // [AUTOFIX] OBS-01: Pasar solo el paquete_id (numerico) en lugar de JSON serializado con comillas
        // para evitar que un nombre con apostrofe (ej. "Tornillo 3/4'") rompa el parse en agregarPaquete()
        html += `<div class="resultado-item" style="background:#fffde7;"
            onclick="${paq.disponible
                ? `agregarPaquete(${paq.paquete_id})`
                : 'alert(\"Stock insuficiente\")'}" >
            <div>
                <div class="resultado-nombre">📦 ${esc(paq.nombre)}</div>
                <div class="resultado-codigo">${esc(paq.codigo)} · ${paq.productos.length} productos</div>
            </div>
            <div style="text-align:right;">
                <div class="resultado-precio">$${parseFloat(paq.precio_paquete).toFixed(2)}</div>
                <span class="stock-badge ${cls}">${label}</span>
            </div>
        </div>`;
    });

    productos.forEach(p => {
        const cls   = p.stock_actual > 0 ? 'stock-ok' : 'stock-bajo';
        const label = p.stock_actual > 0 ? `Stock: ${parseFloat(p.stock_actual).toFixed(p.tipo_venta==='Suelto'?3:0)}` : 'Sin stock';
        html += `<div class="resultado-item"
            onclick="agregarProducto(${p.producto_id},'${esc(p.nombre_producto)}',${p.precio_venta},${p.stock_actual},'${p.tipo_venta}',${parseFloat(p.precio_compra||0)},'${esc(p.unidad_medida||'')}',${parseFloat(p.precio_mayoreo||0)})">
            <div>
                <div class="resultado-nombre">${esc(p.nombre_producto)}</div>
                <div class="resultado-codigo">${esc(p.codigo)}</div>
            </div>
            <div style="text-align:right;">
                <div class="resultado-precio">$${parseFloat(p.precio_venta).toFixed(2)}</div>
                <span class="stock-badge ${cls}">${label}</span>
            </div>
        </div>`;
    });

    if (!html) html = '<div style="padding:14px;text-align:center;color:#aaa;font-size:13px;">No se encontraron resultados</div>';
    drop.innerHTML = html;
    drop.classList.add('visible');
}

// ── Agregar producto ─────────────────────────────────────────────────────────
function agregarProducto(id, nombre, precio, stock, tipo, precioCompra, unidad, precioMayoreo) {
    id    = parseInt(id);
    stock = parseFloat(stock);
    // Bug #6: Mensaje claro al hacer click en producto sin stock en el catálogo
    if (stock <= 0) { alert('No hay stock disponible para este producto.'); return; }

    // Aplicar promoción si existe
    const promo         = promoByProdId[id] || null;
    const precioNormal  = parseFloat(precio);
    const precioFinal   = promo ? promo.precio_promo : precioNormal;

    // Stock total ya ocupado por todas las filas de este producto en el carrito
    const totalEnCarrito = carrito.reduce((sum, it) =>
        it.producto_id === id ? sum + parseFloat(it.cantidad) : sum, 0);
    if (totalEnCarrito >= stock) { alert(`Stock máximo: ${stock}`); return; }

    // Incrementar solo una fila sin ajuste de daño; si todas están dañadas, crear fila nueva limpia
    const existeLimpio = carrito.find(it => it.producto_id === id && !it.ajuste_activo);
    if (existeLimpio) {
        existeLimpio.cantidad++;
    } else {
        carrito.push({
            producto_id:    id,
            nombre,
            precio:         precioFinal,
            precio_normal:  precioNormal,
            tiene_promo:    !!promo,
            promo_id:       promo ? promo.promo_id : null,
            promo_desc:     promo ? (promo.descripcion || '') : '',
            precio_compra:  parseFloat(precioCompra||0),
            precio_mayoreo: parseFloat(precioMayoreo||0),
            es_mayoreo:     false,
            ajuste_activo:  false,
            precio_ajuste:  null,
            nota_ajuste:    '',
            cantidad:       1,
            stock,
            tipo,
            unidad:         unidad||''
        });
    }
    document.getElementById('inputProducto').value = '';
    document.getElementById('dropdownProductos').classList.remove('visible');
    renderCarrito();
    recalcularTodo();
    verificarRecomendaciones();
    if (document.getElementById('chkAjusteDano').checked) actualizarSelectProductos();
}

// ── Stock disponible para un paquete (mínimo de floor(stock/qty_req) por producto) ──
function calcularStockCombo(productos) {
    if (!productos || !productos.length) return 0;
    return Math.floor(Math.min(...productos.map(p => {
        const req   = parseFloat(p.cantidad_requerida || p.cantidad_req || 1);
        const stock = parseFloat(p.stock_actual ?? p.stock ?? 0);
        return req > 0 ? stock / req : 0;
    })));
}

// ── Agregar paquete ──────────────────────────────────────────────────────────
function agregarPaquete(paq) {
    // [AUTOFIX] OBS-01: aceptar ID numerico (desde el dropdown) — busca en paquetesGlobales
    if (typeof paq === 'number') {
        paq = paquetesGlobales.find(p => p.paquete_id === paq);
        if (!paq) return;
    }
    if (typeof paq === 'string') {
        try { paq = JSON.parse(paq.replace(/'/g, '"')); } catch(e) { return; }
    }
    const maxCombos = calcularStockCombo(paq.productos);
    if (maxCombos < 1) {
        alert('No hay stock suficiente para armar ni un combo de "' + paq.nombre + '".');
        return;
    }
    const ids = paq.productos.map(p => parseInt(p.producto_id));
    carrito   = carrito.filter(i => i.tipo === 'paquete' || !ids.includes(parseInt(i.producto_id)));
    carrito.push({
        producto_id:       null,
        paquete_id:        parseInt(paq.paquete_id),
        nombre:            '📦 ' + paq.nombre,
        precio:            parseFloat(paq.precio_paquete),
        cantidad:          1,
        stock:             maxCombos,
        tipo:              'paquete',
        productos_paquete: paq.productos.map(p => ({
            producto_id:        parseInt(p.producto_id),
            nombre_producto:    p.nombre_producto,
            cantidad_requerida: parseFloat(p.cantidad_requerida || p.cantidad_req || 1)
        }))
    });
    document.getElementById('inputProducto').value = '';
    document.getElementById('dropdownProductos').classList.remove('visible');
    renderCarrito();
    recalcularTodo();
    verificarRecomendaciones();
}

// ── Render carrito ───────────────────────────────────────────────────────────
function renderCarrito() {
    const body  = document.getElementById('carritoBody');
    const tabla = document.getElementById('carritoTabla');
    const vacio = document.getElementById('carritoVacio');

    if (!carrito.length) { tabla.style.display='none'; vacio.style.display='block'; return; }
    tabla.style.display = 'table';
    vacio.style.display = 'none';

    body.innerHTML = carrito.map((item, i) => {
        if (item.tipo === 'paquete') {
            const subNames  = item.productos_paquete.map(p => `${p.nombre_producto}×${p.cantidad_requerida}`).join(', ');
            const stockBadgeClass = item.cantidad >= item.stock ? 'stock-bajo' : 'stock-ok';
            return `<tr class="paq-row">
                <td>
                    <strong>${esc(item.nombre)}</strong>
                    <div style="font-size:11px;color:#aaa;">${esc(subNames)}</div>
                </td>
                <td>$${item.precio.toFixed(2)}</td>
                <td>
                    <input class="qty-input" type="number" value="${item.cantidad}"
                        min="1" step="1" max="${item.stock}" inputmode="numeric"
                        oninput="sanitizarCantCarrito(${i},this)"
                        onchange="cambiarCantidad(${i},this.value)">
                </td>
                <td><span class="stock-badge ${stockBadgeClass}">${item.stock} combo${item.stock !== 1 ? 's' : ''}</span></td>
                <td>$${(item.precio * item.cantidad).toFixed(2)}</td>
                <td><button class="btn-eliminar-item" onclick="eliminarItem(${i})">Quitar</button></td>
            </tr>`;
        }
        const esSuelto  = item.tipo === 'Suelto';
        const qtyStep   = esSuelto ? '0.001' : '1';
        const qtyMin    = esSuelto ? '0.001' : '1';
        const qtyMode   = esSuelto ? 'decimal' : 'numeric';
        const stockDisp = esSuelto ? parseFloat(item.stock).toFixed(3).replace(/\.?0+$/,'') : Math.floor(item.stock);
        const cantDisp  = esSuelto ? parseFloat(item.cantidad).toFixed(3).replace(/\.?0+$/,'') : item.cantidad;
        const tieneAjuste  = item.ajuste_activo === true && item.precio_ajuste !== null;
        const tienePromo   = item.tiene_promo === true && !item.es_mayoreo;
        const tieneMayoreo = item.es_mayoreo === true;
        const precioFinal  = tieneAjuste ? item.precio_ajuste : item.precio;
        const precioBase   = item.precio;
        const mayoreoDisp  = item.precio_mayoreo > 0;

        return `<tr>
            <td>
                ${esc(item.nombre)}
                ${tienePromo ? (() => {
                    const pctPromo   = ((1 - item.precio / item.precio_normal) * 100).toFixed(1);
                    const ahorroUnit = (item.precio_normal - item.precio).toFixed(2);
                    const desc       = item.promo_desc ? ` · ${item.promo_desc}` : '';
                    return `<div style="font-size:10px;color:#2e7d32;margin-top:2px;">🏷 Promoción${desc} &nbsp;·&nbsp; -${pctPromo}% (-$${ahorroUnit}/u)</div>`;
                })() : ''}
                ${tieneMayoreo ? (() => {
                    const pctMay     = ((1 - item.precio_mayoreo / item.precio_normal) * 100).toFixed(1);
                    const ahorroUnit = (item.precio_normal - item.precio_mayoreo).toFixed(2);
                    return `<div style="font-size:10px;color:#6a1b9a;margin-top:2px;">📦 Precio mayoreo &nbsp;·&nbsp; -${pctMay}% (-$${ahorroUnit}/u)</div>`;
                })() : ''}
                ${tieneAjuste ? (() => {
                    const pctDesc   = ((1 - item.precio_ajuste / precioBase) * 100).toFixed(1);
                    const montoDesc = (precioBase - item.precio_ajuste).toFixed(2);
                    return `<div style="font-size:10px;color:#e65100;margin-top:2px;">⚠ Ajuste por daño &nbsp;·&nbsp; -${pctDesc}% (-$${montoDesc})</div>`;
                })() : ''}
            </td>
            <td>
                ${tienePromo ? `<span style="text-decoration:line-through;color:#aaa;font-size:11px;display:block;">$${parseFloat(item.precio_normal).toFixed(2)}</span>` : ''}
                ${tieneMayoreo ? `<span style="text-decoration:line-through;color:#aaa;font-size:11px;display:block;">$${parseFloat(item.precio_normal).toFixed(2)}</span>` : ''}
                <span style="${tieneAjuste?'text-decoration:line-through;color:#aaa;font-size:11px;display:block;':''}${(tienePromo||tieneMayoreo)&&!tieneAjuste?'color:#6a1b9a;font-weight:700;':''}">$${parseFloat(precioBase).toFixed(2)}</span>
                ${tieneAjuste ? `<span style="color:#c0392b;font-weight:700;">$${parseFloat(precioFinal).toFixed(2)}</span>` : ''}
                ${mayoreoDisp ? `<button type="button" onclick="toggleMayoreo(${i})"
                    style="margin-top:4px;display:block;padding:2px 7px;border-radius:99px;font-size:10px;font-weight:700;cursor:pointer;border:1px solid ${tieneMayoreo?'#6a1b9a':'#bbb'};background:${tieneMayoreo?'#f3e5f5':'#f5f5f5'};color:${tieneMayoreo?'#6a1b9a':'#888'};">
                    📦 ${tieneMayoreo ? 'Mayoreo ✓' : 'Mayoreo'}
                </button>` : ''}
            </td>
            <td>
                <div style="display:flex;align-items:center;gap:5px;">
                    <input class="qty-input" type="number" value="${cantDisp}"
                        min="${qtyMin}" step="${qtyStep}" max="${item.stock}" inputmode="${qtyMode}"
                        oninput="sanitizarCantCarrito(${i},this)"
                        onchange="cambiarCantidad(${i},this.value)">
                    ${item.unidad ? `<span style="font-size:11px;color:#888;white-space:nowrap;">${esc(item.unidad)}</span>` : ''}
                </div>
            </td>
            <td><span class="stock-badge ${item.cantidad>=item.stock?'stock-bajo':'stock-ok'}">${stockDisp}</span></td>
            <td>$${(item.cantidad*precioFinal).toFixed(2)}</td>
            <td><button class="btn-eliminar-item" onclick="eliminarItem(${i})">Quitar</button></td>
        </tr>`;
    }).join('');
}

// ── Ajuste de precio por daño ────────────────────────────────────────────────
let idxAjusteActual = -1;

function togglePanelAjuste(activo) {
    document.getElementById('panelAjusteDano').style.display = activo ? 'block' : 'none';
    if (!activo) {
        // Si se desactiva el checkbox, quitar ajustes de todos los productos
        carrito.forEach(item => { item.ajuste_activo = false; item.precio_ajuste = null; item.nota_ajuste = ''; });
        renderCarrito(); recalcularTodo();
    } else {
        actualizarSelectProductos();
    }
}

function resetPanelAjuste() {
    // Solo limpia la UI del panel — no toca ajuste_activo de ningún item del carrito
    idxAjusteActual = -1;
    document.getElementById('panelCamposAjuste').style.display = 'none';
    document.getElementById('selProductoAjuste').value         = '';
    document.getElementById('inputPrecioAjuste').value         = '';
    document.getElementById('inputPctAjuste').value            = '';
    document.getElementById('inputCantAjuste').value           = '';
    document.getElementById('textareaNotaAjuste').value        = '';
}

function actualizarSelectProductos() {
    const sel = document.getElementById('selProductoAjuste');
    const idx = idxAjusteActual;
    sel.innerHTML = '<option value="">— Elige un producto —</option>' +
        carrito.map((item, i) => item.tipo !== 'paquete'
            ? `<option value="${i}" ${i===idx?'selected':''}>${esc(item.nombre)} — $${item.precio.toFixed(2)}</option>`
            : '').join('');
    if (idx >= 0) seleccionarProductoAjuste();
}

function seleccionarProductoAjuste() {
    const sel = document.getElementById('selProductoAjuste');
    const i   = parseInt(sel.value);
    const panel = document.getElementById('panelCamposAjuste');
    if (isNaN(i) || i < 0) { panel.style.display = 'none'; idxAjusteActual = -1; return; }
    idxAjusteActual = i;
    const item      = carrito[i];
    const esSuelto  = item.tipo === 'Suelto';
    panel.style.display = 'block';

    // Info de precios
    const minPrecio = item.precio_compra > 0 ? parseFloat(item.precio_compra) : 0;
    document.getElementById('infoMinPrecio').textContent =
        `Precio original: $${item.precio.toFixed(2)}${minPrecio > 0 ? ' · Mín. (precio compra): $' + minPrecio.toFixed(2) : ''}`;

    // Configurar input de cantidad
    const inpCant = document.getElementById('inputCantAjuste');
    inpCant.step  = esSuelto ? '0.001' : '1';
    inpCant.min   = esSuelto ? '0.001' : '1';
    inpCant.max   = item.cantidad;

    // Cargar valores existentes si ya tiene ajuste
    if (item.ajuste_activo && item.precio_ajuste !== null) {
        document.getElementById('inputPrecioAjuste').value = item.precio_ajuste.toFixed(2);
        const pct = ((1 - item.precio_ajuste / item.precio) * 100).toFixed(1);
        document.getElementById('inputPctAjuste').value    = pct;
        inpCant.value = item.cant_danada !== undefined ? item.cant_danada : item.cantidad;
    } else {
        document.getElementById('inputPrecioAjuste').value = '';
        document.getElementById('inputPctAjuste').value    = '';
        inpCant.value = esSuelto ? '0.001' : '1';
    }
    document.getElementById('textareaNotaAjuste').value = item.nota_ajuste || '';
}

function ajustePctCambia(val) {
    if (idxAjusteActual < 0) return;
    const item = carrito[idxAjusteActual];
    let pct = parseFloat(val);
    if (isNaN(pct) || val === '') { document.getElementById('inputPrecioAjuste').value = ''; return; }
    pct = Math.max(0, Math.min(100, pct));
    const nuevo = parseFloat((item.precio * (1 - pct / 100)).toFixed(2));
    document.getElementById('inputPrecioAjuste').value = nuevo.toFixed(2);
    validarMinPrecio(nuevo, item);
}

function ajustePrecioCambia(val) {
    if (idxAjusteActual < 0) return;
    const item = carrito[idxAjusteActual];
    const nuevo = parseFloat(val);
    if (isNaN(nuevo) || val === '') { document.getElementById('inputPctAjuste').value = ''; return; }
    const pct = Math.max(0, ((1 - nuevo / item.precio) * 100));
    document.getElementById('inputPctAjuste').value = pct.toFixed(1);
    validarMinPrecio(nuevo, item);
}

function validarMinPrecio(nuevo, item) {
    const minPrecio = item.precio_compra > 0 ? parseFloat(item.precio_compra) : 0;
    const inp = document.getElementById('inputPrecioAjuste');
    inp.style.borderColor = (minPrecio > 0 && nuevo < minPrecio) ? '#c0392b' : '#ddd';
}

function actualizarNotaAjuste(val) {
    if (idxAjusteActual < 0) return;
    carrito[idxAjusteActual].nota_ajuste = val.trim();
}

function aplicarAjuste() {
    if (idxAjusteActual < 0) { alert('Selecciona un producto primero.'); return; }
    const item      = carrito[idxAjusteActual];
    const nuevo     = parseFloat(document.getElementById('inputPrecioAjuste').value);
    const nota      = document.getElementById('textareaNotaAjuste').value.trim();
    const minPrecio = item.precio_compra > 0 ? parseFloat(item.precio_compra) : 0;
    const esSuelto  = item.tipo === 'Suelto';
    let cantDanada  = esSuelto
        ? parseFloat(document.getElementById('inputCantAjuste').value)
        : parseInt(document.getElementById('inputCantAjuste').value);

    if (isNaN(nuevo) || nuevo <= 0)       { alert('Ingresa un precio válido.'); return; }
    if (nuevo > item.precio)               { alert(`El precio ajustado no puede ser mayor al precio original ($${item.precio.toFixed(2)}).`); return; }
    if (minPrecio > 0 && nuevo < minPrecio){ alert(`El precio ajustado ($${nuevo.toFixed(2)}) no puede ser menor al precio de compra ($${minPrecio.toFixed(2)}).`); return; }
    if (!nota)                             { alert('La nota es obligatoria. Escribe el motivo del ajuste.'); document.getElementById('textareaNotaAjuste').focus(); return; }
    if (isNaN(cantDanada) || cantDanada <= 0) { alert('Ingresa una cantidad dañada válida.'); return; }
    if (cantDanada > item.cantidad)        { alert(`La cantidad dañada (${cantDanada}) no puede ser mayor a la cantidad en el carrito (${item.cantidad}).`); return; }

    const cantRestante = parseFloat((item.cantidad - cantDanada).toFixed(3));

    if (cantRestante > 0.0001) {
        // Dividir en dos items: dañados (con ajuste) + originales (sin ajuste)
        // Item original queda con cantidad restante, sin ajuste
        carrito[idxAjusteActual] = {
            ...item,
            cantidad:      cantRestante,
            ajuste_activo: false,
            precio_ajuste: null,
            nota_ajuste:   '',
            cant_danada:   undefined
        };
        // Insertar nuevo item con la cantidad dañada justo después
        const itemDanado = {
            ...item,
            cantidad:      cantDanada,
            ajuste_activo: true,
            precio_ajuste: nuevo,
            nota_ajuste:   nota,
            cant_danada:   cantDanada
        };
        carrito.splice(idxAjusteActual + 1, 0, itemDanado);
        idxAjusteActual = idxAjusteActual + 1;
    } else {
        // Toda la cantidad está dañada
        item.ajuste_activo = true;
        item.precio_ajuste = nuevo;
        item.nota_ajuste   = nota;
        item.cant_danada   = cantDanada;
    }

    renderCarrito(); recalcularTodo();
    actualizarSelectProductos();
    alert(`✅ Ajuste aplicado: ${item.nombre} × ${cantDanada} → $${nuevo.toFixed(2)}`);
}

function quitarAjusteProducto() {
    if (idxAjusteActual < 0) return;
    const item = carrito[idxAjusteActual];
    item.ajuste_activo = false;
    item.precio_ajuste = null;
    item.nota_ajuste   = '';
    item.cant_danada   = undefined;
    document.getElementById('inputPrecioAjuste').value  = '';
    document.getElementById('inputPctAjuste').value     = '';
    document.getElementById('inputCantAjuste').value    = '';
    document.getElementById('textareaNotaAjuste').value = '';
    renderCarrito(); recalcularTodo();
    actualizarSelectProductos();
}

function toggleAjustePrecio(i, activo) {
    const item = carrito[i];
    if (!item) return;
    item.ajuste_activo = activo;
    if (!activo) { item.precio_ajuste = null; item.nota_ajuste = ''; }
    renderCarrito(); recalcularTodo();
}

function actualizarPrecioAjuste(i, valor) {
    // Mantenido por compatibilidad (ya no se usa en UI pero puede llamarse internamente)
    const item = carrito[i];
    if (!item) return;
    const nuevo = parseFloat(valor);
    if (isNaN(nuevo) || nuevo <= 0) { item.precio_ajuste = null; recalcularTodo(); return; }
    item.precio_ajuste = nuevo;
    recalcularTodo();
}

function sanitizarCantCarrito(i, inp) {
    if (carrito[i] && carrito[i].tipo !== 'Suelto') {
        if (inp.value.includes('.')) {
            const n = parseFloat(inp.value);
            inp.value = !isNaN(n) ? Math.floor(n) || '' : '';
        }
    }
}

function cambiarCantidad(i, val) {
    resetPanelAjuste();
    const item     = carrito[i];
    const esSuelto = item.tipo === 'Suelto';
    let qty = esSuelto ? parseFloat(val) : parseInt(val);
    const minQty = esSuelto ? 0.001 : 1;
    // Stock libre = stock total menos lo que ya ocupan otras filas del mismo producto
    const usadoOtros = carrito.reduce((sum, it, idx) =>
        idx !== i && it.producto_id === item.producto_id ? sum + parseFloat(it.cantidad) : sum, 0);
    const stockLibre = parseFloat((item.stock - usadoOtros).toFixed(3));
    const maxQty = esSuelto ? stockLibre : Math.floor(stockLibre);
    if (isNaN(qty) || qty < minQty) qty = minQty;
    if (qty > maxQty) {
        const dispMax = esSuelto ? maxQty.toFixed(3).replace(/\.?0+$/,'') : maxQty;
        alert('Stock máximo disponible: ' + dispMax);
        qty = maxQty;
    }
    carrito[i].cantidad = qty;
    renderCarrito(); recalcularTodo();
    verificarRecomendaciones();
}

function eliminarItem(i) {
    if (idxAjusteActual === i) { idxAjusteActual = -1; document.getElementById('panelCamposAjuste').style.display='none'; }
    else if (idxAjusteActual > i) { idxAjusteActual--; }
    carrito.splice(i, 1);
    renderCarrito(); recalcularTodo();
    verificarRecomendaciones();
    if (document.getElementById('chkAjusteDano').checked) actualizarSelectProductos();
}

function toggleMayoreo(i) {
    const item = carrito[i];
    if (!item.precio_mayoreo || item.precio_mayoreo <= 0) return;

    // Precio base cambia → borrar ajuste aplicado a este item y limpiar panel
    item.ajuste_activo = false;
    item.precio_ajuste = null;
    item.nota_ajuste   = '';
    item.cant_danada   = undefined;
    resetPanelAjuste();
    item.es_mayoreo = !item.es_mayoreo;

    if (item.es_mayoreo) {
        // Activar precio mayoreo: sobreescribe promo si la hubiera
        item.precio      = item.precio_mayoreo;
        item.tiene_promo = false; // mayoreo tiene prioridad
    } else {
        // Restaurar precio original (promo si existe, sino precio_venta)
        const promo      = promoByProdId[item.producto_id] || null;
        item.precio      = promo ? promo.precio_promo : item.precio_normal;
        item.tiene_promo = !!promo;
    }

    renderCarrito();
    recalcularTodo();
}

function limpiarVenta() {
    if (carrito.length > 0 && !confirm('¿Cancelar la venta?')) return;
    carrito = []; clienteActual = null; metodoPago = null; idxAjusteActual = -1;
    renderCarrito(); recalcularTodo(); quitarCliente();
    document.querySelectorAll('.metodo-btn').forEach(b => b.classList.remove('selected'));
    document.querySelectorAll('.campos-pago').forEach(c => c.classList.remove('visible'));
    document.getElementById('recPaquetes').style.display = 'none';
    document.getElementById('chkAjusteDano').checked = false;
    document.getElementById('panelAjusteDano').style.display = 'none';
    document.getElementById('panelCamposAjuste').style.display = 'none';
}

// ── Motor de recomendación de paquetes ───────────────────────────────────────
function verificarRecomendaciones() {
    const divRec  = document.getElementById('recPaquetes');
    const divList = document.getElementById('recLista');

    const enCarrito = {};
    carrito.forEach(item => {
        if (item.producto_id !== null && item.tipo !== 'paquete') {
            const pid = parseInt(item.producto_id);
            enCarrito[pid] = (enCarrito[pid] || 0) + parseFloat(item.cantidad);
        }
    });

    const recs = [];
    paquetesGlobales.forEach(paq => {
        if (carrito.some(i => i.paquete_id === parseInt(paq.paquete_id))) return;

        let cubiertos    = 0;
        let progressSum  = 0;
        const faltantes  = [];
        let hayAlguno    = false;

        paq.productos.forEach(prod => {
            const pid       = parseInt(prod.producto_id);
            const enCar     = enCarrito[pid] || 0;
            const necesaria = parseFloat(prod.cantidad_requerida);
            if (enCar > 0) hayAlguno = true;
            const ratio = necesaria > 0 ? Math.min(enCar / necesaria, 1) : 0;
            progressSum += ratio;
            if (enCar >= necesaria) { cubiertos++; }
            else { faltantes.push({ nombre: prod.nombre_producto, falta: +(necesaria - enCar).toFixed(3) }); }
        });

        if (!hayAlguno) return;
        const total = paq.productos.length;
        // Solo recomendar si el stock total alcanza para cubrir el paquete completo
        let completable = true;
        paq.productos.forEach(prod => {
            if (parseFloat(prod.stock_actual) < parseFloat(prod.cantidad_requerida)) completable = false;
        });
        if (!completable) return;
        const porcentaje = Math.round((progressSum / total) * 100);
        recs.push({ paq, cubiertos, total, faltantes, porcentaje });
    });

    if (!recs.length) { divRec.style.display = 'none'; return; }
    recs.sort((a, b) => b.porcentaje - a.porcentaje);

    divList.innerHTML = recs.map(rec => {
        const completo = rec.cubiertos === rec.total;
        const color    = completo ? '#2e7d32' : '#14ace7';
        const paqData  = JSON.stringify(rec.paq).replace(/"/g, '&quot;');
        return `<div class="rec-item">
            <div class="rec-header">
                <span class="rec-nombre">${esc(rec.paq.nombre)}</span>
                <span class="rec-precio">$${rec.paq.precio_paquete.toFixed(2)}</span>
            </div>
            <div class="rec-barra-bg">
                <div class="rec-barra" style="width:${rec.porcentaje}%;background:${color};"></div>
            </div>
            <div class="rec-desc">
                ${completo
                    ? `✅ Tienes todos los productos (${rec.total}/${rec.total})`
                    : `${rec.cubiertos}/${rec.total} productos · Falta: ${rec.faltantes.map(f=>f.nombre+' (×'+f.falta+')').join(', ')}`}
            </div>
            <button class="rec-btn" style="background:${color};" onclick='agregarPaquete(${paqData})'>
                ${completo ? '✅ Aplicar paquete al carrito' : `➕ Completar paquete (${rec.porcentaje}%)`}
            </button>
        </div>`;
    }).join('');

    divRec.style.display = 'block';
}

// ── Clientes (búsqueda local) ─────────────────────────────────────────────────
let toCli;
document.getElementById('inputCliente').addEventListener('input', function() {
    clearTimeout(toCli);
    const val = this.value.trim();
    if (!val) { document.getElementById('dropdownClientes').classList.remove('visible'); return; }
    toCli = setTimeout(() => {
        const q = normalizar(val);
        const resultados = clientesGlobales
            .filter(c => normalizar(c.nombre_completo).includes(q) ||
                         normalizar(c.telefono || '').includes(q))
            .slice(0, 8);
        mostrarClientes(resultados);
    }, 60);
});

function mostrarClientes(lista) {
    const drop = document.getElementById('dropdownClientes');
    if (!lista.length) {
        drop.innerHTML = '<div style="padding:14px;text-align:center;color:#aaa;font-size:13px;">Sin resultados</div>';
    } else {
        drop.innerHTML = lista.map(c => `
            <div class="resultado-item"
                onclick="seleccionarCliente(${c.cliente_id},'${esc(c.nombre_completo)}','${esc(c.telefono??'')}',${c.descuento_fijo},${c.credito_autorizado})">
                <div>
                    <div class="resultado-nombre">${esc(c.nombre_completo)}</div>
                    <div class="resultado-codigo">${esc(c.telefono??'')} ${c.descuento_fijo>0?'· Desc: '+c.descuento_fijo+'%':''}</div>
                </div>
            </div>`).join('');
    }
    drop.classList.add('visible');
}

function seleccionarCliente(id, nombre, telefono, descuento, credito) {
    clienteActual = { id, nombre, telefono, descuento: parseFloat(descuento), credito: parseInt(credito) };
    document.getElementById('inputCliente').value = '';
    document.getElementById('dropdownClientes').classList.remove('visible');
    document.getElementById('clienteNombre').textContent    = nombre;
    document.getElementById('clienteTelefono').textContent  = telefono;
    document.getElementById('clienteDescuento').textContent = descuento > 0 ? 'Desc: '+descuento+'%' : '';
    document.getElementById('clienteSeleccionado').style.display = 'flex';
    document.getElementById('clienteDescuentoConfig').style.display = descuento > 0 ? 'block' : 'none';
    document.getElementById('aplicarDescCliente').checked = descuento > 0;
    document.getElementById('porcDescCliente').value = parseFloat(descuento || 0).toFixed(1);
    document.getElementById('porcDescCliente').max = parseFloat(descuento || 0).toFixed(1);
    document.getElementById('clienteDescMax').textContent = descuento > 0 ? 'Maximo: ' + parseFloat(descuento).toFixed(1) + '%' : '';
    document.getElementById('inputClienteId').value = id;
    document.getElementById('btnCredito').style.display = credito ? 'block' : 'none';
    recalcularTodo();
}

function quitarCliente() {
    clienteActual = null;
    document.getElementById('clienteSeleccionado').style.display = 'none';
    document.getElementById('clienteDescuentoConfig').style.display = 'none';
    document.getElementById('inputClienteId').value = '';
    document.getElementById('btnCredito').style.display = 'none';
    if (metodoPago === 'Credito') {
        metodoPago = null;
        document.querySelectorAll('.metodo-btn').forEach(b => b.classList.remove('selected'));
        document.querySelectorAll('.campos-pago').forEach(c => c.classList.remove('visible'));
    }
    recalcularTodo();
}

function ajustarDescuentoCliente() {
    // Solo recalcula sin sobrescribir el valor (permite borrar con backspace)
    if (!clienteActual) return;
    recalcularTodo();
}
function clampearDescuentoCliente() {
    if (!clienteActual) return;
    const input = document.getElementById('porcDescCliente');
    const maximo = parseFloat(clienteActual.descuento || 0);
    let valor = parseFloat(input.value || 0);
    if (Number.isNaN(valor) || valor < 0) valor = 0;
    if (valor > maximo) valor = maximo;
    input.value = valor.toFixed(1);
    recalcularTodo();
}

// ── Métodos de pago ──────────────────────────────────────────────────────────
function seleccionarMetodo(metodo, btn) {
    if (metodo === 'Credito' && !clienteActual) { alert('Selecciona un cliente para pago a crédito.'); return; }
    metodoPago = metodo;
    document.getElementById('inputMetodoPago').value = metodo;
    document.querySelectorAll('.metodo-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    document.querySelectorAll('.campos-pago').forEach(c => c.classList.remove('visible'));
    if (metodo==='Efectivo') document.getElementById('camposEfectivo').classList.add('visible');
    if (metodo==='Terminal') document.getElementById('camposTerminal').classList.add('visible');
    if (metodo==='Transferencia') document.getElementById('camposTransferencia').classList.add('visible');
    if (metodo==='Mixto') {
        document.getElementById('camposMixto').classList.add('visible');
        document.getElementById('mixtoEfectivo').value = '';
        document.getElementById('mixtoTerminal').value = '0.00';
    }
    recalcularTodo(); verificarCobrar();
}

// ── Cálculos ─────────────────────────────────────────────────────────────────
function recalcularTodo() {
    // Subtotal bruto: precio de venta normal (sin promos) × cantidades
    const subtotalBruto = carrito.reduce((a,i) => a + (i.cantidad * (i.precio_normal ?? i.precio)), 0);

    // Ahorro por promociones o precio mayoreo
    const ahorroPromos = carrito.reduce((a,i) => {
        const precioRef = i.precio_normal ?? i.precio;
        if ((i.tiene_promo || i.es_mayoreo) && precioRef > i.precio) {
            return a + (i.cantidad * (precioRef - i.precio));
        }
        return a;
    }, 0);

    // Ahorro por ajustes de daño (sobre precio promo si aplica)
    const ahorroAjustes = carrito.reduce((a,i) => {
        if (i.ajuste_activo && i.precio_ajuste !== null) {
            return a + (i.cantidad * (i.precio - i.precio_ajuste));
        }
        return a;
    }, 0);

    // Subtotal efectivo: precio ya con promo, antes de ajustes de daño
    const subtotalConPromo = carrito.reduce((a,i) => a + (i.cantidad * i.precio), 0);
    const subtotalEfectivo = subtotalConPromo - ahorroAjustes;

    const usarDescuentoCliente = clienteActual && clienteActual.descuento > 0 && document.getElementById('aplicarDescCliente').checked;
    const porcentajeDescuento = usarDescuentoCliente ? (parseFloat(document.getElementById('porcDescCliente').value) || 0) : 0;
    const descuentoCliente = porcentajeDescuento > 0 ? subtotalEfectivo * (porcentajeDescuento / 100) : 0;

    // Descuento total = ahorro por daño + descuento de cliente (las promos ya van en el precio)
    const descuento = ahorroAjustes + descuentoCliente;

    // Subtotal mostrado = precio con promos × cantidades (sin ajustes de daño ni descuento cliente)
    const subtotal = subtotalConPromo;

    let comision = 0;
    if (metodoPago === 'Terminal') {
        comision = (subtotal - descuento) * ((parseFloat(document.getElementById('porcComision').value) || 0) / 100);
    }
    const total       = subtotal - descuento + comision;
    const ahorroTotal = ahorroPromos + ahorroAjustes + descuentoCliente;

    const descuentoTotal = ahorroPromos + descuento;
    document.getElementById('resSubtotal').textContent  = '$'+subtotalBruto.toFixed(2);
    document.getElementById('resDescuento').textContent = '-$'+descuentoTotal.toFixed(2);
    document.getElementById('resComision').textContent  = '$'+comision.toFixed(2);
    document.getElementById('resTotal').textContent     = '$'+total.toFixed(2);
    // Guardar subtotal como precio original (antes de promos) y descuento incluyendo ahorro por promos
    document.getElementById('inputSubtotal').value         = subtotalBruto.toFixed(2);
    document.getElementById('inputDescuento').value        = descuentoTotal.toFixed(2);
    document.getElementById('inputComisionTerminal').value = comision.toFixed(2);
    document.getElementById('inputTotal').value            = total.toFixed(2);

    // Sección de ahorro (fila de promos oculta — ya va en Descuento)
    const resAhorroRow  = document.getElementById('resAhorroRow');
    if (resAhorroRow) resAhorroRow.style.display = 'none';
    const resAhorroTotal = document.getElementById('resAhorroTotal');
    if (resAhorroTotal) {
        resAhorroTotal.closest('.resumen-ahorro-total').style.display = ahorroTotal > 0 ? '' : 'none';
        resAhorroTotal.textContent = '$' + ahorroTotal.toFixed(2);
    }
    // Para pago Terminal: guardar el monto completo en monto_terminal (campo hidden)
    // Esto es necesario para que el corte de caja pueda sumar correctamente
    if (metodoPago === 'Terminal') {
        document.getElementById('inputMontoTerminal').value = total.toFixed(2);
        document.getElementById('inputMontoEfectivo').value = '0.00';
    }
    if (metodoPago === 'Transferencia') {
        document.getElementById('inputMontoTerminal').value = total.toFixed(2);
        document.getElementById('inputMontoEfectivo').value = '0.00';
        document.getElementById('inputCambio').value = '0.00';
        document.getElementById('resCambio').textContent = '$0.00'; document.getElementById('resCambio').style.color = '#2e7d32'; if (document.getElementById('resCambio').previousElementSibling) { document.getElementById('resCambio').previousElementSibling.textContent = 'Cambio'; document.getElementById('resCambio').previousElementSibling.style.color = ''; }
    }
    // Mixto: recalcular el split efectivo/terminal con los nuevos valores base
    if (metodoPago === 'Mixto') {
        calcularMixto();
        return; // calcularMixto ya llama verificarCobrar
    }
    // Efectivo: recalcular cambio si ya hay monto ingresado
    if (metodoPago === 'Efectivo') {
        calcularCambio();
        return; // calcularCambio ya llama verificarCobrar
    }
    verificarCobrar();
}

function calcularCambio() {
    // [AUTOFIX] BUG-07: Leer total desde el campo oculto (ya calculado con precision) en lugar de parsear el span visual
    const total    = parseFloat(document.getElementById('inputTotal').value)||0;
    const recibido = parseFloat(document.getElementById('montoEfectivo').value)||0;
    const falta    = total - recibido;
    const cambio   = recibido >= total ? recibido - total : 0;
    const elCambio = document.getElementById('resCambio');
    const elLabel  = elCambio.previousElementSibling; // <span>Cambio</span>
    if (recibido > 0 && falta > 0) {
        elCambio.textContent = '-$' + falta.toFixed(2);
        elCambio.style.color = '#c0392b';
        if (elLabel) { elLabel.textContent = 'Falta'; elLabel.style.color = '#c0392b'; }
    } else {
        elCambio.textContent = '$' + cambio.toFixed(2);
        elCambio.style.color = '#2e7d32';
        if (elLabel) { elLabel.textContent = 'Cambio'; elLabel.style.color = ''; }
    }
    // actualizar inputTotal con el valor real para que el backend lo reciba bien
    document.getElementById('inputTotal').value          = total.toFixed(2);
    document.getElementById('inputMontoEfectivo').value  = recibido.toFixed(2);
    document.getElementById('inputCambio').value         = cambio.toFixed(2);
    verificarCobrar();
}

function calcularMixto() {
    const ef   = parseFloat(document.getElementById('mixtoEfectivo').value) || 0;
    const porc = parseFloat(document.getElementById('mixtoComision').value) || 0;
    const sub  = parseFloat(document.getElementById('inputSubtotal').value) || 0;
    const desc = parseFloat(document.getElementById('inputDescuento').value) || 0;
    const base = sub - desc; // monto base sin comisión

    let com = 0;
    let termDisplay = 0;
    let total = base;

    if (ef > 0 && ef < base - 0.005) {
        // hay parte que va a terminal
        const resto = base - ef;
        com = resto * (porc / 100);
        termDisplay = resto + com;   // cargo real en tarjeta (incluye comisión)
        total = base + com;
    }
    // ef = 0  → estado neutro: sin comisión, terminal en 0, Cobrar deshabilitado
    // ef >= base → todo en efectivo, sin comisión de terminal

    document.getElementById('mixtoTerminal').value         = termDisplay.toFixed(2);
    document.getElementById('resComision').textContent     = '$' + com.toFixed(2);
    document.getElementById('resTotal').textContent        = '$' + total.toFixed(2);
    document.getElementById('inputComisionTerminal').value = com.toFixed(2);
    document.getElementById('inputMontoEfectivo').value    = ef.toFixed(2);
    document.getElementById('inputMontoTerminal').value    = termDisplay.toFixed(2);
    document.getElementById('inputTotal').value            = total.toFixed(2);
    verificarCobrar();
}

function verificarCobrar() {
    const referenciaOk = metodoPago !== 'Transferencia' || String(document.getElementById('transferReferencia')?.value || '').trim() !== '';
    let mixtoOk = true;
    if (metodoPago === 'Mixto') {
        const ef = parseFloat(document.getElementById('mixtoEfectivo').value) || 0;
        mixtoOk = ef > 0;
    }
    let efectivoOk = true;
    if (metodoPago === 'Efectivo') {
        const total    = parseFloat(document.getElementById('resTotal').textContent.replace(/[^0-9.]/g,'')) || 0;
        const recibido = parseFloat(document.getElementById('montoEfectivo').value) || 0;
        efectivoOk = total > 0 && recibido >= total;
    }
    document.getElementById('btnCobrar').disabled = !(carrito.length > 0 && metodoPago && referenciaOk && mixtoOk && efectivoOk);
}

// ── Preparar y enviar venta ──────────────────────────────────────────────────
function prepararVenta() {
    const itemsExpandidos = [];
    for (const item of carrito) {
        if (item.tipo === 'paquete') {
            // [AUTOFIX] BUG-08: Distribuir precio del paquete con tecnica "ultimo item absorbe el redondeo"
            // para que la suma de subtotales individuales sea exactamente igual al precio del paquete.
            const totalCant   = item.productos_paquete.reduce((s,p) => s+p.cantidad_requerida, 0);
            const paqTotal    = item.precio * item.cantidad;
            let   distribuido = 0;
            const lastIdx     = item.productos_paquete.length - 1;
            item.productos_paquete.forEach((prod, idx) => {
                const cantTotal = prod.cantidad_requerida * item.cantidad;
                let precioUnit;
                if (idx === lastIdx) {
                    precioUnit = cantTotal > 0
                        ? Math.round((paqTotal - distribuido) / cantTotal * 100) / 100
                        : 0;
                } else {
                    precioUnit = totalCant > 0
                        ? Math.round(item.precio / totalCant * 100) / 100
                        : 0;
                    distribuido += precioUnit * cantTotal;
                }
                itemsExpandidos.push({
                    producto_id: prod.producto_id,
                    cantidad:    cantTotal,
                    precio:      precioUnit,
                    paquete_id:  item.paquete_id
                });
            });
        } else {
            const tieneAjuste = item.ajuste_activo === true && item.precio_ajuste !== null;
            if (tieneAjuste && !item.nota_ajuste) {
                alert(`"${item.nombre}": la nota del ajuste de precio es obligatoria.`);
                return false;
            }
            itemsExpandidos.push({
                producto_id:  item.producto_id,
                cantidad:     item.cantidad,
                precio:       tieneAjuste ? item.precio_ajuste : item.precio,
                precio_orig:  item.precio_normal ?? item.precio,   // precio antes de promo (para el ticket)
                nota_ajuste:  tieneAjuste ? (item.nota_ajuste || '') : '',
                tiene_promo:  item.tiene_promo ? 1 : 0,
                promo_desc:   item.promo_desc || ''
            });
        }
    }
    if (metodoPago === 'Efectivo') {
        const total    = parseFloat(document.getElementById('resTotal').textContent.replace(/[^0-9.]/g,'')) || 0;
        const recibido = parseFloat(document.getElementById('montoEfectivo').value) || 0;
        if (recibido < total) {
            alert('La cantidad recibida ($' + recibido.toFixed(2) + ') es menor al total ($' + total.toFixed(2) + '). No se puede procesar la venta.');
            document.getElementById('montoEfectivo').focus();
            return false;
        }
    }
    if (metodoPago === 'Transferencia' && String(document.getElementById('transferReferencia').value || '').trim() === '') {
        alert('Captura la referencia bancaria de la transferencia.');
        document.getElementById('transferReferencia').focus();
        return false;
    }
    const paquetesVendidos = carrito
        .filter(item => item.tipo === 'paquete')
        .map(item => ({
            paquete_id: item.paquete_id,
            nombre: item.nombre.replace(/^📦\s*/, ''),
            cantidad: item.cantidad,
            precio_paquete: item.precio,
            productos: item.productos_paquete.map(prod => ({
                producto_id: prod.producto_id,
                nombre_producto: prod.nombre_producto,
                cantidad_requerida: prod.cantidad_requerida
            }))
        }));
    if (metodoPago === 'Transferencia') {
        document.getElementById('inputReferenciaTransferencia').value =
            String(document.getElementById('transferReferencia').value || '').trim();
    }
    const metaVenta = {
        pago: metodoPago === 'Transferencia' ? {
            referencia: String(document.getElementById('transferReferencia').value || '').trim()
        } : null,
        paquetes: paquetesVendidos
    };
    const resumenNotas = [];
    if (metaVenta.pago) {
        resumenNotas.push('Pago por transferencia. Referencia: ' + metaVenta.pago.referencia);
    }
    if (paquetesVendidos.length) {
        resumenNotas.push('Paquetes vendidos: ' + paquetesVendidos.map(p => p.nombre + ' x' + p.cantidad).join(', '));
    }
    const metaEncoded = btoa(unescape(encodeURIComponent(JSON.stringify(metaVenta))));
    document.getElementById('inputNotasVenta').value = (resumenNotas.join('\n') + (resumenNotas.length ? '\n' : '') + '__META_VENTA__' + metaEncoded).trim();
    document.getElementById('inputItems').value = JSON.stringify(itemsExpandidos);
    return true;
}

// ── Envío AJAX del formulario de venta (evita recargar la página y el catálogo) ─
document.getElementById('formVenta').addEventListener('submit', function(e) {
    e.preventDefault();
    if (!prepararVenta()) return;

    const btn = document.getElementById('btnCobrar');
    btn.disabled = true;
    btn.textContent = 'Procesando...';

    const fd = new FormData(this);
    fd.append('_ajax', '1');

    fetch('nuevaVenta.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.ok) throw new Error(res.error || 'Error desconocido');

            // Actualizar stock en productosGlobales para que refleje la venta
            const itemsVendidos = JSON.parse(document.getElementById('inputItems').value || '[]');
            itemsVendidos.forEach(item => {
                const prod = productosGlobales.find(p => p.producto_id === parseInt(item.producto_id));
                if (prod) prod.stock_actual = Math.max(0, prod.stock_actual - parseFloat(item.cantidad));
            });

            // Mostrar notificación de éxito
            let notif = document.getElementById('_notifVentaOk');
            if (!notif) {
                notif = document.createElement('div');
                notif.id = '_notifVentaOk';
                notif.className = 'msg-exito';
                notif.style.cssText = 'position:fixed;top:14px;left:50%;transform:translateX(-50%);z-index:9999;white-space:nowrap;';
                document.body.appendChild(notif);
            }
            notif.innerHTML = `<span>✅ Venta registrada correctamente.</span>
                <button class="btn-print-ticket" onclick="imprimirTicket(${res.venta_id})">🖨 Imprimir ticket</button>`;
            notif.style.display = 'flex';
            setTimeout(() => { notif.style.display = 'none'; }, 12000);

            // Imprimir ticket automáticamente
            imprimirTicket(res.venta_id);

            // Limpiar carrito sin pedir confirmación
            carrito = []; clienteActual = null; metodoPago = null; idxAjusteActual = -1;
            renderCarrito(); recalcularTodo(); quitarCliente();
            document.querySelectorAll('.metodo-btn').forEach(b => b.classList.remove('selected'));
            document.querySelectorAll('.campos-pago').forEach(c => c.classList.remove('visible'));
            // Reiniciar campos de pago
            const _montoEf = document.getElementById('montoEfectivo');
            if (_montoEf) _montoEf.value = '';
            const _resCambio = document.getElementById('resCambio');
            if (_resCambio) _resCambio.textContent = '$0.00';
            const _transferRef = document.getElementById('transferReferencia');
            if (_transferRef) _transferRef.value = '';
            const _mixtoEf = document.getElementById('mixtoEfectivo');
            if (_mixtoEf) _mixtoEf.value = '';
            const _mixtoTerm = document.getElementById('mixtoTerminal');
            if (_mixtoTerm) _mixtoTerm.value = '';
            document.getElementById('recPaquetes').style.display = 'none';
            const chk = document.getElementById('chkAjusteDano');
            if (chk) { chk.checked = false; }
            const panelAdj = document.getElementById('panelAjusteDano');
            if (panelAdj) panelAdj.style.display = 'none';
            const panelCamp = document.getElementById('panelCamposAjuste');
            if (panelCamp) panelCamp.style.display = 'none';
            document.getElementById('inputProducto').focus();
        })
        .catch(err => {
            const msg = err.message || 'No se pudo procesar la venta.';
            alert('⚠ ' + msg);
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Cobrar';
        });
});

// ── Ticket de impresión ──────────────────────────────────────────────────────
function imprimirTicket(ventaId) {
    if (!ventaId) return;
    fetch(`nuevaVenta.php?ticket_venta=${ventaId}`)
        .then(r => r.json())
        // [AUTOFIX] N-04: Verificar error del servidor antes de generar ticket
        .then(venta => {
            if (!venta || venta.error) { alert('No se pudo cargar el ticket.'); return; }
            generarTicketHTML(venta);
            // Esperar un frame para que el navegador calcule el layout del ticket
            requestAnimationFrame(() => {
                const ticket = document.getElementById('ticketImprimir');
                ticket.style.display = 'block';
                const altoPx = ticket.scrollHeight;
                ticket.style.display = '';
                // Convertir px → mm (1px ≈ 0.2646mm a 96dpi) y agregar 4mm de margen inferior
                const altoMm = Math.ceil(altoPx * 0.2646) + 4;
                // Inyectar @page con alto exacto para que Chrome no agregue hoja en blanco
                let estilo = document.getElementById('__ticketPageStyle');
                if (!estilo) {
                    estilo = document.createElement('style');
                    estilo.id = '__ticketPageStyle';
                    document.head.appendChild(estilo);
                }
                estilo.textContent = `@page { size: 80mm ${altoMm}mm; margin: 0; }`;
                setTimeout(() => window.print(), 150);
            });
        });
}

function generarTicketHTML(venta) {
    const linea = '--------------------------------';
    // Usar fecha ya formateada en servidor (evita desfase de zona horaria en el navegador)
    const fecha = venta.fecha_formateada || venta.created_at;
    const meta = obtenerMetaVenta(venta.notas);

    // Aplicar configuracion de ticket de la sucursal
    const elTicket = document.getElementById('ticketImprimir');
    elTicket.style.fontSize = datosTicket.ticket_font_size + 'px';
    elTicket.style.width    = datosTicket.ticket_ancho_mm  + 'mm';

    let html = '';
    if (datosTicket.ticket_logo) {
        const maxW = datosTicket.ticket_ancho_mm >= 80 ? '140px' : '100px';
        html += `<div class="t-centro" style="margin-bottom:6px;"><img src="../${datosTicket.ticket_logo}" style="max-width:${maxW};max-height:50px;object-fit:contain;"></div>`;
    }
    html += `
        <div class="t-centro t-bold t-grande">${datosTicket.nombre}</div>`;

    if (datosTicket.datos_ticket) {
        html += `<div class="t-centro" style="white-space:pre-line;font-size:11px;">${esc(datosTicket.datos_ticket)}</div>`;
    } else {
        if (datosTicket.rfc)       html += `<div class="t-centro">RFC: ${esc(datosTicket.rfc)}</div>`;
        if (datosTicket.direccion) html += `<div class="t-centro">${esc(datosTicket.direccion)}</div>`;
        if (datosTicket.telefono)  html += `<div class="t-centro">Tel: ${esc(datosTicket.telefono)}</div>`;
    }

    // [AUTOFIX] OBS-03: Escapar folio y fecha para evitar XSS si hubiera HTML en esos campos
    html += `
        <div class="t-linea"></div>
        <div class="t-fila"><span>Folio:</span><span>${esc(venta.folio || ('#'+venta.venta_id))}</span></div>
        <div class="t-fila"><span>Fecha:</span><span>${esc(fecha)}</span></div>
        <div class="t-fila"><span>Cajero:</span><span>${esc(cajeroNombre)}</span></div>`;

    if (venta.cliente) {
        html += `<div class="t-fila"><span>Cliente:</span><span>${esc(venta.cliente)}</span></div>`;
    }

    html += `<div class="t-linea"></div>
        <div class="t-fila t-bold"><span>Producto</span><span>Importe</span></div>
        <div class="t-linea"></div>`;

    const consumidosPaquete = {};
    (meta.paquetes || []).forEach((paq) => {
        html += `
            <div>${esc('Paquete: ' + paq.nombre)}</div>
            <div class="t-fila">
                <span>${paq.cantidad} x $${parseFloat(paq.precio_paquete).toFixed(2)}</span>
                <span>$${(parseFloat(paq.cantidad) * parseFloat(paq.precio_paquete)).toFixed(2)}</span>
            </div>`;
        (paq.productos || []).forEach((prod) => {
            const key = String(prod.producto_id);
            consumidosPaquete[key] = (consumidosPaquete[key] || 0) + (parseFloat(prod.cantidad_requerida || 0) * parseFloat(paq.cantidad || 1));
        });
    });

    let ahorroPromoTicket = 0;

    // [AUTOFIX] Las filas con paquete_id ya se muestran en la sección de paquetes de arriba.
    //           Las filas sueltas (paquete_id null) se muestran tal cual — no restar consumidosPaquete
    //           porque esa resta causaba que tornillos sueltos quedaran en cantidad negativa y
    //           se omitieran del recibo cuando el mismo producto también existía dentro de un paquete.
    (venta.productos || []).forEach((p) => {
        if (p.paquete_id) return;  // ya aparece en la sección de paquetes
        const cantidadRestante = parseFloat(p.cantidad);
        if (cantidadRestante <= 0) return;
        const precioOrig  = parseFloat(p.precio_unitario);   // precio antes de promo
        const precioFinal = p.precio_final ? parseFloat(p.precio_final) : precioOrig;  // precio cobrado
        const tieneAjuste = p.nota_ajuste && p.nota_ajuste.trim() !== '' && precioFinal < precioOrig;
        const tienePromo  = !tieneAjuste && precioFinal < precioOrig;

        html += `<div>${esc(p.nombre_producto)}</div>`;

        if (tienePromo) {
            const pctPromo   = ((1 - precioFinal / precioOrig) * 100).toFixed(0);
            const ahorroUnit = (precioOrig - precioFinal).toFixed(2);
            ahorroPromoTicket += cantidadRestante * (precioOrig - precioFinal);
            html += `
            <div class="t-fila" style="text-decoration:line-through;color:#aaa;font-size:10px;">
                <span>${cantidadRestante} x $${precioOrig.toFixed(2)}</span>
                <span>$${(cantidadRestante * precioOrig).toFixed(2)}</span>
            </div>
            <div class="t-fila">
                <span>${cantidadRestante} x $${precioFinal.toFixed(2)} <span style="font-size:10px;">-${pctPromo}% (-$${ahorroUnit})</span></span>
                <span>$${(cantidadRestante * precioFinal).toFixed(2)}</span>
            </div>`;
        } else if (tieneAjuste) {
            const pctDesc   = ((1 - precioFinal / precioOrig) * 100).toFixed(1);
            const montoDesc = (precioOrig - precioFinal).toFixed(2);
            html += `
            <div class="t-fila" style="text-decoration:line-through;color:#aaa;font-size:10px;">
                <span>${cantidadRestante} x $${precioOrig.toFixed(2)}</span>
                <span>$${(cantidadRestante * precioOrig).toFixed(2)}</span>
            </div>
            <div class="t-fila">
                <span>${cantidadRestante} x $${precioFinal.toFixed(2)} <span style="font-size:10px;">(-${pctDesc}% / -$${montoDesc})</span></span>
                <span>$${(cantidadRestante * precioFinal).toFixed(2)}</span>
            </div>`;
        } else {
            html += `
            <div class="t-fila">
                <span>${cantidadRestante} x $${precioOrig.toFixed(2)}</span>
                <span>$${(cantidadRestante * precioOrig).toFixed(2)}</span>
            </div>`;
        }
    });

    html += `<div class="t-linea"></div>`;

    // [AUTOFIX] N-01: Usar descuento_display (proporcional al subtotal actual) en lugar de descuento bruto
    if (parseFloat(venta.descuento_display ?? venta.descuento) > 0) {
        html += `<div class="t-fila"><span>Subtotal</span><span>$${parseFloat(venta.subtotal).toFixed(2)}</span></div>`;
    }
    if (parseFloat(venta.comision_terminal) > 0) {
        html += `<div class="t-fila"><span>Comisión terminal</span><span>$${parseFloat(venta.comision_terminal).toFixed(2)}</span></div>`;
    }
    if (parseFloat(venta.descuento_display ?? venta.descuento) > 0) {
        html += `<div class="t-fila" style="font-size:11px;"><span>Ahorraste</span><span>-$${parseFloat(venta.descuento_display ?? venta.descuento).toFixed(2)}</span></div>`;
    }

    html += `
        <div class="t-fila t-bold t-grande">
            <span>TOTAL</span><span>$${parseFloat(venta.total).toFixed(2)}</span>
        </div>
        <div class="t-linea"></div>
        <div class="t-fila"><span>Método de pago</span><span>${esc(venta.metodo_pago)}</span></div>`;

    if (meta.pago && meta.pago.metodo_real === 'Transferencia') {
        html += `
        <div class="t-fila"><span>Pago real</span><span>Transferencia</span></div>
        <div class="t-fila"><span>Referencia</span><span>${esc(meta.pago.referencia || '—')}</span></div>`;
        if (meta.pago.banco_origen) {
            html += `<div class="t-fila"><span>Banco origen</span><span>${esc(meta.pago.banco_origen)}</span></div>`;
        }
    }

    if (venta.metodo_pago === 'Efectivo' && parseFloat(venta.cambio) > 0) {
        html += `
        <div class="t-fila"><span>Recibido</span><span>$${parseFloat(venta.monto_efectivo).toFixed(2)}</span></div>
        <div class="t-fila"><span>Cambio</span><span>$${parseFloat(venta.cambio).toFixed(2)}</span></div>`;
    }

    if (venta.metodo_pago === 'Credito') {
        html += `
        <div class="t-centro" style="margin-top:6px;font-weight:bold;">*** VENTA A CRÉDITO ***</div>
        <div class="t-linea"></div>
        <div class="t-centro" style="font-size:10px;margin-bottom:8px;">Al firmar acepto cubrir el monto total adeudado</div>
        <div style="margin-top:22px;font-size:11px;">Nombre: _______________________________</div>
        <div style="margin-top:28px;font-size:11px;">Firma: &nbsp;&nbsp;_______________________________</div>
        <div style="margin-top:28px;font-size:11px;">Fecha: &nbsp;&nbsp;_______________________________</div>`;
    }

    html += `
        <div class="t-linea"></div>
        <div class="t-centro">${esc(datosTicket.ticket_pie || '¡Gracias por su compra!')}</div>
        <div class="t-centro" style="font-size:10px;margin-top:4px;">Conserve su ticket</div>`;

    document.getElementById('ticketImprimir').innerHTML = html;
}

// ── Modal inventario ─────────────────────────────────────────────────────────
function obtenerMetaVenta(notas) {
    const texto = String(notas || '');
    const marker = '__META_VENTA__';
    const idx = texto.indexOf(marker);
    if (idx === -1) return { pago: null, paquetes: [] };
    try {
        return JSON.parse(decodeURIComponent(escape(atob(texto.slice(idx + marker.length).trim()))));
    } catch (e) {
        return { pago: null, paquetes: [] };
    }
}

function abrirInventario() {
    document.getElementById('modalInventario').classList.add('visible');
    buscarInventario();
}
function cerrarInventario() {
    document.getElementById('modalInventario').classList.remove('visible');
}

let toInv;
function buscarInventario() {
    clearTimeout(toInv);
    toInv = setTimeout(() => {
        const buscar   = document.getElementById('invBuscar').value;
        const sucursal = document.getElementById('invSucursal').value;
        fetch(`nuevaVenta.php?inventario_sucursal=${sucursal}&buscar_inv=${encodeURIComponent(buscar)}`)
            .then(r => r.json()).then(renderInventario);
    }, 300);
}

function renderInventario(productos) {
    const div = document.getElementById('invResultados');
    if (!productos.length) {
        div.innerHTML = '<p style="text-align:center;color:#aaa;font-size:13px;padding:20px;">No se encontraron productos.</p>';
        return;
    }
    const sucursalSel = parseInt(document.getElementById('invSucursal').value);
    const esDif       = sucursalSel !== miSucursalId;

    div.innerHTML = `<table class="inv-tabla">
        <thead><tr><th>Código</th><th>Producto</th><th>Stock</th><th>Precio</th><th></th></tr></thead>
        <tbody>${productos.map(p => {
            const sinStock = p.stock_actual <= 0;
            return `<tr>
                <td style="color:#aaa;font-size:12px;">${esc(p.codigo)}</td>
                <td>${esc(p.nombre_producto)}${esDif?'<span class="sucursal-diferente">Otra sucursal</span>':''}</td>
                <td><span class="stock-badge ${sinStock?'stock-bajo':'stock-ok'}">${parseFloat(p.stock_actual).toFixed(p.tipo_venta==='Suelto'?3:0)}</span></td>
                <td>$${parseFloat(p.precio_venta).toFixed(2)}</td>
                <td>${!esDif && !sinStock
                    ? `<button class="btn-agregar-inv" onclick="agregarProducto(${p.producto_id},'${esc(p.nombre_producto)}',${p.precio_venta},${p.stock_actual},'${p.tipo_venta}',${parseFloat(p.precio_compra||0)},'${esc(p.unidad_medida||'')}',${parseFloat(p.precio_mayoreo||0)});cerrarInventario()">Agregar</button>`
                    : `<button class="btn-agregar-inv" ${esDif ? 'disabled' : `onclick="alert('No hay stock disponible para este producto.')"`}>${esDif?'Otra suc.':'Sin stock'}</button>`
                }</td>
            </tr>`;
        }).join('')}</tbody>
    </table>`;
}

// ── Cerrar dropdowns ─────────────────────────────────────────────────────────
document.getElementById('modalInventario').addEventListener('click', function(e) {
    if (e.target === this) cerrarInventario();
});
document.addEventListener('click', function(e) {
    if (!e.target.closest('.busqueda-wrap') && !e.target.closest('.dropdown-resultados')) {
        document.querySelectorAll('.dropdown-resultados').forEach(d => d.classList.remove('visible'));
    }
});


// ── Utilidad ─────────────────────────────────────────────────────────────────
function esc(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// [AUTOFIX] N-05: Usar el porcentaje real de la sucursal en lugar del valor hardcodeado 4.6
const _comisionPct = parseFloat(document.getElementById('porcComision')?.value || document.getElementById('mixtoComision')?.value || 0);
document.querySelectorAll('.js-zero-default').forEach((input) => {
    input.addEventListener('focus', function() {
        const valor = String(this.value ?? '').trim();
        const defaultComision = _comisionPct.toFixed(2);
        if (valor === '0' || valor === '0.00' || valor === '0.0' || valor === defaultComision) {
            this.value = '';
        }
    });
    input.addEventListener('blur', function() {
        if (String(this.value ?? '').trim() === '') {
            this.value = this.id.toLowerCase().includes('comision') ? _comisionPct.toFixed(2) : '0.00';
            if (typeof this.oninput === 'function') this.oninput();
        }
    });
});
</script>
</body>
</html>