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
