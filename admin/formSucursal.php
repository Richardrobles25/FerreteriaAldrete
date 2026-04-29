<?php
session_start();
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/topbar_info.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);

$editando  = null;
$errores   = [];
$esEdicion = isset($_GET['id']);

if ($esEdicion) {
    $stmt = $pdo->prepare("SELECT * FROM sucursales WHERE sucursal_id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editando) { header('Location: sucursales.php'); exit(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre                = trim($_POST['nombre']                ?? '');
    $rfc                   = strtoupper(trim($_POST['rfc']        ?? ''));
    $direccion             = trim($_POST['direccion']             ?? '');
    $telefono              = trim($_POST['telefono']              ?? '');
    $datos_ticket          = trim($_POST['datos_ticket']          ?? '');
    $comision_terminal_pct = floatval($_POST['comision_terminal_pct'] ?? 0);
    $banco                 = trim($_POST['banco']                 ?? '');
    $titular_cuenta        = trim($_POST['titular_cuenta']        ?? '');
    $numero_cuenta         = trim($_POST['numero_cuenta']         ?? '');
    $clabe_interbancaria   = trim($_POST['clabe_interbancaria']   ?? '');
    $alias_tarjeta         = trim($_POST['alias_tarjeta']         ?? '');
    $sucursal_id           = intval($_POST['sucursal_id']         ?? 0);

    if (!$nombre) $errores[] = 'El nombre de la sucursal es obligatorio.';
    if ($clabe_interbancaria && strlen($clabe_interbancaria) !== 18)
        $errores[] = 'La CLABE interbancaria debe tener exactamente 18 dígitos.';

    if (empty($errores)) {
        $campos = [
            'nombre'                => $nombre,
            'rfc'                   => $rfc,
            'direccion'             => $direccion,
            'telefono'              => $telefono,
            'datos_ticket'          => $datos_ticket,
            'comision_terminal_pct' => $comision_terminal_pct,
            'banco'                 => $banco ?: null,
            'titular_cuenta'      => $titular_cuenta ?: null,
            'numero_cuenta'       => $numero_cuenta ?: null,
            'clabe_interbancaria' => $clabe_interbancaria ?: null,
            'alias_tarjeta'       => $alias_tarjeta ?: null,
        ];

        if ($sucursal_id) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
            $pdo->prepare("UPDATE sucursales SET $sets WHERE sucursal_id = ?")
                ->execute([...array_values($campos), $sucursal_id]);
            header('Location: sucursales.php?msg=editado');
        } else {
            $cols = implode(', ', array_keys($campos));
            $vals = implode(', ', array_fill(0, count($campos), '?'));
            $pdo->prepare("INSERT INTO sucursales ($cols, activo) VALUES ($vals, 1)")
                ->execute(array_values($campos));
            header('Location: sucursales.php?msg=creado');
        }
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $editando?'Editar':'Nueva' ?> Sucursal — Ferretería Aldrete</title>
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
    .content { flex: 1; padding: 28px; overflow-y: auto; display: flex; justify-content: center; }
    .form-card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 28px; width: 100%; max-width: 540px; height: fit-content; }
    .form-card h1 { font-size: 18px; font-weight: 600; color: #222; margin: 0 0 6px; }
    .form-card p { font-size: 13px; color: #888; margin: 0 0 22px; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 18px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; color: #333; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .form-group textarea { min-height: 100px; resize: vertical; font-size: 13px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .hint { font-size: 11px; color: #aaa; margin-top: 4px; }
    .acciones-form { display: flex; gap: 10px; margin-top: 4px; }
    .btn-guardar { flex: 1; background: #14ace7; color: white; border: none; padding: 12px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar { background: white; color: #666; border: 1px solid #ddd; padding: 12px 20px; border-radius: 6px; font-size: 14px; cursor: pointer; text-decoration: none; display: flex; align-items: center; }
    .btn-cancelar:hover { background: #f5f5f5; }
</style>

<?php renderAdminSidebar('form_sucursal'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <button class="toggle-btn" onclick="toggleSidebar()">&#9776;</button>
            <h2><?= $editando?'Editar sucursal':'Nueva sucursal' ?></h2>
        </div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">— <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesión</button></form>
        </div>
    </div>

    <div class="content">
        <div class="form-card">
            <h1><?= $editando?'Editar sucursal':'Nueva sucursal' ?></h1>
            <p><?= $editando?'Actualiza los datos de la sucursal.':'Registra una nueva sucursal de la ferretería.' ?></p>

            <?php if (!empty($errores)): ?>
                <div class="errores">
                    <ul><?php foreach($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="sucursal_id" value="<?= $editando['sucursal_id'] ?? 0 ?>">

                <div class="form-group">
                    <label>Nombre de la sucursal *</label>
                    <input type="text" name="nombre"
                        value="<?= htmlspecialchars($_POST['nombre'] ?? $editando['nombre'] ?? '') ?>"
                        placeholder="Ej. Ferretería Aldrete Centro">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>RFC</label>
                        <input type="text" name="rfc"
                            value="<?= htmlspecialchars($_POST['rfc'] ?? $editando['rfc'] ?? '') ?>"
                            placeholder="AAAA000000AAA"
                            oninput="this.value=this.value.toUpperCase()">
                    </div>
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="text" name="telefono"
                            value="<?= htmlspecialchars($_POST['telefono'] ?? $editando['telefono'] ?? '') ?>"
                            placeholder="10 dígitos">
                    </div>
                </div>

                <div class="form-group">
                    <label>Dirección</label>
                    <input type="text" name="direccion"
                        value="<?= htmlspecialchars($_POST['direccion'] ?? $editando['direccion'] ?? '') ?>"
                        placeholder="Calle, número, colonia, ciudad">
                </div>

                <div class="form-group">
                    <label>Datos del ticket</label>
                    <textarea name="datos_ticket"
                        id="datosTicket"
                        placeholder="Texto que aparecerá en los tickets de venta&#10;Ej:&#10;Ferretería Aldrete S.A. de C.V.&#10;RFC: AAAA000000AAA&#10;Calle Morelos #45, Col. Centro&#10;Tel: 8711234567"><?= htmlspecialchars($_POST['datos_ticket'] ?? $editando['datos_ticket'] ?? '') ?></textarea>
                    <div class="hint">Este texto aparece en todos los tickets de venta de esta sucursal.</div>
                </div>

                <div class="form-group" style="display:flex;flex-direction:column;justify-content:flex-end;">
                    <button type="button" class="btn-guardar" onclick="abrirPreviewTicket()" style="background:#9c27b0;margin-bottom:0;">👁️ Vista previa del ticket</button>
                </div>

                <!-- Sección: Terminal y transferencias -->
                <div style="border-top:1px solid #eee;margin:20px 0 18px;padding-top:18px;">
                    <div style="font-size:13px;font-weight:700;color:#333;margin-bottom:4px;">Terminal y transferencias</div>
                    <div style="font-size:12px;color:#aaa;margin-bottom:14px;">Configura la comisión de terminal y los datos bancarios para transferencias.</div>

                    <div class="form-group">
                        <label>% Comisión de terminal</label>
                        <input type="number" name="comision_terminal_pct" step="0.01" min="0" max="100"
                            value="<?= htmlspecialchars($_POST['comision_terminal_pct'] ?? $editando['comision_terminal_pct'] ?? '0') ?>"
                            placeholder="Ej. 3.5">
                        <div class="hint">Se suma automáticamente cuando el cliente paga con terminal en ventas y abonos.</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Banco</label>
                            <input type="text" name="banco"
                                value="<?= htmlspecialchars($_POST['banco'] ?? $editando['banco'] ?? '') ?>"
                                placeholder="Ej. BBVA, Banorte">
                        </div>
                        <div class="form-group">
                            <label>Titular de la cuenta</label>
                            <input type="text" name="titular_cuenta"
                                value="<?= htmlspecialchars($_POST['titular_cuenta'] ?? $editando['titular_cuenta'] ?? '') ?>"
                                placeholder="Nombre del titular">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Número de cuenta</label>
                            <input type="text" name="numero_cuenta"
                                value="<?= htmlspecialchars($_POST['numero_cuenta'] ?? $editando['numero_cuenta'] ?? '') ?>"
                                placeholder="10 dígitos"
                                maxlength="20">
                        </div>
                        <div class="form-group">
                            <label>CLABE interbancaria</label>
                            <input type="text" name="clabe_interbancaria"
                                value="<?= htmlspecialchars($_POST['clabe_interbancaria'] ?? $editando['clabe_interbancaria'] ?? '') ?>"
                                placeholder="18 dígitos"
                                maxlength="18"
                                oninput="this.value=this.value.replace(/\D/g,'').slice(0,18)"
                                id="inputClabe">
                            <div class="hint" id="hintClabe"><?= strlen($_POST['clabe_interbancaria'] ?? $editando['clabe_interbancaria'] ?? '') ?>/18 dígitos</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Alias / nombre de la tarjeta <span style="font-weight:400;color:#aaa;">(opcional)</span></label>
                        <input type="text" name="alias_tarjeta"
                            value="<?= htmlspecialchars($_POST['alias_tarjeta'] ?? $editando['alias_tarjeta'] ?? '') ?>"
                            placeholder="Ej. Débito Nómina BBVA">
                    </div>
                </div>

                <div class="acciones-form">
                    <a class="btn-cancelar" href="sucursales.php">Cancelar</a>
                    <button class="btn-guardar" type="submit">
                        <?= $editando?'Guardar cambios':'Crear sucursal' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Ticket preview -->
<div class="modal-overlay" id="modalPreviewTicket" style="position:fixed;inset:0;background:rgba(0,0,0,0.4);display:none;align-items:center;justify-content:center;padding:20px;z-index:999;" aria-hidden="true">
    <div style="background:white;border-radius:8px;padding:24px;max-width:90mm;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #e8e8e8;">
            <h3 style="margin:0;font-size:16px;color:#333;">Vista previa del ticket</h3>
            <button type="button" onclick="cerrarPreviewTicket()" style="background:none;border:none;font-size:24px;color:#aaa;cursor:pointer;padding:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;">×</button>
        </div>
        <div id="previewTicketContent" style="margin-bottom:16px;"></div>
        <div style="display:flex;gap:10px;justify-content:center;border-top:1px solid #e8e8e8;padding-top:16px;">
            <button type="button" onclick="window.print()" style="background:#14ace7;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600;">🖨️ Imprimir</button>
            <button type="button" onclick="cerrarPreviewTicket()" style="background:#f0f0f0;color:#666;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600;">Cerrar</button>
        </div>
    </div>
</div>

<style>
.modal-overlay.visible { display: flex !important; }
@media print {
    body > * { display: none !important; }
    .modal-overlay.visible { display: flex !important; background: none; position: relative; inset: auto; }
    .modal-overlay.visible > div { max-width: 100%; box-shadow: none; padding: 0; }
    .modal-overlay.visible button { display: none !important; }
    .modal-overlay.visible h3 { display: none !important; }
    .modal-overlay.visible > div > div:first-of-type { display: none !important; }
}
</style>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

/* Modal de vista previa del ticket */
function abrirPreviewTicket() {
    const nombre = document.querySelector('input[name="nombre"]').value || 'FERRETERIA ALDRETE';
    const datosTicket = document.querySelector('textarea[name="datos_ticket"]').value || '';
    const banco = document.querySelector('input[name="banco"]').value || '';
    const titular = document.querySelector('input[name="titular_cuenta"]').value || '';
    const cuenta = document.querySelector('input[name="numero_cuenta"]').value || '';
    const clabe = document.querySelector('input[name="clabe_interbancaria"]').value || '';

    const html = `
<div style="font-family:'Courier New',monospace;width:72mm;font-size:10px;line-height:1.3;white-space:pre-wrap;">
<div style="text-align:center;font-weight:bold;border-bottom:1px dashed #000;padding-bottom:6px;margin-bottom:6px;">
${nombre.toUpperCase()}
${datosTicket.trim() ? '\n' + datosTicket : ''}
</div>

<div style="border-bottom:1px dashed #000;padding:6px 0;margin-bottom:6px;font-size:9px;">
Folio: 0042 | Turno: 1
${new Date().toLocaleString('es-MX', {year:'2-digit',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'})}
Juan Pérez López
</div>

<table style="width:100%;border-collapse:collapse;">
<tr style="border-bottom:0.5px solid #333;font-weight:bold;">
 <th style="text-align:left;padding:3px 0;padding-right:4px;">Producto</th>
 <th style="text-align:right;padding:3px 0;width:35px;">Cant</th>
 <th style="text-align:right;padding:3px 0;width:40px;">Total</th>
</tr>
<tr style="border-bottom:0.5px solid #ddd;">
 <td style="padding:2px 0;padding-right:4px;">Cemento saco 50kg</td>
 <td style="text-align:right;padding:2px 0;">2</td>
 <td style="text-align:right;padding:2px 0;">$17.00</td>
</tr>
<tr style="border-bottom:0.5px solid #ddd;">
 <td style="padding:2px 0;padding-right:4px;">Tubo PVC 1/2"</td>
 <td style="text-align:right;padding:2px 0;">5</td>
 <td style="text-align:right;padding:2px 0;">$60.00</td>
</tr>
</table>

<div style="border-bottom:1px dashed #000;border-top:1px dashed #000;padding:6px 0;margin:6px 0;text-align:right;font-weight:bold;font-size:11px;">
Subtotal: $77.00
TOTAL: $77.00
</div>

<div style="padding:4px 0;font-size:9px;border-bottom:1px dashed #000;margin-bottom:6px;padding-bottom:6px;">
Pago: Efectivo
</div>

${banco || titular || cuenta || clabe ? `
<div style="border-bottom:1px dashed #000;padding:6px 0;margin-bottom:6px;font-size:8px;background:#f9f9f9;padding:4px;">
DATOS BANCARIOS:
${banco ? `Banco: ${banco}` : ''}
${titular ? `\nTitular: ${titular}` : ''}
${cuenta ? `\nCuenta: ${cuenta}` : ''}
${clabe ? `\nCLABE: ${clabe}` : ''}
</div>
` : ''}

<div style="text-align:center;padding-top:6px;font-size:8px;color:#999;">
Gracias por su compra
www.ferreterialdrete.com
</div>
</div>`;

    document.getElementById('previewTicketContent').innerHTML = html;
    document.getElementById('modalPreviewTicket').classList.add('visible');
    document.getElementById('modalPreviewTicket').setAttribute('aria-hidden', 'false');
}

function cerrarPreviewTicket() {
    document.getElementById('modalPreviewTicket').classList.remove('visible');
    document.getElementById('modalPreviewTicket').setAttribute('aria-hidden', 'true');
}

document.getElementById('inputClabe').addEventListener('input', function() {
    const n = this.value.length;
    const hint = document.getElementById('hintClabe');
    hint.textContent = n + '/18 dígitos';
    hint.style.color = n === 18 ? '#2e7d32' : n > 0 ? '#e65100' : '#aaa';
});
</script>
</body>
</html>


