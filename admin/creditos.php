<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);
require_once '../includes/topbar_info.php';

// [FIX-QUINCENA-FIJA] Los cortes de credito NO son "15 dias despues de hoy" (rodante) sino
// fechas fijas de calendario: el dia 15 y el ultimo dia de cada mes, iguales para todos los
// clientes (asi es como realmente se cobra). Si el corte cae en domingo (la tienda cierra ese
// dia), se recorre al lunes siguiente para que el cliente sí tenga oportunidad de ir a pagar
// antes de que se le considere vencido.
function siguienteCorteQuincenal(string $fechaDesde): string {
    $ts   = strtotime($fechaDesde);
    $dia  = (int)date('j', $ts);
    $mes  = (int)date('n', $ts);
    $anio = (int)date('Y', $ts);
    $ultimoDiaMes = (int)date('t', $ts);

    if ($dia < 15) {
        $corte = mktime(0, 0, 0, $mes, 15, $anio);
    } elseif ($dia < $ultimoDiaMes) {
        $corte = mktime(0, 0, 0, $mes, $ultimoDiaMes, $anio);
    } else {
        $corte = mktime(0, 0, 0, $mes + 1, 15, $anio);
    }
    if ((int)date('N', $corte) === 7) { // ISO-8601: 7 = domingo
        $corte = strtotime('+1 day', $corte);
    }
    return date('Y-m-d', $corte);
}

try {
    $pdo->exec("UPDATE creditos SET estado='Vencido' WHERE estado='Activo' AND fecha_limite IS NOT NULL AND fecha_limite <= CURDATE()");
} catch (\PDOException $e) {
    error_log('[Ferreteria/creditos] Error al actualizar vencidos: ' . $e->getMessage());
}

// [FIX-MEDIO-E-12] La mora solo se aplicaba en cajero_creditos.php al cargar esa pagina; si
// nadie la visitaba, un credito podia quedar "Vencido" indefinidamente sin que mora_acumulada
// ni saldo_pendiente reflejaran nunca el recargo (aqui en admin/creditos.php, o en abonos.php,
// nunca se disparaba). Se porta el mismo bloque (con el mismo candado FOR UPDATE por credito
// para evitar aplicar la mora dos veces si dos cargas caen casi juntas) para que la mora se
// aplique sin importar cual de las pantallas de credito visite el administrador primero.
// [FIX-MORA-QUINCENAL] La mora ahora se cobra de forma recurrente cada quincena (15 dias)
// que el credito siga sin liquidarse, no solo una vez. El gatillo ya no es
// "mora_acumulada = 0" (que solo permitia una unica aplicacion en la vida del credito) sino
// que la fecha_limite ya haya pasado; al cobrar la mora se recorre fecha_limite otros 15 dias
// para que sirva como el proximo punto de corte. El while interno alcanza al credito si paso
// mas de una quincena sin que nadie visitara esta pagina (aplica una mora por cada quincena
// vencida, compuesta sobre el saldo que ya trae mora anterior).
try {
    $pdo->beginTransaction();
    $stmtMoraList = $pdo->query("
        SELECT cr.credito_id, s.porcentaje_mora
        FROM creditos cr
        JOIN ventas v     ON cr.venta_id     = v.venta_id
        JOIN cajas  ca    ON v.caja_id       = ca.caja_id
        JOIN sucursales s ON ca.sucursal_id  = s.sucursal_id
        WHERE cr.estado = 'Vencido' AND cr.fecha_limite <= CURDATE() AND s.porcentaje_mora > 0
    ");
    foreach ($stmtMoraList->fetchAll(PDO::FETCH_ASSOC) as $cm) {
        $stmtLockCred = $pdo->prepare("SELECT saldo_pendiente, fecha_limite, estado FROM creditos WHERE credito_id = ? FOR UPDATE");
        $stmtLockCred->execute([$cm['credito_id']]);
        $credLock = $stmtLockCred->fetch(PDO::FETCH_ASSOC);
        if (!$credLock || $credLock['estado'] !== 'Vencido') continue;

        $pct               = floatval($cm['porcentaje_mora']);
        $saldoActual       = round(floatval($credLock['saldo_pendiente']), 2);
        $fechaLimiteActual = $credLock['fecha_limite'];
        $ultimaMora        = 0.0;

        while (strtotime($fechaLimiteActual) <= strtotime(date('Y-m-d'))) {
            $moraAmt           = round($saldoActual * $pct / 100, 2);
            $saldoBase         = $saldoActual;
            $saldoActual       = round($saldoActual + $moraAmt, 2);
            // [FIX-MORA-QUINCENAL] Se registra con la fecha real en que venció esa quincena
            // (no "hoy"), para que el historial de mora no muestre la misma fecha repetida
            // cuando se ponen al día varias quincenas atrasadas en una sola carga de pagina.
            $fechaEstaMora     = $fechaLimiteActual;
            $fechaLimiteActual = siguienteCorteQuincenal($fechaLimiteActual);
            $ultimaMora        = $moraAmt;
            $pdo->prepare("INSERT INTO movimientos_mora (credito_id, monto, saldo_base, porcentaje, created_at) VALUES (?,?,?,?,?)")
                ->execute([$cm['credito_id'], $moraAmt, $saldoBase, $pct, $fechaEstaMora]);
        }
        if ($ultimaMora > 0) {
            $pdo->prepare("UPDATE creditos SET mora_acumulada = ?, saldo_pendiente = ?, fecha_limite = ? WHERE credito_id = ?")
                ->execute([$ultimaMora, $saldoActual, $fechaLimiteActual, $cm['credito_id']]);
        }
    }
    $pdo->commit();
} catch (\PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[Ferreteria/creditos] Error al aplicar mora: ' . $e->getMessage());
}

$busqueda = trim($_GET['buscar'] ?? '');
$estado = $_GET['estado'] ?? '';
$sucursal = intval($_GET['sucursal'] ?? 0);
$vencimiento = $_GET['vencimiento'] ?? '';

$where = "WHERE 1=1";
$params = [];

if ($busqueda !== '') {
    $where .= " AND (c.nombre_completo LIKE ? OR c.telefono LIKE ?)";
    $params[] = '%' . $busqueda . '%';
    $params[] = '%' . $busqueda . '%';
}
if ($estado !== '') {
    $where .= " AND cr.estado = ?";
    $params[] = $estado;
}
if ($sucursal) {
    $where .= " AND ca.sucursal_id = ?";
    $params[] = $sucursal;
}
if ($vencimiento === 'por_vencer') {
    $where .= " AND cr.estado IN ('Activo', 'Vencido') AND cr.fecha_limite IS NOT NULL AND cr.fecha_limite BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
}
if ($vencimiento === 'vencido') {
    $where .= " AND cr.estado = 'Vencido'";
}

// ── Exportar ─────────────────────────────────────────────────────────
if (isset($_GET['exportar']) && in_array($_GET['exportar'], ['pdf','excel'])) {
    require_once __DIR__ . '/export_helper.php';

    $stmtExp = $pdo->prepare("
        SELECT cr.credito_id, c.nombre_completo, c.telefono, cr.monto_total, cr.saldo_pendiente,
               cr.estado, cr.fecha_limite, cr.created_at, s.nombre AS sucursal,
               COALESCE(SUM(a.monto),0) AS total_abonado
        FROM creditos cr
        JOIN clientes c ON cr.cliente_id = c.cliente_id
        JOIN ventas v ON cr.venta_id = v.venta_id
        JOIN cajas ca ON v.caja_id = ca.caja_id
        JOIN sucursales s ON ca.sucursal_id = s.sucursal_id
        LEFT JOIN abonos a ON cr.credito_id = a.credito_id
        $where
        GROUP BY cr.credito_id, c.nombre_completo, c.telefono, cr.monto_total, cr.saldo_pendiente, cr.estado, cr.fecha_limite, cr.created_at, s.nombre
        ORDER BY (cr.estado='Vencido') DESC, (cr.estado='Activo') DESC, cr.created_at DESC
    ");
    $stmtExp->execute($params);
    $expData = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    $titulo = 'Reporte de Créditos';
    $subtitulo = implode(' | ', array_filter([
        $busqueda  ? "Búsqueda: $busqueda" : '',
        $estado    ? "Estado: $estado"      : '',
        $sucursal  ? "Sucursal ID: $sucursal" : '',
    ]));
    $columnas = ['# Crédito','Cliente','Teléfono','Monto Total','Saldo Pendiente','Total Abonado','Estado','Vencimiento','Fecha','Sucursal'];
    $filas = array_map(fn($r) => [
        '#' . $r['credito_id'],
        $r['nombre_completo'],
        $r['telefono'] ?? '',
        '$' . number_format($r['monto_total'], 2),
        '$' . number_format($r['saldo_pendiente'], 2),
        '$' . number_format($r['total_abonado'], 2),
        $r['estado'],
        $r['fecha_limite'] ? date('d/m/Y', strtotime($r['fecha_limite'])) : '—',
        date('d/m/Y', strtotime($r['created_at'])),
        $r['sucursal'],
    ], $expData);

    // Calcular resumen rápido
    $totalPendiente = array_sum(array_column($expData, 'saldo_pendiente'));
    $resumen = [
        ['label' => 'Total Créditos', 'valor' => count($expData)],
        ['label' => 'Saldo Pendiente Total', 'valor' => '$' . number_format($totalPendiente, 2)],
    ];

    if ($_GET['exportar'] === 'pdf') {
        exportarPDF($titulo, $subtitulo, $columnas, $filas, $resumen, 'L');
    } else {
        exportarExcel($titulo, $subtitulo, $columnas, $filas, $resumen);
    }
}

$stmt = $pdo->prepare("
    SELECT
        cr.credito_id,
        cr.cliente_id,
        cr.venta_id,
        cr.monto_total,
        cr.saldo_pendiente,
        cr.estado,
        cr.fecha_limite,
        cr.created_at,
        c.nombre_completo,
        c.telefono,
        c.limite_credito,
        v.created_at AS fecha_venta,
        s.nombre AS sucursal,
        COALESCE(SUM(a.monto), 0) AS total_abonado,
        COUNT(a.abono_id) AS numero_abonos,
        MAX(a.created_at) AS ultimo_abono
    FROM creditos cr
    JOIN clientes c ON cr.cliente_id = c.cliente_id
    JOIN ventas v ON cr.venta_id = v.venta_id
    JOIN cajas ca ON v.caja_id = ca.caja_id
    JOIN sucursales s ON ca.sucursal_id = s.sucursal_id
    LEFT JOIN abonos a ON cr.credito_id = a.credito_id
    $where
    GROUP BY cr.credito_id, c.nombre_completo, c.telefono, c.limite_credito, v.created_at, s.nombre
    ORDER BY (cr.estado = 'Vencido') DESC, (cr.estado = 'Activo') DESC, cr.created_at DESC
");
$stmt->execute($params);
$creditos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totales = $pdo->query("
    SELECT
        COUNT(*) AS total,
        COUNT(CASE WHEN estado = 'Activo' THEN 1 END) AS activos,
        COUNT(CASE WHEN estado = 'Vencido' THEN 1 END) AS vencidos,
        COUNT(CASE WHEN estado = 'Liquidado' THEN 1 END) AS liquidados,
        COALESCE(SUM(CASE WHEN estado IN ('Activo', 'Vencido') THEN saldo_pendiente ELSE 0 END), 0) AS total_pendiente
    FROM creditos
")->fetch(PDO::FETCH_ASSOC);

$resumenAbonos = $pdo->query("
    SELECT COUNT(*) AS total_abonos, COALESCE(SUM(monto), 0) AS monto_abonado
    FROM abonos
")->fetch(PDO::FETCH_ASSOC);

$sucursales = $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creditos - Ferreteria Aldrete</title>
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
    .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px,1fr)); gap: 12px; margin-bottom: 16px; }
    .stat { background: white; border-radius: 8px; padding: 14px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 20px; font-weight: 700; color: #222; margin: 0; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px; margin-bottom: 14px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 5px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: top; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-activo { background: #e8f5e9; color: #2e7d32; }
    .badge-liquidado { background: #f0f0f0; color: #666; }
    .badge-vencido { background: #fdecea; color: #c0392b; }
    .acciones { display: flex; gap: 6px; }
    .btn-accion { padding: 5px 11px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-ver { background: #e3f2fd; color: #1565c0; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
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

<?php renderAdminSidebar('creditos_admin'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left"><button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button><h2>Creditos de clientes</h2></div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">- <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <div class="content-header">
            <h1>Control de creditos</h1>
            <div style="display:flex;gap:8px;">
                <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'pdf'])) ?>" style="background:#c0392b;color:white;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">⬇ PDF</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'excel'])) ?>" style="background:#1b5e20;color:white;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">⬇ Excel</a>
            </div>
        </div>

        <div class="stats">
            <div class="stat"><p>Total creditos</p><h3><?= intval($totales['total'] ?? 0) ?></h3></div>
            <div class="stat"><p>Activos</p><h3><?= intval($totales['activos'] ?? 0) ?></h3></div>
            <div class="stat"><p>Vencidos</p><h3><?= intval($totales['vencidos'] ?? 0) ?></h3></div>
            <div class="stat"><p>Liquidados</p><h3><?= intval($totales['liquidados'] ?? 0) ?></h3></div>
            <div class="stat"><p>Total pendiente</p><h3>$<?= number_format(floatval($totales['total_pendiente'] ?? 0), 0) ?></h3></div>
            <div class="stat"><p>Total abonado</p><h3>$<?= number_format(floatval($resumenAbonos['monto_abonado'] ?? 0), 0) ?></h3></div>
        </div>

        <form method="GET">
            <div class="filtros">
                <div class="filtro-group"><label>Buscar cliente</label><input type="text" name="buscar" placeholder="Nombre o telefono..." value="<?= htmlspecialchars($busqueda) ?>" oninput="filtrarTabla(this.value)"></div>
                <div class="filtro-group">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="">Todos</option>
                        <option value="Activo" <?= $estado === 'Activo' ? 'selected' : '' ?>>Activo</option>
                        <option value="Liquidado" <?= $estado === 'Liquidado' ? 'selected' : '' ?>>Liquidado</option>
                        <option value="Vencido" <?= $estado === 'Vencido' ? 'selected' : '' ?>>Vencido</option>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Sucursal</label>
                    <select name="sucursal">
                        <option value="0">Todas</option>
                        <?php foreach ($sucursales as $item): ?><option value="<?= $item['sucursal_id'] ?>" <?= $sucursal === intval($item['sucursal_id']) ? 'selected' : '' ?>><?= htmlspecialchars($item['nombre']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Vencimiento</label>
                    <select name="vencimiento">
                        <option value="">Todos</option>
                        <option value="por_vencer" <?= $vencimiento === 'por_vencer' ? 'selected' : '' ?>>Por vencer 7 dias</option>
                        <option value="vencido" <?= $vencimiento === 'vencido' ? 'selected' : '' ?>>Ya vencidos</option>
                    </select>
                </div>
                <button class="btn-filtrar" type="submit">Filtrar</button>
                <?php if ($busqueda !== '' || $estado !== '' || $sucursal || $vencimiento !== ''): ?><a class="btn-limpiar" href="creditos.php">Limpiar</a><?php endif; ?>
            </div>
        </form>

        <div class="tabla-wrapper">
            <?php if (count($creditos) > 0): ?>
                <table>
                    <thead><tr><th>#</th><th>Cliente</th><th>Sucursal</th><th>Venta</th><th>Abonos</th><th>Pendiente</th><th>Vence</th><th>Estado</th><th>Acciones</th></tr></thead>
                    <tbody id="tablaFiltrable">
                        <?php foreach ($creditos as $credito): ?>
                            <tr>
                                <td style="color:#aaa;"><?= intval($credito['credito_id']) ?></td>
                                <td><strong><?= htmlspecialchars($credito['nombre_completo']) ?></strong><div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($credito['telefono'] ?: 'Sin telefono') ?></div><div style="font-size:11px;color:#aaa;">Limite: $<?= number_format($credito['limite_credito'], 2) ?></div></td>
                                <td><?= htmlspecialchars($credito['sucursal']) ?></td>
                                <td><strong>$<?= number_format($credito['monto_total'], 2) ?></strong><div style="font-size:11px;color:#aaa;">Venta #<?= intval($credito['venta_id']) ?> - <?= date('d/m/Y', strtotime($credito['fecha_venta'])) ?></div></td>
                                <td><strong>$<?= number_format($credito['total_abonado'], 2) ?></strong><div style="font-size:11px;color:#aaa;"><?= intval($credito['numero_abonos']) ?> abonos</div><div style="font-size:11px;color:#aaa;"><?= $credito['ultimo_abono'] ? 'Ultimo: ' . date('d/m/Y', strtotime($credito['ultimo_abono'])) : 'Sin abonos' ?></div></td>
                                <td style="font-weight:700;color:<?= $credito['saldo_pendiente'] > 0 ? '#c0392b' : '#2e7d32' ?>;">$<?= number_format($credito['saldo_pendiente'], 2) ?></td>
                                <td style="font-size:12px;"><?= $credito['fecha_limite'] ? date('d/m/Y', strtotime($credito['fecha_limite'])) : '-' ?></td>
                                <td><span class="badge badge-<?= strtolower($credito['estado']) ?>"><?= htmlspecialchars($credito['estado']) ?></span></td>
                                <td><div class="acciones"><a class="btn-accion btn-ver" href="abonos.php?ver=<?= intval($credito['credito_id']) ?>">Ver abonos</a></div></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="sin-resultados">No hay creditos registrados con esos filtros.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function normalizar(str) {
    return String(str || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}
function filtrarTabla(q) {
    q = normalizar(q);
    document.querySelectorAll('#tablaFiltrable tr').forEach(function(tr) {
        tr.style.display = normalizar(tr.textContent).includes(q) ? '' : 'none';
    });
}
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
</script>
<script src="../includes/auto_filter.js"></script>
</body>
</html>


