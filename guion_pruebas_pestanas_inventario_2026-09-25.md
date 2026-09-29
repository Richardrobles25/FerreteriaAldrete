# Guion de pruebas — Pestañas de venta, Plazo de crédito, Stock global e Inventario

Cubre los otros módulos modificados esta semana que no estaban en los guiones anteriores (esos se enfocaron en Créditos/Mora e Importar/Exportar Clientes). Todo esto también sigue sin commitear. 5 bloques, 16 pruebas en total.

## ✅ Resultado de la ejecución (corrida por el asistente, 2026-09-25)

**16/16 pasaron. Cero bugs reales.** Detalle:

- **Bloque A (Pestañas, 5/5):** probado en el navegador real con clics de verdad, no solo curl. A1 y A2 confirmados con los números exactos de localStorage (8 stock − 2 sueltos − 3 de paquete = 3 disponible en la otra pestaña). A3 confirmado que cerrar una pestaña libera su reserva al 100% (volvió a mostrar 8). A4 confirmado con un F5 real: las 2 pestañas se restauraron exactas. A5 sin errores nuevos en consola al topar el stock.
- **Bloque B (Plazo 15 días, 3/3):** venta a crédito real registrada por la interfaz → `fecha_limite` exacta a hoy+15 (2026-10-10). Forzado vencido → avanzó al siguiente sábado (2026-09-26), no a otros 15 días — las dos lógicas conviven bien. Confirmado el mismo código exacto en el espejo admin.
- **Bloque C (Stock global, 4/4):** con un producto de prueba en las 2 sucursales (50+30), el catálogo global mostró "80.00" con el desglose correcto por sucursal, en una sola fila. Al desactivar la sucursal Pinar bajó a "50.00" exacto; al reactivarla pero desactivar solo esa relación producto-sucursal, también bajó a "50.00" exacto.
- **Bloque D (Buscador sin recarga, 2/2):** confirmado con la URL y el foco del campo verificados después de escribir y esperar, y después de presionar Enter — nunca se recargó, nunca perdió lo escrito.
- **Bloque E (Datos de facturación, 2/2):** guardé valores distintos en ambos pares de campos y se mantuvieron completamente independientes; al vaciar solo los de facturación, los normales no se tocaron.

Todos los datos y productos de prueba (ZZTEST) se limpiaron. Los datos bancarios reales de la sucursal 1 se restauraron a sus valores originales. Base final: 337 clientes, 75 créditos, $359,501.00 — sin cambios respecto al estado antes de este guion.

---

## Bloque A — Pestañas de venta (carrito multi-pestaña)

Archivos: `cajeroInventario/nuevaVenta.php`, `admin/cajero_nuevaVenta.php`. Permite tener varias ventas en curso a la vez (ej. dos clientes en fila); cada pestaña guarda su propio carrito en `localStorage`, y el stock "disponible" que se muestra en cada pestaña descuenta lo que YA está apartado en el carrito de las OTRAS pestañas abiertas (tanto productos sueltos como componentes de paquetes).

**A1 — Reserva cruzada entre pestañas**
1. Abre `nuevaVenta.php`, agrega 2 pestañas nuevas (total 3).
2. En la Pestaña 1, agrega 5 unidades de un producto con stock real de 8.
3. Cambia a la Pestaña 2 y busca el mismo producto.
4. **Esperado:** la Pestaña 2 debe mostrar solo 3 disponibles (8 stock real − 5 ya reservados en la Pestaña 1), no los 8 completos.

**A2 — La reserva incluye componentes de paquetes**
1. En la Pestaña 1, agrega un paquete que consuma 3 unidades de un producto X como componente.
2. En la Pestaña 2, busca ese mismo producto X suelto.
3. **Esperado:** el disponible en la Pestaña 2 debe descontar esas 3 unidades del paquete de la Pestaña 1 también (no solo productos sueltos cuentan para la reserva).

**A3 — Cerrar una pestaña libera su reserva**
1. Con el escenario de A1 (5 reservadas en Pestaña 1), cierra la Pestaña 1 sin completar la venta.
2. Revisa el disponible del mismo producto en la Pestaña 2.
3. **Esperado:** debe volver a mostrar el stock completo (8), ya no debe descontar nada de la pestaña cerrada.

**A4 — Recargar la página (F5) con varias pestañas abiertas**
1. Con 3 pestañas abiertas, cada una con productos distintos en su carrito, presiona F5.
2. **Esperado:** las 3 pestañas deben restaurarse exactamente como estaban (mismos productos, cantidades, pestaña que estaba activa) — se guardan en `localStorage`, no deben perderse por un refresh.

**A5 — Botón "sin stock" no debe romper la página**
1. Intenta agregar más cantidad de un producto de la que hay disponible (forzando el mensaje de "Stock insuficiente").
2. Abre la consola del navegador antes de hacerlo.
3. **Esperado:** debe aparecer la alerta de stock insuficiente sin ningún error de JavaScript en consola (este fue un bug real ya corregido en la sesión — confirmar que sigue corregido).

---

## Bloque B — Plazo de crédito: 15 días desde la venta (ya no corte fijo)

**B1 — Fecha límite = hoy + 15 días exactos**
1. Registra una venta a crédito HOY.
2. **Esperado:** `SELECT fecha_limite FROM creditos WHERE credito_id = X;` debe ser exactamente la fecha de hoy + 15 días — no el próximo día 15 o fin de mes del calendario (ese era el comportamiento viejo que se reemplazó).

**B2 — El ciclo recurrente de mora usa una lógica distinta (siguiente sábado)**
1. Deja que el crédito de B1 llegue a su fecha_limite (o fuerza la fecha a hoy-1 vía SQL) y visita cualquier pantalla de créditos.
2. **Esperado:** la fecha_limite avanza al siguiente SÁBADO (no otros 15 días) — confirma que las dos lógicas (plazo inicial de 15 días vs. recorte recurrente semanal) conviven correctamente sin mezclarse.

**B3 — Mismo comportamiento en ambos roles**
1. Repite B1 desde `admin/cajero_nuevaVenta.php` (el espejo admin).
2. **Esperado:** exactamente el mismo cálculo de fecha_limite.

---

## Bloque C — Stock total entre sucursales (vista "Todas las sucursales")

Archivo: `admin/inventario_productos.php`, vista global (`sucursal=0` o sin parámetro).

**C1 — Suma correcta con desglose**
1. Elige un producto con stock en AMBAS sucursales (ej. 50 en Barrio de los Indios, 30 en Pinar).
2. Ve al catálogo en "Todas las sucursales".
3. **Esperado:** debe mostrar el total (80) y un desglose tipo "Barrio de los Indios: 50 · Pinar: 30".

**C2 — Una sucursal desactivada no cuenta en el total**
1. Con el mismo producto de C1, desactiva temporalmente una de las 2 sucursales.
2. Recarga el catálogo global.
3. **Esperado:** el total ya no debe incluir el stock de la sucursal desactivada (solo suma sucursales con `activo = 1`). Reactívala al terminar.

**C3 — Stock dado de baja en una sucursal específica no cuenta**
1. Con un producto activo en 2 sucursales, da de baja SOLO la relación producto-sucursal en una de ellas (no el producto completo, ni la sucursal — solo ese `stock_sucursal.activo = 0` para esa combinación).
2. Recarga el catálogo global.
3. **Esperado:** el total debe excluir esa sucursal específica para ese producto, aunque la sucursal en sí siga activa.

**C4 — No se duplican filas de producto**
1. Cuenta cuántas veces aparece un producto que tiene stock en 2 sucursales dentro del catálogo global.
2. **Esperado:** debe aparecer UNA sola vez (con el total sumado), no una fila por cada sucursal — confirma que la subconsulta no está multiplicando filas como haría un JOIN directo a `stock_sucursal`.

---

## Bloque D — El buscador de inventario ya no recarga la página sola

**D1 — Escribir sin presionar Enter**
1. En el buscador de `admin/inventario_productos.php`, escribe una letra y espera 2-3 segundos SIN presionar Enter ni hacer clic en nada.
2. **Esperado:** la tabla se filtra en vivo (vía JavaScript) pero la página NO se recarga completa ni el cursor pierde el foco del campo de búsqueda.

**D2 — Presionar Enter no envía el formulario**
1. En el mismo buscador, escribe algo y presiona Enter.
2. **Esperado:** no debe recargar la página ni perder lo escrito — el Enter debe quedar neutralizado.

---

## Bloque E — Datos bancarios de facturación (cuenta separada)

Archivo: `admin/formSucursal.php`. Permite guardar una segunda cuenta bancaria, distinta de la normal, usada solo cuando un cliente pide factura.

**E1 — Los datos de facturación no pisan los datos bancarios normales**
1. Edita una sucursal: pon un banco/cuenta normal (ej. "BBVA, cuenta 111") Y datos de facturación distintos (ej. "Banorte, cuenta 222"). Guarda.
2. **Esperado:** `SELECT banco, numero_cuenta, banco_fact, numero_cuenta_fact FROM sucursales WHERE sucursal_id = X;` debe mostrar los 4 valores correctos, cada par en su propia columna, sin que uno sobreescriba al otro.

**E2 — Dejar los datos de facturación vacíos no borra los normales**
1. Edita la misma sucursal, esta vez deja las columnas de facturación en blanco (sin tocar las normales), guarda.
2. **Esperado:** las columnas normales (`banco`, `numero_cuenta`, etc.) deben seguir intactas; las de facturación quedan NULL, sin afectar nada más.

---

## Notas
- Bloque A necesita el navegador real (localStorage es por pestaña/sesión del navegador) — no se puede probar solo con curl/fetch.
- Bloque C requiere datos reales con stock en ambas sucursales — usa productos de prueba si hace falta, limpia al terminar.
- Limpia todos los datos de prueba al terminar, como siempre.
