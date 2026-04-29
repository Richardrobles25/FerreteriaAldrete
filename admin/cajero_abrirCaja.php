<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

// Verificar si ya tiene caja abierta
$stmt = $pdo->prepare("SELECT * FROM cajas WHERE usuario_id = ? AND estado = 'Abierta' LIMIT 1");
$stmt->execute([$_SESSION['usuario_id']]);
$cajaAbierta = $stmt->fetch(PDO::FETCH_ASSOC);

// Calcular siguiente número de turno para esta sucursal
$stmtTurno = $pdo->prepare("SELECT COUNT(*) + 1 FROM cajas WHERE sucursal_id = ? AND estado = 'Abierta'");
$stmtTurno->execute([$_SESSION['sucursal_id']]);
$siguienteTurno = $stmtTurno->fetchColumn();

// Contar cajas abiertas actualmente en la sucursal
$stmtAbiertas = $pdo->prepare("SELECT COUNT(*) FROM cajas WHERE sucursal_id = ? AND estado = 'Abierta'");
$stmtAbiertas->execute([$_SESSION['sucursal_id']]);
$cajasAbiertas = $stmtAbiertas->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$cajaAbierta) {
    $monto_apertura = floatval($_POST['monto_apertura'] ?? 0);
    $observaciones  = trim($_POST['observaciones'] ?? '');

    $stmt = $pdo->prepare("
        INSERT INTO cajas (sucursal_id, usuario_id, monto_apertura, observaciones, estado, numero_turno)
        VALUES (?, ?, ?, ?, 'Abierta', ?)
    ");
    $stmt->execute([$_SESSION['sucursal_id'], $_SESSION['usuario_id'], $monto_apertura, $observaciones, $siguienteTurno]);
    header('Location: cajero_inicio.php?msg=cajaAbierta');
    exit();
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
</style>

<?php renderAdminSidebar('cajero_abrir_caja'); ?>

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
            <?php if ($cajaAbierta): ?>
                <h1>Caja en curso</h1>
                <p>Ya tienes un turno activo en este momento.</p>
                <div class="info-box">
                    <strong>Turno #<?= $cajaAbierta['numero_turno'] ?></strong>
                    Abierta el <?= date('d/m/Y \a \l\a\s H:i', strtotime($cajaAbierta['abierta_en'])) ?>
                    · Monto inicial: $<?= number_format($cajaAbierta['monto_apertura'], 2) ?>
                </div>
                <a class="btn-ir" href="cajero_nuevaVenta.php">Ir a nueva venta</a>
            <?php else: ?>
                <h1>Abrir caja</h1>
                <p>Registra el monto con el que inicias el turno.</p>

                <?php if ($cajasAbiertas > 0): ?>
                    <div class="info-box">
                        Hay <?= $cajasAbiertas ?> caja(s) abierta(s) en esta sucursal actualmente.
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label>Monto inicial en caja</label>
                        <input type="number" name="monto_apertura" placeholder="0.00" step="0.01" min="0" value="0" autofocus>
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
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}
</script>
</body>
</html>


