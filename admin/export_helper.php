<?php
/**
 * Helper de exportación PDF y Excel con branding de Ferremateriales Aldrete.
 * Uso:
 *   exportarPDF($titulo, $subtitulo, $columnas, $filas, $resumen, $orientacion)
 *   exportarExcel($titulo, $subtitulo, $columnas, $filas, $resumen, $nombreArchivo)
 *
 * $columnas = ['Columna 1', 'Columna 2', ...]
 * $filas    = [['val1','val2',...], ...]
 * $resumen  = [['label'=>'Total', 'valor'=>'$1,234'], ...]  (opcional)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

define('LOGO_PATH',    __DIR__ . '/../logoDocumentos.jpeg');
define('EMPRESA_NOMBRE', 'Ferremateriales "Aldrete"');
define('EMPRESA_CIUDAD', 'Ixtlán del Río, Nayarit');
define('EMPRESA_TEL',    'Tel. 324 243 2025 / 324 243 6485');
define('COLOR_AZUL',     '#14ace7');
define('COLOR_AZUL_HEX', '14ace7');
define('COLOR_DARK',     '#1a1a2e');

// [FIX-MEDIO-F-08] Un valor de origen (nombre de producto, motivo, notas...) puede traer un
// BOM UTF-8 (EF BB BF) incrustado si alguien lo escribio/pego desde un editor que lo agrega al
// texto (no solo al inicio de archivo). Ese BOM, insertado en medio del HTML que se le pasa a
// mPDF, no es un caracter imprimible valido ahi — mPDF corrompe el layout del resto del
// documento a partir de ese punto (columnas/filas desalineadas o corte de contenido). Se limpia
// centralizado aqui, en el unico punto por el que pasan todos los exports (PDF y Excel).
function limpiarBOM($valor): string
{
    return str_replace("\xEF\xBB\xBF", '', (string) $valor);
}

// [FIX-MEDIO-F-09] Inyeccion de formulas en Excel: si una celda empieza con =, +, -, @, TAB o
// retorno de carro, Excel (y PhpSpreadsheet, que auto-detecta "=" como formula) puede
// interpretar el contenido como una formula al abrir el archivo — un nombre de producto o un
// motivo de movimiento con ese prefijo (a proposito o por accidente) podria ejecutar una
// formula/macro en vez de mostrarse como texto. Mitigacion estandar (OWASP CSV/Excel
// injection): anteponer un apostrofe para forzar texto literal.
function sanearFormulaExcel(string $valor): string
{
    if ($valor !== '' && strpbrk($valor[0], "=+-@\t\r") !== false) {
        return "'" . $valor;
    }
    return $valor;
}

/* ─────────────────────────────────────────────────────────────────── */
/*  PDF                                                                 */
/* ─────────────────────────────────────────────────────────────────── */
function exportarPDF(
    string $titulo,
    string $subtitulo,
    array  $columnas,
    array  $filas,
    array  $resumen      = [],
    string $orientacion  = 'L',   // L = landscape, P = portrait
    string $nombreArchivo = ''
): void {
    if (!$nombreArchivo) {
        $nombreArchivo = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $titulo)) . '_' . date('Y-m-d') . '.pdf';
    }

    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mpdf';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

    $mpdf = new Mpdf([
        'orientation'  => $orientacion,
        'margin_top'   => 38,
        'margin_bottom'=> 15,
        'margin_left'  => 12,
        'margin_right' => 12,
        'tempDir'      => $tmpDir,
    ]);

    // Marca de agua: tamaño moderado, centrada
    if (file_exists(LOGO_PATH)) {
        $mpdf->SetWatermarkImage(LOGO_PATH, 0.07, [120, 90], 'P');
        $mpdf->showWatermarkImage = true;
    }

    // Encabezado en cada página
    $logoBase64 = '';
    if (file_exists(LOGO_PATH)) {
        $logoBase64 = 'data:image/jpeg;base64,' . base64_encode(file_get_contents(LOGO_PATH));
    }
    $logoTag = $logoBase64
        ? "<img src='$logoBase64' style='height:36px;vertical-align:middle;'>"
        : '';

    $mpdf->SetHTMLHeader("
        <table width='100%' style='font-family:Arial;font-size:10px;border-bottom:2px solid #14ace7;padding-bottom:6px;'>
          <tr>
            <td width='18%' style='vertical-align:middle;'>$logoTag</td>
            <td width='64%' style='text-align:center;vertical-align:middle;'>
              <div style='font-size:15px;font-weight:bold;color:#14ace7;'>" . EMPRESA_NOMBRE . "</div>
              <div style='font-size:9px;color:#666;margin-top:2px;'>" . EMPRESA_CIUDAD . " &nbsp;|&nbsp; " . EMPRESA_TEL . "</div>
            </td>
            <td width='18%' style='text-align:right;color:#888;font-size:8px;vertical-align:middle;'>
              Generado: " . date('d/m/Y H:i') . "<br>
              Página {PAGENO} de {nbpg}
            </td>
          </tr>
        </table>
    ");

    $mpdf->SetHTMLFooter("
        <div style='text-align:center;font-size:7px;color:#aaa;border-top:1px solid #eee;padding-top:4px;'>
            " . EMPRESA_NOMBRE . " — " . EMPRESA_CIUDAD . " — Documento generado el " . date('d/m/Y \a \l\a\s H:i') . "
        </div>
    ");

    // ── Contenido HTML ─────────────────────────────────────────────
    $html = "<style>
        body { font-family: Arial, sans-serif; font-size: 10px; color: #333; }
        h2   { font-size: 14px; color: #1a1a2e; margin: 10px 0 2px; text-align:center; }
        .sub { font-size: 9px; color: #888; text-align:center; margin-bottom:10px; }
        .resumen-grid { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; justify-content:center; }
        .res-item { border:1px solid #e0e0e0; border-radius:5px; padding:6px 12px; min-width:110px; text-align:center; border-top:3px solid #14ace7; }
        .res-label { font-size:8px; color:#999; text-transform:uppercase; }
        .res-val   { font-size:12px; font-weight:bold; color:#222; }
        table  { width:100%; border-collapse:collapse; margin-top:4px; }
        thead tr { background-color:#14ace7; color:white; }
        th { padding:7px 8px; font-size:9px; text-align:left; font-weight:bold; }
        td { padding:6px 8px; font-size:9px; border-bottom:1px solid #f0f0f0; }
        tr.alt td { background-color:#f5fbff; }
        tr:last-child td { border-bottom:none; }
        .total-row td { font-weight:bold; background:#eef8ff; border-top:2px solid #14ace7; }
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
    </style>";

    $html .= "<h2>" . htmlspecialchars(limpiarBOM($titulo)) . "</h2>";
    if ($subtitulo) {
        $html .= "<div class='sub'>" . htmlspecialchars(limpiarBOM($subtitulo)) . "</div>";
    }

    // Resumen
    if ($resumen) {
        $html .= "<table style='width:100%;margin-bottom:12px;'><tr>";
        foreach ($resumen as $item) {
            $html .= "<td style='text-align:center;background:#eef8ff;border:1px solid #c0e8f8;border-radius:5px;border-top:3px solid #14ace7;padding:7px 12px;'>
                <div style='font-size:8px;color:#14ace7;text-transform:uppercase;font-weight:bold;letter-spacing:0.5px;'>" . htmlspecialchars(limpiarBOM($item['label'])) . "</div>
                <div style='font-size:13px;font-weight:bold;color:#1a1a2e;margin-top:2px;'>" . htmlspecialchars(limpiarBOM($item['valor'])) . "</div>
            </td>";
        }
        $html .= "</tr></table>";
    }

    // Tabla de datos
    $html .= "<table><thead><tr>";
    foreach ($columnas as $col) {
        $html .= "<th>" . htmlspecialchars(limpiarBOM($col)) . "</th>";
    }
    $html .= "</tr></thead><tbody>";

    foreach ($filas as $i => $fila) {
        $cls = ($i % 2 === 1) ? " class='alt'" : "";
        $html .= "<tr$cls>";
        foreach ($fila as $celda) {
            $html .= "<td>" . htmlspecialchars(limpiarBOM((string)$celda)) . "</td>";
        }
        $html .= "</tr>";
    }
    if (empty($filas)) {
        $html .= "<tr><td colspan='" . count($columnas) . "' style='text-align:center;color:#aaa;padding:20px;'>Sin datos</td></tr>";
    }
    $html .= "</tbody></table>";

    $mpdf->WriteHTML($html);
    $mpdf->Output($nombreArchivo, 'D');
    exit();
}

/* ─────────────────────────────────────────────────────────────────── */
/*  EXCEL                                                               */
/* ─────────────────────────────────────────────────────────────────── */
function exportarExcel(
    string $titulo,
    string $subtitulo,
    array  $columnas,
    array  $filas,
    array  $resumen       = [],
    string $nombreArchivo = ''
): void {
    if (!$nombreArchivo) {
        $nombreArchivo = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $titulo)) . '_' . date('Y-m-d') . '.xlsx';
    }

    $wb    = new Spreadsheet();
    $sheet = $wb->getActiveSheet();
    $sheet->setTitle(mb_substr($titulo, 0, 31));

    $numCols = max(count($columnas), 6);
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($numCols);

    // ── Fila 1-3: Encabezado empresa ────────────────────────────────
    $sheet->mergeCells("B1:{$lastCol}1");
    $sheet->mergeCells("B2:{$lastCol}2");
    $sheet->mergeCells("B3:{$lastCol}3");

    $sheet->setCellValue('B1', limpiarBOM(EMPRESA_NOMBRE));
    $sheet->setCellValue('B2', limpiarBOM(EMPRESA_CIUDAD . '  |  ' . EMPRESA_TEL));
    $sheet->setCellValue('B3', 'Generado: ' . date('d/m/Y H:i'));

    $sheet->getStyle('B1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF' . COLOR_AZUL_HEX]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
    ]);
    $sheet->getStyle('B2')->applyFromArray([
        'font' => ['size' => 9, 'color' => ['argb' => 'FF888888']],
    ]);
    $sheet->getStyle('B3')->applyFromArray([
        'font' => ['size' => 8, 'italic' => true, 'color' => ['argb' => 'FFaaaaaa']],
    ]);

    // Logo en columna A
    if (file_exists(LOGO_PATH)) {
        $drawing = new Drawing();
        $drawing->setPath(LOGO_PATH);
        $drawing->setCoordinates('A1');
        $drawing->setHeight(46);
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(2);
        $drawing->setWorksheet($sheet);
    }
    $sheet->getColumnDimension('A')->setWidth(10);
    $sheet->getRowDimension(1)->setRowHeight(20);
    $sheet->getRowDimension(2)->setRowHeight(14);
    $sheet->getRowDimension(3)->setRowHeight(13);

    // Línea separadora debajo del header
    $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . COLOR_AZUL_HEX]],
    ]);
    $sheet->getRowDimension(4)->setRowHeight(3);

    // ── Fila 5-6: Título del reporte ────────────────────────────────
    $sheet->mergeCells("A5:{$lastCol}5");
    $sheet->setCellValue('A5', limpiarBOM($titulo));
    $sheet->getStyle('A5')->applyFromArray([
        'font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FF1a1a2e']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
    ]);
    $sheet->getRowDimension(5)->setRowHeight(20);

    $filaActual = 6;
    if ($subtitulo) {
        $sheet->mergeCells("A6:{$lastCol}6");
        $sheet->setCellValue('A6', limpiarBOM($subtitulo));
        $sheet->getStyle('A6')->applyFromArray([
            'font' => ['size' => 9, 'italic' => true, 'color' => ['argb' => 'FF888888']],
        ]);
        $sheet->getRowDimension(6)->setRowHeight(14);
        $filaActual = 7;
    }

    // ── Resumen ─────────────────────────────────────────────────────
    if ($resumen) {
        $filaActual++;
        $col = 1;
        foreach ($resumen as $item) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $nextCol   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1);

            $sheet->setCellValue("{$colLetter}{$filaActual}", limpiarBOM($item['label']));
            $sheet->getStyle("{$colLetter}{$filaActual}")->applyFromArray([
                'font' => ['size' => 8, 'bold' => true, 'color' => ['argb' => 'FF888888']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFf0f9ff']],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF' . COLOR_AZUL_HEX]]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getRowDimension($filaActual)->setRowHeight(14);

            $filaActual++;
            $sheet->setCellValue("{$colLetter}{$filaActual}", limpiarBOM($item['valor']));
            $sheet->getStyle("{$colLetter}{$filaActual}")->applyFromArray([
                'font' => ['size' => 11, 'bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFf0f9ff']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFdddddd']]],
            ]);
            $sheet->getRowDimension($filaActual)->setRowHeight(18);
            $filaActual--;

            $col += 2;
            if ($col > $numCols) break;
        }
        $filaActual += 2;
    }

    $filaActual++;

    // ── Encabezados de tabla ─────────────────────────────────────────
    $filaHeaders = $filaActual;
    foreach ($columnas as $i => $col) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->setCellValue("{$colLetter}{$filaHeaders}", limpiarBOM($col));
    }
    $sheet->getStyle("A{$filaHeaders}:{$lastCol}{$filaHeaders}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 10],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . COLOR_AZUL_HEX]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => false],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF0e8fc0']],
        ],
    ]);
    $sheet->getRowDimension($filaHeaders)->setRowHeight(18);

    // ── Filas de datos ───────────────────────────────────────────────
    foreach ($filas as $i => $fila) {
        $filaExcel = $filaHeaders + 1 + $i;
        foreach ($fila as $j => $celda) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($j + 1);
            // [FIX-MEDIO-F-09] setCellValueExplicit(...TYPE_STRING) fuerza texto literal,
            // ademas del apostrofe de sanearFormulaExcel() — doble defensa contra inyeccion
            // de formulas por valores que vienen de datos del usuario (nombre de producto,
            // motivo, notas, etc.).
            $sheet->setCellValueExplicit(
                "{$colLetter}{$filaExcel}",
                sanearFormulaExcel(limpiarBOM((string)$celda)),
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
        }

        $fillColor = ($i % 2 === 1) ? 'FFf0f9ff' : 'FFFFFFFF';
        $sheet->getStyle("A{$filaExcel}:{$lastCol}{$filaExcel}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $fillColor]],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFe0e0e0']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($filaExcel)->setRowHeight(15);
    }

    // Auto ancho columnas
    foreach (range(1, $numCols) as $col) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
    }
    $sheet->getColumnDimension('A')->setWidth(max(12, $sheet->getColumnDimension('A')->getWidth()));

    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
    try {
        (new Xlsx($wb))->save($tmpFile);
    } catch (\Throwable $e) {
        @unlink($tmpFile);
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<pre>Error al generar Excel: ' . htmlspecialchars($e->getMessage()) . '</pre>';
        exit();
    }

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"{$nombreArchivo}\"");
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    readfile($tmpFile);
    @unlink($tmpFile);
    exit();
}
