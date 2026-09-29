<?php
// [FEATURE-IMPORTAR-CLIENTES 2026-09-22] ob_start() para poder limpiar cualquier salida
// accidental antes de enviar el Excel de exportacion/plantilla (mismo patron que ya usa
// admin/inventario_productos.php para su propia importacion/plantilla).
ob_start();
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();
require_once '../includes/auth.php';
require_once '../includes/icons.php';
require_once '../config/database.php';
require_once __DIR__ . '/_admin_sidebar.php';
require_once '../vendor/autoload.php';
verificarSesion();
verificarRol(['Administrador']);
require_once '../includes/topbar_info.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
        // [FIX-TOGGLE-VENTA-PENDIENTE] (espejo de cajeroInventario/clientes.php y
        // admin/cajero_clientes.php) esta pantalla nunca revisaba ventas a domicilio
        // pendientes antes de desactivar -- a diferencia de las otras dos, aqui "toggle" es
        // la UNICA forma de desactivar un cliente (no existe un "eliminar" aparte), y el
        // boton "Desactivar" de la lista normal apunta directo aqui.
        $ventaPend = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE cliente_id = ? AND estado = 'Pendiente'");
        $ventaPend->execute([$cid]);
        if ($ventaPend->fetchColumn() > 0) {
            header('Location: clientes.php?error=tiene_pendientes');
            exit();
        }
    }

    $pdo->prepare("UPDATE clientes SET activo = NOT activo WHERE cliente_id = ?")->execute([$cid]);
    header('Location: clientes.php');
    exit();
}

// [FEATURE-ELIMINAR-TODOS-CLIENTES 2026-09-24] Borra TODOS los clientes (y en cascada sus
// creditos/ventas/abonos asociados) para poder reimportar el archivo del sistema anterior desde
// cero sin duplicar -- necesario porque ese modo de importacion siempre crea, nunca actualiza
// (ver [FEATURE-IMPORTAR-CLIENTES] mas abajo). Accion irreversible y de alto impacto: requiere
// escribir la frase de confirmacion exacta ademas del CSRF -- se valida aqui en el servidor, no
// solo en el JS del boton (el JS es solo para que el admin no lo dispare por accidente).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_todos_clientes'])) {
    requerirCSRF($_POST['_token'] ?? '', 'clientes.php');
    $fraseEsperada = 'ELIMINAR TODO';
    $fraseEscrita  = trim(is_scalar($_POST['confirmacion_texto'] ?? null) ? (string)$_POST['confirmacion_texto'] : '');
    if ($fraseEscrita !== $fraseEsperada) {
        header('Location: clientes.php?error=confirmacion_invalida');
        exit();
    }

    $idsBorrar = $pdo->query("SELECT cliente_id FROM clientes")->fetchAll(PDO::FETCH_COLUMN);
    $totalClientesBorrados = count($idsBorrar);
    if ($idsBorrar) {
        $ph = implode(',', array_fill(0, count($idsBorrar), '?'));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM abonos WHERE credito_id IN (SELECT credito_id FROM creditos WHERE cliente_id IN ($ph))")->execute($idsBorrar);
            $pdo->prepare("DELETE FROM movimientos_mora WHERE credito_id IN (SELECT credito_id FROM creditos WHERE cliente_id IN ($ph))")->execute($idsBorrar);
            $pdo->prepare("DELETE FROM mora_cancelaciones WHERE credito_id IN (SELECT credito_id FROM creditos WHERE cliente_id IN ($ph))")->execute($idsBorrar);
            $pdo->prepare("DELETE FROM devoluciones WHERE venta_id IN (SELECT venta_id FROM ventas WHERE cliente_id IN ($ph))")->execute($idsBorrar);
            $pdo->prepare("DELETE FROM venta_productos WHERE venta_id IN (SELECT venta_id FROM ventas WHERE cliente_id IN ($ph))")->execute($idsBorrar);
            $pdo->prepare("DELETE FROM creditos WHERE cliente_id IN ($ph)")->execute($idsBorrar);
            $pdo->prepare("DELETE FROM ventas WHERE cliente_id IN ($ph)")->execute($idsBorrar);
            $pdo->prepare("DELETE FROM clientes WHERE cliente_id IN ($ph)")->execute($idsBorrar);
            $pdo->commit();
        } catch (\Throwable $eBorrar) {
            $pdo->rollBack();
            error_log('[Ferreteria/clientes] Error al eliminar todos los clientes: ' . $eBorrar->getMessage());
            header('Location: clientes.php?error=eliminar_fallo');
            exit();
        }
    }
    $_SESSION['import_clientes_flash'] = ['exito' => "$totalClientesBorrados cliente(s) eliminado(s) junto con sus créditos y ventas asociadas.", 'errores' => []];
    header('Location: clientes.php');
    exit();
}

// ── Importar/exportar clientes (Excel) ──────────────────────────────────
// [FEATURE-IMPORTAR-CLIENTES 2026-09-22]

// Normaliza un encabezado para comparacion (mismo patron que admin/inventario_productos.php)
function normalizarEncabezadoCliente(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $s = preg_replace('/[^a-z0-9]/', '', $s);
    return $s;
}

// Reconoce tanto el export del sistema anterior (Nombres/Apellidos/Domicilio1.../Saldo actual)
// como nuestro propio export round-trip (ID Cliente/Nombre completo/Direccion/...).
function detectarCampoCliente(string $header): ?string {
    $h = normalizarEncabezadoCliente($header);
    $mapa = [
        'idcliente'         => 'cliente_id',
        'nombrecompleto'    => 'nombre_completo',
        'nombres'           => 'nombres',
        'apellidos'         => 'apellidos',
        'telefono'          => 'telefono',
        'direccion'         => 'direccion',
        'domicilio1'        => 'domicilio1',
        'domicilio2'        => 'domicilio2',
        'colonia'           => 'colonia',
        'municipio'         => 'municipio',
        'codigopostal'      => 'codigo_postal',
        'estado'            => 'estado_mx',
        'correo'            => 'correo',
        'email'             => 'correo',
        'descuentofijo'     => 'descuento_fijo',
        'notas'             => 'notas',
        'creditoautorizado' => 'credito_autorizado',
        'limitedecredito'   => 'limite_credito',
        'cobrarmora'        => 'cobrar_mora',
        'saldopendiente'    => 'saldo_actual',
        'saldoactual'       => 'saldo_actual',
        'activo'            => 'activo',
    ];
    foreach ($mapa as $patron => $campo) {
        if (str_contains($h, $patron)) return $campo;
    }
    return null;
}

// Descargar plantilla de importacion (formato de nuestro propio export)
if (isset($_GET['plantilla_clientes'])) {
    try {
        $spreadsheetPlt = new Spreadsheet();
        $sheetPlt = $spreadsheetPlt->getActiveSheet();
        $sheetPlt->setTitle('Plantilla');
        $headersPlt = ['ID Cliente','Nombre completo*','Telefono','Direccion','Correo','Descuento fijo','Notas','Credito autorizado (Si/No)','Limite de credito','Cobrar mora (Si/No)','Activo (Si/No)','Saldo pendiente'];
        $ejemplosPlt = [
            ['', 'Juan Garcia Lopez', '3111234567', 'Calle Hidalgo #45, El Pinar', 'juan@ejemplo.com', '0', '', 'No', '0', 'Si', 'Si', '0'],
            ['', 'Maria Fernanda Ruiz', '', 'Av. Mexico #12', '', '5', 'Cliente frecuente', 'Si', '3000', 'Si', 'Si', '850.00'],
        ];
        foreach ($headersPlt as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheetPlt->setCellValue("{$col}1", $h);
            $sheetPlt->getStyle("{$col}1")->getFont()->setBold(true);
        }
        foreach ($ejemplosPlt as $fila => $ejemplo) {
            foreach ($ejemplo as $i => $v) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheetPlt->setCellValue("{$col}" . ($fila + 2), $v);
            }
        }
        $ultimaColPlt = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headersPlt));
        foreach (range('A', $ultimaColPlt) as $col) {
            $sheetPlt->getColumnDimension($col)->setAutoSize(true);
        }
        $tmpPlt = sys_get_temp_dir() . '/cli_plt_' . uniqid() . '.xlsx';
        (new Xlsx($spreadsheetPlt))->save($tmpPlt);
        while (ob_get_level() > 0) ob_end_clean();
        @ini_set('zlib.output_compression', '0');
        if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="plantilla_clientes.xlsx"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tmpPlt));
        readfile($tmpPlt);
        @unlink($tmpPlt);
        exit();
    } catch (\Throwable $ePlt) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        die('Error al generar plantilla: ' . htmlspecialchars($ePlt->getMessage()));
    }
}

// Importar clientes desde Excel. Dos modos segun las columnas del archivo:
//  - Migracion del sistema anterior (sin columna "ID Cliente"): SIEMPRE crea clientes nuevos,
//    nunca actualiza -- se probo emparejar por "Folio" (igual que admin/inventario_productos.php
//    usa "codigo") pero el Folio del sistema anterior NO es unico por cliente (se repite entre
//    personas distintas), asi que se revirtio para no arriesgar fusionar clientes reales
//    distintos en un solo registro. Nace con credito_autorizado=0 y cobrar_mora=0 (el usuario
//    decidio no heredar confianza de credito ni cobrar mora retroactiva).
//  - Reimportacion de nuestro propio export (con columna "ID Cliente"): actualiza los clientes
//    que coincidan por ID y da de alta los que vengan con ID vacio/inexistente.
// [FIX-EXPORT-SALDO] En AMBOS modos, si el archivo trae "Saldo actual"/"Saldo pendiente" > 0 Y
// el cliente destino no tiene ya un credito Activo/Vencido, se recrea como credito standalone
// (venta_id NULL, sin fecha_limite para que nunca dispare "Vencido" ni mora automatica). Esto
// cubre tanto la migracion (cliente nuevo) como restaurar despues de un borrado completo (cliente
// recreado, sin credito) -- si el cliente YA tiene un credito activo, nunca se duplica.
$erroresImportCli = [];
$exitoImportCli    = false;

// [FIX-IMPORT-REFRESH-DUPLICA] El resultado de la importacion se guardaba en variables locales
// y se renderizaba directo en la misma respuesta del POST -- sin un redirect despues, recargar
// la pagina (F5) hacia que el navegador reenviara el MISMO POST (con el MISMO archivo adjunto),
// volviendo a importar todo de nuevo silenciosamente. Reportado en vivo: "le di recargar y me
// volvio a importar todo". Se cambia al patron Post-Redirect-Get: el resultado se guarda un
// instante en la sesion, se redirige, y se lee/borra aqui en el siguiente GET -- un F5 despues
// del redirect solo repite el GET (inofensivo), nunca el POST original.
if (isset($_SESSION['import_clientes_flash'])) {
    $flashImportCli = $_SESSION['import_clientes_flash'];
    unset($_SESSION['import_clientes_flash']);
    $exitoImportCli   = $flashImportCli['exito']   ?? false;
    $erroresImportCli = $flashImportCli['errores'] ?? [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['clientes_excel'])) {
    requerirCSRF($_POST['_token'] ?? '', 'clientes.php');
    set_time_limit(300);
    $archivoCli = $_FILES['clientes_excel'];
    if ($archivoCli['error'] === 0) {
        try {
            $readerCli      = IOFactory::createReaderForFile($archivoCli['tmp_name']);
            $spreadsheetCli = $readerCli->load($archivoCli['tmp_name']);

            // [FIX-HOJA-ACTIVA 2026-09-25] Antes solo se leia getActiveSheet() -- si el archivo
            // trae varias hojas (ej. una "Portada"/notas antes de los datos, algo comun al editar
            // en Excel real) y esa NO es la hoja activa al guardar, la importacion fallaba con
            // "no se encontro columna de nombre" sin ninguna pista de que el problema era la hoja
            // equivocada. Ahora se recorren TODAS las hojas del archivo y se usa la PRIMERA que
            // tenga una columna de nombre reconocible en su encabezado; si ninguna la tiene (o el
            // archivo es de una sola hoja, el caso de siempre), se usa la hoja activa como antes.
            $rowsCli = null;
            foreach ($spreadsheetCli->getAllSheets() as $hojaCandidata) {
                $filasHoja = $hojaCandidata->toArray();
                if (empty($filasHoja)) continue;
                $tieneNombreHoja = false;
                foreach ($filasHoja[0] as $header) {
                    if ($header === null || $header === '') continue;
                    $campoHoja = detectarCampoCliente((string)$header);
                    if ($campoHoja === 'nombre_completo' || $campoHoja === 'nombres') { $tieneNombreHoja = true; break; }
                }
                if ($tieneNombreHoja) { $rowsCli = $filasHoja; break; }
            }
            if ($rowsCli === null) $rowsCli = $spreadsheetCli->getActiveSheet()->toArray();
            if (empty($rowsCli)) throw new Exception('El archivo está vacío.');

            $headerRowCli = $rowsCli[0];
            $colMapCli = [];
            foreach ($headerRowCli as $idx => $header) {
                if ($header === null || $header === '') continue;
                $campo = detectarCampoCliente((string)$header);
                if ($campo && !isset($colMapCli[$campo])) $colMapCli[$campo] = $idx;
            }

            $esReimportacion = isset($colMapCli['cliente_id']);
            $tieneNombre = isset($colMapCli['nombre_completo']) || isset($colMapCli['nombres']);
            if (!$tieneNombre) {
                throw new Exception('No se encontró una columna de nombre en el archivo (se esperaba "Nombre completo" o "Nombres").');
            }

            $pdo->beginTransaction();
            $creados = 0; $actualizados = 0; $conDeuda = 0; $conDeudaOmitida = 0;
            $filasConErrorCli = [];
            $telsEnLote = [];

            for ($i = 1; $i < count($rowsCli); $i++) {
                $row  = $rowsCli[$i];
                $fila = $i + 1;
                $g = fn($campo) => isset($colMapCli[$campo]) ? trim((string)($row[$colMapCli[$campo]] ?? '')) : '';
                $nombreCli = '';

                try {
                    if (isset($colMapCli['nombre_completo'])) {
                        $nombreCli = $g('nombre_completo');
                    } else {
                        $nombreCli = trim($g('nombres') . ' ' . $g('apellidos'));
                    }
                    if ($nombreCli === '') continue; // fila vacia, se ignora sin error
                    if (mb_strlen($nombreCli) > 100) throw new Exception('El nombre no puede tener más de 100 caracteres.');

                    // Telefono: se limpia a solo digitos; si no quedan exactamente 10, se deja
                    // en blanco (dato legado poco confiable) en vez de rechazar la fila completa.
                    $telCrudo    = preg_replace('/\D/', '', $g('telefono'));
                    $telefonoCli = (strlen($telCrudo) === 10) ? $telCrudo : '';

                    // Direccion: nuestro propio export trae un solo campo; el sistema anterior
                    // la trae repartida en varias columnas que se concatenan aqui.
                    if (isset($colMapCli['direccion'])) {
                        $direccionCli = $g('direccion');
                    } else {
                        $direccionCli = trim(implode(', ', array_filter([
                            $g('domicilio1'), $g('domicilio2'), $g('colonia'),
                            $g('municipio'), $g('estado_mx'), $g('codigo_postal'),
                        ], fn($v) => $v !== '')));
                    }
                    if (mb_strlen($direccionCli) > 255) throw new Exception('La dirección no puede tener más de 255 caracteres (revisa las columnas de domicilio).');

                    $correoCli = $g('correo');
                    // Dato legado con formato invalido: se descarta sin bloquear la fila.
                    if ($correoCli !== '' && !filter_var($correoCli, FILTER_VALIDATE_EMAIL)) $correoCli = '';
                    if (mb_strlen($correoCli) > 100) throw new Exception('El correo no puede tener más de 100 caracteres.');

                    $notasCli = $g('notas');
                    if (mb_strlen($notasCli) > 255) throw new Exception('Las notas no pueden tener más de 255 caracteres.');

                    $descuentoCli = isset($colMapCli['descuento_fijo']) ? floatval($g('descuento_fijo')) : 0;
                    if ($descuentoCli < 0 || $descuentoCli > 100) throw new Exception('El descuento fijo debe estar entre 0 y 100.');

                    if ($esReimportacion) {
                        $esSiCli = fn($v) => in_array(mb_strtolower($v), ['si','sí','1','true'], true);
                        $creditoAutCli = $esSiCli($g('credito_autorizado')) ? 1 : 0;
                        $cobrarMoraCli = ($g('cobrar_mora') === '') ? 1 : ($esSiCli($g('cobrar_mora')) ? 1 : 0);
                        $activoCli     = ($g('activo') === '') ? 1 : ($esSiCli($g('activo')) ? 1 : 0);
                        $limiteCli     = floatval(str_replace([',', '$'], '', $g('limite_credito')));
                        if (!$creditoAutCli) $limiteCli = 0;
                        if ($limiteCli < 0 || $limiteCli > 500000) throw new Exception('El límite de crédito debe estar entre 0 y $500,000.00.');
                    } else {
                        // Migracion del sistema anterior: sin confianza de credito heredada.
                        $creditoAutCli = 0;
                        $cobrarMoraCli = 0;
                        $activoCli     = 1;
                        $limiteCli     = 0;
                    }

                    // [FEATURE-IMPORTAR-CLIENTES] Solo el modo "ID Cliente" (reimportacion de
                    // nuestro propio export) actualiza por coincidencia. El modo migracion NO
                    // intenta emparejar por "Folio": se probo y el Folio del sistema anterior NO
                    // es unico por cliente (el mismo folio aparece repetido en filas de personas
                    // distintas -- verificado: Folio 1 = "ABELINO" Y "JOSE CERVANTES" a la vez),
                    // asi que emparejar por folio fusionaba clientes reales distintos en un solo
                    // registro, perdiendo datos. Sin una llave confiable, este modo siempre crea.
                    $clienteIdDestino = 0;
                    if ($esReimportacion) {
                        $cid = intval($g('cliente_id'));
                        if ($cid > 0) {
                            $stmtExisteCli = $pdo->prepare("SELECT 1 FROM clientes WHERE cliente_id = ?");
                            $stmtExisteCli->execute([$cid]);
                            if ($stmtExisteCli->fetchColumn()) $clienteIdDestino = $cid;
                        }
                    }

                    // Telefono duplicado: contra la BD (excluyendo al propio cliente que se esta
                    // actualizando, si aplica) y contra otra fila ya procesada en este archivo.
                    if ($telefonoCli !== '') {
                        if (isset($telsEnLote[$telefonoCli])) {
                            throw new Exception('Teléfono duplicado dentro del mismo archivo (fila ' . $telsEnLote[$telefonoCli] . ').');
                        }
                        // [FIX-TEL-INACTIVO 2026-09-25] El telefono de un cliente ya desactivado
                        // no debe bloquear para siempre a otra persona real que use ese mismo
                        // numero despues (pasa seguido: alguien se da de baja, su numero se
                        // reasigna a otra persona meses despues). Solo bloquea si el duplicado
                        // esta ACTIVO.
                        $stmtDupTelCli = $pdo->prepare("SELECT nombre_completo FROM clientes WHERE telefono = ? AND cliente_id != ? AND activo = 1");
                        $stmtDupTelCli->execute([$telefonoCli, $clienteIdDestino]);
                        $dupTelCli = $stmtDupTelCli->fetchColumn();
                        if ($dupTelCli !== false) {
                            throw new Exception('Ya existe un cliente con este teléfono: "' . $dupTelCli . '".');
                        }
                        $telsEnLote[$telefonoCli] = $fila;
                    }

                    if ($clienteIdDestino > 0) {
                        $pdo->prepare("
                            UPDATE clientes
                            SET nombre_completo=?, telefono=?, direccion=?, correo=?, descuento_fijo=?, notas=?,
                                credito_autorizado=?, limite_credito=?, cobrar_mora=?, activo=?
                            WHERE cliente_id=?
                        ")->execute([$nombreCli, $telefonoCli ?: null, $direccionCli ?: null, $correoCli ?: null, $descuentoCli, $notasCli ?: null, $creditoAutCli, $limiteCli, $cobrarMoraCli, $activoCli, $clienteIdDestino]);
                        $actualizados++;
                    } else {
                        $pdo->prepare("
                            INSERT INTO clientes (nombre_completo, telefono, direccion, correo, descuento_fijo, notas, credito_autorizado, limite_credito, cobrar_mora, activo)
                            VALUES (?,?,?,?,?,?,?,?,?,?)
                        ")->execute([$nombreCli, $telefonoCli ?: null, $direccionCli ?: null, $correoCli ?: null, $descuentoCli, $notasCli ?: null, $creditoAutCli, $limiteCli, $cobrarMoraCli, $activoCli]);
                        $clienteIdDestino = (int)$pdo->lastInsertId();
                        $creados++;
                    }

                    // Saldo heredado/restaurado: aplica igual en los dos modos (columna "Saldo
                    // actual" del sistema anterior o "Saldo pendiente" de nuestro propio export
                    // -- detectarCampoCliente() ya las mapea al mismo campo). Solo se recrea el
                    // credito standalone si el cliente NO tiene ya un credito Activo/Vencido: eso
                    // cubre tanto la migracion (cliente nuevo, sin credito) como restaurar tras un
                    // borrado completo (cliente recreado, sin credito) sin arriesgar duplicar la
                    // deuda de un cliente que ya tenia su credito real intacto (ese SIEMPRE se
                    // omite, sin importar el numero que traiga el archivo -- podria estar viejo).
                    if (isset($colMapCli['saldo_actual'])) {
                        $saldoCli = round((float) str_replace(['$', ',', ' '], '', $g('saldo_actual')), 2);
                        if ($saldoCli > 0) {
                            $stmtTieneCredCli = $pdo->prepare("SELECT COUNT(*) FROM creditos WHERE cliente_id = ? AND estado IN ('Activo','Vencido')");
                            $stmtTieneCredCli->execute([$clienteIdDestino]);
                            if ($stmtTieneCredCli->fetchColumn() == 0) {
                                $pdo->prepare("
                                    INSERT INTO creditos (cliente_id, venta_id, monto_total, saldo_pendiente, estado, fecha_limite)
                                    VALUES (?, NULL, ?, ?, 'Activo', NULL)
                                ")->execute([$clienteIdDestino, $saldoCli, $saldoCli]);
                                $conDeuda++;
                            } else {
                                $conDeudaOmitida++;
                            }
                        }
                    }
                } catch (Exception $eRowCli) {
                    $filasConErrorCli[] = "Fila $fila (\"$nombreCli\"): " . $eRowCli->getMessage();
                }
            }

            $pdo->commit();
            $exitoImportCli = "$creados cliente(s) nuevo(s), $actualizados actualizado(s).";
            if ($conDeuda > 0) $exitoImportCli .= " $conDeuda con saldo pendiente restaurado.";
            if ($conDeudaOmitida > 0) $exitoImportCli .= " $conDeudaOmitida ya tenían crédito activo (no se duplicó).";
            if ($filasConErrorCli) $erroresImportCli = $filasConErrorCli;
        } catch (Exception $eCli) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $erroresImportCli[] = 'Error al leer el archivo: ' . $eCli->getMessage();
        }
    } else {
        $erroresImportCli[] = 'Error al subir el archivo.';
    }
    $_SESSION['import_clientes_flash'] = ['exito' => $exitoImportCli, 'errores' => $erroresImportCli];
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

// [FEATURE-IMPORTAR-CLIENTES] "!isset($_FILES['clientes_excel'])" evita que el POST del
// formulario de importacion (manejado arriba) tambien caiga en el alta/edicion manual de abajo
// -- sin este candado, ese POST no traeria "nombre_completo" y este bloque lo tomaria como un
// intento de alta manual vacio, mostrando "El nombre completo es obligatorio" en la pantalla.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['clientes_excel'])) {
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
    // [FEATURE-COBRAR-MORA 2026-09-22] Si se desmarca, este cliente NUNCA acumula mora en
    // ninguno de sus creditos (permanente, no es lo mismo que "Cancelar mora" mas abajo en
    // creditos.php, que solo perdona la ya acumulada de un credito puntual). El checkbox en
    // el HTML viene marcado por default para un alta nueva, asi que un envio normal sin
    // tocarlo llega aqui como 1 -- solo llega en 0 si el admin lo desmarca a proposito.
    $cobrarMora = isset($_POST['cobrar_mora']) ? 1 : 0;
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
        // [FIX-TEL-INACTIVO 2026-09-25] Ver comentario igual mas arriba en el import -- un
        // cliente desactivado no debe bloquear su telefono para siempre.
        $stmtDupTel = $pdo->prepare("SELECT nombre_completo FROM clientes WHERE telefono = ? AND cliente_id != ? AND activo = 1");
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
                SET nombre_completo = ?, telefono = ?, direccion = ?, correo = ?, descuento_fijo = ?, notas = ?, credito_autorizado = ?, limite_credito = ?, cobrar_mora = ?
                WHERE cliente_id = ?
            ");
            $stmt->execute([$nombre, $telefono, $direccion, $correo, $descuento, $notas, $creditoAutorizado, $limiteCredito, $cobrarMora, $clienteId]);
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
            INSERT INTO clientes (nombre_completo, telefono, direccion, correo, descuento_fijo, notas, credito_autorizado, limite_credito, cobrar_mora, activo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$nombre, $telefono, $direccion, $correo, $descuento, $notas, $creditoAutorizado, $limiteCredito, $cobrarMora]);
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
if (isset($_GET['exportar']) && $_GET['exportar'] === 'pdf') {
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

    exportarPDF($titulo, $subtitulo, $columnas, $filas, $resumen, 'L');
}

// [FEATURE-IMPORTAR-CLIENTES] El boton "Excel" (a diferencia del PDF, que sigue siendo el
// reporte decorativo de arriba) genera el mismo formato reimportable que el panel de "Importar
// Excel" espera -- incluye "ID Cliente" para que, si se vuelve a subir este mismo archivo mas
// adelante, se actualicen los clientes existentes en vez de duplicarlos. Respeta los filtros
// activos (busqueda/sucursal/credito) igual que ya hacia el Excel decorativo anterior.
if (isset($_GET['exportar']) && $_GET['exportar'] === 'excel') {
    try {
        // [FIX-EXPORT-SALDO] "saldo_pendiente" (suma de creditos Activo/Vencido de cada cliente)
        // se agrega al export para que sea un respaldo completo: sin esta columna, borrar y
        // reimportar el propio export traia de vuelta a los clientes pero perdia su deuda por
        // completo (reportado en vivo: 338 clientes con $0 pendiente tras restaurar).
        $stmtExpXlsx = $pdo->prepare("
            SELECT c.*,
                   (SELECT COALESCE(SUM(cr.saldo_pendiente), 0) FROM creditos cr WHERE cr.cliente_id = c.cliente_id AND cr.estado IN ('Activo','Vencido')) AS saldo_pendiente_actual
            FROM clientes c
            $where
            ORDER BY c.nombre_completo ASC
        ");
        $stmtExpXlsx->execute($params);
        $clientesExp = $stmtExpXlsx->fetchAll(PDO::FETCH_ASSOC);

        $spreadsheetExp = new Spreadsheet();
        $sheetExp = $spreadsheetExp->getActiveSheet();
        $sheetExp->setTitle('Clientes');
        $headersExp = ['ID Cliente','Nombre completo','Telefono','Direccion','Correo','Descuento fijo','Notas','Credito autorizado','Limite de credito','Cobrar mora','Activo','Saldo pendiente'];
        foreach ($headersExp as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheetExp->setCellValue("{$col}1", $h);
            $sheetExp->getStyle("{$col}1")->getFont()->setBold(true);
        }
        foreach ($clientesExp as $fIdx => $c) {
            $fila = $fIdx + 2;
            $valores = [
                $c['cliente_id'], $c['nombre_completo'], $c['telefono'] ?? '', $c['direccion'] ?? '',
                $c['correo'] ?? '', $c['descuento_fijo'], $c['notas'] ?? '',
                $c['credito_autorizado'] ? 'Si' : 'No', $c['limite_credito'],
                $c['cobrar_mora'] ? 'Si' : 'No', $c['activo'] ? 'Si' : 'No', $c['saldo_pendiente_actual'],
            ];
            foreach ($valores as $i => $v) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheetExp->setCellValue("{$col}{$fila}", $v);
            }
        }
        $ultimaColExp = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headersExp));
        foreach (range('A', $ultimaColExp) as $col) {
            $sheetExp->getColumnDimension($col)->setAutoSize(true);
        }
        $tmpExp = sys_get_temp_dir() . '/cli_exp_' . uniqid() . '.xlsx';
        (new Xlsx($spreadsheetExp))->save($tmpExp);
        while (ob_get_level() > 0) ob_end_clean();
        @ini_set('zlib.output_compression', '0');
        if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="clientes_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tmpExp));
        readfile($tmpExp);
        @unlink($tmpExp);
        exit();
    } catch (\Throwable $eExp) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        die('Error al generar el archivo: ' . htmlspecialchars($eExp->getMessage()));
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
    .btn-excel-import { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-excel-import:hover { background: #c8e6c9; }
    .btn-plantilla { background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-block; }
    .btn-plantilla:hover { background: #bbdefb; }
    .import-card { background: white; border-radius: 8px; border: 0.5px solid #e8e8e8; padding: 16px; margin-bottom: 16px; display: none; grid-column: 1 / -1; }
    .import-card.visible { display: block; }
    .import-card h3 { font-size: 14px; font-weight: 600; color: #333; margin: 0 0 12px; }
    .import-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .import-form input[type=file] { flex: 1; min-width: 200px; padding: 8px; border: 1px dashed #ddd; border-radius: 6px; font-size: 13px; }
    .btn-subir { background: #14ace7; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; }
    .msg-error { background: #fdecea; color: #c0392b; border-left: 3px solid #c0392b; padding: 12px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
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
        <!-- [FEATURE-IMPORTAR-CLIENTES] Panel de importacion: fuera de las dos columnas para
             ocupar todo el ancho (grid-column: 1 / -1 en la clase .import-card). -->
        <div class="import-card" id="importCard">
            <h3>Importar clientes desde Excel</h3>
            <?php if (!empty($erroresImportCli)): ?>
                <div class="msg-error">
                    <?php if (count($erroresImportCli) === 1): ?>
                        <?= htmlspecialchars($erroresImportCli[0]) ?>
                    <?php else: ?>
                        <strong><?= count($erroresImportCli) ?> error(es) durante la importación:</strong>
                        <ul style="margin:8px 0 0 16px;padding:0;">
                            <?php foreach ($erroresImportCli as $err): ?>
                                <li style="margin-bottom:4px;"><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($exitoImportCli): ?>
                <div class="msg msg-exito"><?= htmlspecialchars($exitoImportCli, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="import-form">
                    <input type="file" name="clientes_excel" accept=".xlsx,.xls" required>
                    <button class="btn-subir" type="submit">Importar</button>
                </div>
                <p style="font-size:12px;color:#aaa;margin-top:8px;">
                    Acepta el export del sistema anterior (crea clientes nuevos sin crédito autorizado ni mora, y conserva su saldo pendiente como deuda) o el propio botón "Excel" de esta pantalla (actualiza por ID Cliente). No modifica clientes existentes al migrar del sistema anterior.
                </p>
            </form>
            <!-- [FEATURE-ELIMINAR-TODOS-CLIENTES 2026-09-24] Solo tiene sentido para poder
                 reimportar el archivo del sistema anterior desde cero (ese modo siempre crea,
                 nunca actualiza). Accion irreversible: borra TODOS los clientes y en cascada sus
                 creditos/ventas/abonos. Doble confirmacion (confirm + escribir la frase exacta),
                 validada tambien en el servidor -- el JS es solo para evitar un clic accidental. -->
            <div style="margin-top:14px;padding-top:14px;border-top:1px dashed #f0c0c0;">
                <button type="button" onclick="confirmarEliminarTodosClientes()" style="background:#fdecea;color:#c0392b;border:1px solid #f5c6c6;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;">
                    Eliminar todos los clientes
                </button>
                <p style="font-size:11px;color:#c0392b;margin-top:6px;">
                    Borra permanentemente los <?= intval($totales['total_clientes'] ?? 0) ?> clientes actuales y sus <?= intval($resumenCreditos['total_creditos_abiertos'] ?? 0) ?> créditos abiertos (y ventas asociadas). Úsalo solo para reimportar el archivo original desde cero.
                </p>
            </div>
            <form method="POST" id="formEliminarTodosClientes" style="display:none;">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="eliminar_todos_clientes" value="1">
                <input type="hidden" name="confirmacion_texto" id="inputConfirmacionEliminar">
            </form>
        </div>

        <div>
            <div class="content-header">
                <h1>Administracion de clientes</h1>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a class="btn-plantilla" href="clientes.php?plantilla_clientes=1">Descargar plantilla</a>
                    <button class="btn-excel-import" onclick="toggleImportCli()">Importar Excel</button>
                    <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'pdf'])) ?>" style="background:#c0392b;color:white;border:none;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;"><?= icono('download') ?> PDF</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['exportar'=>'excel'])) ?>" style="background:#1b5e20;color:white;border:none;padding:8px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;" title="Incluye ID Cliente: se puede reimportar mas adelante para actualizar en bloque"><?= icono('download') ?> Excel</a>
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
            <?php if (($_GET['error'] ?? '') === 'tiene_pendientes'): ?>
                <div class="msg" style="background:#fdecea;color:#c0392b;">No se puede desactivar este cliente porque tiene ventas a domicilio pendientes de entrega.</div>
            <?php endif; ?>
            <?php if (($_GET['error'] ?? '') === 'confirmacion_invalida'): ?>
                <div class="msg" style="background:#fdecea;color:#c0392b;">No se eliminó nada: el texto de confirmación no coincidió exactamente con "ELIMINAR TODO".</div>
            <?php endif; ?>
            <?php if (($_GET['error'] ?? '') === 'eliminar_fallo'): ?>
                <div class="msg" style="background:#fdecea;color:#c0392b;">Ocurrió un error al eliminar. No se borró nada (se revirtió todo).</div>
            <?php endif; ?>

            <div class="stats">
                <div class="stat"><p>Total clientes</p><h3><?= intval($totales['total_clientes'] ?? 0) ?></h3></div>
                <div class="stat"><p>Clientes activos</p><h3><?= intval($totales['clientes_activos'] ?? 0) ?></h3></div>
                <div class="stat"><p>Con credito</p><h3><?= intval($totales['con_credito'] ?? 0) ?></h3></div>
                <div class="stat"><p>Saldo pendiente</p><h3>$<?= number_format(floatval($resumenCreditos['saldo_total'] ?? 0), 0) ?></h3></div>
            </div>

            <form method="GET">
                <div class="filtros">
                    <div class="filtro-group"><label>Buscar</label><input type="text" name="buscar" placeholder="Nombre, telefono o correo..." value="<?= htmlspecialchars($busqueda) ?>" style="width:180px;" oninput="filtrarTabla(this.value)" onkeydown="if(event.key==='Enter'){event.preventDefault();}" data-no-auto></div>
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
                                        <?php if (!$cliente['cobrar_mora']): ?><div style="font-size:11px;color:#e65100;font-weight:600;">Sin mora</div><?php endif; ?>
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
                        // [FIX-IMPORT-VARS-UNDEFINED] El POST del formulario de importacion
                        // (clientes_excel) tambien entra aqui abajo -- sin excluirlo, $esPostCliente
                        // salia true sin que $nombre/$telefono/etc. (solo se definen en el bloque de
                        // alta/edicion manual, guardado con el mismo candado) llegaran a existir.
                        $esPostCliente = $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['clientes_excel']);
                        $vNombre    = $esPostCliente ? $nombre    : ($editando['nombre_completo'] ?? '');
                        $vTelefono  = $esPostCliente ? $telefono  : ($editando['telefono']        ?? '');
                        $vDireccion = $esPostCliente ? $direccion : ($editando['direccion']       ?? '');
                        $vCorreo    = $esPostCliente ? $correo    : ($editando['correo']          ?? '');
                        $vDescuento = $esPostCliente ? $descuento : ($editando['descuento_fijo']  ?? 0);
                        $vNotas     = $esPostCliente ? $notas     : ($editando['notas']           ?? '');
                        $vCredAut   = $esPostCliente ? $creditoAutorizado : ($editando['credito_autorizado'] ?? 0);
                        $vLimite    = $esPostCliente ? $limiteCredito     : ($editando['limite_credito'] ?? '');
                        // [FEATURE-COBRAR-MORA 2026-09-22] Un alta nueva (sin $editando) nace
                        // marcada por default (1) — solo un cliente ya existente puede traer 0
                        // guardado (por ejemplo, importado desde el sistema anterior).
                        $vCobrarMora = $esPostCliente ? $cobrarMora : ($editando['cobrar_mora'] ?? 1);
                    ?>
                    <div class="form-group"><label>Nombre completo *</label><input type="text" name="nombre_completo" maxlength="100" value="<?= htmlspecialchars($vNombre) ?>" placeholder="Ej. Juan Garcia"></div>
                    <div class="form-row">
                        <div class="form-group"><label>Telefono</label><input type="tel" name="telefono" value="<?= htmlspecialchars($vTelefono) ?>" placeholder="10 digitos" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)"></div>
                        <div class="form-group"><label>Descuento fijo (%)</label><input type="number" name="descuento_fijo" value="<?= htmlspecialchars($vDescuento) ?>" step="0.01" min="0" max="100"></div>
                    </div>
                    <div class="form-group"><label>Direccion</label><input type="text" name="direccion" maxlength="255" value="<?= htmlspecialchars($vDireccion) ?>" placeholder="Calle, numero, colonia"></div>
                    <div class="form-group"><label>Correo</label><input type="email" name="correo" maxlength="100" value="<?= htmlspecialchars($vCorreo) ?>" placeholder="correo@ejemplo.com"></div>
                    <div class="form-group"><label>Notas</label><textarea name="notas" rows="3" maxlength="255" placeholder="Observaciones del cliente"><?= htmlspecialchars($vNotas) ?></textarea></div>
                    <div class="check-row">
                        <input type="checkbox" name="cobrar_mora" id="chkCobrarMora" <?= ($vCobrarMora ? 'checked' : '') ?>>
                        <label for="chkCobrarMora">Cobrar mora a este cliente si se atrasa</label>
                    </div>
                    <div style="font-size:11px;color:#aaa;margin:-8px 0 13px;">Si la desmarcas, este cliente nunca acumulará recargo por atraso en ningún crédito futuro, aunque se pase de su fecha límite. No afecta la mora que ya tenga acumulada — para eso usa "Cancelar mora" desde Créditos.</div>
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
function toggleImportCli() { document.getElementById('importCard').classList.toggle('visible'); }
<?php if ($exitoImportCli || !empty($erroresImportCli)): ?>
document.getElementById('importCard').classList.add('visible');
<?php endif; ?>
// [FEATURE-ELIMINAR-TODOS-CLIENTES] Doble paso: confirm() con el numero exacto de clientes que se
// van a borrar, y luego escribir la frase exacta -- ambos se vuelven a validar en el servidor.
function confirmarEliminarTodosClientes() {
    const total = <?= intval($totales['total_clientes'] ?? 0) ?>;
    if (total === 0) { alert('No hay clientes que eliminar.'); return; }
    if (!confirm('Vas a eliminar PERMANENTEMENTE los ' + total + ' clientes actuales junto con sus creditos y ventas asociadas. Esta accion no se puede deshacer.\n\n¿Deseas continuar?')) return;
    const frase = prompt('Para confirmar, escribe exactamente: ELIMINAR TODO');
    if (frase === null) return;
    if (frase.trim() !== 'ELIMINAR TODO') { alert('El texto no coincidio. No se elimino nada.'); return; }
    document.getElementById('inputConfirmacionEliminar').value = frase.trim();
    document.getElementById('formEliminarTodosClientes').submit();
}
</script>
<script src="../includes/auto_filter.js"></script>
</body>
</html>


