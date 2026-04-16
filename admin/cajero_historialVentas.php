<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

$fecha  = $_GET['fecha'] ?? date('Y-m-d');
$metodo = $_GET['metodo'] ?? '';

// Caja actual del cajero
$stmt = $pdo->prepare("SELECT caja_id FROM cajas WHERE usuario_id = ? AND estado = 'Abierta' LIMIT 1");
$stmt->execute([$_SESSION['usuario_id']]);
$cajaActual = $stmt->fetchColumn();

$where  = "WHERE v.usuario_id = ? AND DATE(v.created_at) = ?";
$params = [$_SESSION['usuario_id'], $fecha];

if ($metodo) {
    $where .= " AND v.metodo_pago = ?";
    $params[] = $metodo;
}

$stmt = $pdo->prepare("
    SELECT v.*, c.nombre_completo as cliente
    FROM ventas v
    LEFT JOIN clientes c ON v.cliente_id = c.cliente_id
    $where
    ORDER BY v.created_at DESC
");
$stmt->execute($params);
$ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totales del día
$stmtTot = $pdo->prepare("
    SELECT
        COUNT(*) as total_ventas,
        COALESCE(SUM(total),0) as total_cobrado,
        COALESCE(SUM(CASE WHEN metodo_pago='Efectivo' THEN total ELSE 0 END),0) as ef,
        COALESCE(SUM(CASE WHEN metodo_pago='Terminal' THEN total ELSE 0 END),0) as term,
        COALESCE(SUM(CASE WHEN metodo_pago='Credito' THEN total ELSE 0 END),0) as cred,
        COALESCE(SUM(CASE WHEN metodo_pago='Mixto' THEN total ELSE 0 END),0) as mixto
    FROM ventas
    WHERE usuario_id = ? AND DATE(created_at) = ? AND estado = 'Completada'
");
$stmtTot->execute([$_SESSION['usuario_id'], $fecha]);
$totales = $stmtTot->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Ventas — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; }
    .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .content-header h1 { font-size: 20px; color: #222; font-weight: 600; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 16px; margin-bottom: 16px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px,1fr)); gap: 12px; margin-bottom: 16px; }
    .stat { background: white; border-radius: 8px; padding: 14px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 18px; font-weight: 700; color: #222; margin: 0; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-efectivo { background: #e8f5e9; color: #2e7d32; }
    .badge-terminal { background: #e3f2fd; color: #1565c0; }
    .badge-mixto { background: #f3e5f5; color: #6a1b9a; }
    .badge-credito { background: #e3f2fd; color: #1565c0; }
    .badge-completada { background: #e8f5e9; color: #2e7d32; }
    .badge-cancelada { background: #fdecea; color: #c0392b; }
    .badge-pendiente { background: #e3f2fd; color: #1565c0; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
    .btn-ver { background: #e3f2fd; color: #1565c0; border: none; padding: 4px 10px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
</style>

<?php renderAdminSidebar('cajero_historial_ventas'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Historial de Ventas</h2>
        </div>
        <div class="topbar-right">
            <span>Hola, <?= htmlspecialchars($_SESSION['nombre_completo']) ?></span>
            <form method="POST" action="/logout.php">
                <button class="logout-btn" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </div>

    <div class="content">
        <div class="content-header">
            <h1>Mis ventas</h1>
        </div>

        <form method="GET">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Fecha</label>
                    <input type="date" name="fecha" value="<?= htmlspecialchars($fecha) ?>">
                </div>
                <div class="filtro-group">
                    <label>Método de pago</label>
                    <select name="metodo">
                        <option value="">Todos</option>
                        <option value="Efectivo" <?= $metodo==='Efectivo'?'selected':'' ?>>Efectivo</option>
                        <option value="Terminal" <?= $metodo==='Terminal'?'selected':'' ?>>Terminal</option>
                        <option value="Mixto" <?= $metodo==='Mixto'?'selected':'' ?>>Mixto</option>
                        <option value="Credito" <?= $metodo==='Credito'?'selected':'' ?>>Crédito</option>
                    </select>
                </div>
                <button class="btn-filtrar" type="submit">Filtrar</button>
            </div>
        </form>

        <div class="stats">
            <div class="stat"><p>Total ventas</p><h3><?= $totales['total_ventas'] ?></h3></div>
            <div class="stat"><p>Total cobrado</p><h3>$<?= number_format($totales['total_cobrado'],2) ?></h3></div>
            <div class="stat"><p>Efectivo</p><h3>$<?= number_format($totales['ef'],2) ?></h3></div>
            <div class="stat"><p>Terminal</p><h3>$<?= number_format($totales['term'],2) ?></h3></div>
            <div class="stat"><p>Crédito</p><h3>$<?= number_format($totales['cred'],2) ?></h3></div>
        </div>

        <div class="tabla-wrapper">
            <?php if (count($ventas) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Cliente</th>
                        <th>Método</th>
                        <th>Subtotal</th>
                        <th>Descuento</th>
                        <th>Total</th>
                        <th>Estado</th>
                        <th>Hora</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ventas as $v): ?>
                    <tr>
                        <td style="color:#aaa;"><?= $v['venta_id'] ?></td>
                        <td><?= htmlspecialchars($v['cliente'] ?? 'Público general') ?></td>
                        <td><span class="badge badge-<?= strtolower($v['metodo_pago']) ?>"><?= $v['metodo_pago'] ?></span></td>
                        <td>$<?= number_format($v['subtotal'],2) ?></td>
                        <td><?= $v['descuento']>0 ? '-$'.number_format($v['descuento'],2) : '—' ?></td>
                        <td style="font-weight:700;">$<?= number_format($v['total'],2) ?></td>
                        <td><span class="badge badge-<?= strtolower($v['estado']) ?>"><?= $v['estado'] ?></span></td>
                        <td style="color:#aaa;font-size:12px;"><?= date('H:i', strtotime($v['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="sin-resultados">No hay ventas para esta fecha.</div>
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


