<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
verificarSesion();
verificarRol(['Administrador', 'Cajero', 'Inventario/Cajero']);

// AJAX: créditos activos de un cliente (para modal de detalles y panel abonar)
if (isset($_GET['get_creditos_cliente'])) {
    header('Content-Type: application/json');
    try {
        $cliente_id = intval($_GET['get_creditos_cliente']);
        $stmt = $pdo->prepare("
            SELECT cr.credito_id, cr.monto_total, cr.saldo_pendiente, cr.estado,
                   cr.created_at, cr.fecha_limite,
                   v.folio, v.total AS total_venta, v.created_at AS fecha_venta,
                   GROUP_CONCAT(
                       CONCAT(p.nombre_producto, '||', CAST(vp.cantidad AS CHAR), '||', CAST(vp.precio_unitario AS CHAR))
                       ORDER BY p.nombre_producto SEPARATOR ';;'
                   ) AS prods_raw
            FROM creditos cr
            JOIN ventas v ON cr.venta_id = v.venta_id
            LEFT JOIN venta_productos vp ON cr.venta_id = vp.venta_id
            LEFT JOIN productos p ON vp.producto_id = p.producto_id
            WHERE cr.cliente_id = ? AND cr.estado IN ('Activo', 'Vencido')
            GROUP BY cr.credito_id
            ORDER BY cr.created_at ASC
        ");
        $stmt->execute([$cliente_id]);
        $creditos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($creditos as &$cr) {
            $cr['productos'] = [];
            if (!empty($cr['prods_raw'])) {
                foreach (explode(';;', $cr['prods_raw']) as $prod) {
                    $parts = explode('||', $prod, 3);
                    if (count($parts) === 3) {
                        $cr['productos'][] = [
                            'nombre'   => $parts[0],
                            'cantidad' => floatval($parts[1]),
                            'precio'   => floatval($parts[2]),
                        ];
                    }
                }
            }
            unset($cr['prods_raw']);
        }
        echo json_encode($creditos);
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// Clientes con deuda activa
$stmt = $pdo->prepare("
    SELECT c.cliente_id, c.nombre_completo, c.telefono,
           COUNT(cr.credito_id)                                      AS num_creditos,
           SUM(cr.saldo_pendiente)                                   AS total_pendiente,
           MIN(cr.created_at)                                        AS primer_credito,
           MAX(CASE WHEN cr.estado = 'Vencido' THEN 1 ELSE 0 END)   AS tiene_vencido
    FROM creditos cr
    JOIN clientes c ON cr.cliente_id = c.cliente_id
    WHERE cr.estado IN ('Activo', 'Vencido')
    GROUP BY c.cliente_id
    ORDER BY total_pendiente DESC
");
$stmt->execute();
$clientesDeuda = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas globales
$totales = $pdo->query("
    SELECT
        COUNT(DISTINCT cr.cliente_id)                           AS clientes_con_deuda,
        COALESCE(SUM(cr.saldo_pendiente), 0)                    AS total_pendiente,
        COUNT(CASE WHEN cr.estado = 'Vencido' THEN 1 END)       AS creditos_vencidos,
        COUNT(cr.credito_id)                                    AS total_creditos
    FROM creditos cr
    WHERE cr.estado IN ('Activo', 'Vencido')
")->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Créditos — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 20px; overflow-y: auto; }

    /* Stats */
    .stats { grid-column: 1 / -1; display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 4px; }
    .stat { background: white; border-radius: 8px; padding: 14px 16px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 0.4px; }
    .stat h3 { font-size: 20px; font-weight: 700; color: #222; margin: 0; }

    /* Lista de clientes */
    .col-lista { display: flex; flex-direction: column; gap: 0; max-width: 900px; }
    .buscar-clientes { width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 13px; margin-bottom: 12px; background: white; }
    .buscar-clientes:focus { outline: none; border-color: #14ace7; }
    .cliente-card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 14px 16px; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; transition: box-shadow 0.15s; }
    .cliente-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
    .cliente-card.vencido { border-left: 3px solid #c0392b; }
    .cliente-info { flex: 1; min-width: 0; }
    .cliente-nombre { font-size: 14px; font-weight: 700; color: #222; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cliente-tel { font-size: 11px; color: #aaa; margin-top: 2px; }
    .cliente-meta { font-size: 12px; color: #888; margin-top: 4px; }
    .cliente-saldo { text-align: right; flex-shrink: 0; }
    .cliente-saldo .monto { font-size: 17px; font-weight: 700; color: #c0392b; }
    .cliente-saldo .etiq { font-size: 11px; color: #aaa; }
    .cliente-acciones { display: flex; flex-direction: column; gap: 5px; flex-shrink: 0; }
    .btn-detalles { background: #e3f2fd; color: #1565c0; border: none; padding: 6px 12px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }
    .btn-detalles:hover { background: #bbdefb; }
    .badge-vencido { display: inline-block; background: #fdecea; color: #c0392b; border-radius: 99px; padding: 1px 7px; font-size: 10px; font-weight: 700; margin-left: 6px; }
    .sin-resultados { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 48px; text-align: center; color: #aaa; font-size: 14px; }

    .btn-abonar-directo { background: #2e7d32; color: white; border: none; padding: 6px 14px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; white-space: nowrap; }
    .btn-abonar-directo:hover { background: #1b5e20; }

    /* Modal detalles */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 500; align-items: center; justify-content: center; }
    .modal-overlay.visible { display: flex; }
    .modal { background: white; border-radius: 10px; width: 560px; max-width: 95vw; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden; }
    .modal-header { padding: 18px 20px; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; }
    .modal-header h3 { font-size: 16px; font-weight: 700; color: #222; margin: 0; }
    .modal-header .modal-subtitulo { font-size: 12px; color: #888; margin-top: 2px; }
    .modal-close { background: none; border: none; font-size: 22px; color: #aaa; cursor: pointer; line-height: 1; }
    .modal-close:hover { color: #555; }
    .modal-body { flex: 1; overflow-y: auto; padding: 18px 20px; }
    .credito-det { border: 0.5px solid #eee; border-radius: 8px; margin-bottom: 14px; overflow: hidden; }
    .credito-det:last-child { margin-bottom: 0; }
    .credito-det-header { background: #f9f9f9; padding: 10px 14px; border-bottom: 0.5px solid #eee; display: flex; align-items: center; gap: 10px; }
    .credito-det-folio { font-size: 13px; font-weight: 700; color: #333; }
    .credito-det-fecha { font-size: 11px; color: #aaa; flex: 1; }
    .credito-det-saldo { font-size: 14px; font-weight: 700; color: #c0392b; }
    .credito-det-badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; }
    .badge-activo { background: #e8f5e9; color: #2e7d32; }
    .badge-vencido2 { background: #fdecea; color: #c0392b; }
    .credito-det-prods { padding: 10px 14px; }
    .prod-det-row { display: flex; align-items: center; justify-content: space-between; padding: 4px 0; border-bottom: 0.5px solid #f5f5f5; font-size: 13px; color: #444; }
    .prod-det-row:last-child { border-bottom: none; }
    .prod-det-nombre { flex: 1; color: #333; }
    .prod-det-cant { color: #888; font-size: 12px; margin: 0 10px; white-space: nowrap; }
    .prod-det-precio { font-weight: 600; color: #14ace7; white-space: nowrap; }
    .credito-det-footer { padding: 8px 14px; border-top: 0.5px solid #eee; display: flex; justify-content: space-between; align-items: center; }
    .btn-abonar-modal { background: #2e7d32; color: white; border: none; padding: 6px 14px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
    .btn-abonar-modal:hover { background: #1b5e20; }
    .modal-sin-creditos { text-align: center; color: #aaa; padding: 32px; font-size: 13px; }
    .det-cargando { text-align: center; color: #aaa; padding: 40px; font-size: 13px; }
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
        <a class="menu-item active" href="creditos.php">Créditos</a>
        <a class="menu-item" href="abonos.php">Abonos</a>
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
            <h2>Créditos de clientes</h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <div class="stats">
            <div class="stat">
                <p>Clientes con deuda</p>
                <h3><?= intval($totales['clientes_con_deuda']) ?></h3>
            </div>
            <div class="stat">
                <p>Créditos activos</p>
                <h3><?= intval($totales['total_creditos']) ?></h3>
            </div>
            <div class="stat" style="border-top-color:#c0392b;">
                <p>Créditos vencidos</p>
                <h3 style="color:<?= $totales['creditos_vencidos'] > 0 ? '#c0392b' : '#222' ?>;"><?= intval($totales['creditos_vencidos']) ?></h3>
            </div>
            <div class="stat" style="border-top-color:#e67e22;">
                <p>Total pendiente</p>
                <h3 style="color:#e67e22;">$<?= number_format($totales['total_pendiente'], 2) ?></h3>
            </div>
        </div>

        <!-- Lista de clientes -->
        <div class="col-lista">
            <input type="text" class="buscar-clientes" placeholder="Buscar cliente..." oninput="filtrarClientes(this.value)" autocomplete="off">

            <?php if (count($clientesDeuda) > 0): ?>
                <?php foreach ($clientesDeuda as $cl): ?>
                <div class="cliente-card <?= $cl['tiene_vencido'] ? 'vencido' : '' ?>"
                     data-texto="<?= htmlspecialchars(mb_strtolower($cl['nombre_completo'] . ' ' . ($cl['telefono'] ?? ''))) ?>">
                    <div class="cliente-info">
                        <div class="cliente-nombre">
                            <?= htmlspecialchars($cl['nombre_completo']) ?>
                            <?php if ($cl['tiene_vencido']): ?>
                                <span class="badge-vencido">Vencido</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($cl['telefono']): ?>
                            <div class="cliente-tel"><?= htmlspecialchars($cl['telefono']) ?></div>
                        <?php endif; ?>
                        <div class="cliente-meta">
                            <?= intval($cl['num_creditos']) ?> crédito<?= $cl['num_creditos'] != 1 ? 's' : '' ?> activo<?= $cl['num_creditos'] != 1 ? 's' : '' ?>
                            · desde <?= date('d/m/Y', strtotime($cl['primer_credito'])) ?>
                        </div>
                    </div>
                    <div class="cliente-saldo">
                        <div class="monto">$<?= number_format($cl['total_pendiente'], 2) ?></div>
                        <div class="etiq">pendiente</div>
                    </div>
                    <div class="cliente-acciones">
                        <button class="btn-detalles" onclick="abrirDetalles(<?= $cl['cliente_id'] ?>, '<?= htmlspecialchars($cl['nombre_completo'], ENT_QUOTES) ?>')">Ver créditos</button>
                        <a class="btn-abonar-directo" href="abonos.php?cliente=<?= $cl['cliente_id'] ?>">Cobrar →</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="sin-resultados">No hay clientes con crédito pendiente.</div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Modal detalles -->
<div class="modal-overlay" id="modalOverlay" onclick="if(event.target===this)cerrarModal()">
    <div class="modal">
        <div class="modal-header">
            <div>
                <h3 id="modalTitulo">Créditos</h3>
                <div class="modal-subtitulo" id="modalSubtitulo"></div>
            </div>
            <button class="modal-close" onclick="cerrarModal()">✕</button>
        </div>
        <div class="modal-body" id="modalBody">
            <div class="det-cargando">Cargando...</div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }
function normalizar(s) { return String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }

/* ── Filtro lista clientes ── */
function filtrarClientes(q) {
    q = normalizar(q);
    document.querySelectorAll('.cliente-card').forEach(card => {
        card.style.display = normalizar(card.dataset.texto || '').includes(q) ? '' : 'none';
    });
}

/* ── Modal detalles ── */
function abrirDetalles(clienteId, nombre) {
    document.getElementById('modalTitulo').textContent = nombre;
    document.getElementById('modalSubtitulo').textContent = 'Créditos y productos pendientes de pago';
    document.getElementById('modalBody').innerHTML = '<div class="det-cargando">Cargando...</div>';
    document.getElementById('modalOverlay').classList.add('visible');

    fetch('creditos.php?get_creditos_cliente=' + clienteId)
        .then(r => r.json())
        .then(creditos => {
            if (!creditos || !creditos.length) {
                document.getElementById('modalBody').innerHTML = '<div class="modal-sin-creditos">No hay créditos activos para este cliente.</div>';
                return;
            }
            let html = '';
            creditos.forEach(cr => {
                const badgeClass = cr.estado === 'Vencido' ? 'badge-vencido2' : 'badge-activo';
                const folio = cr.folio ? 'Folio ' + cr.folio : 'Venta #' + cr.credito_id;
                html += `<div class="credito-det">
                    <div class="credito-det-header">
                        <div>
                            <div class="credito-det-folio">${folio}</div>
                            <div class="credito-det-fecha">${formatFecha(cr.fecha_venta)}</div>
                        </div>
                        <span class="credito-det-badge ${badgeClass}">${cr.estado}</span>
                        <div class="credito-det-saldo">$${parseFloat(cr.saldo_pendiente).toFixed(2)}</div>
                    </div>`;

                if (cr.productos && cr.productos.length) {
                    html += '<div class="credito-det-prods">';
                    cr.productos.forEach(p => {
                        const cant = Number.isInteger(p.cantidad) ? p.cantidad : parseFloat(p.cantidad).toFixed(2).replace(/\.?0+$/,'');
                        html += `<div class="prod-det-row">
                            <span class="prod-det-nombre">${p.nombre}</span>
                            <span class="prod-det-cant">× ${cant}</span>
                            <span class="prod-det-precio">$${(p.cantidad * p.precio).toFixed(2)}</span>
                        </div>`;
                    });
                    html += '</div>';
                }

                html += `<div class="credito-det-footer">
                    <span style="font-size:12px;color:#888;">Monto original: $${parseFloat(cr.monto_total).toFixed(2)}</span>
                    <a class="btn-abonar-modal" href="abonos.php?credito_id=${cr.credito_id}">Abonar</a>
                </div></div>`;
            });
            document.getElementById('modalBody').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('modalBody').innerHTML = '<div class="modal-sin-creditos">Error al cargar los créditos.</div>';
        });
}

function cerrarModal() {
    document.getElementById('modalOverlay').classList.remove('visible');
}

function formatFecha(str) {
    if (!str) return '';
    const d = new Date(str);
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/* Cerrar modal con Escape */
document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });
</script>
</body>
</html>
