<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);

$periodo   = $_GET['periodo'] ?? 'hoy';
$sucursal  = intval($_GET['sucursal'] ?? 0);
$fechaDesde = match($periodo) {
    'hoy'      => date('Y-m-d'),
    'semana'   => date('Y-m-d', strtotime('-7 days')),
    'mes'      => date('Y-m-d', strtotime('-30 days')),
    'trimestre'=> date('Y-m-d', strtotime('-90 days')),
    'año'      => date('Y-m-d', strtotime('-365 days')),
    default    => date('Y-m-d')
};
$fechaHasta = date('Y-m-d');

if ($periodo === 'personalizado') {
    $fechaDesde = $_GET['desde'] ?? date('Y-m-d');
    $fechaHasta = $_GET['hasta'] ?? date('Y-m-d');
}

$sucursalWhere = $sucursal ? "AND ca.sucursal_id = $sucursal" : "";

// ── Exportar ─────────────────────────────────────────────────────────
if (isset($_GET['exportar']) && in_array($_GET['exportar'], ['pdf','excel'])) {
    require_once __DIR__ . '/export_helper.php';

    // Ventas por día (sin LIMIT para exportar todo)
    $stmtExp = $pdo->prepare("
        SELECT DATE(v.created_at) AS fecha,
               s.nombre AS sucursal,
               COUNT(*) AS num_ventas,
               SUM(v.total) AS total
        FROM ventas v
        JOIN cajas ca ON v.caja_id = ca.caja_id
        JOIN sucursales s ON ca.sucursal_id = s.sucursal_id
        WHERE v.estado='Completada' AND DATE(v.created_at) BETWEEN ? AND ?
        $sucursalWhere
        GROUP BY DATE(v.created_at), ca.sucursal_id
        ORDER BY fecha DESC
    ");
    $stmtExp->execute([$fechaDesde, $fechaHasta]);
    $expData = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    // Resumen
    $stmtR = $pdo->prepare("
        SELECT COUNT(*) AS total_ventas,
               COALESCE(SUM(v.total),0) AS total_cobrado,
               COALESCE(SUM(v.descuento),0) AS total_descuentos
        FROM ventas v JOIN cajas ca ON v.caja_id=ca.caja_id
        WHERE v.estado='Completada' AND DATE(v.created_at) BETWEEN ? AND ? $sucursalWhere
    ");
    $stmtR->execute([$fechaDesde, $fechaHasta]);
    $resExp = $stmtR->fetch(PDO::FETCH_ASSOC);

    $titulo = 'Reporte de Ventas';
    $subtitulo = "Período: $fechaDesde al $fechaHasta";
    $columnas = ['Fecha', 'Sucursal', 'No. Ventas', 'Total'];
    $filas = array_map(fn($r) => [
        date('d/m/Y', strtotime($r['fecha'])),
        $r['sucursal'],
        $r['num_ventas'],
        '$' . number_format($r['total'], 2),
    ], $expData);
    $resumen = [
        ['label' => 'Total Ventas',    'valor' => $resExp['total_ventas']],
        ['label' => 'Total Cobrado',   'valor' => '$' . number_format($resExp['total_cobrado'], 2)],
        ['label' => 'Total Descuentos','valor' => '$' . number_format($resExp['total_descuentos'], 2)],
    ];

    if ($_GET['exportar'] === 'pdf') {
        exportarPDF($titulo, $subtitulo, $columnas, $filas, $resumen, 'L');
    } else {
        exportarExcel($titulo, $subtitulo, $columnas, $filas, $resumen);
    }
}

// Resumen general
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_ventas,
        COALESCE(SUM(v.total),0) AS total_cobrado,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Efectivo' THEN v.total ELSE 0 END),0) AS efectivo,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Terminal' THEN v.total ELSE 0 END),0) AS terminal,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Credito' THEN v.total ELSE 0 END),0) AS credito,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Mixto' THEN v.total ELSE 0 END),0) AS mixto,
        COALESCE(SUM(v.descuento),0) AS total_descuentos,
        COALESCE(SUM(v.comision_terminal),0) AS total_comisiones,
        COUNT(DISTINCT DATE(v.created_at)) AS dias_con_ventas
    FROM ventas v
    JOIN cajas ca ON v.caja_id = ca.caja_id
    WHERE v.estado = 'Completada'
      AND DATE(v.created_at) BETWEEN ? AND ?
      $sucursalWhere
");
$stmt->execute([$fechaDesde, $fechaHasta]);
$resumen = $stmt->fetch(PDO::FETCH_ASSOC);

// Ventas por día
$stmt = $pdo->prepare("
    SELECT DATE(v.created_at) AS fecha,
           COUNT(*) AS num_ventas,
           SUM(v.total) AS total,
           s.nombre AS sucursal
    FROM ventas v
    JOIN cajas ca ON v.caja_id = ca.caja_id
    JOIN sucursales s ON ca.sucursal_id = s.sucursal_id
    WHERE v.estado='Completada' AND DATE(v.created_at) BETWEEN ? AND ?
    $sucursalWhere
    GROUP BY DATE(v.created_at), ca.sucursal_id
    ORDER BY fecha DESC
    LIMIT 30
");
$stmt->execute([$fechaDesde, $fechaHasta]);
$ventasPorDia = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Por sucursal
$stmt = $pdo->prepare("
    SELECT s.nombre AS sucursal,
           COUNT(*) AS num_ventas,
           COALESCE(SUM(v.total),0) AS total
    FROM ventas v
    JOIN cajas ca ON v.caja_id = ca.caja_id
    JOIN sucursales s ON ca.sucursal_id = s.sucursal_id
    WHERE v.estado='Completada' AND DATE(v.created_at) BETWEEN ? AND ?
    GROUP BY ca.sucursal_id
    ORDER BY total DESC
");
$stmt->execute([$fechaDesde, $fechaHasta]);
$ventasPorSucursal = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sucursales = $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Ventas — Ferretería Aldrete</title>
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
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 16px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 12px; margin-bottom: 16px; }
    .stat { background: white; border-radius: 8px; padding: 16px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 20px; font-weight: 700; color: #222; margin: 0; }
    .stat small { font-size: 11px; color: #14ace7; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    .card-header { padding: 14px 18px; border-bottom: 0.5px solid #eee; font-size: 14px; font-weight: 600; color: #333; }
    table { width: 100%; border-collapse: collapse; }
    th { padding: 10px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; border-bottom: 1px solid #eee; background: #f9f9f9; }
    td { padding: 10px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .sin-datos { padding: 30px; text-align: center; color: #aaa; font-size: 13px; }
    .metodo-row { display: flex; justify-content: space-between; padding: 10px 18px; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; color: #444; }
    .metodo-row:last-child { border-bottom: none; }
    .campo-personalizado { display: none; }
    .campo-personalizado.visible { display: flex; gap: 10px; }
</style>

<?php renderAdminSidebar('reporte_ventas'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Reporte de Ventas</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <div class="content-header">
            <h1>Reporte de ventas</h1>
            <div style="display:flex;gap:8px;">
                <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'pdf'])) ?>" style="background:#c0392b;color:white;border:none;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">⬇ PDF</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'excel'])) ?>" style="background:#1b5e20;color:white;border:none;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">⬇ Excel</a>
            </div>
        </div>

        <form method="GET">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Período</label>
                    <select name="periodo" onchange="togglePersonalizado(this.value)">
                        <option value="hoy" <?= $periodo==='hoy'?'selected':'' ?>>Hoy</option>
                        <option value="semana" <?= $periodo==='semana'?'selected':'' ?>>Última semana</option>
                        <option value="mes" <?= $periodo==='mes'?'selected':'' ?>>Último mes</option>
                        <option value="trimestre" <?= $periodo==='trimestre'?'selected':'' ?>>Último trimestre</option>
                        <option value="año" <?= $periodo==='año'?'selected':'' ?>>Último año</option>
                        <option value="personalizado" <?= $periodo==='personalizado'?'selected':'' ?>>Personalizado</option>
                    </select>
                </div>
                <div class="campo-personalizado <?= $periodo==='personalizado'?'visible':'' ?>" id="campoPers">
                    <div class="filtro-group">
                        <label>Desde</label>
                        <input type="date" name="desde" value="<?= $_GET['desde'] ?? date('Y-m-d') ?>">
                    </div>
                    <div class="filtro-group">
                        <label>Hasta</label>
                        <input type="date" name="hasta" value="<?= $_GET['hasta'] ?? date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="filtro-group">
                    <label>Sucursal</label>
                    <select name="sucursal">
                        <option value="0">Todas</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= $s['sucursal_id'] ?>" <?= $sucursal===$s['sucursal_id']?'selected':'' ?>><?= htmlspecialchars($s['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-filtrar" type="submit">Aplicar</button>
            </div>
        </form>

        <div class="stats">
            <div class="stat"><p>Total ventas</p><h3><?= $resumen['total_ventas'] ?></h3></div>
            <div class="stat"><p>Total cobrado</p><h3>$<?= number_format($resumen['total_cobrado'],0) ?></h3></div>
            <div class="stat"><p>Descuentos</p><h3>$<?= number_format($resumen['total_descuentos'],0) ?></h3></div>
            <div class="stat"><p>Comisiones term.</p><h3>$<?= number_format($resumen['total_comisiones'],0) ?></h3></div>
            <div class="stat"><p>Días con ventas</p><h3><?= $resumen['dias_con_ventas'] ?></h3></div>
            <div class="stat">
                <p>Promedio por día</p>
                <h3>$<?= $resumen['dias_con_ventas']>0 ? number_format($resumen['total_cobrado']/$resumen['dias_con_ventas'],0) : 0 ?></h3>
            </div>
        </div>

        <div class="grid-2">
            <!-- Por método de pago -->
            <div class="card">
                <div class="card-header">Por método de pago</div>
                <div class="metodo-row"><span>Efectivo</span><strong>$<?= number_format($resumen['efectivo'],2) ?></strong></div>
                <div class="metodo-row"><span>Terminal</span><strong>$<?= number_format($resumen['terminal'],2) ?></strong></div>
                <div class="metodo-row"><span>Crédito</span><strong>$<?= number_format($resumen['credito'],2) ?></strong></div>
                <div class="metodo-row"><span>Mixto</span><strong>$<?= number_format($resumen['mixto'],2) ?></strong></div>
            </div>

            <!-- Por sucursal -->
            <div class="card">
                <div class="card-header">Por sucursal</div>
                <?php if (count($ventasPorSucursal) > 0): ?>
                    <?php foreach ($ventasPorSucursal as $vs): ?>
                    <div class="metodo-row">
                        <span><?= htmlspecialchars($vs['sucursal']) ?> (<?= $vs['num_ventas'] ?> ventas)</span>
                        <strong>$<?= number_format($vs['total'],2) ?></strong>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="sin-datos">Sin datos</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ventas por día -->
        <div class="card">
            <div class="card-header">Detalle por día</div>
            <?php if (count($ventasPorDia) > 0): ?>
            <table>
                <thead><tr><th>Fecha</th><th>Sucursal</th><th>Ventas</th><th>Total</th></tr></thead>
                <tbody>
                    <?php foreach ($ventasPorDia as $vd): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($vd['fecha'])) ?></td>
                        <td><?= htmlspecialchars($vd['sucursal']) ?></td>
                        <td><?= $vd['num_ventas'] ?></td>
                        <td style="font-weight:700;">$<?= number_format($vd['total'],2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="sin-datos">No hay ventas en este período.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
function togglePersonalizado(val) {
    document.getElementById('campoPers').classList.toggle('visible', val === 'personalizado');
}
</script>
</body>
</html>


