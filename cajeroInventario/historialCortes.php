<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

$fecha = $_GET['fecha'] ?? '';

$where  = "WHERE c.usuario_id = ?";
$params = [$_SESSION['usuario_id']];

if ($fecha) { $where .= " AND DATE(c.abierta_en) = ?"; $params[] = $fecha; }

$stmt = $pdo->prepare("
    SELECT c.*,
        s.nombre AS nombre_sucursal,
        COUNT(v.venta_id) AS total_ventas,
        COALESCE(SUM(v.total),0) AS total_cobrado,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Efectivo' THEN v.total ELSE 0 END),0) AS ef,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Terminal' THEN v.total ELSE 0 END),0) AS term,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Credito' THEN v.total ELSE 0 END),0) AS cred,
        COALESCE(SUM(CASE WHEN v.metodo_pago='Mixto' THEN v.monto_efectivo ELSE 0 END),0) AS mixto_ef
    FROM cajas c
    JOIN sucursales s ON c.sucursal_id = s.sucursal_id
    LEFT JOIN ventas v ON c.caja_id = v.caja_id AND v.estado = 'Completada'
    $where
    GROUP BY c.caja_id
    ORDER BY c.abierta_en DESC
");
$stmt->execute($params);
$cortes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totales del cajero
$totalVentas  = array_sum(array_column($cortes, 'total_ventas'));
$totalCobrado = array_sum(array_column($cortes, 'total_cobrado'));
$totalCortes  = count(array_filter($cortes, fn($c) => $c['estado'] === 'Cerrada'));

// Corte seleccionado para ver detalle
$verCorte   = intval($_GET['ver'] ?? 0);
$detalleCorte = null;
$detalleVentas = [];

if ($verCorte) {
    $stmtC = $pdo->prepare("
        SELECT c.*, s.nombre AS nombre_sucursal
        FROM cajas c
        JOIN sucursales s ON c.sucursal_id = s.sucursal_id
        WHERE c.caja_id = ? AND c.usuario_id = ?
    ");
    $stmtC->execute([$verCorte, $_SESSION['usuario_id']]);
    $detalleCorte = $stmtC->fetch(PDO::FETCH_ASSOC);

    if ($detalleCorte) {
        $stmtV = $pdo->prepare("
            SELECT v.venta_id, v.total, v.metodo_pago, v.created_at,
                   cl.nombre_completo AS cliente
            FROM ventas v
            LEFT JOIN clientes cl ON v.cliente_id = cl.cliente_id
            WHERE v.caja_id = ? AND v.estado = 'Completada'
            ORDER BY v.created_at ASC
        ");
        $stmtV->execute([$verCorte]);
        $detalleVentas = $stmtV->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Cortes — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 24px; overflow-y: auto; display: grid; grid-template-columns: 1fr 380px; gap: 18px; align-items: start; }
    .stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-bottom: 14px; }
    .stat { background: white; border-radius: 8px; padding: 14px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 20px; font-weight: 700; color: #222; margin: 0; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 13px; margin-bottom: 13px; display: flex; gap: 10px; align-items: flex-end; }
    .filtro-group { display: flex; flex-direction: column; gap: 4px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .corte-item { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 16px; margin-bottom: 10px; cursor: pointer; transition: border-color 0.15s; }
    .corte-item:hover { border-color: #14ace7; }
    .corte-item.seleccionado { border-color: #14ace7; border-width: 1.5px; }
    .corte-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
    .corte-info h4 { font-size: 14px; color: #333; margin: 0 0 3px; font-weight: 600; }
    .corte-info p { font-size: 12px; color: #888; margin: 0; }
    .corte-total { text-align: right; }
    .corte-total strong { font-size: 16px; font-weight: 700; color: #222; display: block; }
    .corte-total span { font-size: 11px; color: #aaa; }
    .corte-desglose { display: flex; gap: 14px; flex-wrap: wrap; }
    .corte-desglose span { font-size: 11px; color: #888; }
    .badge-estado { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; margin-left: 6px; }
    .estado-cerrada { background: #e8f5e9; color: #2e7d32; }
    .estado-abierta { background: #e3f2fd; color: #1565c0; }
    .dif-ok  { color: #2e7d32; font-size: 11px; font-weight: 600; }
    .dif-neg { color: #c0392b; font-size: 11px; font-weight: 600; }
    .dif-pos { color: #1565c0; font-size: 11px; font-weight: 600; }
    .sin-resultados { padding: 30px; text-align: center; color: #aaa; font-size: 13px; background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; }
    .detalle-card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; position: sticky; top: 0; }
    .detalle-card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 16px; }
    .det-fila { display: flex; justify-content: space-between; font-size: 13px; color: #555; padding: 7px 0; border-bottom: 0.5px solid #f9f9f9; }
    .det-fila:last-child { border-bottom: none; }
    .det-fila.total { font-weight: 700; color: #222; font-size: 14px; border-top: 1px solid #eee; padding-top: 10px; margin-top: 4px; }
    .det-seccion { margin-bottom: 16px; padding-bottom: 16px; border-bottom: 0.5px solid #f0f0f0; }
    .det-seccion:last-child { border-bottom: none; margin-bottom: 0; }
    .det-seccion h4 { font-size: 12px; color: #aaa; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 10px; }
    .ventas-mini { max-height: 200px; overflow-y: auto; }
    .venta-mini-row { display: flex; justify-content: space-between; font-size: 12px; color: #555; padding: 5px 0; border-bottom: 0.5px solid #f9f9f9; }
    .venta-mini-row:last-child { border-bottom: none; }
    .badge-mini { font-size: 10px; padding: 1px 6px; border-radius: 99px; font-weight: 600; }
    .badge-ef { background: #e8f5e9; color: #2e7d32; }
    .badge-term { background: #e3f2fd; color: #1565c0; }
    .badge-mix { background: #f3e5f5; color: #6a1b9a; }
    .badge-cred { background: #e3f2fd; color: #1565c0; }
    .sin-seleccion { text-align: center; color: #aaa; font-size: 13px; padding: 40px 20px; }
    .dif-box { border-radius: 6px; padding: 10px 14px; font-size: 13px; font-weight: 600; text-align: center; margin-top: 12px; }
    .dif-box.cuadrado { background: #e8f5e9; color: #2e7d32; }
    .dif-box.faltante  { background: #fdecea; color: #c0392b; }
    .dif-box.sobrante  { background: #fff8e1; color: #1565c0; }
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
        <a class="menu-item active" href="historialCortes.php">Historial de cortes</a>
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
            <h2>Historial de cortes</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <!-- Lista de cortes -->
        <div>
            <div class="stats">
                <div class="stat"><p>Turnos totales</p><h3><?= count($cortes) ?></h3></div>
                <div class="stat"><p>Total ventas</p><h3><?= $totalVentas ?></h3></div>
                <div class="stat"><p>Total cobrado</p><h3 style="font-size:16px;">$<?= number_format($totalCobrado,0) ?></h3></div>
            </div>

            <form method="GET">
                <div class="filtros">
                    <div class="filtro-group">
                        <label>Filtrar por fecha</label>
                        <input type="date" name="fecha" value="<?= htmlspecialchars($fecha) ?>">
                    </div>
                    <button class="btn-filtrar" type="submit">Filtrar</button>
                    <?php if ($fecha): ?><a class="btn-limpiar" href="historialCortes.php">Limpiar</a><?php endif; ?>
                </div>
            </form>

            <?php if (count($cortes) > 0): ?>
                <?php foreach ($cortes as $c): ?>
                <?php
                    $dif = floatval($c['diferencia'] ?? 0);
                    $difClass = $dif == 0 ? 'dif-ok' : ($dif < 0 ? 'dif-neg' : 'dif-pos');
                    $difLabel = $dif == 0 ? 'Cuadrada' : ($dif < 0 ? '-$'.number_format(abs($dif),2) : '+$'.number_format($dif,2));
                    $esSeleccionado = $verCorte === intval($c['caja_id']);
                ?>
                <a href="historialCortes.php?ver=<?= $c['caja_id'] ?><?= $fecha?'&fecha='.$fecha:'' ?>"
                   class="corte-item <?= $esSeleccionado?'seleccionado':'' ?>"
                   style="text-decoration:none;display:block;">
                    <div class="corte-header">
                        <div class="corte-info">
                            <h4>
                                Turno #<?= $c['numero_turno'] ?>
                                <span class="badge-estado <?= $c['estado']==='Cerrada'?'estado-cerrada':'estado-abierta' ?>"><?= $c['estado'] ?></span>
                            </h4>
                            <p>
                                <?= date('d/m/Y H:i', strtotime($c['abierta_en'])) ?>
                                <?php if ($c['cerrada_en']): ?>
                                    → <?= date('H:i', strtotime($c['cerrada_en'])) ?>
                                <?php else: ?>
                                    · <span style="color:#14ace7;">En curso</span>
                                <?php endif; ?>
                                · <?= htmlspecialchars($c['nombre_sucursal']) ?>
                            </p>
                        </div>
                        <div class="corte-total">
                            <strong>$<?= number_format($c['total_cobrado'],2) ?></strong>
                            <span><?= $c['total_ventas'] ?> ventas</span>
                            <?php if ($c['estado'] === 'Cerrada'): ?>
                                <span class="<?= $difClass ?>"><?= $difLabel ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="corte-desglose">
                        <span>Ef: $<?= number_format($c['ef']+$c['mixto_ef'],2) ?></span>
                        <span>Term: $<?= number_format($c['term'],2) ?></span>
                        <span>Cred: $<?= number_format($c['cred'],2) ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="sin-resultados">No hay cortes registrados.</div>
            <?php endif; ?>
        </div>

        <!-- Detalle del corte seleccionado -->
        <div>
            <div class="detalle-card">
                <?php if ($detalleCorte): ?>
                    <h3>Turno #<?= $detalleCorte['numero_turno'] ?> — Detalle</h3>

                    <div class="det-seccion">
                        <h4>Información del turno</h4>
                        <div class="det-fila"><span>Apertura</span><span><?= date('d/m/Y H:i', strtotime($detalleCorte['abierta_en'])) ?></span></div>
                        <?php if ($detalleCorte['cerrada_en']): ?>
                            <div class="det-fila"><span>Cierre</span><span><?= date('d/m/Y H:i', strtotime($detalleCorte['cerrada_en'])) ?></span></div>
                        <?php endif; ?>
                        <div class="det-fila"><span>Sucursal</span><span><?= htmlspecialchars($detalleCorte['nombre_sucursal']) ?></span></div>
                        <div class="det-fila"><span>Monto apertura</span><span>$<?= number_format($detalleCorte['monto_apertura'],2) ?></span></div>
                    </div>

                    <?php if ($detalleCorte['estado'] === 'Cerrada'): ?>
                    <div class="det-seccion">
                        <h4>Resultado del corte</h4>
                        <div class="det-fila"><span>Efectivo esperado</span><span>$<?= number_format($detalleCorte['monto_esperado']??0,2) ?></span></div>
                        <div class="det-fila"><span>Efectivo contado</span><span>$<?= number_format($detalleCorte['monto_cierre']??0,2) ?></span></div>
                        <?php
                            $dif = floatval($detalleCorte['diferencia']??0);
                            $difBoxClass = $dif==0?'cuadrado':($dif<0?'faltante':'sobrante');
                            $difBoxLabel = $dif==0?'✅ Caja cuadrada':($dif<0?'⚠ Faltante: $'.number_format(abs($dif),2):'📌 Sobrante: $'.number_format($dif,2));
                        ?>
                        <div class="dif-box <?= $difBoxClass ?>"><?= $difBoxLabel ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if ($detalleCorte['observaciones']): ?>
                    <div class="det-seccion">
                        <h4>Observaciones</h4>
                        <p style="font-size:13px;color:#555;"><?= htmlspecialchars($detalleCorte['observaciones']) ?></p>
                    </div>
                    <?php endif; ?>

                    <div class="det-seccion">
                        <h4>Ventas del turno (<?= count($detalleVentas) ?>)</h4>
                        <?php if (count($detalleVentas) > 0): ?>
                        <div class="ventas-mini">
                            <?php foreach ($detalleVentas as $v): ?>
                            <div class="venta-mini-row">
                                <span><?= htmlspecialchars($v['cliente']??'Público general') ?></span>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span class="badge-mini badge-<?= strtolower(substr($v['metodo_pago'],0,2)) === 'ef'?'ef':(strtolower(substr($v['metodo_pago'],0,2))==='te'?'term':(strtolower(substr($v['metodo_pago'],0,2))==='mi'?'mix':'cred')) ?>"><?= $v['metodo_pago'] ?></span>
                                    <strong>$<?= number_format($v['total'],2) ?></strong>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="det-fila total" style="margin-top:10px;">
                            <span>Total</span>
                            <span>$<?= number_format(array_sum(array_column($detalleVentas,'total')),2) ?></span>
                        </div>
                        <?php else: ?>
                            <p style="color:#aaa;font-size:12px;text-align:center;padding:12px 0;">Sin ventas en este turno</p>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <div class="sin-seleccion">
                        Selecciona un turno de la lista para ver su detalle.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
<script src="../includes/auto_filter.js"></script>
</body>
</html>
