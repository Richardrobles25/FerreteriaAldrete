<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

// Verificar si ya tiene caja abierta hoy
$stmt = $pdo->prepare("SELECT * FROM cajas WHERE usuario_id = ? AND estado = 'Abierta' LIMIT 1");
$stmt->execute([$_SESSION['usuario_id']]);
$cajaAbierta = $stmt->fetch(PDO::FETCH_ASSOC);

// [FIX-MEDIO-D3-10] (portado de admin/cajero_abrirCaja.php): "numero_turno" se calculaba
// como "cuantas cajas de OTROS usuarios estan ABIERTAS ahora mismo + 1" — un numero de
// turnos CONCURRENTES, no un consecutivo real. En la operacion tipica (un solo cajero a
// la vez por sucursal) ese conteo casi siempre da 0, asi que absolutamente todos los
// turnos del dia se abrian como "Turno #1". Ahora es un consecutivo real: cuantas cajas
// (abiertas o ya cerradas, de cualquier usuario) se han abierto HOY en esta sucursal + 1.
$stmtTurno = $pdo->prepare("
    SELECT COUNT(*) + 1
    FROM cajas
    WHERE sucursal_id = ? AND DATE(abierta_en) = CURDATE()
");
$stmtTurno->execute([$_SESSION['sucursal_id']]);
$siguienteTurno = $stmtTurno->fetchColumn();

// Contar cajas abiertas de otros usuarios en la sucursal (para mostrar aviso)
$stmtAbiertas = $pdo->prepare("
    SELECT COUNT(*)
    FROM cajas
    WHERE sucursal_id = ? AND estado = 'Abierta' AND usuario_id != ?
");
$stmtAbiertas->execute([$_SESSION['sucursal_id'], $_SESSION['usuario_id']]);
$cajasAbiertas = $stmtAbiertas->fetchColumn();

$erroresApertura = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$cajaAbierta) {
    // [FIX-A1] Verificar CSRF antes de procesar la apertura de caja
    requerirCSRF($_POST['_token'] ?? '', 'abrirCaja.php');
    $monto_apertura_raw = $_POST['monto_apertura'] ?? '';
    $monto_apertura     = floatval($monto_apertura_raw);
    $observaciones      = trim($_POST['observaciones'] ?? '');

    // [AUTOFIX] VALIDACION-1A-1: Requerir monto de apertura mayor a $0.00
    if ($monto_apertura_raw === '' || $monto_apertura < 0) {
        $erroresApertura[] = 'El monto de apertura es obligatorio y no puede ser negativo.';
    } elseif ($monto_apertura == 0) {
        $erroresApertura[] = 'El monto de apertura debe ser mayor a $0.00. Si no tienes fondo inicial, contacta al administrador.';
    } elseif ($monto_apertura > 50000) {
        // [FIX-ALTO-D3-03] Sin tope superior, un error de dedo (un cero de mas) se
        // truncaba en silencio a $99,999,999.99 (local) o tiraba error 500 (produccion,
        // sql_mode estricto) al chocar con monto_apertura DECIMAL(10,2).
        $erroresApertura[] = 'El monto de apertura no puede ser mayor a $50,000.00. Verifica la cantidad capturada.';
    }

    if (empty($erroresApertura)) {
        // [FIX-CRIT-D3-02] (portado de admin/cajero_abrirCaja.php): antes solo un
        // SELECT-luego-INSERT sin transaccion ni candado, asi que dos sesiones del mismo
        // usuario (dos dispositivos, o un doble envio) podian abrir dos cajas "Abierta" a
        // la vez — una caja fantasma con su propio monto de apertura que nunca existio
        // fisicamente. Ahora se relee y bloquea la ausencia de caja abierta dentro de una
        // transaccion justo antes de insertar, con el indice UNIQUE de la BD como respaldo.
        $pdo->beginTransaction();
        try {
            $stmtRelock = $pdo->prepare("SELECT caja_id FROM cajas WHERE usuario_id = ? AND sucursal_id = ? AND estado = 'Abierta' FOR UPDATE");
            $stmtRelock->execute([$_SESSION['usuario_id'], $_SESSION['sucursal_id']]);
            if ($stmtRelock->fetch()) {
                $pdo->rollBack();
                header('Location: inicioCajeroInventario.php?msg=cajaAbierta');
                exit();
            }

            $stmt = $pdo->prepare("
                INSERT INTO cajas (sucursal_id, usuario_id, monto_apertura, observaciones, estado, numero_turno)
                VALUES (?, ?, ?, ?, 'Abierta', ?)
            ");
            $stmt->execute([$_SESSION['sucursal_id'], $_SESSION['usuario_id'], $monto_apertura, $observaciones, $siguienteTurno]);
            $pdo->commit();
            header('Location: inicioCajeroInventario.php?msg=cajaAbierta');
            exit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23000') {
                header('Location: inicioCajeroInventario.php?msg=cajaAbierta');
                exit();
            }
            throw $e;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Abrir Caja — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 28px; overflow-y: auto; display: flex; justify-content: center; align-items: flex-start; }
    .form-card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 32px; width: 100%; max-width: 480px; }
    .form-card h1 { font-size: 18px; color: #222; margin: 0 0 8px; font-weight: 600; }
    .form-card p { font-size: 13px; color: #888; margin: 0 0 24px; }
    .info-box { background: #eef8ff; border: 1px solid #bbdefb; border-radius: 8px; padding: 14px 16px; margin-bottom: 24px; font-size: 13px; color: #1565c0; }
    .info-box strong { display: block; font-size: 15px; margin-bottom: 4px; }
    .alerta-box { background: #fdecea; border: 1px solid #ffcdd2; border-radius: 8px; padding: 14px 16px; margin-bottom: 24px; font-size: 13px; color: #c0392b; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; color: #333; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .form-group textarea { min-height: 80px; resize: vertical; }
    .btn-abrir { width: 100%; background: #14ace7; color: white; border: none; padding: 14px; border-radius: 6px; font-size: 15px; font-weight: 700; cursor: pointer; }
    .btn-abrir:hover { background: #1196cb; }
    .btn-ir { width: 100%; background: #2e7d32; color: white; border: none; padding: 14px; border-radius: 6px; font-size: 15px; font-weight: 700; cursor: pointer; text-decoration: none; display: block; text-align: center; }
    .btn-ir:hover { background: #1b5e20; }
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
        <a class="menu-item active" href="abrirCaja.php">Abrir caja</a>
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
            <h2>Abrir Caja</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="content">
        <div class="form-card">
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'sinCaja'): ?>
                <div class="alerta-box" style="background:#fff3e0;border-color:#ffb74d;color:#e65100;">
                    ⚠ Necesitas abrir una caja para acceder a ese módulo.<br>
                    <span style="font-size:12px;opacity:.85;">Sesión actual: <strong><?= htmlspecialchars($_SESSION['nombre_completo']) ?> (<?= htmlspecialchars($_SESSION['rol'] ?? '') ?>)</strong>. La caja es por usuario — si la abriste con otra cuenta, inicia sesión con esa cuenta.</span>
                </div>
            <?php endif; ?>
            <?php // [FIX-MEDIO-D3-08] (portado de admin/cajero_abrirCaja.php): requerirCSRF()
                  // redirige con "?msg=error_token" si el token es invalido/expirado, pero esta
                  // pagina no mostraba ningun aviso para ese caso. ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'error_token'): ?>
                <div class="alerta-box">
                    ⚠ Tu sesión expiró o la página estuvo abierta demasiado tiempo. Recarga la página e intenta abrir la caja de nuevo.
                </div>
            <?php endif; ?>
            <?php if ($cajaAbierta): ?>
                <h1>Caja en curso</h1>
                <p>Ya tienes un turno activo en este momento.</p>
                <div class="info-box">
                    <strong>Turno #<?= $cajaAbierta['numero_turno'] ?></strong>
                    Abierta el <?= date('d/m/Y \a \l\a\s H:i', strtotime($cajaAbierta['abierta_en'])) ?>
                    · Monto inicial: $<?= number_format($cajaAbierta['monto_apertura'], 2) ?>
                </div>
                <a class="btn-ir" href="nuevaVenta.php">Ir a nueva venta</a>
            <?php else: ?>
                <h1>Abrir caja</h1>
                <p>Registra el monto con el que inicias el turno.</p>

                <?php if ($cajasAbiertas > 0): ?>
                    <div class="info-box">
                        Hay <?= $cajasAbiertas ?> caja(s) abierta(s) en esta sucursal actualmente.
                    </div>
                <?php endif; ?>

                <?php if (!empty($erroresApertura)): ?>
                    <div style="background:#fdecea;color:#c0392b;border-left:3px solid #c0392b;padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:14px;">
                        <?= htmlspecialchars($erroresApertura[0]) ?>
                    </div>
                <?php endif; ?>
                <form method="POST">
                    <!-- [FIX-A1] Token CSRF para proteger la apertura de caja -->
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="form-group">
                        <label>Monto inicial en caja *</label>
                        <!-- [AUTOFIX] VALIDACION-1A-1: min="0.01" y required para bloquear $0 y vacío -->
                        <input type="number" name="monto_apertura" placeholder="0.00" step="0.01" min="0.01" max="50000" required autofocus
                               value="<?= isset($_POST['monto_apertura']) ? htmlspecialchars($_POST['monto_apertura']) : '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Observaciones (opcional)</label>
                        <textarea name="observaciones" placeholder="Notas del turno..."></textarea>
                    </div>
                    <button class="btn-abrir" type="submit">Abrir caja — Turno #<?= $siguienteTurno ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

</script>
</body>
</html>
