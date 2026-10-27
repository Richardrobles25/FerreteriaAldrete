<?php
/**
 * Mensajes de error de las ventas (nuevaVenta / ventas a domicilio) que dicen CUAL producto fallo y POR QUE,
 * en lugar de un "Precio invalido para el producto" generico. Los usa el servidor al validar el carrito.
 */

function ventaNombreProducto(PDO $pdo, $productoId): string
{
    $id = intval(is_scalar($productoId) ? $productoId : 0);
    $st = $pdo->prepare("SELECT nombre_producto FROM productos WHERE producto_id = ?");
    $st->execute([$id]);
    $n = $st->fetchColumn();
    return ($n !== false && $n !== null && $n !== '') ? (string)$n : 'Producto #' . $id;
}

function ventaFmtCant($n): string
{
    $s = rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

function ventaMsgPrecioBajo(string $nombre, float $precio, float $piso, bool $esCosto): string
{
    $ref = $esCosto ? 'su costo' : 'su precio de catálogo';
    return "«{$nombre}»: no se puede vender a $" . number_format($precio, 2) . " porque es menor que {$ref} ($" . number_format($piso, 2)
        . "). Sube el precio de venta, o si ese dato está mal capturado, corrígelo en Productos.";
}

function ventaMsgPrecioCero(string $nombre): string
{
    return "«{$nombre}»: no se puede vender a $0.00 porque el producto no tiene un precio de venta válido. Captura su precio en Productos.";
}

function ventaMsgStock(string $nombre, float $pedido, float $disponible): string
{
    return "«{$nombre}»: stock insuficiente en esta sucursal. Pides " . ventaFmtCant($pedido) . " y solo hay " . ventaFmtCant(max(0, $disponible))
        . ". Reduce la cantidad o quítalo del carrito.";
}

function ventaMsgNoDisponible(string $nombre): string
{
    return "«{$nombre}» ya no está disponible en esta sucursal (fue desactivado o dado de baja). Quítalo del carrito.";
}

function ventaMsgCantidadEntera(string $nombre, float $cantidad): string
{
    return "«{$nombre}»: este producto se vende por pieza, así que la cantidad debe ser un número entero (pediste " . ventaFmtCant($cantidad) . ").";
}

function ventaMsgCantidadPrecioInvalido(string $nombre, bool $cantidadMala): string
{
    return $cantidadMala
        ? "«{$nombre}»: la cantidad debe ser mayor a 0."
        : "«{$nombre}»: el precio del carrito no es válido (es negativo). Quítalo y vuelve a agregarlo.";
}

/**
 * [FIX-PRECIO-SIN-NOTA] Precios que un producto puede cobrar SIN nota de ajuste: su precio de venta, su precio de mayoreo (si lo
 * tiene) y el de cualquier promocion vigente en la sucursal. Antes el servidor solo exigia que el precio fuera >= al costo, asi que
 * una venta mandada con un precio menor al de lista (herramientas del navegador) se guardaba sin nota ni descuento registrado.
 * La pantalla ya obliga a escribir la nota al "Ajustar precio"; esto hace que el servidor tambien la exija.
 */
function ventaPreciosDeLista(PDO $pdo, $productoId, $sucursalId): array
{
    $id = intval(is_scalar($productoId) ? $productoId : 0);
    $st = $pdo->prepare("SELECT precio_venta, precio_mayoreo FROM productos WHERE producto_id = ?");
    $st->execute([$id]);
    $fila  = $st->fetch(PDO::FETCH_ASSOC);
    $lista = [];
    if ($fila) {
        foreach (['precio_venta', 'precio_mayoreo'] as $col) {
            $v = round(floatval($fila[$col] ?? 0), 2);
            if ($v > 0) $lista[] = $v;
        }
    }
    $sp = $pdo->prepare("
        SELECT precio_promocional FROM promociones
        WHERE producto_id = ? AND activo = 1
          AND (sucursal_id = ? OR sucursal_id IS NULL)
          AND CURDATE() BETWEEN fecha_inicio AND fecha_fin
    ");
    $sp->execute([$id, intval(is_scalar($sucursalId) ? $sucursalId : 0)]);
    foreach ($sp->fetchAll(PDO::FETCH_COLUMN) as $v) {
        $v = round(floatval($v), 2);
        if ($v > 0) $lista[] = $v;
    }
    $lista = array_values(array_unique($lista));
    rsort($lista);
    return $lista;
}

function ventaPrecioEsDeLista(array $lista, float $precio): bool
{
    foreach ($lista as $p) {
        if (abs($p - $precio) < 0.005) return true;
    }
    return false;
}

function ventaMsgPrecioSinNota(string $nombre, float $precio, array $lista): string
{
    $vigentes = implode(' o ', array_map(function ($p) { return '$' . number_format($p, 2); }, $lista));
    return "«{$nombre}»: no se puede vender a $" . number_format($precio, 2) . " porque no es uno de sus precios vigentes ({$vigentes}) y no trae nota de ajuste. "
        . "Si el precio del carrito quedó viejo, quítalo y vuelve a agregarlo; si es un descuento por daño, usa «Ajustar precio» y escribe el motivo.";
}
