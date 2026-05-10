<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

$pdo->exec("UPDATE creditos SET estado='Vencido' WHERE estado='Activo' AND fecha_limite IS NOT NULL AND fecha_limite < CURDATE()");

$credito_id = intval($_GET['credito_id'] ?? $_GET['ver'] ?? 0);
$soloVer    = isset($_GET['ver']);
$credito    = null;
$errores    = [];

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
    $cred_id    = intval($_POST['credito_id'] ?? 0);

    if ($monto <= 0) $errores[] = 'El monto debe ser mayor a 0.';
    if (!$metodo)    $errores[] = 'Selecciona el método de pago.';

    if ($credito && $monto > $credito['saldo_pendiente']) {
        $errores[] = 'El monto no puede ser mayor al saldo pendiente ($'.number_format($credito['saldo_pendiente'],2).')';
    }

    if (empty($errores)) {
        // Insertar abono
        $pdo->prepare("INSERT INTO abonos (credito_id, usuario_id, monto, metodo_pago, notas) VALUES (?,?,?,?,?)")
            ->execute([$cred_id, $_SESSION['usuario_id'], $monto, $metodo, $notas]);

        // Actualizar saldo
        $nuevoSaldo = $credito['saldo_pendiente'] - $monto;
        if ($nuevoSaldo <= 0) {
            $pdo->prepare("UPDATE creditos SET saldo_pendiente = 0, estado = 'Liquidado' WHERE credito_id = ?")
                ->execute([$cred_id]);
        } else {
            $pdo->prepare("UPDATE creditos SET saldo_pendiente = ?, estado = 'Activo', fecha_limite = IF(fecha_limite < CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY), fecha_limite) WHERE credito_id = ?")
                ->execute([$nuevoSaldo, $cred_id]);
        }

        header('Location: cajero_abonos.php?ver='.$cred_id.'&msg=abonado');
        exit();
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

// Si no hay crédito específico, mostrar lista de créditos activos
$creditosActivos = [];
if (!$credito_id) {
    $stmt = $pdo->query("
        SELECT cr.*, c.nombre_completo
        FROM creditos cr
        JOIN clientes c ON cr.cliente_id = c.cliente_id
        WHERE cr.estado IN ('Activo', 'Vencido')
        ORDER BY (cr.estado = 'Vencido') DESC, cr.created_at DESC
    ");
    $creditosActivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    .badge-mixto { background: #f3e5f5; color: #6a1b9a; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    .lista-creditos { display: flex; flex-direction: column; gap: 10px; }
    .credito-item { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px 16px; display: flex; justify-content: space-between; align-items: center; }
    .credito-item:hover { border-color: #14ace7; }
    .btn-seleccionar { background: #14ace7; color: white; border: none; padding: 7px 14px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
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

<?php renderAdminSidebar('cajero_abonos'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Abonos a créditos</h2>
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
            <a class="btn-volver" href="cajero_abonos.php">← Volver</a>

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
                            <select name="metodo_pago">
                                <option value="">-- Selecciona --</option>
                                <option value="Efectivo">Efectivo</option>
                                <option value="Terminal">Terminal</option>
                                <option value="Mixto">Mixto</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Notas (opcional)</label>
                            <input type="text" name="notas" placeholder="Observaciones del abono...">
                        </div>
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
                            <th>#</th>
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
                            <td style="color:#aaa;"><?= $a['abono_id'] ?></td>
                            <td style="font-weight:700;color:#2e7d32;">$<?= number_format($a['monto'],2) ?></td>
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
    <!-- Lista de créditos activos para seleccionar -->
    <div class="content-full">
        <h1 style="font-size:20px;font-weight:600;color:#222;margin:0 0 20px;">Selecciona un crédito</h1>
        <?php if (count($creditosActivos) > 0): ?>
            <div class="lista-creditos">
                <?php foreach ($creditosActivos as $cr): ?>
                <div class="credito-item">
                    <div>
                        <strong><?= htmlspecialchars($cr['nombre_completo']) ?></strong>
                        <div style="font-size:12px;color:#aaa;margin-top:2px;">
                            Pendiente: <strong style="color:#c0392b;">$<?= number_format($cr['saldo_pendiente'],2) ?></strong>
                            de $<?= number_format($cr['monto_total'],2) ?>
                        </div>
                    </div>
                    <a class="btn-seleccionar" href="cajero_abonos.php?credito_id=<?= $cr['credito_id'] ?>">Abonar</a>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="sin-resultados" style="background:white;border-radius:8px;border:0.5px solid #e8e8e8;">No hay créditos activos.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>


