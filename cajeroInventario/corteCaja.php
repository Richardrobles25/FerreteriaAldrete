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
    header('Location: inicioCajeroInventario.php?msg=sinCaja');
    exit();
}

// Resumen de ventas de la caja actual
$stmtVentas = $pdo->prepare("
    SELECT
        COUNT(*) AS total_ventas,
        COALESCE(SUM(total),0) AS total_cobrado,
        COALESCE(SUM(CASE WHEN metodo_pago='Efectivo' THEN total ELSE 0 END),0) AS ef,
        COALESCE(SUM(CASE WHEN metodo_pago='Terminal' THEN total ELSE 0 END),0) AS term,
        COALESCE(SUM(CASE WHEN metodo_pago='Credito'      OR metodo_pago='Crédito' THEN total ELSE 0 END),0) AS cred,
        COALESCE(SUM(CASE WHEN metodo_pago='Mixto' THEN monto_efectivo ELSE 0 END),0) AS mixto_ef,
        COALESCE(SUM(CASE WHEN metodo_pago='Mixto' THEN monto_terminal ELSE 0 END),0) AS mixto_term,
        COALESCE(SUM(CASE WHEN metodo_pago='Transferencia' THEN total ELSE 0 END),0) AS transf,
        COALESCE(SUM(comision_terminal),0) AS comisiones
    FROM ventas
    WHERE caja_id = ? AND estado IN ('Completada', 'Modificado', 'Devuelto')
");
$stmtVentas->execute([$caja['caja_id']]);
$resumen = $stmtVentas->fetch(PDO::FETCH_ASSOC);

// Ventas pendientes sin liquidar — del USUARIO, no solo de la caja actual:
// las pendientes creadas en turnos anteriores tambien deben avisarse antes de cerrar
// (al liquidarlas se reasignan a la caja abierta en ese momento)
$stmtPend = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE usuario_id = ? AND estado = 'Pendiente'");
$stmtPend->execute([$_SESSION['usuario_id']]);
$ventasPendientes = $stmtPend->fetchColumn();

// Movimientos de caja (retiros e ingresos) del turno actual
$stmtMov = $pdo->prepare("
    SELECT tipo, monto, nota, created_at
    FROM movimientos_caja
    WHERE caja_id = ?
    ORDER BY created_at ASC
");
$stmtMov->execute([$caja['caja_id']]);
$movimientos = $stmtMov->fetchAll(PDO::FETCH_ASSOC);

// Separar ingresos: efectivo vs terminal/transferencia (no cuentan como físico en caja).
// El sufijo [Terminal]/[Transferencia] al final de la nota lo escriben los abonos de
// crédito pagados con tarjeta o transferencia (creditos.php) — ese dinero no entra al cajón.
$ingresosCash   = array_filter($movimientos, fn($m) => $m['tipo'] === 'Ingreso' && !preg_match('/\[(Terminal|Transferencia)\]$/', $m['nota']));
$ingresosNoCash = array_filter($movimientos, fn($m) => $m['tipo'] === 'Ingreso' &&  preg_match('/\[(Terminal|Transferencia)\]$/', $m['nota']));
$totalIngresosCash   = array_sum(array_column(array_values($ingresosCash),   'monto'));
$totalIngresosNoCash = array_sum(array_column(array_values($ingresosNoCash), 'monto'));
$totalIngresos       = $totalIngresosCash + $totalIngresosNoCash;
// Retiros: los reembolsos por devolución SIEMPRE se entregan en efectivo (política del
// negocio), así que los retiros de devolución ya NO llevan sufijo y cuentan como salida
// de caja física. Los retiros con sufijo [Terminal]/[Transferencia] son solo registros
// antiguos (anteriores al cambio) y se mantienen excluidos para no alterar cortes viejos.
$retirosCash         = array_filter($movimientos, fn($m) => $m['tipo'] === 'Retiro' && !preg_match('/\[(Terminal|Transferencia)\]$/', $m['nota'] ?? ''));
$retirosNoCash       = array_filter($movimientos, fn($m) => $m['tipo'] === 'Retiro' &&  preg_match('/\[(Terminal|Transferencia)\]$/', $m['nota'] ?? ''));
$totalRetiros        = array_sum(array_column(array_values($retirosCash),   'monto'));
$totalRetirosNoCash  = array_sum(array_column(array_values($retirosNoCash), 'monto'));

// Efectivo esperado = apertura + ventas en efectivo + ingresos en efectivo - retiros en efectivo
// (los retiros incluyen los reembolsos de devoluciones, que siempre salen del cajón)
$efectivoEsperado = floatval($caja['monto_apertura'])
                  + floatval($resumen['ef'])
                  + floatval($resumen['mixto_ef'])
                  + $totalIngresosCash
                  - $totalRetiros;

// Procesar cierre
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [FIX-A1] Verificar CSRF antes de procesar el cierre de caja
    requerirCSRF($_POST['_token'] ?? '', 'corteCaja.php');
    // [AUTOFIX] VALIDACION-1B-1: Verificar que el campo no esté vacío antes de convertir a float.
    // floatval('') = 0.0, lo que permitía cerrar sin escribir nada.
    $monto_cierre_raw = $_POST['monto_cierre'] ?? '';
    $observaciones    = trim($_POST['observaciones'] ?? '');

    if ($monto_cierre_raw === '') {
        $errores[] = 'El monto contado es obligatorio. Escribe la cantidad que encontraste en caja.';
        $monto_cierre = 0.0;
    } else {
        $monto_cierre = floatval($monto_cierre_raw);
        if ($monto_cierre < 0) $errores[] = 'El monto contado no puede ser negativo.';
    }

    if (empty($errores)) {
        $diferencia = $monto_cierre - $efectivoEsperado;

        // [FIX-M1] Se agrega "AND estado = 'Abierta'": un doble envio del formulario
        // (doble clic, reenvio) ya no sobrescribe el corte con otro monto — el UPDATE
        // simplemente no afecta ninguna fila si la caja ya quedo cerrada por el primer envio.
        $pdo->prepare("
            UPDATE cajas SET
                estado = 'Cerrada',
                monto_cierre = ?,
                monto_esperado = ?,
                diferencia = ?,
                observaciones = ?,
                cerrada_en = NOW()
            WHERE caja_id = ? AND estado = 'Abierta'
        ")->execute([$monto_cierre, $efectivoEsperado, $diferencia, $observaciones, $caja['caja_id']]);

        header('Location: inicioCajeroInventario.php?msg=cajaCerrada');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corte de Caja — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 28px; overflow-y: auto; display: grid; grid-template-columns: 1fr 400px; gap: 20px; align-items: start; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 22px; }
    .card h2 { font-size: 16px; font-weight: 600; color: #222; margin: 0 0 18px; }
    .card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 14px; }
    .seccion { margin-bottom: 22px; padding-bottom: 22px; border-bottom: 0.5px solid #f0f0f0; }
    .seccion:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    .fila { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; font-size: 13px; color: #555; border-bottom: 0.5px solid #f9f9f9; }
    .fila:last-child { border-bottom: none; }
    .fila.subtotal { font-weight: 600; color: #333; }
    .fila.total-ef { font-size: 15px; font-weight: 700; color: #222; border-top: 1px solid #eee; padding-top: 12px; margin-top: 4px; }
    .fila.positivo span:last-child { color: #2e7d32; font-weight: 700; }
    .fila.negativo span:last-child { color: #c0392b; font-weight: 700; }
    .alerta-pend { background: #e3f2fd; border: 1px solid #bbdefb; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #1565c0; margin-bottom: 18px; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; border-left: 3px solid #c0392b; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .form-group textarea { min-height: 80px; resize: vertical; }
    .diferencia-preview { border-radius: 8px; padding: 14px 16px; margin-bottom: 18px; font-size: 14px; font-weight: 600; text-align: center; display: none; }
    .dif-cuadrado { background: #e8f5e9; color: #2e7d32; }
    .dif-faltante { background: #fdecea; color: #c0392b; }
    .dif-sobrante { background: #fff8e1; color: #1565c0; }
    .btn-cerrar { width: 100%; background: #c0392b; color: white; border: none; padding: 14px; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; }
    .btn-cerrar:hover { background: #a93226; }
    .turno-info { background: #f9f9f9; border-radius: 8px; padding: 14px 16px; margin-bottom: 18px; font-size: 13px; }
    .turno-info strong { display: block; font-size: 15px; color: #222; margin-bottom: 4px; }
    .turno-info span { color: #888; }
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
        <a class="menu-item active" href="corteCaja.php">Corte de caja</a>
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
            <h2>Corte de Caja</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Resumen del turno -->
        <div>
            <div class="card">
                <h2>Resumen del turno #<?= $caja['numero_turno'] ?></h2>

                <div class="turno-info">
                    <strong><?= htmlspecialchars($_SESSION['nombre_completo']) ?></strong>
                    <span>Abierta el <?= date('d/m/Y \a \l\a\s H:i', strtotime($caja['abierta_en'])) ?></span>
                </div>

                <?php if ($ventasPendientes > 0): ?>
                <div class="alerta-pend">
                    ⚠ Tienes <strong><?= $ventasPendientes ?></strong> venta(s) pendiente(s) sin liquidar.
                    <a href="ventasPendientes.php" style="color:#1565c0;font-weight:700;margin-left:6px;">Ver</a>
                </div>
                <?php endif; ?>

                <!-- Ventas -->
                <div class="seccion">
                    <h3>Ventas del turno</h3>
                    <div class="fila"><span>Total de ventas completadas</span><span><?= $resumen['total_ventas'] ?></span></div>
                    <div class="fila"><span>Total cobrado</span><span style="font-weight:700;">$<?= number_format($resumen['total_cobrado'],2) ?></span></div>
                    <?php if (floatval($resumen['comisiones']) > 0): ?>
                    <div class="fila" style="font-size:12px;color:#888;">
                        <span>Incluye comisión de terminal</span>
                        <span>$<?= number_format($resumen['comisiones'],2) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Desglose por método -->
                <div class="seccion">
                    <h3>Desglose por método de pago</h3>
                    <div class="fila"><span>Efectivo directo</span><span>$<?= number_format($resumen['ef'],2) ?></span></div>
                    <div class="fila"><span>Terminal directa</span><span>$<?= number_format($resumen['term'],2) ?></span></div>
                    <div class="fila"><span>Mixto — parte efectivo</span><span>$<?= number_format($resumen['mixto_ef'],2) ?></span></div>
                    <div class="fila"><span>Mixto — parte terminal</span><span>$<?= number_format($resumen['mixto_term'],2) ?></span></div>
                    <?php if (floatval($resumen['transf']) > 0): ?>
                    <div class="fila"><span>Transferencia bancaria</span><span>$<?= number_format($resumen['transf'],2) ?></span></div>
                    <?php endif; ?>
                    <div class="fila"><span>Crédito (no cobrado en caja)</span><span>$<?= number_format($resumen['cred'],2) ?></span></div>
                </div>

                <!-- Movimientos de caja del turno -->
                <?php if (count($movimientos) > 0): ?>
                <div class="seccion">
                    <h3>Movimientos de caja</h3>
                    <?php foreach ($movimientos as $m): ?>
                    <div class="fila <?= $m['tipo'] === 'Ingreso' ? 'positivo' : 'negativo' ?>">
                        <span>
                            <?= $m['tipo'] === 'Ingreso' ? '&#8593;' : '&#8595;' ?>
                            <?= htmlspecialchars($m['tipo']) ?>
                            <span style="font-size:11px;color:#aaa;margin-left:6px;"><?= htmlspecialchars($m['nota']) ?></span>
                        </span>
                        <span><?= $m['tipo'] === 'Ingreso' ? '+' : '-' ?>$<?= number_format($m['monto'],2) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($movimientos) > 1): ?>
                    <div class="fila subtotal" style="margin-top:4px;">
                        <span>Neto de movimientos</span>
                        <?php $netoMovimientos = $totalIngresos - ($totalRetiros + $totalRetirosNoCash); ?>
                        <span style="color:<?= $netoMovimientos >= 0 ? '#2e7d32' : '#c0392b' ?>;">
                            <?= $netoMovimientos >= 0 ? '+' : '' ?>$<?= number_format($netoMovimientos, 2) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Efectivo esperado -->
                <div class="seccion">
                    <h3>Efectivo esperado en caja</h3>
                    <div class="fila"><span>Monto de apertura</span><span>$<?= number_format($caja['monto_apertura'],2) ?></span></div>
                    <div class="fila"><span>+ Ventas en efectivo</span><span>$<?= number_format($resumen['ef'],2) ?></span></div>
                    <div class="fila"><span>+ Efectivo de pagos mixtos</span><span>$<?= number_format($resumen['mixto_ef'],2) ?></span></div>
                    <?php if (floatval($resumen['transf']) > 0): ?>
                    <div class="fila" style="font-size:12px;color:#888;"><span>↗ Transferencias bancarias <em style="font-size:11px;">(no entran a caja física)</em></span><span>$<?= number_format($resumen['transf'],2) ?></span></div>
                    <?php endif; ?>
                    <?php if ($totalIngresosCash > 0): ?>
                    <div class="fila positivo"><span>+ Ingresos en efectivo</span><span>+$<?= number_format($totalIngresosCash,2) ?></span></div>
                    <?php endif; ?>
                    <?php if ($totalIngresosNoCash > 0): ?>
                    <div class="fila" style="font-size:12px;color:#888;"><span>↗ Pagos crédito terminal/transferencia <em style="font-size:11px;">(no afectan caja física)</em></span><span>$<?= number_format($totalIngresosNoCash,2) ?></span></div>
                    <?php endif; ?>
                    <?php if ($totalRetiros > 0): ?>
                    <div class="fila negativo"><span>- Retiros de caja (efectivo)</span><span>-$<?= number_format($totalRetiros,2) ?></span></div>
                    <?php endif; ?>
                    <?php if ($totalRetirosNoCash > 0): ?>
                    <div class="fila" style="font-size:12px;color:#888;"><span>↙ Devoluciones antiguas terminal/transferencia <em style="font-size:11px;">(registros previos al cambio; no restan del físico)</em></span><span>-$<?= number_format($totalRetirosNoCash,2) ?></span></div>
                    <?php endif; ?>
                    <div class="fila total-ef">
                        <span>Total esperado en caja</span>
                        <span>$<?= number_format($efectivoEsperado,2) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Formulario de cierre -->
        <div>
            <div class="card">
                <h3>Registrar cierre</h3>

                <?php if (!empty($errores)): ?>
                    <div class="errores"><?= htmlspecialchars($errores[0]) ?></div>
                <?php endif; ?>

                <form method="POST" onsubmit="return confirmarCierre()">
                    <!-- [FIX-A1] Token CSRF para proteger el cierre de caja -->
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="form-group">
                        <label>Monto contado en caja *</label>
                        <!-- [AUTOFIX] VALIDACION-1B-1: required evita envío vacío desde el navegador -->
                        <input type="number" name="monto_cierre" id="inputMontoCierre"
                            placeholder="0.00" step="0.01" min="0" required
                            oninput="calcularDiferencia(this.value)" autofocus>
                    </div>

                    <!-- Preview de diferencia -->
                    <div class="diferencia-preview" id="difPreview"></div>

                    <div class="form-group">
                        <label>Observaciones del corte (opcional)</label>
                        <textarea name="observaciones" placeholder="Notas sobre el cierre, diferencias, etc..."></textarea>
                    </div>

                    <button class="btn-cerrar" type="submit">
                        Cerrar caja — Turno #<?= $caja['numero_turno'] ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const efectivoEsperado = <?= $efectivoEsperado ?>;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

function calcularDiferencia(val) {
    const contado   = parseFloat(val) || 0;
    const diferencia = contado - efectivoEsperado;
    const div        = document.getElementById('difPreview');

    if (val === '') { div.style.display = 'none'; return; }

    div.style.display = 'block';

    if (Math.abs(diferencia) < 0.01) {
        div.className  = 'diferencia-preview dif-cuadrado';
        div.textContent = '✅ Caja cuadrada — Sin diferencia';
    } else if (diferencia < 0) {
        div.className  = 'diferencia-preview dif-faltante';
        div.textContent = `⚠ Faltante: $${Math.abs(diferencia).toFixed(2)} (contaste menos de lo esperado)`;
    } else {
        div.className  = 'diferencia-preview dif-sobrante';
        div.textContent = `📌 Sobrante: $${diferencia.toFixed(2)} (contaste más de lo esperado)`;
    }
}

function confirmarCierre() {
    const raw     = document.getElementById('inputMontoCierre').value.trim();
    const contado = parseFloat(raw);

    // [AUTOFIX] VALIDACION-1B-1: Bloquear envío si el campo está vacío o no es un número
    if (raw === '' || isNaN(contado)) {
        alert('Escribe el monto que contaste en caja antes de cerrar.');
        return false;
    }
    // Advertencia extra si se cierra con $0.00 (puede ser error de omisión)
    if (contado === 0 && !confirm('Estás cerrando la caja con $0.00 contados. ¿Es correcto?')) {
        return false;
    }

    const diferencia = contado - efectivoEsperado;
    if (Math.abs(diferencia) > 0.01) {
        const tipo = diferencia < 0 ? 'faltante' : 'sobrante';
        return confirm(`Hay un ${tipo} de $${Math.abs(diferencia).toFixed(2)}. ¿Confirmas el cierre de caja?`);
    }
    return confirm('¿Confirmas el cierre de caja?');
}
</script>
</body>
</html>
