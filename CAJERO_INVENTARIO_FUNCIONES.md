# Módulo cajeroInventario — Inventario de funciones

> Generado el 2026-06-03 a partir de la **lectura real de cada archivo**.
> Rol autorizado: `Inventario/Cajero`
> Nota: algunos archivos también incluyen los roles `Administrador`, `Cajero` o `Inventario` — se indica en cada caso.

---

## Archivos y su `verificarRol()` real

| Archivo | Roles permitidos en el código |
|---------|-------------------------------|
| `inicioCajeroInventario.php` | `Inventario/Cajero` |
| `nuevaVenta.php` | `Administrador`, `Cajero`, `Inventario/Cajero` |
| `historialVentas.php` | `Administrador`, `Cajero`, `Inventario/Cajero` |
| `ventasPendientes.php` | `Administrador`, `Cajero`, `Inventario/Cajero` |
| `devoluciones.php` | `Administrador`, `Cajero`, `Inventario/Cajero` |
| `abrirCaja.php` | `Administrador`, `Cajero`, `Inventario/Cajero` |
| `corteCaja.php` | `Administrador`, `Cajero`, `Inventario/Cajero` |
| `historialCortes.php` | `Administrador`, `Cajero`, `Inventario/Cajero` |
| `clientes.php` | `Administrador`, `Cajero`, `Inventario/Cajero` |
| `creditos.php` | `Administrador`, `Cajero`, `Inventario/Cajero` |
| `abonos.php` | `Administrador`, `Cajero`, `Inventario/Cajero` |
| `productos.php` | `Administrador`, `Inventario`, `Inventario/Cajero` ← NO incluye `Cajero` |
| `formProducto.php` | `Administrador`, `Inventario`, `Inventario/Cajero` |
| `categorias.php` | `Administrador`, `Inventario`, `Inventario/Cajero` |
| `unidades.php` | `Administrador`, `Inventario`, `Inventario/Cajero` |
| `entradas.php` | `Administrador`, `Inventario`, `Inventario/Cajero` |
| `salidas.php` | `Administrador`, `Inventario`, `Inventario/Cajero` |
| `historial.php` | `Administrador`, `Inventario`, `Inventario/Cajero` |
| `proveedores.php` | `Administrador`, `Inventario`, `Inventario/Cajero` |
| `compras.php` | `Administrador`, `Inventario`, `Inventario/Cajero` |
| `transferencias.php` | `Administrador`, `Inventario`, `Inventario/Cajero` |
| `paquetes.php` | `Administrador`, `Inventario`, `Inventario/Cajero` |
| `promociones.php` | `Administrador`, `Inventario`, `Inventario/Cajero` |
| `masVendidos.php` | `Administrador`, `Inventario`, `Inventario/Cajero` |

---

## Detalle real de funciones por módulo

---

### `inicioCajeroInventario.php` — Panel de inicio

| Función | Lo que hace realmente |
|---------|-----------------------|
| Estado de caja | Detecta si hay caja abierta, muestra turno # y monto de apertura |
| Stats del turno | Ventas completadas, total cobrado, pendientes, clientes con deuda, productos con stock bajo |
| Alerta de stock bajo | Cuenta `stock_actual <= stock_minimo` en la sucursal del cajero |
| Alerta de créditos | Cuenta clientes con crédito Activo o Vencido **global (todas las sucursales)** — decisión 2026-07-31: la deuda se puede pagar en cualquier sucursal |
| Notificaciones de transferencias | 4 tipos: pendientes de aprobar, aprobadas a enviar, en tránsito para recibir, modificadas para confirmar |
| Accesos rápidos | Links a: Nueva venta, Entradas, Clientes, Inventario, Créditos, Transferencias |
| Últimas ventas del turno | Las últimas 5 ventas de la caja actual |

---

### `nuevaVenta.php` — Nueva venta

| Función | Lo que hace realmente |
|---------|-----------------------|
| Carrito de compras | Agregar/quitar productos, editar cantidades |
| Búsqueda de producto | Por nombre o código (filtra del catálogo de la sucursal) |
| Búsqueda por código de barras | Escaneo con lector, agrega automáticamente |
| Búsqueda y venta de paquetes | Combos de productos con precio especial |
| Carga inicial del catálogo | Al abrir, carga todos los productos de la sucursal para búsqueda local en JS |
| Selección de cliente | Búsqueda local, cliente público general si no hay |
| Descuento por cliente | Aplica `descuento_fijo` del cliente |
| Métodos de pago | Efectivo, Terminal, Mixto (efectivo + terminal), Crédito |
| Validación de límite de crédito | Verifica deuda actual vs límite autorizado antes de permitir venta a crédito |
| Comisión por terminal | Campo para capturar el monto de comisión cobrado |
| Cálculo de cambio | En pagos con efectivo |
| Promociones activas | Aplica precio promocional si hay vigente para ese producto/sucursal |
| Precio mayoreo | Aplica según `tipo_venta` del producto |
| Ajuste por daño | Nota especial que registra precio reducido por daño en el ítem |
| Modal inventario multi-sucursal | Consulta stock en cualquier sucursal (solo lectura; no permite vender desde otra) |
| Retiro / Ingreso de caja | Registra movimientos manuales de efectivo en el turno actual |
| Ticket de venta | Genera e imprime ticket con datos de sucursal, productos, totales |
| Reimpresión de ticket | Desde el mismo modal de nueva venta |
| Validación de stock antes de vender | Bloquea si no hay suficiente stock |
| Bloqueo de condición de carrera | `SELECT ... FOR UPDATE` al descontar stock |
| Folio secuencial mensual | Genera folio `NNNN` con mutex de BD para evitar duplicados |
| Registro en `movimientos_inventario` | Registra salida de cada producto al completar la venta |
| Registro de crédito en `creditos` | Crea el registro si el método de pago es Crédito |

---

### `historialVentas.php` — Historial de ventas

| Función | Lo que hace realmente |
|---------|-----------------------|
| Listado de ventas | Todas las ventas de la sucursal (las 150 más recientes sin filtro, hasta 1000 con filtro de fecha) |
| Filtros | Rango de fechas, método de pago, estado, folio/cliente |
| Stats del período | Total ventas, total cobrado, desglose por método (Efectivo, Terminal, Crédito, Mixto, Transferencia, Canceladas) |
| Detalle de venta en modal | Cajero, cliente, método, productos, cantidades devueltas, totales originales, historial de devoluciones |
| Reimpresión de ticket | Desde la lista o desde el modal de detalle |
| Movimientos de caja del período | 3 secciones separadas: abonos de crédito, devoluciones, retiros/ingresos manuales |
| Totales de movimientos | Desglose de abonos por método (Efectivo, Terminal, Transferencia), salidas/entradas por devoluciones |

---

### `ventasPendientes.php` — Ventas pendientes (domicilio)

| Función | Lo que hace realmente |
|---------|-----------------------|
| Crear venta pendiente | Mismo flujo que nueva venta pero sin descontar stock al crear |
| Métodos permitidos | Solo Efectivo, Transferencia y Crédito (no Terminal ni Mixto) |
| Validación de stock comprometido | Calcula stock disponible = actual − comprometido en otras pendientes activas |
| Liquidar venta | Marca como Completada y **descuenta stock en ese momento** |
| Validación de crédito al liquidar | Crea registro en `creditos` si el método es Crédito |
| Cancelar venta pendiente | Cambia estado a Cancelada (stock nunca fue descontado, no hay que revertirlo) |
| Búsqueda en lista de pendientes | Filtro en tiempo real por cliente, notas o producto |
| Ticket de venta | Pre-visualización e impresión del ticket |
| Auto-abrir ticket al crear | Abre el modal de ticket automáticamente tras crear la venta |
| Paquetes en venta pendiente | El formulario también permite agregar paquetes |
| Descuento por cliente | Aplica descuento_fijo si se selecciona cliente |

---

### `devoluciones.php` — Devoluciones

| Función | Lo que hace realmente |
|---------|-----------------------|
| Buscar venta por folio | Por número de folio + mes + año (solo ventas de la sucursal del cajero) |
| Ver productos devolvibles | Muestra cantidad original y restante por devolver |
| Devolución parcial | Selección de cantidades menores a lo comprado |
| Devolución de paquetes | Devuelve el combo completo (todos sus productos) cuando aplica |
| Cálculo del monto a devolver | En tiempo real según las cantidades seleccionadas, aplica factor de descuento del cliente |
| Comisión de terminal | Muestra advertencia de que la comisión (Terminal/Mixto) no se reembolsa |
| Reembolso en efectivo | Siempre en efectivo, excepto Crédito (descuenta del saldo) |
| Registro en `devoluciones` | Guarda el grupo de devolución para poder cancelarla después |
| Reintegro de stock | Incrementa `stock_actual` en `stock_sucursal` |
| Actualización de `ventas` | Reduce subtotal, descuento, comisión y total proporcionalmente |
| Registro en `movimientos_inventario` | Entrada por devolución con `devolucion_id` |
| Registro en `movimientos_caja` | Retiro de caja solo para Terminal/Mixto/Transferencia |
| Actualización de crédito | Si era venta a crédito, reduce el saldo pendiente |
| Cancelar devolución | Solo dentro de las **24 horas** siguientes a la devolución |
| Al cancelar: revertir stock | Descuenta de nuevo lo que se había reingresado |
| Historial de devoluciones | Últimas 30 devoluciones de la sucursal (activas y canceladas) |
| ⚠ Sin límite de días para devolver | No hay validación de cuántos días han pasado desde la venta original |

---

### `abrirCaja.php` — Abrir caja

| Función | Lo que hace realmente |
|---------|-----------------------|
| Cerrar cajas huérfanas automáticamente | Al entrar, cierra cajas del usuario de días anteriores que quedaron abiertas |
| Abrir turno | Registra apertura con monto inicial (mínimo $0.01, obligatorio) |
| Asignar número de turno | Cuenta cajas abiertas de otros usuarios en la sucursal + 1 |
| Aviso de cajas activas | Muestra cuántas cajas hay abiertas actualmente en la sucursal |
| Redirigir si ya tiene caja | Muestra la caja actual y enlace directo a Nueva venta |

---

### `corteCaja.php` — Corte de caja

| Función | Lo que hace realmente |
|---------|-----------------------|
| Resumen del turno | Ventas completadas, total cobrado, desglose por método (Efectivo, Terminal, Mixto partes, Transferencia, Crédito) |
| Movimientos de caja | Lista todos los retiros e ingresos del turno con sus notas |
| Cálculo de efectivo esperado | Apertura + ventas en efectivo + ingresos en efectivo − retiros en efectivo (devoluciones de terminal/transferencia NO restan del físico) |
| Aviso de pendientes | Si hay ventas pendientes sin liquidar, las muestra con enlace |
| Captura de monto contado | El cajero escribe cuánto dinero hay físicamente |
| Preview de diferencia | Muestra en tiempo real si hay faltante, sobrante o cuadra |
| Cerrar caja | Registra `monto_cierre`, `monto_esperado`, `diferencia` y `cerrada_en` |

---

### `historialCortes.php` — Historial de cortes

| Función | Lo que hace realmente |
|---------|-----------------------|
| Listado de cortes propios | Solo los turnos del usuario actual |
| Stats globales | Total de turnos, total de ventas, total cobrado (acumulado de todos los turnos) |
| Filtro por fecha | Filtra turnos de un día específico |
| Detalle del turno seleccionado | Info de apertura/cierre, resultado del corte (esperado vs contado vs diferencia), lista de ventas del turno |
| Observaciones del corte | Muestra las notas que el cajero escribió al cerrar |

---

### `clientes.php` — Clientes

| Función | Lo que hace realmente |
|---------|-----------------------|
| Listado de clientes activos | Con búsqueda por nombre o teléfono |
| Alta de cliente | Nombre, teléfono, dirección, correo, descuento_fijo, crédito autorizado y límite |
| Edición de cliente | Cualquier campo |
| Desactivar cliente | Baja lógica (`activo = 0`) |
| Reactivar cliente | Toggle activo/inactivo |
| Protecciones al desactivar | No permite si tiene saldo pendiente, créditos activos o ventas pendientes |

---

### `creditos.php` — Créditos

| Función | Lo que hace realmente |
|---------|-----------------------|
| Actualizar vencidos | Al cargar, marca como Vencido los créditos con `fecha_limite < hoy` |
| Listado de créditos | Créditos activos y vencidos **globales (todas las sucursales)** — el cliente paga en cualquier sucursal (decisión 2026-07-31) |
| Pago distribuido automáticamente | Registra un pago y lo distribuye desde el crédito más antiguo al más reciente |
| Métodos de pago | Efectivo, Terminal, Transferencia, Mixto |
| Comisión de terminal | Calcula y registra comisión según el porcentaje configurado en la sucursal |
| Historial de abonos por cliente | AJAX que retorna todos los pagos de todos los créditos del cliente |
| Mostrar datos bancarios | Para orientar al cliente en pagos por transferencia |
| Registro en `movimientos_caja` | Ingreso en caja por cada pago (con sufijo `[Efectivo]`, `[Terminal]`, `[Transferencia]`) |
| Requiere caja abierta | Si no hay caja, redirige a `abrirCaja.php` |
| Pago adelantado | Opción para extender `fecha_limite` al registrar el pago |

---

### `abonos.php` — Abonos ⚠️ (archivo posiblemente obsoleto)

| Función | Lo que hace realmente |
|---------|-----------------------|
| Abono a un crédito específico | Recibe `credito_id` por GET, registra el abono solo a ese crédito |
| Versión más simple | No distribuye entre varios créditos, no muestra historial del cliente |
| **No aparece en el menú lateral** | Existe en disco pero no está enlazado desde el sidebar |

> Esta funcionalidad parece haber sido reemplazada por `creditos.php`, que es más completo. Verificar si puede eliminarse.

---

### `productos.php` — Productos / Inventario

> El cajero/inventario **NO crea ni edita** productos. El catálogo es global y lo gestiona el administrador.

| Función | Lo que hace realmente |
|---------|-----------------------|
| Listado de productos | Productos activos con su stock en la sucursal del cajero |
| Búsqueda por nombre o código | En tiempo real |
| Filtro por categoría | Desplegable de categorías |
| Filtro de stock bajo | Ver solo los que tienen `stock_actual <= stock_minimo` |
| Exportar a PDF | Genera PDF con código, nombre, categoría, precios, stock, mínimo, tipo, unidad |
| Exportar a Excel | Genera `.xlsx` con los mismos datos más stock máximo y descripción |
| Link a editar producto | Lleva a `formProducto.php` para editar datos del producto |

---

### `formProducto.php` — Editar producto (no crear)

> El propio código tiene el comentario: *"En cajeroInventario no se pueden crear productos nuevos al catálogo global. Solo el administrador puede crear productos; aquí solo se editan los existentes."*

| Función | Lo que hace realmente |
|---------|-----------------------|
| Editar producto existente | Modifica campos del producto (nombre, precios, categoría, unidad, tipo de venta, stock mínimo) |
| **No permite crear** | Si se accede sin `?id=`, redirige o bloquea |

> ⚠️ Aunque el código previene crear productos nuevos, el rol `Inventario/Cajero` SÍ puede **editar precios de venta, precio mayoreo y otros datos del catálogo global** desde esta pantalla. Revisar si eso es correcto según el planteamiento.

---

### `categorias.php` — Categorías ✅

| Función | Lo que hace realmente |
|---------|-----------------------|
| Listado de categorías | Todas las categorías activas (visible para Inventario/Cajero) |
| **Alta de categoría** | Solo `Administrador` — ver nota |
| **Edición de categoría** | Solo `Administrador` — ver nota |
| **Desactivar/Reactivar** | Solo `Administrador`. Con protección: no elimina si tiene productos activos |

> ✅ **Resuelto (2026-08-02):** el cajero/inventario **ya no puede** crear, editar ni eliminar categorías — se agregó verificación de rol (`$_SESSION['rol'] === 'Administrador'`) antes de cada acción de escritura. El listado sigue visible para consulta. Ver auditoría al final de este documento (hallazgo C5).

---

### `unidades.php` — Unidades de medida ✅

| Función | Lo que hace realmente |
|---------|-----------------------|
| Listado de unidades | Pieza, metro, kg, litro, etc. (visible para Inventario/Cajero) |
| **Alta de unidad** | Solo `Administrador` — ver nota |
| **Edición de unidad** | Solo `Administrador` — ver nota |
| **Desactivar/Reactivar** | Baja lógica, solo `Administrador` |

> ✅ **Resuelto (2026-08-02):** mismo caso que categorías — el cajero/inventario ya no puede crear, editar ni eliminar unidades de medida. Ver auditoría al final de este documento (hallazgo C5).

---

### `entradas.php` — Entradas de inventario

| Función | Lo que hace realmente |
|---------|-----------------------|
| Registrar entrada de mercancía | Seleccionar producto, cantidad, motivo, proveedor opcional |
| Producto preseleccionable | Acepta `?producto_id=` en la URL (usado desde productos.php) |
| Actualizar stock | Suma la cantidad al `stock_actual` de la sucursal |
| Registro en `movimientos_inventario` | Entrada con proveedor_id si se indicó |
| Historial de entradas | Últimas 25 entradas de la sucursal (con nombre de proveedor si aplica) |

---

### `salidas.php` — Salidas y mermas

| Función | Lo que hace realmente |
|---------|-----------------------|
| Registrar salida manual | Producto, cantidad y motivo (obligatorio) |
| Validación de stock | No permite sacar más de lo que hay en `stock_actual` |
| Alerta de stock bajo | Si al restar queda por debajo del mínimo, redirige con mensaje de advertencia |
| Actualizar stock | Resta del `stock_actual` de la sucursal |
| Registro en `movimientos_inventario` | Salida con motivo descriptivo |
| Historial de salidas | Últimas 30 salidas de la sucursal |

---

### `historial.php` — Movimientos de inventario

| Función | Lo que hace realmente |
|---------|-----------------------|
| Listado de movimientos | Todos los tipos: Entrada, Salida, Ajuste, Transferencia de la sucursal |
| Filtros | Por fecha (default: hoy), tipo de movimiento, nombre de producto |
| Resumen del día | Total entradas, salidas, ajustes y transferencias del filtro aplicado |
| Datos por movimiento | Producto, cantidad, stock anterior, stock nuevo, motivo, usuario, fecha |
| Límite | Hasta 200 movimientos por consulta |

---

### `proveedores.php` — Proveedores

| Función | Lo que hace realmente |
|---------|-----------------------|
| Listado de proveedores | Activos e inactivos |
| Alta de proveedor | Nombre (único), teléfono, correo, dirección, categorías del proveedor |
| Edición de proveedor | Cualquier campo |
| Desactivar / Reactivar | Toggle activo |
| Asignar categorías al proveedor | Relación proveedor ↔ categorías de productos que maneja |
| Validación de nombre duplicado | No permite dos proveedores activos con el mismo nombre |

---

### `compras.php` — Compras a proveedor

| Función | Lo que hace realmente |
|---------|-----------------------|
| Registrar orden de compra | Seleccionar proveedor, agregar productos con cantidad y precio unitario |
| Actualiza stock automáticamente | Al registrar la compra, suma la cantidad al `stock_actual` de la sucursal |
| Ver detalle de compra | Muestra proveedor, sucursal, usuario, productos y totales de una compra existente |
| Historial de compras | Lista de compras de la sucursal |

> ⚠️ La compra no tiene estado Pendiente/Recibida — al registrarla, el stock se actualiza de inmediato.

---

### `transferencias.php` — Transferencias entre sucursales

| Función | Lo que hace realmente |
|---------|-----------------------|
| Solicitar transferencia | Pedir un producto a otra sucursal (destino solicita al origen) |
| Aprobar solicitud | La sucursal origen aprueba (o modifica la cantidad) |
| Modificar cantidad al aprobar | Origen puede reducir la cantidad si no tiene suficiente |
| Confirmar modificación | Destino acepta o rechaza la cantidad modificada |
| Marcar como enviada | Origen descuenta el stock y marca "En tránsito" |
| Confirmar recepción | Destino suma el stock y marca "Recibida" |
| Ver historial | Lista de transferencias propias (origen o destino) con todos los estados |
| Estados posibles | Pendiente → Aprobada → En tránsito → Recibida / Modificada / Rechazada |

---

### `paquetes.php` — Paquetes

| Función | Lo que hace realmente |
|---------|-----------------------|
| Listado de paquetes globales | Solo lectura. Muestra nombre, código, precio, productos que incluye |
| **No crea ni edita paquetes** | Solo muestra la lista del catálogo global |

---

### `promociones.php` — Promociones

| Función | Lo que hace realmente |
|---------|-----------------------|
| Listado de promociones de la sucursal | Activas, próximas y vencidas recientes (máx 100) |
| **Solo lectura** | No crea ni edita promociones desde aquí |
| Ordenadas por estado | Primero activas, luego próximas, luego vencidas |

---

### `masVendidos.php` — Más vendidos

| Función | Lo que hace realmente |
|---------|-----------------------|
| Reporte de productos más vendidos | Ordenado por cantidad total vendida |
| Filtros de período | Hoy, última semana, último mes |
| Selector de sucursal | Permite ver el reporte de **cualquier sucursal** (no solo la propia) |
| Límite de resultados | Configurable (default: 20 productos) |

> ✅ **Confirmado por el dueño del proyecto (2026-08-02):** el selector de sucursal en `masVendidos.php` es **intencional** — las sucursales trabajan coordinadas y siempre van a poder ver la información entre sí. No se modifica.

---

## Tabla resumen: ¿Qué puede hacer el Inventario/Cajero?

| Área | Puede | No puede |
|------|-------|----------|
| **Ventas** | Nueva venta, historial, pendientes (domicilio), devoluciones | — |
| **Caja** | Abrir, cerrar, historial de cortes, retiros/ingresos | — |
| **Clientes** | Alta, edición, desactivar | — |
| **Créditos** | Ver créditos de todas las sucursales, registrar pagos (se abonan FIFO al crédito más antiguo del cliente), historial de abonos | — |
| **Productos** | Ver catálogo, exportar PDF/Excel, editar producto | Crear producto nuevo |
| **Stock** | Entradas manuales, salidas/mermas | — |
| **Movimientos** | Ver historial de la sucursal | — |
| **Categorías** | Solo consulta (✅ resuelto 2026-08-02) | Crear / editar / eliminar — solo Administrador |
| **Unidades** | Solo consulta (✅ resuelto 2026-08-02) | Crear / editar / eliminar — solo Administrador |
| **Proveedores** | Alta, edición, desactivar | — |
| **Compras** | Registrar compra (actualiza stock inmediatamente) | — |
| **Transferencias** | Flujo completo entre sucursales | — |
| **Paquetes** | Solo lectura | Crear / editar |
| **Promociones** | Solo lectura | Crear / editar |
| **Más vendidos** | Ver reporte de cualquier sucursal (por diseño, confirmado) | — |

---

## Inconsistencias confirmadas por el código

| # | Archivo | Situación |
|---|---------|-----------|
| 1 | `abonos.php` | No está en el menú pero existe. Confirmado obsoleto/huérfano; `creditos.php` cubre todo lo que hace. No se elimina por ahora (pendiente del dueño del proyecto) |
| 2 | `categorias.php` | ✅ **Resuelto 2026-08-02** — ya no puede crear/editar/eliminar categorías, solo Administrador |
| 3 | `unidades.php` | ✅ **Resuelto 2026-08-02** — ya no puede crear/editar/eliminar unidades, solo Administrador |
| 4 | `formProducto.php` | Puede editar precios de venta/mayoreo del catálogo global, incluso de productos que no son de su sucursal (IDOR — auditoría hallazgo C3). **Decisión del dueño (2026-08-02): se deja así**, el catálogo es compartido entre sucursales por diseño. También permite crear productos nuevos pese al bloqueo aparente en pantalla (hallazgo C4) — **decisión: bajo riesgo, se deja así** |
| 5 | `masVendidos.php` | Selector de sucursal muestra reporte de cualquier sucursal. **Confirmado por el dueño (2026-08-02): es intencional**, las sucursales trabajan coordinadas |
| 6 | `devoluciones.php` | ✅ Corregido en una revisión anterior a esta auditoría — ya existe límite de 7 días para iniciar una devolución (política vigente: reembolso siempre en efectivo) |
| 7 | `ventasPendientes.php` | Sigue pendiente — inconsistencia `'Crédito'` vs `'Credito'` en BD (con/sin acento) |
| 8 | `compras.php` | No tiene estado Pendiente/Recibida; el stock se actualiza al registrar, no al recibir |

---

## Auditoría de seguridad y QA — 2026-08-02

Auditoría exhaustiva del rol `Inventario/Cajero` (lectura completa del código, esquema real de BD y datos vivos). Reporte completo con reproducción, causa raíz y solución de cada hallazgo, publicado como artefacto en la conversación de esa fecha. Resumen de lo corregido y lo pendiente:

### Corregido en esta sesión

| Hallazgo | Descripción | Archivo(s) |
|----------|-------------|------------|
| C1 (Crítica) | XSS almacenado: un nombre de cliente con comillas rompía el atributo `onclick` y ejecutaba JS arbitrario al abrir un crédito o seleccionar el cliente en una venta | `creditos.php`, `nuevaVenta.php` |
| C5 (Crítica) | Categorías y unidades de medida sin restricción de rol para crear/editar/eliminar | `categorias.php`, `unidades.php` |
| C6 (Crítica) | XSS almacenado en el modal "Agregar del catálogo" (nombre/código de producto y categoría sin escapar correctamente) | `productos.php` |
| C7 (Crítica) | XSS almacenado en el selector de "Áreas que abastece" al dar de alta un proveedor (sin ningún escape) | `proveedores.php` |
| C8 (Crítica) | Recibir una transferencia dos veces (doble clic) duplicaba el stock del destino | `transferencias.php` |
| C9 (Crítica) | Cajas cerradas automáticamente (sesión abandonada) se mostraban como "Cuadrada" con $0.00 sin verificación real | `historialCortes.php` |
| A1 (Alta) | CSRF ausente en: confirmar venta, abrir/cerrar caja, procesar devolución, registrar compra/entrada/salida, y las 7 acciones de transferencias (aprobar/rechazar/enviar/recibir/editar cantidad/aceptar-rechazar modificación) | `nuevaVenta.php`, `corteCaja.php`, `abrirCaja.php`, `devoluciones.php`, `compras.php`, `entradas.php`, `salidas.php`, `transferencias.php` |
| A2 (Alta) | Enviar una transferencia dos veces (doble clic) duplicaba el descuento de stock en origen | `transferencias.php` |
| A5 (Alta) | Pérdida de actualización (sin bloqueo de fila) en entradas, salidas y abono de crédito — dos operaciones simultáneas del mismo producto/cliente podían pisarse entre sí | `entradas.php`, `salidas.php`, `creditos.php` |
| M1 (Media) | Cierre de caja sin candado contra doble envío — podía sobrescribir el corte con otro monto | `corteCaja.php` |
| M2 (Media) | Detalle de compra (`?ver=`) sin filtro de sucursal — se podía ver el detalle de una compra de otra sucursal adivinando el ID | `compras.php` |

### Recalificado tras verificar contra el código real

| Hallazgo | Nota |
|----------|------|
| C2 | El panel de "Importar Excel" en `productos.php` no tiene ningún botón que lo muestre en pantalla (`toggleImport()` nunca se llama) — recalificado de Crítica a Baja. El handler de backend sigue sin protección de rol propia, pero no es alcanzable desde la interfaz actual |

### Pendiente por decisión del dueño del proyecto (no se modifica)

| Hallazgo | Decisión |
|----------|----------|
| C3 — `formProducto.php` permite editar cualquier producto del catálogo (IDOR, no valida que sea de la sucursal propia) | Se deja así: el catálogo es compartido entre sucursales por diseño |
| C4 — `formProducto.php` permite crear productos nuevos pese al bloqueo aparente | Se deja así: riesgo bajo |
| A4 — `masVendidos.php` permite ver el reporte de cualquier sucursal | Se deja así: es intencional, las sucursales trabajan coordinadas |
| A6 — Credencial de la base de datos de producción en texto plano en `config/database.php` (comentada, pero presente) | Pendiente — el dueño del proyecto la rotará y limpiará una vez terminados los demás pendientes |

### Sigue abierto — no auditado a fondo en esta pasada (Medios/Bajos del reporte completo)

KPIs de `historialVentas.php`/`historial.php` que no coinciden con la tabla mostrada cuando no hay filtro de fecha, falta de validación de nombre duplicado en categorías/unidades (antes de que fueran solo-Administrador), validación incompleta de negativos en productos tipo "Suelto", mensajes de error técnicos expuestos en la importación de Excel, y la inconsistencia `'Crédito'`/`'Credito'` de la fila 7 de arriba. Detalle completo en el reporte de auditoría de esa fecha.

### Cierre de esta auditoría (2026-08-02)

Con M1 y M2 corregidos, quedan atendidos todos los hallazgos Críticos y Altos del rol Inventario/Cajero, salvo A6 (credencial de BD — pendiente de que el dueño la rote, ahora que sigue con el rol Administrador) y las decisiones explícitas de dejar C3/C4/A4 tal cual. Los pendientes Medios/Bajos que quedaron sin tocar están listados arriba y en `PENDIENTES.md`.

---

## Auditoría Adversarial Final — 2026-08-02 (segunda pasada)

Segunda auditoría, independiente y adversarial: ignoró deliberadamente las correcciones de la auditoría anterior, reconcilió ~4 meses de datos reales de la base de datos (folios, stock, cajas, créditos) y reprodujo condiciones de carrera con peticiones HTTP simultáneas reales. Reporte completo publicado como artefacto en la conversación de esa fecha. Confirmó sano el núcleo financiero/inventario (0 folios duplicados, 0 stock negativo, 0 cajas duplicadas, 39/40 créditos reconcilian exactos) y encontró 4 hallazgos nuevos que ninguna auditoría anterior había cerrado — los 4 ya corregidos y verificados en vivo:

| Hallazgo | Descripción | Archivo(s) | Verificación |
|----------|-------------|------------|--------------|
| NC1 (Crítica) | La mora automática de créditos podía aplicarse más de una vez por condición de carrera (mismo patrón que A5, pero en un bloque que nunca se tocó) | `creditos.php` | 5 peticiones simultáneas reales sobre un crédito recién vencido → mora aplicada exactamente 1 vez. Reverificado: abono, venta a crédito y stock siguen intactos |
| NC2 (Crítica) | "Liquidar" y "Cancelar" una venta pendiente podían chocar entre sí (son botones/acciones distintas, ninguna protección de doble-clic existente los cubría) y dejar stock descontado bajo una venta marcada como Cancelada | `ventasPendientes.php` | 5 rondas de `liquidar`+`cancelar` disparados verdaderamente simultáneos sobre la misma venta → resultado siempre consistente (Cancelada conserva stock, Completada lo descuenta) |
| NC3 (Crítica) | CSRF seguía ausente en la edición del catálogo global — documentado desde la primera auditoría pero nunca corregido (a diferencia de proveedores/categorías/unidades, sin ningún candado de rol que lo mitigara) | `formProducto.php` | Ataque simulado (sin token y con token forjado) bloqueado, producto sin cambios; edición legítima con token válido sigue funcionando |
| NA1 (Alta) | CSRF ausente en "eliminar producto" y "agregar del catálogo" (alcance limitado a la sucursal propia, no cruza sucursales) | `productos.php` | Mismo patrón de prueba que NC3: ataque bloqueado en ambas acciones, operación legítima con token válido sigue funcionando |

Observaciones operativas nuevas (no son bugs de código, quedaron sin tocar — ver `PENDIENTES.md`): un cliente inactivo con deuda que los listados no muestran por defecto (NM1), y una transferencia pendiente de aprobar desde hace 77 días sin recordatorio (NM2).

### Cierre de la auditoría adversarial (2026-08-02)

Con NC1, NC2, NC3 y NA1 corregidos y verificados en vivo, no queda ningún hallazgo Crítico ni Alto abierto de esta segunda auditoría. Siguen en pie las mismas decisiones explícitas del dueño del proyecto (C3/C4/A4 se dejan así, A6 pendiente de que el dueño rote la credencial) y las observaciones operativas NM1/NM2. El dueño del proyecto continúa con el rol Administrador.
