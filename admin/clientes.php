<?php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../includes/icons.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';
verificarSesion();
verificarRol(['Administrador']);
require_once '../includes/topbar_info.php';

if (isset($_GET['toggle'])) {
    // [FIX-ALTO-E-06] CSRF ausente antes — cualquier pagina visitada con la sesion del
    // Administrador abierta podia activar/desactivar un cliente por un simple GET.
    requerirCSRF($_GET['_token'] ?? '', 'clientes.php');
    // [FIX-TIPO-ARRAY-ID] (mismo patron ya corregido en admin/gastos*.php,
    // admin/empleados.php, etc.) intval() sobre un array se coacciona en silencio a 1/0
    // en vez de fallar -- sin este guard, "?toggle[]=x" activaria/desactivaria siempre
    // el cliente real cliente_id=1 sin importar que id se haya mandado.
    $cid = intval(is_scalar($_GET['toggle'] ?? null) ? $_GET['toggle'] : 0);
    $actual = $pdo->prepare("SELECT activo FROM clientes WHERE cliente_id = ?");
    $actual->execute([$cid]);
    $activo = $actual->fetchColumn();

    if ($activo) {
        $credPend = $pdo->prepare("SELECT COUNT(*) FROM creditos WHERE cliente_id = ? AND estado IN ('Activo','Vencido')");
        $credPend->execute([$cid]);
        if ($credPend->fetchColumn() > 0) {
            header('Location: clientes.php?error=credito_pendiente');
            exit();
        }
    }

    $pdo->prepare("UPDATE clientes SET activo = NOT activo WHERE cliente_id = ?")->execute([$cid]);
    header('Location: clientes.php');
    exit();
}

$errores = [];
$editando = null;

if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM clientes WHERE cliente_id = ?");
    $stmt->execute([intval(is_scalar($_GET['editar'] ?? null) ? $_GET['editar'] : 0)]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC);
    // [FIX-CLIENTE-EDITAR-FANTASMA] (espejo de cajeroInventario/clientes.php y
    // admin/cajero_clientes.php, que ya tenian esta comprobacion): sin esto, un cliente_id
    // inexistente en "?editar=" mostraba el formulario en blanco como "Nuevo cliente" en vez
    // de avisar que ese cliente ya no existe.
    if ($editando === false) {
        header('Location: clientes.php?msg=no_encontrado');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [FIX-ALTO-E-06] CSRF ausente antes en alta/edición de cliente.
    requerirCSRF($_POST['_token'] ?? '', 'clientes.php');
    // [FIX-TIPO-ARRAY-ID] (mismo patron ya corregido en admin/gastos*.php) un campo
    // mandado como array truena trim()/htmlspecialchars() con un TypeError sin capturar.
    $nombre = trim(is_scalar($_POST['nombre_completo'] ?? null) ? (string)$_POST['nombre_completo'] : '');
    $telefono = trim(is_scalar($_POST['telefono'] ?? null) ? (string)$_POST['telefono'] : '');
    $direccion = trim(is_scalar($_POST['direccion'] ?? null) ? (string)$_POST['direccion'] : '');
    $correo = trim(is_scalar($_POST['correo'] ?? null) ? (string)$_POST['correo'] : '');
    $descuento = floatval(is_scalar($_POST['descuento_fijo'] ?? null) ? $_POST['descuento_fijo'] : 0);
    $notas = trim(is_scalar($_POST['notas'] ?? null) ? (string)$_POST['notas'] : '');
    $creditoAutorizado = isset($_POST['credito_autorizado']) ? 1 : 0;
    $limiteCredito = floatval(is_scalar($_POST['limite_credito'] ?? null) ? $_POST['limite_credito'] : 0);
    // [FIX-CLIENTE-ID-DESINCRONIZADO] (mismo patron ya corregido en admin/formGasto.php,
    // admin/formEmpleado.php) Antes $clienteId salia directo del campo oculto del POST,
    // sin ninguna relacion con el "?editar=" de la URL que decide cual cliente se esta
    // mostrando/validando -- un POST manipulado podia editar/sobreescribir CUALQUIER otro
    // cliente real con los datos del formulario que se esta viendo. Se ancla al cliente_id
    // de $editando (ya resuelto contra la URL) cuando se esta editando; para un alta nueva
    // (sin "?editar=" en la URL) siempre es 0, sin importar que venga en el POST.
    $clienteId = (isset($_GET['editar']) && $editando) ? intval($editando['cliente_id']) : 0;

    if ($nombre === '') $errores[] = 'El nombre completo es obligatorio.';
    // [FIX-CLIENTE-LARGO] (espejo de cajeroInventario/clientes.php): nombre_completo (100),
    // direccion (255), correo (100) y notas (255) sin tope de longitud en el servidor — se
    // truncaban en silencio y se guardaban como "creado correctamente".
    if (mb_strlen($nombre) > 100)    $errores[] = 'El nombre no puede tener más de 100 caracteres.';
    if (mb_strlen($direccion) > 255) $errores[] = 'La dirección no puede tener más de 255 caracteres.';
    if (mb_strlen($correo) > 100)    $errores[] = 'El correo no puede tener más de 100 caracteres.';
    if (mb_strlen($notas) > 255)     $errores[] = 'Las notas no pueden tener más de 255 caracteres.';
    // [FIX-CONSISTENCIA] admin/cajero_clientes.php y cajeroInventario/clientes.php ya validan
    // el formato de correo con filter_var(); aqui faltaba por completo — cualquier texto se
    // aceptaba como "correo" valido.
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) $errores[] = 'El correo electrónico no tiene un formato válido.';
    // [FIX-TELEFONO-FORMATO] Faltaba aqui (cajeroInventario/clientes.php y
    // admin/cajero_clientes.php ya lo validaban) — sin esto, "311 425 4121" y "3114254121" se
    // guardaban como telefonos "distintos" para la comparacion exacta del check de duplicados,
    // dejando pasar un cliente duplicado del mismo numero con formato diferente (verificado en
    // vivo: cliente_id 62 se creo duplicando el telefono de Nicolas Cisneros).
    if ($telefono !== '' && (!ctype_digit($telefono) || strlen($telefono) !== 10)) $errores[] = 'El teléfono debe tener exactamente 10 dígitos numéricos.';
    if ($descuento < 0 || $descuento > 100) $errores[] = 'El descuento fijo debe estar entre 0 y 100.';
    if (!$creditoAutorizado) $limiteCredito = 0;
    // [FIX-PRECIO-MAX-CREDITO] limite_credito es DECIMAL(10,2); sin tope, un valor absurdo
    // tronaba con HTTP 500 crudo en vez de un mensaje claro (mismo patrón ya corregido en
    // formProducto.php/paquetes.php). Se agrega también el negativo, que faltaba aquí
    // (admin/cajero_clientes.php y cajeroInventario/clientes.php ya lo validaban).
    if ($limiteCredito < 0) $errores[] = 'El límite de crédito no puede ser negativo.';
    if ($limiteCredito > 500000) $errores[] = 'El límite de crédito no puede ser mayor a $500,000.00. Verifica la cantidad capturada.';

    // [FIX-TELEFONO-DUPLICADO] El nombre del cliente no es único (dos personas reales pueden
    // llamarse igual), pero el teléfono sí debería serlo — es lo que realmente distingue a un
    // cliente de otro en el buscador de Nueva Venta. Mismo fix aplicado en
    // cajeroInventario/clientes.php y admin/cajero_clientes.php.
    // [FIX-TELEFONO-RACE] La tabla clientes no tiene UNIQUE en telefono (verificado en
    // baseDeDatos.sql), asi que el check-then-insert de arriba es una condicion de carrera
    // clasica: dos altas casi simultaneas con el mismo telefono pueden pasar ambas el SELECT
    // antes de que cualquiera haga el INSERT. No se pudo forzar el duplicado en el entorno
    // local (el servidor de pruebas de PHP serializa las peticiones), pero el mismo patron ya
    // se corrigio de forma preventiva en devoluciones.php esta sesion -- se aplica el mismo
    // candado GET_LOCK aqui, ahora candado + verificacion dentro de la misma seccion critica.
    $lockTelefono   = null;
    $lockTelAdquirido = true;
    if ($telefono !== '') {
        $lockTelefono     = 'cliente_telefono_' . $telefono;
        $lockTelAdquirido = (bool) $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockTelefono) . ", 5)")->fetchColumn();
        if (!$lockTelAdquirido) {
            $errores[] = 'Otro usuario está guardando un cliente con este mismo teléfono en este momento. Intenta de nuevo.';
        }
    }

    if (empty($errores) && $telefono !== '') {
        $stmtDupTel = $pdo->prepare("SELECT nombre_completo FROM clientes WHERE telefono = ? AND cliente_id != ?");
        $stmtDupTel->execute([$telefono, $clienteId]);
        $dupTel = $stmtDupTel->fetchColumn();
        if ($dupTel !== false) {
            $errores[] = 'Ya existe un cliente con este teléfono: "' . $dupTel . '". Verifica que no sea la misma persona.';
        }
    }

    if (empty($errores)) {
        if ($clienteId > 0) {
            $stmt = $pdo->prepare("
                UPDATE clientes
                SET nombre_completo = ?, telefono = ?, direccion = ?, correo = ?, descuento_fijo = ?, notas = ?, credito_autorizado = ?, limite_credito = ?
                WHERE cliente_id = ?
            ");
            $stmt->execute([$nombre, $telefono, $direccion, $correo, $descuento, $notas, $creditoAutorizado, $limiteCredito, $clienteId]);
            // [FIX-CLIENTE-EDITAR-FANTASMA] (espejo de cajeroInventario/clientes.php): rowCount()
            // por si solo no basta (PDO/MySQL reporta filas MODIFICADAS, no encontradas — guardar
            // sin cambios tambien da 0), asi que se verifica existencia por separado.
            if ($stmt->rowCount() === 0) {
                $stmtExisteCliente = $pdo->prepare("SELECT 1 FROM clientes WHERE cliente_id = ?");
                $stmtExisteCliente->execute([$clienteId]);
                if (!$stmtExisteCliente->fetchColumn()) {
                    if ($lockTelefono) $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockTelefono) . ")");
                    header('Location: clientes.php?msg=no_encontrado');
                    exit();
                }
            }
            if ($lockTelefono) $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockTelefono) . ")");
            header('Location: clientes.php?msg=editado');
            exit();
        }

        $stmt = $pdo->prepare("
            INSERT INTO clientes (nombre_completo, telefono, direccion, correo, descuento_fijo, notas, credito_autorizado, limite_credito, activo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$nombre, $telefono, $direccion, $correo, $descuento, $notas, $creditoAutorizado, $limiteCredito]);
        if ($lockTelefono) $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockTelefono) . ")");
        header('Location: clientes.php?msg=creado');
        exit();
    }
    if ($lockTelefono && $lockTelAdquirido) $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockTelefono) . ")");
}

$busqueda = trim(is_scalar($_GET['buscar'] ?? null) ? (string)$_GET['buscar'] : '');
$sucursal = intval(is_scalar($_GET['sucursal'] ?? null) ? $_GET['sucursal'] : 0);
$soloCredito = $_GET['credito'] ?? '';
$mostrarInactivos = isset($_GET['inactivos']);

$where = "WHERE 1=1";
$params = [];

if (!$mostrarInactivos) $where .= " AND c.activo = 1";
if ($busqueda !== '') {
    $where .= " AND (c.nombre_completo LIKE ? OR c.telefono LIKE ? OR c.correo LIKE ?)";
    $params[] = '%' . $busqueda . '%';
    $params[] = '%' . $busqueda . '%';
    $params[] = '%' . $busqueda . '%';
}
if ($soloCredito === 'si') $where .= " AND c.credito_autorizado = 1";
if ($soloCredito === 'no') $where .= " AND c.credito_autorizado = 0";
if ($sucursal) {
    $where .= " AND EXISTS (
        SELECT 1
        FROM ventas vx
        JOIN cajas cax ON vx.caja_id = cax.caja_id
        WHERE vx.cliente_id = c.cliente_id AND cax.sucursal_id = ?
    )";
    $params[] = $sucursal;
}

// ── Exportar deudores ─────────────────────────────────────────────────────
if (isset($_GET['exportar']) && $_GET['exportar'] === 'deudores') {
    require_once __DIR__ . '/export_helper.php';
    // [FIX-ALTO-E-09] "AND c.activo = 1" antes excluia del reporte a clientes desactivados
    // que aun asi tienen deuda viva — dinero por cobrar que dejaba de aparecer en cualquier
    // reporte en cuanto alguien desactivaba al cliente (a proposito o por error).
    $stmtD = $pdo->query("
        SELECT c.nombre_completo, c.telefono, c.direccion, c.correo, c.activo,
               COUNT(cr.credito_id)              AS creditos_abiertos,
               COALESCE(SUM(cr.saldo_pendiente),0) AS total_por_cobrar,
               MAX(cr.fecha_limite)               AS proximo_vencimiento
        FROM clientes c
        JOIN creditos cr ON cr.cliente_id = c.cliente_id
        WHERE cr.estado IN ('Activo','Vencido')
        GROUP BY c.cliente_id, c.nombre_completo, c.telefono, c.direccion, c.correo, c.activo
        ORDER BY total_por_cobrar DESC
    ");
    $deudores = $stmtD->fetchAll(PDO::FETCH_ASSOC);
    $columnas = ['Cliente','Teléfono','Domicilio','Correo','Créditos abiertos','Total por cobrar','Próx. vencimiento'];
    $filas = array_map(fn($r) => [
        $r['nombre_completo'] . ($r['activo'] ? '' : ' (Inactivo)'),
        $r['telefono']  ?: '—',
        $r['direccion'] ?: '—',
        $r['correo']    ?: '—',
        $r['creditos_abiertos'],
        '$' . number_format($r['total_por_cobrar'], 2),
        $r['proximo_vencimiento'] ? date('d/m/Y', strtotime($r['proximo_vencimiento'])) : '—',
    ], $deudores);
    $totalDeuda = array_sum(array_column($deudores, 'total_por_cobrar'));
    $resumen = [
        ['label' => 'Clientes con deuda', 'valor' => count($deudores)],
        ['label' => 'Total por cobrar',   'valor' => '$' . number_format($totalDeuda, 2)],
    ];
    exportarPDF('Clientes con Saldo Pendiente', 'Generado el ' . date('d/m/Y H:i'), $columnas, $filas, $resumen, 'L', '', [20, 13, 24, 17, 9, 10, 7]);
}

// ── Exportar ─────────────────────────────────────────────────────────
if (isset($_GET['exportar']) && in_array($_GET['exportar'], ['pdf','excel'])) {
    require_once __DIR__ . '/export_helper.php';

    $stmtExp = $pdo->prepare("
        SELECT
            c.nombre_completo, c.telefono, c.direccion, c.correo, c.descuento_fijo,
            c.credito_autorizado, c.limite_credito,
            (SELECT COUNT(*) FROM ventas v WHERE v.cliente_id = c.cliente_id) AS total_ventas,
            (SELECT COALESCE(SUM(v.total),0) FROM ventas v WHERE v.cliente_id = c.cliente_id AND v.estado='Completada') AS total_comprado
        FROM clientes c
        $where
        ORDER BY c.nombre_completo ASC
    ");
    $stmtExp->execute($params);
    $expData = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    $titulo = 'Listado de Clientes';
    $subtitulo = $busqueda ? "Búsqueda: $busqueda" : 'Todos los clientes';
    $columnas = ['Nombre','Teléfono','Dirección','Correo','Descuento %','Crédito','Límite Crédito','No. Ventas','Total Comprado'];
    $filas = array_map(fn($r) => [
        $r['nombre_completo'],
        $r['telefono']  ?: '—',
        $r['direccion'] ?: '—',
        $r['correo']    ?: '—',
        $r['descuento_fijo'] . '%',
        $r['credito_autorizado'] ? 'Sí' : 'No',
        '$' . number_format($r['limite_credito'], 2),
        $r['total_ventas'],
        '$' . number_format($r['total_comprado'], 2),
    ], $expData);
    $resumen = [
        ['label' => 'Total Clientes', 'valor' => count($expData)],
    ];

    if ($_GET['exportar'] === 'pdf') {
        exportarPDF($titulo, $subtitulo, $columnas, $filas, $resumen, 'L');
    } else {
        exportarExcel($titulo, $subtitulo, $columnas, $filas, $resumen);
    }
}

$stmt = $pdo->prepare("
    SELECT
        c.*,
        (
            SELECT COUNT(*)
            FROM ventas v
            WHERE v.cliente_id = c.cliente_id
        ) AS total_ventas,
        (
            SELECT COALESCE(SUM(v.total), 0)
            FROM ventas v
            WHERE v.cliente_id = c.cliente_id AND v.estado = 'Completada'
        ) AS total_comprado,
        (
            SELECT MAX(v.created_at)
            FROM ventas v
            WHERE v.cliente_id = c.cliente_id AND v.estado = 'Completada'
        ) AS ultima_compra,
        (
            SELECT COUNT(*)
            FROM creditos cr
            WHERE cr.cliente_id = c.cliente_id AND cr.estado IN ('Activo', 'Vencido')
        ) AS creditos_abiertos,
        (
            SELECT COALESCE(SUM(cr.saldo_pendiente), 0)
            FROM creditos cr
            WHERE cr.cliente_id = c.cliente_id AND cr.estado IN ('Activo', 'Vencido')
        ) AS saldo_pendiente,
        (
            SELECT GROUP_CONCAT(DISTINCT s.nombre ORDER BY s.nombre SEPARATOR ', ')
            FROM ventas v
            JOIN cajas ca ON v.caja_id = ca.caja_id
            JOIN sucursales s ON ca.sucursal_id = s.sucursal_id
            WHERE v.cliente_id = c.cliente_id
        ) AS sucursales_relacionadas
    FROM clientes c
    $where
    ORDER BY c.activo DESC, total_comprado DESC, c.nombre_completo ASC
");
$stmt->execute($params);
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totales = $pdo->query("
    SELECT COUNT(*) AS total_clientes, SUM(activo) AS clientes_activos, SUM(credito_autorizado) AS con_credito
    FROM clientes
")->fetch(PDO::FETCH_ASSOC);

$resumenCreditos = $pdo->query("
    SELECT COUNT(*) AS total_creditos_abiertos, COALESCE(SUM(saldo_pendiente), 0) AS saldo_total
    FROM creditos
    WHERE estado IN ('Activo', 'Vencido')
")->fetch(PDO::FETCH_ASSOC);

$sucursales = $pdo->query("SELECT sucursal_id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes - Ferreteria Aldrete</title>
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
    .toggle-btn { background: none; border: none; color: white; cursor: pointer; font-size: 20px; padding: 4px 8px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; }
    .toggle-btn:hover { background: rgba(255,255,255,0.2); }
    .topbar-right { display: flex; align-items: center; gap: 14px; font-size: 13px; }
    .logout-btn { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); color: white; padding: 5px 14px; border-radius: 5px; cursor: pointer; font-size: 12px; }
    .logout-btn:hover { background: rgba(255,255,255,0.3); }
    .content { flex: 1; padding: 24px; overflow-y: auto; display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; align-items: start; }
    .content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
    .content-header h1 { font-size: 20px; color: #222; font-weight: 600; }
    .stats { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 16px; }
    .stat { background: white; border-radius: 8px; padding: 14px; border: 0.5px solid #e8e8e8; border-top: 3px solid #14ace7; }
    .stat p { font-size: 11px; color: #999; margin: 0 0 4px; text-transform: uppercase; }
    .stat h3 { font-size: 20px; font-weight: 700; color: #222; margin: 0; }
    .filtros { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 13px; margin-bottom: 14px; display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; }
    .filtro-group { display: flex; flex-direction: column; gap: 4px; }
    .filtro-group label { font-size: 11px; color: #888; font-weight: 600; text-transform: uppercase; }
    .filtro-group input, .filtro-group select { padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    .filtro-group input:focus, .filtro-group select:focus { outline: none; border-color: #14ace7; }
    .btn-filtrar { background: #14ace7; color: white; border: none; padding: 9px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .btn-limpiar { background: white; color: #666; border: 1px solid #ddd; padding: 9px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; display: inline-block; }
    .btn-inactivos { background: #f0f0f0; color: #666; border: none; padding: 9px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
    .btn-inactivos.activo { background: #333; color: white; }
    .card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 20px; }
    .card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 16px; }
    .msg { padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
    .msg-exito { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #2e7d32; }
    .errores { background: #fdecea; color: #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; border-left: 3px solid #c0392b; }
    .errores ul { margin: 6px 0 0 16px; }
    .tabla-wrapper { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f9f9f9; }
    th { padding: 11px 14px; text-align: left; font-size: 12px; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #eee; }
    td { padding: 11px 14px; font-size: 13px; color: #444; border-bottom: 0.5px solid #f5f5f5; vertical-align: top; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }
    tr.inactivo td { opacity: 0.55; }
    .acciones { display: flex; gap: 5px; flex-wrap: wrap; }
    .btn-accion { padding: 5px 11px; border-radius: 5px; font-size: 12px; cursor: pointer; border: none; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-editar { background: #e3f2fd; color: #1565c0; }
    .btn-activar { background: #e8f5e9; color: #2e7d32; }
    .btn-desactivar { background: #fff8e1; color: #1565c0; }
    .badge-credito { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
    .badge-si { background: #e8f5e9; color: #2e7d32; }
    .badge-no { background: #f0f0f0; color: #666; }
    .sin-resultados { padding: 40px; text-align: center; color: #aaa; font-size: 14px; }
    .form-group { margin-bottom: 13px; }
    .form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; font-weight: 600; }
    .form-group input, .form-group textarea { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; color: #333; font-family: Arial, sans-serif; }
    .form-group input:focus, .form-group textarea:focus { outline: none; border-color: #14ace7; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .check-row { display: flex; align-items: center; gap: 8px; margin-bottom: 13px; font-size: 13px; color: #555; }
    .check-row input { width: auto; }
    .credito-campos { display: none; }
    .credito-campos.visible { display: block; }
    .btn-guardar { background: #14ace7; color: white; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; width: 100%; }
    .btn-cancelar-edit { background: white; color: #666; border: 1px solid #ddd; padding: 10px; border-radius: 6px; cursor: pointer; font-size: 13px; width: 100%; margin-top: 8px; text-decoration: none; display: block; text-align: center; }
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

<?php renderAdminSidebar('clientes_admin'); ?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left"><button class="toggle-btn" onclick="toggleSidebar()"><?= icono('menu') ?></button><h2>Clientes</h2></div>
        <div class="topbar-right">
            <span><?= htmlspecialchars($_SESSION['nombre_completo']) ?> <span style="opacity:.75;font-size:12px;">- <?= htmlspecialchars($nombreSucursal) ?></span></span>
            <form method="POST" action="/logout.php"><button class="logout-btn" type="submit">Cerrar sesion</button></form>
        </div>
    </div>

    <div class="content">
        <div>
            <div class="content-header">
                <h1>Administracion de clientes</h1>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'pdf'])) ?>" style="background:#c0392b;color:white;border:none;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;"><?= icono('download') ?> PDF</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'excel'])) ?>" style="background:#1b5e20;color:white;border:none;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;"><?= icono('download') ?> Excel</a>
                    <a href="?exportar=deudores" style="background:#6a1b9a;color:white;border:none;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;"><?= icono('wallet') ?> Deudores</a>
                </div>
            </div>
            <?php $msgKeyCli = is_scalar($_GET['msg'] ?? null) ? $_GET['msg'] : ''; ?>
            <?php if ($msgKeyCli === 'no_encontrado'): ?>
                <div class="msg" style="background:#fdecea;color:#c0392b;">Cliente no encontrado.</div>
            <?php elseif ($msgKeyCli !== ''): $mensajes = ['creado' => 'Cliente registrado correctamente.', 'editado' => 'Cliente actualizado correctamente.']; ?>
                <div class="msg msg-exito"><?= htmlspecialchars($mensajes[$msgKeyCli] ?? '') ?></div>
            <?php endif; ?>
            <?php if (($_GET['error'] ?? '') === 'credito_pendiente'): ?>
                <div class="msg" style="background:#fdecea;color:#c0392b;">No se puede desactivar este cliente porque tiene un crédito pendiente de pago.</div>
            <?php endif; ?>

            <div class="stats">
                <div class="stat"><p>Total clientes</p><h3><?= intval($totales['total_clientes'] ?? 0) ?></h3></div>
                <div class="stat"><p>Clientes activos</p><h3><?= intval($totales['clientes_activos'] ?? 0) ?></h3></div>
                <div class="stat"><p>Con credito</p><h3><?= intval($totales['con_credito'] ?? 0) ?></h3></div>
                <div class="stat"><p>Saldo pendiente</p><h3>$<?= number_format(floatval($resumenCreditos['saldo_total'] ?? 0), 0) ?></h3></div>
            </div>

            <form method="GET">
                <div class="filtros">
                    <div class="filtro-group"><label>Buscar</label><input type="text" name="buscar" placeholder="Nombre, telefono o correo..." value="<?= htmlspecialchars($busqueda) ?>" style="width:180px;" oninput="filtrarTabla(this.value)"></div>
                    <div class="filtro-group">
                        <label>Compró en sucursal</label>
                        <select name="sucursal">
                            <option value="0">Todas</option>
                            <?php foreach ($sucursales as $s): ?><option value="<?= $s['sucursal_id'] ?>" <?= $sucursal === intval($s['sucursal_id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['nombre']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Credito</label>
                        <select name="credito">
                            <option value="">Todos</option>
                            <option value="si" <?= $soloCredito === 'si' ? 'selected' : '' ?>>Autorizado</option>
                            <option value="no" <?= $soloCredito === 'no' ? 'selected' : '' ?>>Sin credito</option>
                        </select>
                    </div>
                    <?php if ($mostrarInactivos): ?><input type="hidden" name="inactivos" value="1"><?php endif; ?>
                    <button class="btn-filtrar" type="submit">Filtrar</button>
                    <?php if ($busqueda !== '' || $sucursal || $soloCredito !== ''): ?><a class="btn-limpiar" href="clientes.php">Limpiar</a><?php endif; ?>
                    <a class="btn-inactivos <?= $mostrarInactivos ? 'activo' : '' ?>" href="clientes.php?<?= $mostrarInactivos ? '' : 'inactivos=1' ?>"><?= $mostrarInactivos ? 'Ocultar inactivos' : 'Ver inactivos' ?></a>
                </div>
            </form>

            <div class="tabla-wrapper">
                <?php if (count($clientes) > 0): ?>
                    <table>
                        <thead><tr><th>Cliente</th><th>Compras</th><th>Credito</th><th>Sucursales</th><th>Estado</th><th>Acciones</th></tr></thead>
                        <tbody id="tablaFiltrable">
                            <?php foreach ($clientes as $cliente): ?>
                                <tr class="<?= !$cliente['activo'] ? 'inactivo' : '' ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($cliente['nombre_completo']) ?></strong>
                                        <div style="font-size:11px;color:#aaa;"><?= htmlspecialchars($cliente['telefono'] ?: ($cliente['correo'] ?: 'Sin contacto')) ?></div>
                                        <?php if (!empty($cliente['ultima_compra'])): ?><div style="font-size:11px;color:#14ace7;">Ultima compra: <?= date('d/m/Y', strtotime($cliente['ultima_compra'])) ?></div><?php endif; ?>
                                    </td>
                                    <td><strong><?= intval($cliente['total_ventas']) ?> ventas</strong><div style="font-size:11px;color:#aaa;">$<?= number_format($cliente['total_comprado'], 2) ?> acumulado</div></td>
                                    <td>
                                        <span class="badge-credito <?= $cliente['credito_autorizado'] ? 'badge-si' : 'badge-no' ?>"><?= $cliente['credito_autorizado'] ? 'Autorizado' : 'No autorizado' ?></span>
                                        <div style="font-size:11px;color:#aaa;">Limite: $<?= number_format($cliente['limite_credito'], 2) ?></div>
                                        <div style="font-size:11px;color:<?= $cliente['saldo_pendiente'] > 0 ? '#c0392b' : '#888' ?>;">Pendiente: $<?= number_format($cliente['saldo_pendiente'], 2) ?></div>
                                    </td>
                                    <td style="font-size:12px;"><?= htmlspecialchars($cliente['sucursales_relacionadas'] ?: 'Sin compras registradas') ?></td>
                                    <td><span style="font-size:12px;color:<?= $cliente['activo'] ? '#2e7d32' : '#c0392b' ?>;font-weight:600;"><?= $cliente['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                                    <td><div class="acciones"><a class="btn-accion btn-editar" href="clientes.php?editar=<?= $cliente['cliente_id'] ?>">Editar</a>
                                        <?php if ($cliente['activo'] && $cliente['saldo_pendiente'] > 0): ?>
                                            <span class="btn-accion" style="background:#f0f0f0;color:#aaa;cursor:not-allowed;" title="Tiene crédito pendiente de $<?= number_format($cliente['saldo_pendiente'],2) ?>">Desactivar</span>
                                        <?php else: ?>
                                            <a class="btn-accion <?= $cliente['activo'] ? 'btn-desactivar' : 'btn-activar' ?>" href="clientes.php?toggle=<?= $cliente['cliente_id'] ?>&_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>" onclick="return confirm('Deseas cambiar el estado de este cliente?')"><?= $cliente['activo'] ? 'Desactivar' : 'Activar' ?></a>
                                        <?php endif; ?>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="sin-resultados">No se encontraron clientes con esos filtros.</div>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="card">
                <h3><?= $editando ? 'Editar cliente' : 'Nuevo cliente' ?></h3>
                <?php if (!empty($errores)): ?><div class="errores"><ul><?php foreach ($errores as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="cliente_id" value="<?= intval($editando['cliente_id'] ?? 0) ?>">
                    <?php
                        // [FIX-TIPO-ARRAY-ID] Repoblar el formulario tras un error releyendo
                        // $_POST crudo tenia el mismo hueco ya corregido en admin/formGasto.php:
                        // si algun campo llegaba como array, el htmlspecialchars() de abajo
                        // volveria a tronar. Se usan las variables YA saneadas del manejador de
                        // POST (siempre escalares) en vez de releer $_POST directo.
                        $esPostCliente = $_SERVER['REQUEST_METHOD'] === 'POST';
                        $vNombre    = $esPostCliente ? $nombre    : ($editando['nombre_completo'] ?? '');
                        $vTelefono  = $esPostCliente ? $telefono  : ($editando['telefono']        ?? '');
                        $vDireccion = $esPostCliente ? $direccion : ($editando['direccion']       ?? '');
                        $vCorreo    = $esPostCliente ? $correo    : ($editando['correo']          ?? '');
                        $vDescuento = $esPostCliente ? $descuento : ($editando['descuento_fijo']  ?? 0);
                        $vNotas     = $esPostCliente ? $notas     : ($editando['notas']           ?? '');
                        $vCredAut   = $esPostCliente ? $creditoAutorizado : ($editando['credito_autorizado'] ?? 0);
                        $vLimite    = $esPostCliente ? $limiteCredito     : ($editando['limite_credito'] ?: '');
                    ?>
                    <div class="form-group"><label>Nombre completo *</label><input type="text" name="nombre_completo" maxlength="100" value="<?= htmlspecialchars($vNombre) ?>" placeholder="Ej. Juan Garcia"></div>
                    <div class="form-row">
                        <div class="form-group"><label>Telefono</label><input type="text" name="telefono" value="<?= htmlspecialchars($vTelefono) ?>" placeholder="10 digitos"></div>
                        <div class="form-group"><label>Descuento fijo (%)</label><input type="number" name="descuento_fijo" value="<?= htmlspecialchars($vDescuento) ?>" step="0.01" min="0" max="100"></div>
                    </div>
                    <div class="form-group"><label>Direccion</label><input type="text" name="direccion" maxlength="255" value="<?= htmlspecialchars($vDireccion) ?>" placeholder="Calle, numero, colonia"></div>
                    <div class="form-group"><label>Correo</label><input type="email" name="correo" maxlength="100" value="<?= htmlspecialchars($vCorreo) ?>" placeholder="correo@ejemplo.com"></div>
                    <div class="form-group"><label>Notas</label><textarea name="notas" rows="3" maxlength="255" placeholder="Observaciones del cliente"><?= htmlspecialchars($vNotas) ?></textarea></div>
                    <div class="check-row"><input type="checkbox" name="credito_autorizado" id="chkCredito" <?= ($vCredAut ? 'checked' : '') ?> onchange="toggleCredito(this.checked)"><label for="chkCredito">Autorizar credito a este cliente</label></div>
                    <div class="credito-campos <?= ($vCredAut ? 'visible' : '') ?>" id="creditoCampos"><div class="form-group"><label>Limite de credito</label><input type="number" name="limite_credito" value="<?= htmlspecialchars($vLimite) ?>" step="0.01" min="0" placeholder="0.00"></div></div>
                    <button class="btn-guardar" type="submit"><?= $editando ? 'Guardar cambios' : 'Registrar cliente' ?></button>
                    <?php if ($editando): ?><a class="btn-cancelar-edit" href="clientes.php">Cancelar</a><?php endif; ?>
                </form>
            </div>
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
function toggleCredito(checked) { document.getElementById('creditoCampos').classList.toggle('visible', checked); }
</script>
<script src="../includes/auto_filter.js"></script>
</body>
</html>


