<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

$pdo->exec("UPDATE creditos SET estado='Vencido' WHERE estado='Activo' AND fecha_limite IS NOT NULL AND fecha_limite < CURDATE()");

$credito_id = intval($_GET['credito_id'] ?? $_GET['ver'] ?? 0);
$soloVer    = isset($_GET['ver']);
$credito    = null;
$errores    = [];

// Datos bancarios y comisión de la sucursal
$sucursalInfo = $pdo->prepare("SELECT banco, titular_cuenta, numero_cuenta, clabe_interbancaria, alias_tarjeta, comision_terminal_pct FROM sucursales WHERE sucursal_id = ?");
$sucursalInfo->execute([$_SESSION['sucursal_id']]);
$datosBanco = $sucursalInfo->fetch(PDO::FETCH_ASSOC);
$comisionPct = floatval($datosBanco['comision_terminal_pct'] ?? 0);

if ($credito_id) {
    $stmt = $pdo->prepare("
        SELECT cr.*, c.nombre_completo, c.telefono
        FROM creditos cr
        JOIN clientes c ON cr.cliente_id = c.cliente_id
        WHERE cr.credito_id = ?
    ");
    $stmt->execute([$credito_id]);
    $credito = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Registrar abono
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$soloVer) {
    $monto      = floatval($_POST['monto'] ?? 0);
    $metodo     = $_POST['metodo_pago'] ?? '';
    $notas      = trim($_POST['notas'] ?? '');
    $referencia = trim($_POST['referencia'] ?? '');
    $cred_id    = intval($_POST['credito_id'] ?? 0);
    $adelantado = isset($_POST['pago_adelantado']) ? 1 : 0;

    // Comisión de terminal
    $comisionMonto = 0;
    if ($metodo === 'Terminal' && $comisionPct > 0) {
        $comisionMonto = round($monto * $comisionPct / 100, 2);
    }

    // Adjuntar referencia a notas si aplica
    if ($metodo === 'Transferencia' && $referencia !== '') {
        $notas = 'Ref: ' . $referencia . ($notas !== '' ? ' — ' . $notas : '');
    }

    if ($monto <= 0) $errores[] = 'El monto debe ser mayor a 0.';
    if (!$metodo)    $errores[] = 'Selecciona el método de pago.';
    if ($metodo === 'Transferencia' && $referencia === '') $errores[] = 'Ingresa la referencia de la transferencia.';

    if ($credito && $monto > $credito['saldo_pendiente']) {
        $errores[] = 'El monto no puede ser mayor al saldo pendiente ($'.number_format($credito['saldo_pendiente'],2).')';
    }

    if (empty($errores)) {
        $pdo->beginTransaction();
        try {
            // Insertar abono
            $pdo->prepare("INSERT INTO abonos (credito_id, usuario_id, monto, comision_terminal, metodo_pago, notas) VALUES (?,?,?,?,?,?)")
                ->execute([$cred_id, $_SESSION['usuario_id'], $monto, $comisionMonto, $metodo, $notas]);

            // Actualizar saldo del crédito
            $nuevoSaldo = $credito['saldo_pendiente'] - $monto;
            if ($nuevoSaldo <= 0.001) {
                $pdo->prepare("UPDATE creditos SET saldo_pendiente = 0, estado = 'Liquidado' WHERE credito_id = ?")
                    ->execute([$cred_id]);
            } else {
                $pdo->prepare("UPDATE creditos SET saldo_pendiente = ?, estado = 'Activo', fecha_limite = IF(fecha_limite < CURDATE() OR ?, DATE_ADD(GREATEST(fecha_limite, CURDATE()), INTERVAL 1 DAY), fecha_limite) WHERE credito_id = ?")
                    ->execute([$nuevoSaldo, $adelantado, $cred_id]);
            }

            // Registrar ingreso en movimientos_caja si hay caja abierta
            $stmtCaja = $pdo->prepare("SELECT caja_id FROM cajas WHERE usuario_id = ? AND estado = 'Abierta' LIMIT 1");
            $stmtCaja->execute([$_SESSION['usuario_id']]);
            $cajaId = $stmtCaja->fetchColumn();

            if ($cajaId) {
                $notaMov = 'Abono de crédito — ' . $credito['nombre_completo'];
                if (trim($notas) !== '') $notaMov .= ': ' . trim($notas);
                $pdo->prepare("INSERT INTO movimientos_caja (caja_id, usuario_id, sucursal_id, tipo, monto, nota) VALUES (?,?,?,'Ingreso',?,?)")
                    ->execute([$cajaId, $_SESSION['usuario_id'], $_SESSION['sucursal_id'], $monto, $notaMov]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $errores[] = 'Error al registrar el abono: ' . $e->getMessage();
        }

        if (empty($errores)) {
            header('Location: abonos.php?ver='.$cred_id.'&msg=abonado');
            exit();
        }
    }
}

// Historial de abonos del crédito
$abonos = [];
if ($credito_id) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.nombre_completo as cajero
        FROM abonos a
        JOIN usuarios u ON a.usuario_id = u.usuario_id
        WHERE a.credito_id = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$credito_id]);
    $abonos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Si no hay crédito específico, mostrar lista de clientes con deuda
$porCliente    = [];
$statsAbonos   = null;
$clienteFiltro = intval($_GET['cliente'] ?? 0);

if (!$credito_id) {
    $statsAbonos = $pdo->query("
        SELECT
            COALESCE(SUM(CASE WHEN DATE(a.created_at) = CURDATE() THEN a.monto ELSE 0 END), 0)  AS cobrado_hoy,
            COALESCE(SUM(CASE WHEN YEAR(a.created_at) = YEAR(NOW()) AND MONTH(a.created_at) = MONTH(NOW()) THEN a.monto ELSE 0 END), 0) AS cobrado_mes
        FROM abonos a
    ")->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT cr.credito_id, cr.monto_total, cr.saldo_pendiente, cr.estado, cr.created_at,
               v.folio,
               c.cliente_id, c.nombre_completo, c.telefono
        FROM creditos cr
        JOIN clientes c ON cr.cliente_id = c.cliente_id
        LEFT JOIN ventas v ON cr.venta_id = v.venta_id
        WHERE cr.estado IN ('Activo', 'Vencido')
        ORDER BY c.nombre_completo ASC, cr.created_at ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $cid = $cr['cliente_id'];
        if (!isset($porCliente[$cid])) {
            $porCliente[$cid] = [
                'info'    => ['id' => $cid, 'nombre' => $cr['nombre_completo'], 'telefono' => $cr['telefono']],
                'creditos'=> [],
                'total'   => 0,
                'vencido' => false,
            ];
        }
        $porCliente[$cid]['creditos'][] = $cr;
        $porCliente[$cid]['total']     += floatval($cr['saldo_pendiente']);
        if ($cr['estado'] === 'Vencido') $porCliente[$cid]['vencido'] = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Abonos — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; display: grid; grid-template-columns: 380px 1fr; gap: 16px; align-items: start; }
    .content-full { flex: 1; padding: 24px; overflow-y: auto; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; margin-bottom: 14px; }
    .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 14px; }
    .breadcrumb { font-size: 12px; color: #aaa; margin-bottom: 16px; }
    .breadcrumb a { color: #14ace7; text-decoration: none; }
    .info-credito { background: #eef8ff; border: 1px solid #bbdefb; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; }
    .info-credito h4 { font-size: 15px; color: #333; margin: 0 0 8px; }
    .info-fila { display: flex; justify-content: space-between; font-size: 13px; color: #555; margin-bottom: 6px; }
    .info-fila.pendiente { font-size: 16px; font-weight: 700; color: #c0392b; }
    .info-fila.liquidado { font-size: 16px; font-weight: 700; color: #2e7d32; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .btn-abonar { width: 100%; background: #2e7d32; color: white; border: none; padding: 12px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-abonar:hover { background: #1b5e20; }
    .btn-volver { display: inline-block; background: white; color: #666; border: 1px solid #ddd; padding: 8px 16px; border-radius: 6px; font-size: 13px; text-decoration: none; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 10px 13px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 10px 13px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-efectivo { background: #e8f5e9; color: #2e7d32; }
    .badge-terminal { background: #e3f2fd; color: #1565c0; }
    .badge-transferencia { background: #fff8e1; color: #e65100; }
    .badge-mixto { background: #f3e5f5; color: #6a1b9a; }
    .badge-credito { background: #fdecea; color: #c0392b; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    .panel-transferencia { display: none; background: #f0f9ff; border: 1px solid #b3e0f7; border-radius: 8px; padding: 14px 16px; margin-bottom: 14px; }
    .panel-transferencia.visible { display: block; }
    .panel-transferencia h4 { font-size: 13px; font-weight: 700; color: #0077a8; margin: 0 0 10px; }
    .dato-banco { display: flex; justify-content: space-between; font-size: 13px; color: #444; margin-bottom: 6px; }
    .dato-banco span:first-child { color: #888; }
    .dato-banco span:last-child { font-weight: 600; }
    /* Landing — cobrar */
    .landing-wrap { max-width: 820px; }
    .landing-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
    .lstat { background: white; border-radius: 8px; padding: 14px 16px; border: 0.5px solid #e8e8e8; border-top: 3px solid #2e7d32; }
    .lstat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 0.4px; }
    .lstat h3 { font-size: 20px; font-weight: 700; color: #2e7d32; margin: 0; }
    .lstat.neutral h3 { color: #222; }
    .search-cobrar { width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 13px; margin-bottom: 12px; background: white; }
    .search-cobrar:focus { outline: none; border-color: #2e7d32; }
    .cliente-bloque { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; margin-bottom: 10px; overflow: hidden; }
    .cliente-bloque.vencido { border-left: 3px solid #c0392b; }
    .cliente-bloque-header { padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; border-bottom: 0.5px solid #f0f0f0; background: #fafafa; }
    .cbloque-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
    .cbloque-nombre { font-size: 14px; font-weight: 700; color: #222; }
    .cbloque-tel { font-size: 11px; color: #aaa; }
    .cbloque-total { font-size: 14px; font-weight: 700; color: #c0392b; white-space: nowrap; flex-shrink: 0; }
    .badge-venc { background: #fdecea; color: #c0392b; font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 99px; }
    .credito-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-bottom: 0.5px solid #f5f5f5; gap: 10px; }
    .credito-row:last-child { border-bottom: none; }
    .credito-row.row-vencido { background: #fffaf9; }
    .crow-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .crow-folio { font-size: 13px; font-weight: 600; color: #333; }
    .crow-fecha { font-size: 11px; color: #aaa; }
    .badge-estado-activo { background: #e8f5e9; color: #2e7d32; font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 99px; }
    .badge-estado-vencido { background: #fdecea; color: #c0392b; font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 99px; }
    .crow-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .crow-saldo { font-size: 13px; font-weight: 700; color: #c0392b; white-space: nowrap; }
    .btn-abonar-ir { background: #2e7d32; color: white; border: none; padding: 6px 14px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; white-space: nowrap; }
    .btn-abonar-ir:hover { background: #1b5e20; }
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
        <a class="menu-item" href="creditos.php">Créditos</a>
        <a class="menu-item active" href="abonos.php">Abonos</a>
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
            <h2><?= $credito_id ? 'Registrar abono' : 'Cobrar créditos' ?></h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <?php if ($credito): ?>
    <div class="content">
        <!-- Panel izquierdo: formulario de abono -->
        <div>
            <a class="btn-volver" href="abonos.php">← Volver</a>

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'abonado'): ?>
                <div class="msg msg-exito">Abono registrado correctamente.</div>
            <?php endif; ?>

            <div class="info-credito">
                <h4><?= htmlspecialchars($credito['nombre_completo']) ?></h4>
                <div class="info-fila"><span>Tel:</span><span><?= htmlspecialchars($credito['telefono']??'—') ?></span></div>
                <div class="info-fila"><span>Monto original:</span><span>$<?= number_format($credito['monto_total'],2) ?></span></div>
                <div class="info-fila <?= $credito['saldo_pendiente']<=0?'liquidado':'pendiente' ?>">
                    <span>Saldo pendiente:</span>
                    <span>$<?= number_format($credito['saldo_pendiente'],2) ?></span>
                </div>
                <div class="info-fila"><span>Estado:</span><span><?= $credito['estado'] ?></span></div>
            </div>

            <?php if (in_array($credito['estado'], ['Activo', 'Vencido']) && !$soloVer): ?>
                <?php if (!empty($errores)): ?>
                    <div class="errores"><ul><?php foreach($errores as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?></ul></div>
                <?php endif; ?>

                <div class="card">
                    <h3>Registrar abono</h3>
                    <form method="POST">
                        <input type="hidden" name="credito_id" value="<?= $credito['credito_id'] ?>">
                        <div class="form-group">
                            <label>Monto del abono *</label>
                            <input type="number" name="monto" placeholder="0.00" step="0.01" min="0.01" max="<?= $credito['saldo_pendiente'] ?>" autofocus>
                        </div>
                        <div class="form-group">
                            <label>Método de pago *</label>
                            <select name="metodo_pago" onchange="toggleTransferencia(this.value)">
                                <option value="">-- Selecciona --</option>
                                <option value="Efectivo">Efectivo</option>
                                <option value="Terminal">Terminal</option>
                                <option value="Transferencia">Transferencia</option>
                                <option value="Mixto">Mixto</option>
                            </select>
                        </div>

                        <!-- Panel de terminal -->
                        <div class="panel-transferencia" id="panelTerminal" style="background:#e3f2fd;border-color:#90caf9;">
                            <h4 style="color:#1565c0;">💳 Comisión de terminal</h4>
                            <?php if ($comisionPct > 0): ?>
                                <div class="dato-banco"><span>Porcentaje de comisión</span><span style="color:#1565c0;font-weight:700;"><?= number_format($comisionPct, 2) ?>%</span></div>
                                <div class="dato-banco" id="filaComisionMonto">
                                    <span>Comisión (cargo extra)</span>
                                    <span id="montoComision" style="color:#c0392b;">$0.00</span>
                                </div>
                                <div style="border-top:1px dashed #90caf9;margin:8px 0;"></div>
                                <div class="dato-banco" id="filaTotalTerminal" style="font-size:14px;">
                                    <span><strong>Total que paga el cliente</strong></span>
                                    <span id="totalTerminal" style="color:#1565c0;font-weight:700;">$0.00</span>
                                </div>
                                <div style="font-size:11px;color:#666;margin-top:6px;">El abono al crédito es solo el monto base, sin incluir la comisión.</div>
                            <?php else: ?>
                                <div style="font-size:12px;color:#888;">No hay comisión de terminal configurada para esta sucursal.</div>
                            <?php endif; ?>
                        </div>

                        <!-- Panel de transferencia -->
                        <div class="panel-transferencia" id="panelTransferencia">
                            <h4>📋 Datos para transferencia</h4>
                            <?php if (!empty($datosBanco['banco'])): ?>
                                <div class="dato-banco"><span>Banco</span><span><?= htmlspecialchars($datosBanco['banco']) ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($datosBanco['titular_cuenta'])): ?>
                                <div class="dato-banco"><span>Titular</span><span><?= htmlspecialchars($datosBanco['titular_cuenta']) ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($datosBanco['numero_cuenta'])): ?>
                                <div class="dato-banco"><span>N° cuenta</span><span><?= htmlspecialchars($datosBanco['numero_cuenta']) ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($datosBanco['clabe_interbancaria'])): ?>
                                <div class="dato-banco"><span>CLABE</span><span><?= htmlspecialchars($datosBanco['clabe_interbancaria']) ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($datosBanco['alias_tarjeta'])): ?>
                                <div class="dato-banco"><span>Alias / tarjeta</span><span><?= htmlspecialchars($datosBanco['alias_tarjeta']) ?></span></div>
                            <?php endif; ?>
                            <?php if (empty(array_filter($datosBanco ?? []))): ?>
                                <div style="font-size:12px;color:#888;">No hay datos bancarios configurados para esta sucursal.</div>
                            <?php endif; ?>
                        </div>

                        <!-- Referencia (solo transferencia) -->
                        <div class="form-group" id="grupoReferencia" style="display:none;">
                            <label>Referencia de transferencia *</label>
                            <input type="text" name="referencia" id="campoReferencia" placeholder="Número de folio o referencia...">
                        </div>

                        <div class="form-group">
                            <label>Notas (opcional)</label>
                            <input type="text" name="notas" placeholder="Observaciones del abono...">
                        </div>
                        <?php if ($credito['estado'] === 'Activo'): ?>
                        <div class="form-group" style="margin-top:4px;">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;">
                                <input type="checkbox" name="pago_adelantado" value="1">
                                Pago por adelantado (extiende fecha límite 1 día)
                            </label>
                        </div>
                        <?php endif; ?>
                        <button class="btn-abonar" type="submit">Registrar abono</button>
                    </form>
                </div>
            <?php elseif ($credito['estado'] === 'Liquidado'): ?>
                <div class="msg msg-exito">Este crédito ya está liquidado completamente.</div>
            <?php endif; ?>
        </div>

        <!-- Panel derecho: historial de abonos -->
        <div>
            <div class="card" style="padding:0;">
                <div style="padding:16px 20px;border-bottom:0.5px solid #eee;">
                    <h3 style="margin:0;">Historial de abonos</h3>
                </div>
                <?php if (count($abonos) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Monto</th>
                            <th>Método</th>
                            <th>Cajero</th>
                            <th>Fecha</th>
                            <th>Notas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($abonos as $a): ?>
                        <tr>
                            <td>
                                <span style="font-weight:700;color:#2e7d32;">$<?= number_format($a['monto'],2) ?></span>
                                <?php if (!empty($a['comision_terminal']) && $a['comision_terminal'] > 0): ?>
                                    <br><span style="font-size:11px;color:#1565c0;">+$<?= number_format($a['comision_terminal'],2) ?> comisión</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-<?= strtolower($a['metodo_pago']) ?>"><?= $a['metodo_pago'] ?></span></td>
                            <td style="font-size:12px;"><?= htmlspecialchars($a['cajero']) ?></td>
                            <td style="font-size:12px;color:#aaa;"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td>
                            <td style="font-size:12px;color:#888;"><?= htmlspecialchars($a['notas']??'—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="sin-resultados">No hay abonos registrados.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php else: ?>
    <div class="content-full">
        <div class="landing-wrap">
            <!-- Stats -->
            <div class="landing-stats">
                <div class="lstat">
                    <p>Cobrado hoy</p>
                    <h3>$<?= number_format($statsAbonos['cobrado_hoy'] ?? 0, 2) ?></h3>
                </div>
                <div class="lstat">
                    <p>Cobrado este mes</p>
                    <h3>$<?= number_format($statsAbonos['cobrado_mes'] ?? 0, 2) ?></h3>
                </div>
                <div class="lstat neutral">
                    <p>Clientes pendientes</p>
                    <h3><?= count($porCliente) ?></h3>
                </div>
            </div>

            <?php if (!empty($porCliente)): ?>
                <input type="text" class="search-cobrar" id="searchCobrar"
                       placeholder="Buscar cliente..." autocomplete="off"
                       oninput="filtrarCobrar(this.value)">

                <div id="listaCobrar">
                    <?php foreach ($porCliente as $cid => $grupo): ?>
                    <div class="cliente-bloque <?= $grupo['vencido'] ? 'vencido' : '' ?>"
                         id="cliente-<?= $cid ?>"
                         data-nombre="<?= htmlspecialchars(mb_strtolower($grupo['info']['nombre'] . ' ' . ($grupo['info']['telefono'] ?? ''))) ?>">
                        <div class="cliente-bloque-header">
                            <div class="cbloque-info">
                                <span class="cbloque-nombre"><?= htmlspecialchars($grupo['info']['nombre']) ?></span>
                                <?php if ($grupo['vencido']): ?><span class="badge-venc">Vencido</span><?php endif; ?>
                                <?php if ($grupo['info']['telefono']): ?>
                                    <span class="cbloque-tel"><?= htmlspecialchars($grupo['info']['telefono']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="cbloque-total">$<?= number_format($grupo['total'], 2) ?> pendiente</div>
                        </div>
                        <?php foreach ($grupo['creditos'] as $cr): ?>
                        <div class="credito-row <?= $cr['estado'] === 'Vencido' ? 'row-vencido' : '' ?>">
                            <div class="crow-info">
                                <span class="crow-folio"><?= $cr['folio'] ? htmlspecialchars('Folio '.$cr['folio']) : 'Crédito #'.$cr['credito_id'] ?></span>
                                <span class="crow-fecha"><?= date('d/m/Y', strtotime($cr['created_at'])) ?></span>
                                <span class="badge-estado-<?= strtolower($cr['estado']) ?>"><?= $cr['estado'] ?></span>
                            </div>
                            <div class="crow-right">
                                <span class="crow-saldo">$<?= number_format($cr['saldo_pendiente'], 2) ?></span>
                                <a class="btn-abonar-ir" href="abonos.php?credito_id=<?= $cr['credito_id'] ?>">Abonar →</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="sin-resultados" style="background:white;border-radius:8px;border:0.5px solid #e8e8e8;margin-top:4px;">
                    No hay créditos activos para cobrar.
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

const COMISION_PCT = <?= $comisionPct ?>;

function toggleTransferencia(valor) {
    // Panel terminal
    document.getElementById('panelTerminal').classList.toggle('visible', valor === 'Terminal');

    // Panel transferencia
    const panelTrans = document.getElementById('panelTransferencia');
    const grupo      = document.getElementById('grupoReferencia');
    const campo      = document.getElementById('campoReferencia');
    if (valor === 'Transferencia') {
        panelTrans.classList.add('visible');
        grupo.style.display = 'block';
        campo.required = true;
    } else {
        panelTrans.classList.remove('visible');
        grupo.style.display = 'none';
        campo.required = false;
        campo.value = '';
    }

    actualizarComision();
}

function actualizarComision() {
    if (COMISION_PCT <= 0) return;
    const sel   = document.querySelector('select[name="metodo_pago"]');
    const monto = parseFloat(document.querySelector('input[name="monto"]').value) || 0;
    if (!sel || sel.value !== 'Terminal') return;

    const comision = Math.round(monto * COMISION_PCT / 100 * 100) / 100;
    const total    = monto + comision;

    document.getElementById('montoComision').textContent = '$' + comision.toFixed(2);
    document.getElementById('totalTerminal').textContent  = '$' + total.toFixed(2);
}

// Landing: filtrar clientes
function filtrarCobrar(q) {
    const qn = q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
    document.querySelectorAll('#listaCobrar .cliente-bloque').forEach(el => {
        el.style.display = (el.dataset.nombre||'').includes(qn) ? '' : 'none';
    });
}

// Aplicar filtro si viene ?cliente=X
(function() {
    const clienteId = <?= $clienteFiltro ?>;
    if (!clienteId) return;
    const el = document.getElementById('cliente-' + clienteId);
    if (!el) return;
    // Hide everything else
    document.querySelectorAll('#listaCobrar .cliente-bloque').forEach(b => {
        b.style.display = b.id === 'cliente-' + clienteId ? '' : 'none';
    });
    // Show name in search box
    const nombre = el.querySelector('.cbloque-nombre');
    const search = document.getElementById('searchCobrar');
    if (search && nombre) search.value = nombre.textContent.trim();
})();

// Recalcular al cambiar el monto
document.querySelector('input[name="monto"]')?.addEventListener('input', actualizarComision);

// Si hubo error y el método ya estaba seleccionado, restaurar el panel
(function() {
    const sel = document.querySelector('select[name="metodo_pago"]');
    <?php if (!empty($_POST['metodo_pago'])): ?>
    if (sel) { sel.value = '<?= htmlspecialchars($_POST['metodo_pago']) ?>'; toggleTransferencia(sel.value); }
    <?php endif; ?>
})();
</script>
</body>
</html>
