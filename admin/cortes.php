<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador']);

$fecha    = $_GET['fecha'] ?? '';
$sucursal = intval($_GET['sucursal'] ?? 0);
$usuario  = intval($_GET['usuario'] ?? 0);

$where  = "WHERE 1=1";
$params = [];
if ($sucursal) { $where .= " AND c.sucursal_id = ?"; $params[] = $sucursal; }
if ($usuario)  { $where .= " AND c.usuario_id = ?"; $params[] = $usuario; }
if ($fecha)    { $where .= " AND DATE(c.abierta_en) = ?"; $params[] = $fecha; }

$stmt = $pdo->prepare("
    SELECT c.*,
        u.nombre_completo,
        s.nombre AS nombre_sucursal,
        COUNT(v.venta_id) AS total_ventas,
        COALESCE(SUM(v.total),0) AS total_cobrado,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Efectivo' THEN v.total ELSE 0 END),0) AS ef,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Terminal' THEN v.total ELSE 0 END),0) AS term,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Credito' THEN v.total ELSE 0 END),0) AS cred,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Mixto' THEN v.monto_efectivo ELSE 0 END),0) AS mixto_ef
    FROM cajas c
    JOIN usuarios u ON c.usuario_id = u.usuario_id
    JOIN sucursales s ON c.sucursal_id = s.sucursal_id
    LEFT JOIN ventas v ON c.caja_id = v.caja_id AND v.estado = 'Completada'
    $where
    GROUP BY c.caja_id
    ORDER BY c.abierta_en DESC
    LIMIT 200
");
$stmt->execute($params);
$cortes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totales del filtro
$totalVentas   = array_sum(array_column($cortes,'total_ventas'));
$totalCobrado  = array_sum(array_column($cortes,'total_cobrado'));
$totalFaltante = array_sum(array_map(fn($c) => min(0, floatval($c['diferencia']??0)), $cortes));
$totalSobrante = array_sum(array_map(fn($c) => max(0, floatval($c['diferencia']??0)), $cortes));

$sucursales = $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1")->fetchAll(PDO::FETCH_ASSOC);
$usuarios   = $pdo->query("SELECT usuario_id, nombre_completo FROM usuarios WHERE activo = 1 AND rol IN ('Cajero','Inventario/Cajero') ORDER BY nombre_completo")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cortes de Caja — Ferretería Aldrete</title>
</head>
<body>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Arial, sans-serif; display: flex; height: 100vh; overflow: hidden; }
    .sidebar { width: 220px; background: white; border-right: 1px solid #e8e8e8; display: flex; flex-direction: column; transition: width 0.3s; flex-shrink: 0; overflow: hidden; }
    .sidebar.collapsed { width: 0; }
    .sidebar-header { padding: 18px 16px; border-bottom: 1px solid #f0f0f0; }
    .sidebar-header h3 { font-size: 14px; font-weight: 700; color: #ff8c00; margin: 0; }
    .sidebar-header p { font-size: 11px; color: #999; margin: 4px 0 0; }
    .sidebar-menu { flex: 1; padding: 8px 0; overflow-y: auto; }
    .menu-item { display: block; padding: 10px 16px; font-size: 13px; color: #555; cursor: pointer; border-left: 3px solid transparent; text-decoration: none; transition: all 0.15s; white-space: nowrap; }
    .menu-item:hover { background: #fff5e6; color: #ff8c00; }
    .menu-item.active { background: #fff5e6; border-left-color: #ff8c00; color: #ff8c00; font-weight: 600; }
    .divider { height: 1px; background: #f0f0f0; margin: 6px 8px; }
    .sidebar-footer { padding: 12px 16px; border-top: 1px solid #f0f0f0; font-size: 11px; color: #bbb; white-space: nowrap; }
    .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #f7f7f7; }
    .topbar { background: #ff8c00; color: white; padding: 0 20px; height: 52px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
    .topbar-left { display: flex; align-items: center; gap: 12px; }
    .topbar h2 { font-size: 15px; font-weight: 600; }
    .toggle-btn { background: none; border: none; color: white; cursor: pointer; font-size: 20px; padding: 4px 8px; border-radius: 4px; }
    .toggle-btn:hover { background: rgba(255,255,255,0.2); }
    .topbar-right { display: flex; align-items: center; gap: 14px; font-size: 13px; }
    .logout-btn { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); color: white; padding: 5px 14px; border-radius: 5px; cursor: pointer; font-size: 12px; }
    .logout-btn:hover { background: rgba(255,255,255,0.3); }
    .content { flex: 1; padding: 24px; overflow-y: auto; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 14px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #ff8c00; }
    .btn-filtrar { background: #ff8c00; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 12px; margin-bottom: 14px; }
    .stat { background: white; border-radius: 8px; padding: 14px; border: 0.5px solid #e8e8e8; border-top: 3px solid #ff8c00; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 18px; font-weight: 700; color: #222; margin: 0; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 13px; text-align: left; font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 13px; font-size: 12px; color: #444; border-bottom: 0.5px solid #f5f5f5; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .badge-estado { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .estado-cerrada { background: #e8f5e9; color: #2e7d32; }
    .estado-abierta { background: #fff3e0; color: #e65c00; }
    .dif-ok  { color: #2e7d32; font-weight: 600; }
    .dif-neg { color: #c0392b; font-weight: 600; }
    .dif-pos { color: #f57f17; font-weight: 600; }
    .desglose { font-size: 10px; color: #aaa; margin-top: 2px; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h3>Ferretería Aldrete</h3>
        <p>Administrador</p>
    </div>
    <div class="sidebar-menu">
        <a class="menu-item" href="inicioAdmin.php">Inicio</a>
        <a class="menu-item" href="usuarios.php">Usuarios</a>
        <a class="menu-item" href="sucursales.php">Sucursales</a>
        <div class="divider"></div>
        <a class="menu-item" href="reporteVentas.php">Ventas</a>
        <a class="menu-item" href="reporteProductos.php">Productos más vendidos</a>
        <a class="menu-item" href="historial.php">Historial de movimientos</a>
        <a class="menu-item active" href="cortes.php">Cortes de caja</a>
        <div class="divider"></div>
        <a class="menu-item" href="../inventario/inicioInventario.php">Inventario</a>
        <a class="menu-item" href="../cajero/inicioCajero.php">Cajero</a>
        <div class="divider"></div>
        <a class="menu-item" href="clientes.php">Clientes</a>
        <a class="menu-item" href="creditos.php">Créditos</a>
    </div>
    <div class="sidebar-footer">v1.0.0</div>
</div>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2>Cortes de Caja</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <form method="GET">
            <div class="filtros">
                <div class="filtro-group">
                    <label>Fecha</label>
                    <input type="date" name="fecha" value="<?= htmlspecialchars($fecha) ?>">
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
                <div class="filtro-group">
                    <label>Cajero</label>
                    <select name="usuario">
                        <option value="0">Todos</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= $u['usuario_id'] ?>" <?= $usuario===$u['usuario_id']?'selected':'' ?>><?= htmlspecialchars($u['nombre_completo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn-filtrar" type="submit">Filtrar</button>
                <?php if ($fecha||$sucursal||$usuario): ?><a class="btn-limpiar" href="cortes.php">Limpiar</a><?php endif; ?>
            </div>
        </form>

        <div class="stats">
            <div class="stat"><p>Total cortes</p><h3><?= count($cortes) ?></h3></div>
            <div class="stat"><p>Total ventas</p><h3><?= $totalVentas ?></h3></div>
            <div class="stat"><p>Total cobrado</p><h3>$<?= number_format($totalCobrado,0) ?></h3></div>
            <div class="stat"><p>Faltantes</p><h3 style="color:#c0392b;">$<?= number_format(abs($totalFaltante),0) ?></h3></div>
            <div class="stat"><p>Sobrantes</p><h3 style="color:#f57f17;">$<?= number_format($totalSobrante,0) ?></h3></div>
        </div>

        <div class="tabla-wrapper">
            <?php if (count($cortes) > 0): ?>
            <table>
                <thead>
                    <tr><th>Turno</th><th>Cajero</th><th>Sucursal</th><th>Apertura</th><th>Cierre</th><th>Ventas</th><th>Cobrado</th><th>Esperado</th><th>Contado</th><th>Diferencia</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($cortes as $c): ?>
                    <tr>
                        <td style="color:#aaa;">#<?= $c['numero_turno'] ?></td>
                        <td><?= htmlspecialchars($c['nombre_completo']) ?></td>
                        <td style="font-size:11px;"><?= htmlspecialchars($c['nombre_sucursal']) ?></td>
                        <td>
                            <?= date('d/m/Y', strtotime($c['abierta_en'])) ?>
                            <div style="font-size:10px;color:#aaa;"><?= date('H:i', strtotime($c['abierta_en'])) ?></div>
                        </td>
                        <td>
                            <?php if ($c['cerrada_en']): ?>
                                <?= date('d/m/Y', strtotime($c['cerrada_en'])) ?>
                                <div style="font-size:10px;color:#aaa;"><?= date('H:i', strtotime($c['cerrada_en'])) ?></div>
                            <?php else: ?>
                                <span style="color:#ff8c00;font-size:11px;">En curso</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $c['total_ventas'] ?></td>
                        <td>
                            $<?= number_format($c['total_cobrado'],2) ?>
                            <div class="desglose">Ef:$<?= number_format($c['ef']+$c['mixto_ef'],0) ?> Term:$<?= number_format($c['term'],0) ?></div>
                        </td>
                        <td>$<?= number_format($c['monto_esperado']??0,2) ?></td>
                        <td>$<?= number_format($c['monto_cierre']??0,2) ?></td>
                        <td>
                            <?php if ($c['diferencia'] !== null): ?>
                                <?php $dif = floatval($c['diferencia']); ?>
                                <span class="<?= $dif==0?'dif-ok':($dif<0?'dif-neg':'dif-pos') ?>">
                                    <?= $dif==0?'Cuadrado':($dif<0?'-$'.number_format(abs($dif),2):'+$'.number_format($dif,2)) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#aaa;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge-estado <?= $c['estado']==='Cerrada'?'estado-cerrada':'estado-abierta' ?>">
                                <?= $c['estado'] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="sin-resultados">No hay cortes registrados.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
</body>
</html>