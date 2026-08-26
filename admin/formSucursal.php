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
    // [FIX-MEDIO] CSRF ausente en este formulario (a diferencia de los demás formularios
    // admin, que ya lo tienen) — un POST desde cualquier página con la sesión del
    // Administrador abierta podía crear/editar sucursales, incluyendo sus datos bancarios.
    requerirCSRF($_POST['_token'] ?? '', $esEdicion ? 'formSucursal.php?id=' . intval($_GET['id']) : 'formSucursal.php');
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
    $ticket_font_size      = intval($_POST['ticket_font_size']    ?? 12);
    $ticket_ancho_mm       = intval($_POST['ticket_ancho_mm']     ?? 58);
    $ticket_pie            = trim($_POST['ticket_pie']            ?? '');
    $ticket_pie_efectivo   = trim($_POST['ticket_pie_efectivo']   ?? '');
    $ticket_pie_credito    = trim($_POST['ticket_pie_credito']    ?? '');
    $ticket_pie_terminal   = trim($_POST['ticket_pie_terminal']   ?? '');
    $ticket_nota_credito   = trim($_POST['ticket_nota_credito']   ?? '');
    $porcentaje_mora       = floatval($_POST['porcentaje_mora']   ?? 0);

    if (!$nombre) $errores[] = 'El nombre de la sucursal es obligatorio.';
    // [FIX-MEDIO-A-17] Antes no habia ningun chequeo de nombre duplicado ni UNIQUE en BD:
    // se podian crear dos sucursales con el mismo nombre, confundiendo reportes y selectores
    // que las listan solo por nombre.
    elseif ($nombre) {
        $stmtDupNombre = $pdo->prepare("SELECT COUNT(*) FROM sucursales WHERE nombre = ? AND sucursal_id != ?");
        $stmtDupNombre->execute([$nombre, $sucursal_id]);
        if ($stmtDupNombre->fetchColumn() > 0) $errores[] = 'Ya existe una sucursal con ese nombre.';
    }
    if ($telefono !== '' && !preg_match('/^\d{10}$/', $telefono))
        $errores[] = 'El teléfono debe tener exactamente 10 dígitos numéricos.';
    if ($numero_cuenta !== '' && !preg_match('/^\d{1,10}$/', $numero_cuenta))
        $errores[] = 'El número de cuenta debe contener solo dígitos (máximo 10).';
    if ($clabe_interbancaria && strlen($clabe_interbancaria) !== 18)
        $errores[] = 'La CLABE interbancaria debe tener exactamente 18 dígitos.';
    // [FIX-MEDIO-A-13] El rango 0-100 solo se validaba con min/max de HTML5 (se salta con
    // DevTools o un POST directo); la columna es DECIMAL(5,2), asi que sin tope real
    // aceptaba hasta 999.99% de comision o de mora.
    if ($comision_terminal_pct < 0 || $comision_terminal_pct > 100)
        $errores[] = 'El porcentaje de comisión de terminal debe estar entre 0 y 100.';
    if ($porcentaje_mora < 0 || $porcentaje_mora > 100)
        $errores[] = 'El porcentaje de mora debe estar entre 0 y 100.';

    if (empty($errores)) {
        $ticket_logo     = $editando['ticket_logo'] ?? null;
        $pendingLogoFile = null; // para nueva sucursal: se procesa después del INSERT

        if ($sucursal_id) {
            // ── EDICIÓN: tenemos el ID, procesamos el logo ahora ──────────────
            if (isset($_POST['eliminar_logo']) && $ticket_logo) {
                $old = __DIR__ . '/../' . $ticket_logo;
                if (file_exists($old)) @unlink($old);
                $ticket_logo = null;
            } elseif (!empty($_FILES['ticket_logo_file']['name']) && $_FILES['ticket_logo_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['ticket_logo_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                    $dir = __DIR__ . '/../uploads/logos/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fname = 'logo_suc' . $sucursal_id . '_' . uniqid('', true) . '.' . $ext;
                    if (move_uploaded_file($_FILES['ticket_logo_file']['tmp_name'], $dir . $fname)) {
                        if ($ticket_logo) { $old = __DIR__ . '/../' . $ticket_logo; if (file_exists($old)) @unlink($old); }
                        $ticket_logo = 'uploads/logos/' . $fname;
                    } else {
                        $errores[] = 'No se pudo guardar el logo. Verifica los permisos del directorio uploads/logos/.';
                    }
                } else {
                    $errores[] = 'Formato de imagen no soportado. Usa JPG, PNG, GIF o WEBP.';
                }
            }
        } else {
            // ── NUEVA sucursal: guardamos el archivo para después del INSERT ──
            if (!empty($_FILES['ticket_logo_file']['name']) && $_FILES['ticket_logo_file']['error'] === UPLOAD_ERR_OK) {
                $pendingLogoFile = $_FILES['ticket_logo_file'];
            }
            $ticket_logo = null; // se asignará tras conocer el sucursal_id real
        }

        if (empty($errores)) {
            $campos = [
                'nombre'               => $nombre,
                'rfc'                  => $rfc,
                'direccion'            => $direccion,
                'telefono'             => $telefono,
                'datos_ticket'         => $datos_ticket,
                'comision_terminal_pct'=> $comision_terminal_pct,
                'banco'                => $banco ?: null,
                'titular_cuenta'       => $titular_cuenta ?: null,
                'numero_cuenta'        => $numero_cuenta ?: null,
                'clabe_interbancaria'  => $clabe_interbancaria ?: null,
                'alias_tarjeta'        => $alias_tarjeta ?: null,
                'ticket_font_size'     => $ticket_font_size,
                'ticket_ancho_mm'      => $ticket_ancho_mm,
                'ticket_pie'           => $ticket_pie ?: null,
                'ticket_pie_efectivo'  => $ticket_pie_efectivo ?: null,
                'ticket_pie_credito'   => $ticket_pie_credito  ?: null,
                'ticket_pie_terminal'  => $ticket_pie_terminal ?: null,
                'ticket_nota_credito'  => $ticket_nota_credito ?: null,
                'ticket_logo'          => $ticket_logo,
                'porcentaje_mora'      => $porcentaje_mora,
            ];

            // [FIX-MEDIO-A-11] Antes una PDOException aquí (dato que no cabe en su columna,
            // FK inválida, etc.) se dejaba sin capturar: HTTP 500 con la ruta del servidor
            // expuesta en vez de un error normal.
            try {
                if ($sucursal_id) {
                    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
                    $stmtUpd = $pdo->prepare("UPDATE sucursales SET $sets WHERE sucursal_id = ?");
                    $stmtUpd->execute([...array_values($campos), $sucursal_id]);
                    // [FIX-MEDIO-A-15] Antes no se comprobaba rowCount(): editar una sucursal
                    // que ya no existe (borrada por otra sesión, o un ID manipulado en el
                    // <input hidden>) igual reportaba "editado correctamente" sin haber
                    // cambiado ninguna fila real.
                    $stmtChkExiste = $pdo->prepare("SELECT COUNT(*) FROM sucursales WHERE sucursal_id = ?");
                    $stmtChkExiste->execute([$sucursal_id]);
                    if ($stmtChkExiste->fetchColumn() == 0) {
                        header('Location: sucursales.php?msg=error_no_existe');
                    } else {
                        header('Location: sucursales.php?msg=editado');
                    }
                } else {
                    $cols = implode(', ', array_keys($campos));
                    $vals = implode(', ', array_fill(0, count($campos), '?'));
                    $pdo->prepare("INSERT INTO sucursales ($cols, activo) VALUES ($vals, 1)")
                        ->execute(array_values($campos));
                    $newId = intval($pdo->lastInsertId());

                    // Ahora procesar el logo con el ID real de la sucursal recién creada
                    if ($pendingLogoFile) {
                        $ext = strtolower(pathinfo($pendingLogoFile['name'], PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                            $dir = __DIR__ . '/../uploads/logos/';
                            if (!is_dir($dir)) mkdir($dir, 0755, true);
                            $fname = 'logo_suc' . $newId . '_' . uniqid('', true) . '.' . $ext;
                            if (move_uploaded_file($pendingLogoFile['tmp_name'], $dir . $fname)) {
                                $pdo->prepare("UPDATE sucursales SET ticket_logo = ? WHERE sucursal_id = ?")
                                    ->execute(['uploads/logos/' . $fname, $newId]);
                            }
                        }
                    }

                    header('Location: sucursales.php?msg=creado');
                }
                exit();
            } catch (PDOException $e) {
                $errores[] = 'No se pudo guardar la sucursal. Verifica los datos e intenta de nuevo.';
            }
        }
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
    .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; color: #333; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group textarea:focus, .form-group select:focus { outline: none; border-color: #14ace7; }
    .logo-preview { display:flex; align-items:center; gap:12px; background:#f9f9f9; border:1px solid #eee; border-radius:6px; padding:10px; margin-bottom:8px; }
    .logo-preview img { max-width:100px; max-height:50px; object-fit:contain; }
    .logo-preview-info { flex:1; font-size:12px; color:#888; }
    input[type="file"] { padding:6px 8px; font-size:13px; cursor:pointer; border:1px dashed #ddd; background:#fafafa; }
    .form-group textarea { min-height: 100px; resize: vertical; font-size: 13px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .hint { font-size: 11px; color: #aaa; margin-top: 4px; }
    .acciones-form { display: flex; gap: 10px; margin-top: 4px; }
    .btn-guardar { flex: 1; background: #14ace7; color: white; border: none; padding: 12px; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; }
    .btn-guardar:hover { background: #1196cb; }
    .btn-cancelar { background: white; color: #666; border: 1px solid #ddd; padding: 12px 20px; border-radius: 6px; font-size: 14px; cursor: pointer; text-decoration: none; display: flex; align-items: center; }
    .btn-cancelar:hover { background: #f5f5f5; }
    .ticket-tabs-wrap { border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden; }
    .ticket-tabs { display: flex; background: #f5f5f5; border-bottom: 1px solid #e0e0e0; }
    .t-tab { flex: 1; padding: 8px 4px; border: none; background: none; cursor: pointer; font-size: 12px; font-weight: 600; color: #777; transition: all 0.15s; border-bottom: 2px solid transparent; font-family: Arial, sans-serif; }
    .t-tab:hover { background: #ebebeb; color: #444; }
    .t-tab.active { background: white; color: #14ace7; border-bottom-color: #14ace7; }
    .t-panel { padding: 12px; }
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
            <?php elseif (($_GET['msg'] ?? '') === 'error_token'): ?>
                <div class="errores"><ul><li>La sesión expiró o el formulario no es válido. Intenta de nuevo.</li></ul></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
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
                        <input type="tel" name="telefono"
                            value="<?= htmlspecialchars($_POST['telefono'] ?? $editando['telefono'] ?? '') ?>"
                            placeholder="10 dígitos"
                            maxlength="10"
                            pattern="\d{10}"
                            inputmode="numeric"
                            oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)"
                            title="Ingresa exactamente 10 dígitos numéricos">
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
                        <?php
                            $comVal = $_POST['comision_terminal_pct'] ?? $editando['comision_terminal_pct'] ?? '';
                            $comVal = (floatval($comVal) == 0 && !isset($_POST['comision_terminal_pct'])) ? '' : $comVal;
                        ?>
                        <input type="number" name="comision_terminal_pct" step="0.01" min="0" max="100"
                            value="<?= htmlspecialchars($comVal) ?>"
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
                                maxlength="10"
                                inputmode="numeric"
                                oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)"
                                title="Solo dígitos, máximo 10">
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

                <!-- Seccion: Creditos -->
                <div style="border-top:1px solid #eee;margin:20px 0 18px;padding-top:18px;">
                    <div style="font-size:13px;font-weight:700;color:#333;margin-bottom:4px;">Créditos</div>
                    <div style="font-size:12px;color:#aaa;margin-bottom:14px;">Configura el recargo por vencimiento de crédito para esta sucursal.</div>
                    <div class="form-group">
                        <label>% Mora por vencimiento</label>
                        <input type="number" name="porcentaje_mora" step="0.01" min="0" max="100"
                            value="<?= htmlspecialchars($_POST['porcentaje_mora'] ?? $editando['porcentaje_mora'] ?? '') ?>"
                            placeholder="Ej. 5 (dejar en 0 para no cobrar mora)">
                        <div class="hint">Se aplica una sola vez sobre el saldo pendiente de cada crédito cuando vence. Dejar en 0 para no cobrar.</div>
                    </div>
                </div>

                <!-- Seccion: Configuracion del ticket -->
                <div style="border-top:1px solid #eee;margin:20px 0 18px;padding-top:18px;">
                    <div style="font-size:13px;font-weight:700;color:#333;margin-bottom:4px;">Configuracion de impresion del ticket</div>
                    <div style="font-size:12px;color:#aaa;margin-bottom:14px;">Personaliza el aspecto del ticket para esta sucursal. Cada sucursal puede tener su propio logo y estilo.</div>

                    <div class="form-group">
                        <label>Logotipo</label>
                        <?php $logoActual = $editando['ticket_logo'] ?? null; ?>
                        <?php if ($logoActual): ?>
                        <div class="logo-preview" id="logoPreviewActual">
                            <img src="../<?= htmlspecialchars($logoActual) ?>" alt="Logo actual">
                            <div class="logo-preview-info">
                                Logo actual<br>
                                <label style="display:flex;align-items:center;gap:6px;margin-top:6px;cursor:pointer;">
                                    <input type="checkbox" name="eliminar_logo" id="eliminarLogo">
                                    <span style="color:#c0392b;">Eliminar logo</span>
                                </label>
                            </div>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="ticket_logo_file" id="ticketLogoFile" accept="image/jpeg,image/png,image/gif,image/webp">
                        <div class="hint">Formatos: JPG, PNG, GIF o WEBP. Se mostrara centrado en la parte superior del ticket.</div>
                        <div id="logoNuevoPreview" style="display:none;margin-top:8px;text-align:center;background:#f9f9f9;border:1px solid #eee;border-radius:6px;padding:10px;">
                            <img id="logoNuevoImg" src="" alt="Vista previa" style="max-width:120px;max-height:60px;object-fit:contain;">
                            <div style="font-size:11px;color:#888;margin-top:4px;">Vista previa del nuevo logo</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Tamano de fuente</label>
                            <select name="ticket_font_size" id="ticketFontSize">
                                <?php $fs = intval($_POST['ticket_font_size'] ?? $editando['ticket_font_size'] ?? 12); ?>
                                <option value="10" <?= $fs===10?'selected':'' ?>>Chico (10px)</option>
                                <option value="12" <?= $fs===12?'selected':'' ?>>Normal (12px)</option>
                                <option value="14" <?= $fs===14?'selected':'' ?>>Grande (14px)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Ancho del papel</label>
                            <select name="ticket_ancho_mm" id="ticketAnchoMm">
                                <?php $am = intval($_POST['ticket_ancho_mm'] ?? $editando['ticket_ancho_mm'] ?? 58); ?>
                                <option value="58" <?= $am===58?'selected':'' ?>>58 mm (rollo estrecho)</option>
                                <option value="80" <?= $am===80?'selected':'' ?>>80 mm (rollo ancho)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Pie de p&aacute;gina por tipo de pago <span style="font-weight:400;color:#aaa;">(opcional)</span></label>
                        <div class="ticket-tabs-wrap">
                            <div class="ticket-tabs">
                                <button type="button" class="t-tab active" onclick="switchTTab('efectivo',this)">Efectivo</button>
                                <button type="button" class="t-tab" onclick="switchTTab('terminal',this)">Terminal</button>
                                <button type="button" class="t-tab" onclick="switchTTab('credito',this)">Cr&eacute;dito</button>
                            </div>
                            <div id="ttab-efectivo" class="t-panel">
                                <input type="text" name="ticket_pie_efectivo"
                                    value="<?= htmlspecialchars($_POST['ticket_pie_efectivo'] ?? $editando['ticket_pie_efectivo'] ?? '') ?>"
                                    placeholder="Ej. ¡Gracias por su compra!">
                                <div class="hint">Pie para tickets pagados en efectivo.</div>
                            </div>
                            <div id="ttab-terminal" class="t-panel" style="display:none;">
                                <input type="text" name="ticket_pie_terminal"
                                    value="<?= htmlspecialchars($_POST['ticket_pie_terminal'] ?? $editando['ticket_pie_terminal'] ?? '') ?>"
                                    placeholder="Ej. Pago con tarjeta &mdash; ¡Gracias!">
                                <div class="hint">Pie para tickets pagados con terminal.</div>
                            </div>
                            <div id="ttab-credito" class="t-panel" style="display:none;">
                                <input type="text" name="ticket_pie_credito"
                                    value="<?= htmlspecialchars($_POST['ticket_pie_credito'] ?? $editando['ticket_pie_credito'] ?? '') ?>"
                                    placeholder="Ej. Pago en 3 d&iacute;as h&aacute;biles — ¡Gracias!">
                                <div class="hint">Pie para tickets de venta a cr&eacute;dito.</div>
                                <label style="display:block;margin-top:14px;font-size:13px;color:#555;font-weight:600;">Texto del pagar&eacute; (firma)</label>
                                <textarea name="ticket_nota_credito"
                                    style="width:100%;min-height:72px;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;color:#333;font-family:Arial,sans-serif;resize:vertical;margin-top:6px;"
                                    placeholder="Al firmar acepto cubrir el monto total adeudado en el plazo establecido."><?= htmlspecialchars($_POST['ticket_nota_credito'] ?? $editando['ticket_nota_credito'] ?? '') ?></textarea>
                                <div class="hint">Aparece en el bloque de firma del ticket de cr&eacute;dito.</div>
                            </div>
                        </div>
                        <div class="hint" style="margin-top:6px;">Si se deja vac&iacute;o, se usa el pie general de abajo.</div>
                    </div>

                    <div class="form-group">
                        <label>Pie de p&aacute;gina general <span style="font-weight:400;color:#aaa;">(fallback)</span></label>
                        <input type="text" name="ticket_pie"
                            value="<?= htmlspecialchars($_POST['ticket_pie'] ?? $editando['ticket_pie'] ?? '') ?>"
                            placeholder="Ej. Gracias por su compra &middot; Tel: 871-123-4567">
                        <div class="hint">Se muestra cuando el pie por tipo est&aacute; vac&iacute;o. Si tambi&eacute;n est&aacute; vac&iacute;o, se muestra el texto predeterminado.</div>
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

<!-- Area exclusiva para impresion del ticket -->
<div id="areaImpresionTicket"></div>

<!-- Modal: Ticket preview -->
<div class="modal-overlay" id="modalPreviewTicket" style="position:fixed;inset:0;background:rgba(0,0,0,0.4);display:none;align-items:center;justify-content:center;padding:20px;z-index:999;" aria-hidden="true">
    <div style="background:white;border-radius:8px;padding:20px;width:320px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e8e8e8;">
            <h3 style="margin:0;font-size:16px;color:#333;">Vista previa del ticket</h3>
            <button type="button" onclick="cerrarPreviewTicket()" style="background:none;border:none;font-size:24px;color:#aaa;cursor:pointer;padding:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;">×</button>
        </div>
        <div style="display:flex;gap:6px;margin-bottom:14px;">
            <button type="button" id="btnPrevEfectivo" onclick="renderPreview('Efectivo')" style="flex:1;padding:6px;border:2px solid #14ace7;background:#eef8ff;color:#14ace7;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;">Efectivo</button>
            <button type="button" id="btnPrevTerminal" onclick="renderPreview('Terminal')" style="flex:1;padding:6px;border:2px solid #ddd;background:white;color:#666;border-radius:6px;cursor:pointer;font-size:12px;">Terminal</button>
            <button type="button" id="btnPrevCredito"  onclick="renderPreview('Credito')"  style="flex:1;padding:6px;border:2px solid #ddd;background:white;color:#666;border-radius:6px;cursor:pointer;font-size:12px;">Crédito</button>
        </div>
        <div id="previewTicketContent" style="margin-bottom:16px;"></div>
        <div style="display:flex;gap:10px;justify-content:center;border-top:1px solid #e8e8e8;padding-top:16px;">
            <button type="button" onclick="imprimirPreview()" style="background:#14ace7;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600;">🖨️ Imprimir</button>
            <button type="button" onclick="cerrarPreviewTicket()" style="background:#f0f0f0;color:#666;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600;">Cerrar</button>
        </div>
    </div>
</div>

<style>
.modal-overlay.visible { display: flex !important; }
@media print {
    body > * { display: none !important; }
    .modal-overlay { display: none !important; }
    #areaImpresionTicket { display: block !important; position: fixed; top: 0; left: 0; }
}
#areaImpresionTicket { display: none; }
/* Clases del ticket — identicas a cajeroInventario/nuevaVenta.php */
.t-ticket  { font-family:'Courier New',monospace; font-size:12px; width:58mm; margin:0 auto; line-height:1.5; }
.t-centro  { text-align:center; }
.t-linea   { border-top:1px dashed #000; margin:4px 0; }
.t-fila    { display:flex; justify-content:space-between; }
.t-bold    { font-weight:bold; }
.t-grande  { font-size:13px; font-weight:bold; }
</style>

<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('collapsed'); }

/* Pestañas de configuración por tipo de pago */
function switchTTab(tab, btn) {
    document.querySelectorAll('.t-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.t-panel').forEach(p => p.style.display = 'none');
    btn.classList.add('active');
    document.getElementById('ttab-' + tab).style.display = '';
}

/* Obtiene el pie de página correcto según método de pago */
function getPieTicket(metodo) {
    let pie = '';
    if (metodo === 'Efectivo')  pie = document.querySelector('input[name="ticket_pie_efectivo"]')?.value || '';
    else if (metodo === 'Terminal') pie = document.querySelector('input[name="ticket_pie_terminal"]')?.value || '';
    else if (metodo === 'Credito')  pie = document.querySelector('input[name="ticket_pie_credito"]')?.value || '';
    return pie || document.querySelector('input[name="ticket_pie"]').value || '¡Gracias por su compra!';
}

/* Vista previa del logo cuando se selecciona un archivo */
document.getElementById('ticketLogoFile').addEventListener('change', function() {
    const file = this.files[0];
    const preview = document.getElementById('logoNuevoPreview');
    const img = document.getElementById('logoNuevoImg');
    if (file) {
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; preview.style.display = 'block'; };
        reader.readAsDataURL(file);
    } else {
        preview.style.display = 'none';
    }
});

/* Modal de vista previa del ticket */
function renderPreview(metodo) {
    ['Efectivo','Terminal','Credito'].forEach(m => {
        const btn = document.getElementById('btnPrev'+m);
        if (!btn) return;
        btn.style.border     = m===metodo ? '2px solid #14ace7' : '2px solid #ddd';
        btn.style.background = m===metodo ? '#eef8ff' : 'white';
        btn.style.color      = m===metodo ? '#14ace7' : '#666';
        btn.style.fontWeight = m===metodo ? '600' : '400';
    });

    const nombre   = document.querySelector('input[name="nombre"]').value || 'FERRETERIA ALDRETE';
    const datosTxt = document.querySelector('textarea[name="datos_ticket"]').value || '';
    const rfc      = document.querySelector('input[name="rfc"]').value || '';
    const dir      = document.querySelector('input[name="direccion"]').value || '';
    const tel      = document.querySelector('input[name="telefono"]').value || '';
    const fontSize = document.getElementById('ticketFontSize').value || '12';
    const anchoMm  = document.getElementById('ticketAnchoMm').value || '58';
    const pie      = getPieTicket(metodo);
    const comPct   = parseFloat(document.querySelector('input[name="comision_terminal_pct"]').value || 0);
    const fecha    = new Date().toLocaleString('es-MX',{day:'2-digit',month:'2-digit',year:'2-digit',hour:'2-digit',minute:'2-digit'});
    const maxW     = parseInt(anchoMm) >= 80 ? '140px' : '100px';

    const L  = `<div style="border-top:1px dashed #000;margin:4px 0;"></div>`;
    const C  = t => `<div style="text-align:center;">${t}</div>`;
    const F  = (a,b) => `<table style="width:100%;border-collapse:collapse;"><tr><td style="text-align:left;">${a}</td><td style="text-align:right;">${b}</td></tr></table>`;
    const FB = (a,b) => `<table style="width:100%;border-collapse:collapse;"><tr><td style="text-align:left;font-weight:bold;font-size:13px;">${a}</td><td style="text-align:right;font-weight:bold;font-size:13px;">${b}</td></tr></table>`;

    let html = '';

    // Logo
    const logoNuevoSrc = document.getElementById('logoNuevoImg').src;
    const logoActualEl = document.querySelector('.logo-preview img');
    if (logoNuevoSrc && document.getElementById('logoNuevoPreview').style.display !== 'none') {
        html += `<div style="text-align:center;margin-bottom:6px;"><img src="${logoNuevoSrc}" style="max-width:${maxW};max-height:50px;object-fit:contain;"></div>`;
    } else if (logoActualEl && !document.getElementById('eliminarLogo')?.checked) {
        html += `<div style="text-align:center;margin-bottom:6px;"><img src="${logoActualEl.src}" style="max-width:${maxW};max-height:50px;object-fit:contain;"></div>`;
    }

    // Encabezado
    html += `<div style="text-align:center;font-weight:bold;font-size:13px;">${nombre}</div>`;
    if (datosTxt.trim()) {
        html += `<div style="text-align:center;white-space:pre-line;font-size:11px;">${datosTxt}</div>`;
    } else {
        if (rfc) html += C(`RFC: ${rfc}`);
        if (dir) html += C(dir);
        if (tel) html += C(`Tel: ${tel}`);
    }

    html += L;
    html += F('Folio:', '0042');
    html += F('Fecha:', fecha);
    html += F('Cajero:', 'Juan Perez');
    html += F('Cliente:', 'Maria Esparza');
    html += L;
    html += F('<b>Producto</b>', '<b>Importe</b>');
    html += L;
    html += '<div>Cemento blanco 1kg</div>';
    html += F('2 x $8.50', '$17.00');
    html += '<div>Tubo PVC 1/2" x 3m</div>';
    html += F('5 x $12.00', '$60.00');
    html += L;

    const subtotal = 77.00;
    const comision = metodo === 'Terminal' ? parseFloat((subtotal * comPct / 100).toFixed(2)) : 0;
    const total    = subtotal + comision;
    if (comision > 0) {
        html += F('Subtotal', `$${subtotal.toFixed(2)}`);
        html += F('Comisión terminal', `$${comision.toFixed(2)}`);
    }
    html += FB('TOTAL', `$${total.toFixed(2)}`);
    html += L;
    html += F('Método de pago', metodo === 'Credito' ? 'Crédito' : metodo);

    if (metodo === 'Efectivo') {
        html += F('Recibido', '$100.00');
        html += F('Cambio', `$${(100 - total).toFixed(2)}`);
    } else if (metodo === 'Terminal') {
        html += F('Pago con terminal', `$${total.toFixed(2)}`);
    } else if (metodo === 'Credito') {
        const notaCred = document.querySelector('textarea[name="ticket_nota_credito"]')?.value?.trim() || 'Al firmar acepto cubrir el monto total adeudado';
        html += `<div style="text-align:center;font-weight:bold;margin-top:6px;">*** VENTA A CRÉDITO ***</div>`;
        html += L;
        html += `<div style="text-align:center;font-size:10px;margin-bottom:8px;">${notaCred}</div>`;
        html += `<div style="margin-top:48px;">Firma: _______________________________</div>`;
    }

    html += L;
    html += C(pie);
    html += `<div style="text-align:center;font-size:10px;margin-top:4px;">Conserve su ticket</div>`;

    document.getElementById('previewTicketContent').innerHTML =
        `<div style="font-family:'Courier New',monospace;font-size:${fontSize}px;width:100%;line-height:1.5;">${html}</div>`;
}

function abrirPreviewTicket() {
    document.getElementById('modalPreviewTicket').classList.add('visible');
    document.getElementById('modalPreviewTicket').setAttribute('aria-hidden', 'false');
    renderPreview('Efectivo');
}

function cerrarPreviewTicket() {
    document.getElementById('modalPreviewTicket').classList.remove('visible');
    document.getElementById('modalPreviewTicket').setAttribute('aria-hidden', 'true');
}

function imprimirPreview() {
    const fontSize = document.getElementById('ticketFontSize').value || '12';
    const anchoMm  = document.getElementById('ticketAnchoMm').value || '58';
    const contenido = document.getElementById('previewTicketContent').innerHTML;

    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:0;height:0;border:none;';
    document.body.appendChild(iframe);

    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write(`<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Courier New',monospace; font-size:${fontSize}px; width:${anchoMm}mm; padding:8px; }
table { width:100%; border-collapse:collapse; }
td { padding:1px 0; }
@media print { @page { margin:0; size:${anchoMm}mm auto; } }
</style></head><body>${contenido}</body></html>`);
    doc.close();

    iframe.onload = function() {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
        setTimeout(() => document.body.removeChild(iframe), 1000);
    };
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


