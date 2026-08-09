# Script de Pruebas — Rol cajeroInventario
**Sistema:** Ferretería Aldrete  
**Usuario cajero:** inventarioCajero / 1234  
**Usuario admin:** admin / 1234  
**Fecha:** 2026-05-22  

> ⚠️ **Nota (2026-08-02):** la contraseña `1234` para `inventarioCajero` ya no es válida contra la base de datos local actual (se comprobó al verificar la auditoría de seguridad de esa fecha) — probablemente se cambió en algún momento después de escribirse este script. El usuario `inventarioCajero` (rol Inventario/Cajero, sucursal 1) sigue existiendo y activo; solo hace falta actualizar o restablecer su contraseña para volver a usar este script tal cual.

> ℹ️ **Nota (2026-08-02):** además de este checklist manual, el rol pasó por dos auditorías automatizadas de seguridad/QA (revisión de código + pruebas HTTP en vivo contra el sistema real, incluyendo condiciones de carrera con peticiones simultáneas genuinas). Todos los hallazgos Críticos y Altos de ambas quedaron corregidos y verificados — detalle completo en `CAJERO_INVENTARIO_FUNCIONES.md` y la tabla de `CONTEXTO_SISTEMA.md` (§12, SEC-01 a SEC-25). Esto cubre en buena parte el Módulo 7 (Seguridad General) de abajo — en particular SEC01/SEC04/SEC05 de este documento y V23 (recalculo de precio en servidor) ya están confirmados como resueltos.

> **Cómo usar este script:**  
> Ejecuta cada caso en orden. Anota en la columna "Resultado real" lo que ocurrió.  
> ✅ = Pasó como se esperaba | ❌ = Falló | ⚠️ = Comportamiento extraño

---

## PREPARACIÓN PREVIA (hacer antes de empezar)
Antes de iniciar las pruebas, verificar que existan estos datos en el sistema:
- Al menos 2 sucursales registradas
- Al menos 5 productos con stock > 0
- Al menos 1 producto tipo "suelto" (granel)
- Al menos 1 cliente registrado
- Al menos 1 proveedor activo
- Al menos 1 categoría y 1 unidad de medida
- Caja cerrada (para probar apertura)

---

## MÓDULO 1 — CAJA

### 1.1 Abrir Caja
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| C01 | Abrir caja normal | Monto inicial: $500 | Caja abierta, redirige al inicio | | |
| C02 | Abrir caja con monto $0 | Monto inicial: 0 | ¿Acepta o pide monto mayor? Debería aceptar (puede iniciar con $0) | | |
| C03 | Abrir caja con monto negativo | Monto inicial: -100 | Debe rechazar el valor negativo | | |
| C04 | Abrir caja ya abierta | Intentar abrir cuando ya está abierta | Debe bloquear y avisar que ya está abierta | | |
| C05 | Abrir caja con monto decimal | Monto inicial: 500.75 | ¿Acepta decimales en el monto inicial? | | |
| C06 | Abrir caja con monto muy alto | Monto inicial: 9999999 | ¿Acepta sin error? | | |
| C07 | Dejar monto vacío | No escribir nada y enviar | Debe pedir que llenes el campo | | |
| C08 | Inyección en monto | Monto: `'; DROP TABLE cajas;--` | No debe ejecutar SQL, debe rechazar | | |

### 1.2 Corte de Caja
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| C09 | Corte normal con ventas del día | Hacer al menos 1 venta primero, luego cortar | Muestra resumen correcto, cierra caja | | |
| C10 | Corte sin ninguna venta del día | Hacer corte sin haber vendido nada | Debe funcionar mostrando $0 en ventas | | |
| C11 | Corte con caja no abierta | Intentar corte si la caja está cerrada | Debe bloquear y avisar | | |
| C12 | Verificar que el total cuadra | Sumar manualmente ventas del día vs lo que muestra el corte | Los montos deben coincidir exactamente | | |
| C13 | Corte con ventas canceladas | Cancelar una venta y hacer corte | Las ventas canceladas no deben contarse en el total | | |
| C14 | Corte con ventas a crédito | Hacer una venta a crédito y cortar | ¿Cómo se refleja en el corte? ¿Aparece separado? | | |
| C15 | Segundo corte mismo día | Intentar hacer otro corte después de cerrar | Debe bloquear o abrir nueva caja primero | | |

### 1.3 Historial de Cortes
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| C16 | Ver historial con cortes registrados | Abrir sección | Lista de cortes anteriores | | |
| C17 | Filtrar por fecha sin resultados | Filtrar por fecha en la que no hay cortes | Mensaje "sin resultados" o lista vacía | | |
| C18 | Ver detalle de un corte | Click en un corte del historial | Muestra desglose del corte seleccionado | | |

---

## MÓDULO 2 — VENTAS

### 2.1 Nueva Venta
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| V01 | Venta simple efectivo | 1 producto, pago en efectivo, monto exacto | Venta registrada, ticket generado | | |
| V02 | Venta con cambio | Producto $150, cliente paga $200 | Muestra cambio de $50 | | |
| V03 | Venta con pago menor al total | Producto $200, cliente paga $100 | Debe rechazar o pedir más monto | | |
| V04 | Venta con terminal | 1 producto, método: terminal | Aplica comisión 4.6% correctamente | | |
| V05 | Comisión terminal calculada | Producto $1000, terminal | Total con comisión = $1046 | | |
| V06 | Venta mixta (efectivo + terminal) | Producto $500: $200 efectivo + $300 terminal | Acepta pago mixto correctamente | | |
| V07 | Venta sin caja abierta | Intentar vender sin abrir caja | Debe bloquear y mandar a abrir caja | | |
| V08 | Venta con carrito vacío | Intentar procesar sin agregar productos | Debe pedir que agregues productos | | |
| V09 | Agregar producto con stock = 0 | Buscar producto agotado y agregarlo | Debe avisar que no hay stock | | |
| V10 | Cantidad mayor al stock | Producto con 5 en stock, pedir 10 | Debe rechazar o avisar stock insuficiente | | |
| V11 | Cantidad = 0 en carrito | Intentar agregar producto con cantidad 0 | Debe rechazar | | |
| V12 | Cantidad negativa en carrito | Intentar poner -1 en cantidad | Debe rechazar | | |
| V13 | Producto tipo suelto con decimales | Agregar 1.5 kg de producto suelto | Debe aceptar y calcular bien | | |
| V14 | Eliminar producto del carrito | Agregar 2 productos, eliminar uno | Solo queda el producto no eliminado | | |
| V15 | Venta con cliente registrado | Seleccionar cliente con descuento del 10% | Aplica descuento automáticamente | | |
| V16 | Venta con cliente con descuento 0% | Cliente sin descuento | No aplica ningún descuento | | |
| V17 | Venta con cliente al límite de crédito | Cliente con $0 disponible de crédito | Si intenta pagar a crédito, debe rechazar | | |
| V18 | Venta a crédito | Cliente con crédito disponible, pago: crédito | Registra venta, descuenta del límite de crédito del cliente | | |
| V19 | Venta con promoción activa | Producto con promoción vigente | Aplica el precio promocional | | |
| V20 | Venta de paquete | Agregar paquete al carrito | Descuenta stock de todos sus componentes | | |
| V21 | Venta de paquete con componente sin stock | Paquete cuyos componentes no tienen stock | Debe rechazar la venta | | |
| V22 | Cancelar venta antes de procesar | Click en cancelar/limpiar carrito | Limpia el carrito, no registra nada | | |
| V23 | **SEGURIDAD** Modificar precio en consola del navegador | F12 → buscar variable de precio → cambiar a $1 → procesar | El servidor debe recalcular el total real (CRÍTICO) | | |
| V24 | Doble click en "Procesar venta" | Click rápido dos veces en el botón | No debe registrar la venta dos veces | | |
| V25 | Venta con descuento manual del 100% | Si hay campo de descuento, poner 100% | ¿Permite venta en $0? | | |
| V26 | Venta con descuento mayor al 100% | Si hay campo de descuento, poner 150% | Debe rechazar | | |
| V27 | Ticket generado correcto | Revisar ticket después de venta | Folio, productos, total, método de pago correctos | | |
| V28 | Reimprimir ticket | Ir a historial y reimprimir | Mismo ticket con mismos datos | | |

### 2.2 Historial de Ventas
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| V29 | Ver historial del día | Abrir sección | Lista de ventas de hoy | | |
| V30 | Buscar folio existente | Folio de una venta recién hecha | Muestra la venta correcta | | |
| V31 | Buscar folio inexistente | Folio: 99999999 | Mensaje "no encontrado" o lista vacía | | |
| V32 | Filtrar por fecha sin ventas | Fecha en la que no hay ventas | Lista vacía | | |
| V33 | **BUG CONOCIDO** Ver venta con devolución parcial | Hacer venta de 2 productos, devolver 1, buscar folio | ¿Sigue mostrando 2 o actualiza a 1? (Bug: sigue en 2) | | |
| V34 | **BUG CONOCIDO** Total tras devolución | Venta de $1000, devolver $500, ver historial | ¿El total se actualiza a $500? (Bug: no actualiza) | | |
| V35 | Ver detalle de venta cancelada | Cancelar una venta, buscarla en historial | ¿Aparece marcada como cancelada? | | |
| V36 | Descancelar una venta | Venta cancelada → botón descancelar | La venta vuelve a estar activa y afecta el inventario | | |

### 2.3 Ventas Pendientes
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| V37 | Crear venta pendiente normal | Seleccionar producto y cliente, guardar | Se registra como pendiente | | |
| V38 | **BUG CONOCIDO** Buscador de productos | Escribir nombre de producto en el campo | ¿El combo box encuentra el producto? (Bug: no carga) | | |
| V39 | Crear pendiente sin cliente | Intentar guardar sin seleccionar cliente | ¿Permite o exige cliente? | | |
| V40 | Crear pendiente sin productos | Intentar guardar sin agregar productos | Debe rechazar | | |
| V41 | Liquidar venta pendiente (efectivo) | Abrir pendiente y marcar como liquidada | Descuenta stock, registra en historial, genera ticket | | |
| V42 | Liquidar pendiente con stock insuficiente | Pendiente de 10 unidades, solo hay 3 en stock | Debe rechazar o alertar | | |
| V43 | **BUG CONOCIDO** Folio en historial | Liquidar una venta pendiente, ver su folio en historial | ¿El folio coincide con el formato de las demás ventas? | | |
| V44 | Liquidar pendiente pagando a crédito | Liquidar con método crédito | Registra correctamente en créditos del cliente | | |

### 2.4 Devoluciones
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| D01 | Devolución total normal | Folio de venta de 2 productos, devolver ambos | Stock restaurado, se refleja en historial | | |
| D02 | Devolución parcial | Venta de 2 productos, devolver solo 1 | Solo ese producto regresa al stock | | |
| D03 | **BUG CONOCIDO** Devolver más de lo comprado | Venta de 2 unidades, intentar devolver 5 | Debe rechazar (Bug: lo permite) | | |
| D04 | Devolver cantidad = 0 | Poner 0 en cantidad a devolver | Debe rechazar | | |
| D05 | Devolver cantidad negativa | Poner -1 en cantidad a devolver | Debe rechazar | | |
| D06 | Folio inexistente | Buscar folio 99999999 | Mensaje de error claro | | |
| D07 | Devolver de venta ya devuelta | Buscar folio de venta ya devuelta al 100% | Debe rechazar segunda devolución | | |
| D08 | Devolver de venta cancelada | Buscar folio de venta cancelada | ¿Permite devolución de cancelada? No debería | | |
| D09 | Devolución de venta a crédito | Venta pagada a crédito, hacer devolución | ¿Devuelve el crédito al límite del cliente? | | |
| D10 | Devolución de paquete | Venta de un paquete, intentar devolver | ¿Regresa los componentes individuales o el paquete completo? | | |
| D11 | Cancelar una devolución ya registrada | Buscar devolución y cancelarla | ¿Existe opción? Si no existe, anotar como faltante | | |

---

## MÓDULO 3 — CLIENTES

### 3.1 Gestión de Clientes
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| CL01 | Crear cliente normal | Nombre: "Juan Pérez", teléfono, correo | Cliente registrado correctamente | | |
| CL02 | Crear cliente sin nombre | Dejar nombre vacío | Debe rechazar | | |
| CL03 | Crear cliente con solo espacios en nombre | Nombre: "   " | Debe rechazar | | |
| CL04 | Crear cliente con descuento 0% | Descuento: 0 | Se registra sin descuento | | |
| CL05 | Crear cliente con descuento 100% | Descuento: 100 | ¿Acepta 100%? | | |
| CL06 | Crear cliente con descuento 101% | Descuento: 101 | Debe rechazar | | |
| CL07 | Crear cliente con descuento negativo | Descuento: -5 | Debe rechazar | | |
| CL08 | Límite de crédito $0 | Límite crédito: 0 | ¿Permite crédito $0? | | |
| CL09 | Límite de crédito negativo | Límite crédito: -500 | Debe rechazar | | |
| CL10 | Crear cliente nombre duplicado | Mismo nombre de un cliente existente | ¿Permite duplicados? ¿Avisa? | | |
| CL11 | Editar cliente existente | Cambiar nombre y teléfono | Se actualiza correctamente | | |
| CL12 | Editar ID inexistente en URL | Cambiar URL a ?editar=99999 | Debe redirigir con mensaje de error | | |
| CL13 | Eliminar cliente sin créditos activos | Cliente sin créditos, click eliminar | Se desactiva correctamente | | |
| CL14 | **BUG ESPERADO** Eliminar cliente con crédito activo | Cliente con crédito activo, intentar eliminar | Debe bloquear (si BUG-03 fue aplicado) | | |
| CL15 | **BUG ESPERADO** Eliminar cliente con ventas pendientes | Cliente con venta pendiente, intentar eliminar | Debe bloquear | | |
| CL16 | Reactivar cliente eliminado | Cliente con activo=0, usar link reactivar | Cliente vuelve a aparecer en lista | | |
| CL17 | Filtrar por nombre | Buscar "Juan" en el buscador | Solo muestra clientes con "Juan" | | |
| CL18 | Filtrar por teléfono | Buscar número de teléfono | Encuentra al cliente por teléfono | | |
| CL19 | Filtrar por correo | Buscar parte del correo | Encuentra al cliente por correo | | |
| CL20 | Buscar texto que no existe | Buscar "xkzqwerty" | Lista vacía o mensaje sin resultados | | |
| CL21 | XSS en nombre de cliente | Nombre: `<script>alert('xss')</script>` | No debe ejecutar el script, debe mostrar el texto plano | | |

### 3.2 Créditos
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| CR01 | Ver créditos de cliente activo | Seleccionar cliente con créditos | Lista de créditos pendientes | | |
| CR02 | Abonar monto normal | Abonar $200 a cliente que debe $500 | Saldo baja a $300 | | |
| CR03 | Abonar exactamente lo que debe | Abonar $500 a cliente que debe $500 | Crédito queda en $0, estado cambia | | |
| CR04 | Abonar más de lo que debe | Abonar $1000 a cliente que debe $500 | ¿Acepta sobrepago? Debe rechazar o ajustar | | |
| CR05 | Abonar $0 | Cantidad: 0 | Debe rechazar | | |
| CR06 | Abonar cantidad negativa | Cantidad: -100 | Debe rechazar | | |
| CR07 | Abonar a cliente sin créditos | Cliente con saldo $0 | Debe avisar que no tiene deuda | | |
| CR08 | Verificar FIFO en pagos | Cliente con 2 créditos de fechas distintas | El abono debe liquidar primero el más antiguo | | |
| CR09 | Ver historial de abonos | Click en historial del cliente | Lista de todos sus abonos con fechas y montos | | |
| CR10 | Abonar con texto en cantidad | Cantidad: "abc" | Debe rechazar o convertir a 0 | | |

---

## MÓDULO 4 — INVENTARIO

### 4.1 Productos
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| P01 | Crear producto normal (unidad) | Código, nombre, precios, stock, categoría | Producto registrado correctamente | | |
| P02 | Crear producto tipo suelto | Tipo: suelto, cantidad inicial con decimales | Acepta decimales en stock | | |
| P03 | Crear sin nombre | Dejar nombre vacío | Debe rechazar | | |
| P04 | Crear sin código | Dejar código vacío | Debe rechazar o generar automático | | |
| P05 | Código duplicado | Mismo código de producto existente | Debe rechazar con mensaje de error | | |
| P06 | Precio venta $0 | Precio venta: 0 | ¿Permite precio $0? | | |
| P07 | Precio negativo | Precio venta: -50 | Debe rechazar | | |
| P08 | Precio venta menor al de compra | Compra: $100, Venta: $50 | ¿Avisa que se vende a pérdida? | | |
| P09 | Stock inicial negativo | Cantidad: -5 | Debe rechazar | | |
| P10 | Stock máximo menor al mínimo | Min: 10, Max: 5 | Debe rechazar o advertir | | |
| P11 | Stock mínimo = stock máximo | Min: 10, Max: 10 | ¿Acepta? | | |
| P12 | XSS en nombre | Nombre: `<img src=x onerror=alert(1)>` | No ejecuta el script | | |
| P13 | Precio con texto | Precio venta: "cien" | Debe rechazar | | |
| P14 | **BUG CONOCIDO** Precio compra default | Abrir formulario nuevo producto | ¿El campo precio compra tiene 0 que hay que borrar manualmente? | | |
| P15 | Editar producto existente | Cambiar precio de venta | Precio actualizado, afecta ventas futuras | | |
| P16 | **BUG CONOCIDO** Eliminar producto sin confirmación | Click en eliminar | ¿Pide confirmación y nota del por qué? | | |
| P17 | Eliminar producto con stock > 0 | Producto con 10 unidades, eliminar | ¿Bloquea o permite? ¿Qué pasa con el stock? | | |
| P18 | Eliminar producto con ventas activas | Producto que ya fue vendido, eliminar | ¿Afecta el historial de ventas? | | |
| P19 | Buscar producto por nombre | Escribir nombre parcial | Filtra correctamente | | |
| P20 | Alerta stock bajo al iniciar sesión | Iniciar sesión con producto bajo mínimo | Debe mostrar alerta de stock bajo | | |

### 4.2 Categorías
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| CA01 | Crear categoría normal | Nombre: "Herramientas" | Creada correctamente | | |
| CA02 | Crear categoría duplicada | Mismo nombre que una existente | ¿Permite duplicado o rechaza? | | |
| CA03 | Crear categoría vacía | Dejar nombre vacío | Debe rechazar | | |
| CA04 | Eliminar categoría sin productos | Categoría sin productos asignados | Se elimina correctamente | | |
| CA05 | Eliminar categoría con productos | Categoría que tiene productos asignados | Debe bloquear o reasignar | | |
| CA06 | XSS en nombre | Nombre: `<script>alert(1)</script>` | No ejecuta el script | | |

### 4.3 Unidades de Medida
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| UM01 | Crear unidad normal | Nombre: "Kilogramo", símbolo: "kg" | Creada correctamente | | |
| UM02 | Crear unidad duplicada | Mismo nombre | ¿Permite o rechaza? | | |
| UM03 | Eliminar unidad en uso | Unidad asignada a productos existentes | Debe bloquear o advertir | | |

### 4.4 Entradas
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| E01 | Entrada normal | Producto, cantidad 10, proveedor | Stock aumenta en 10 | | |
| E02 | Entrada cantidad 0 | Cantidad: 0 | Debe rechazar | | |
| E03 | Entrada cantidad negativa | Cantidad: -5 | Debe rechazar | | |
| E04 | Entrada sin seleccionar producto | Enviar sin producto | Debe rechazar | | |
| E05 | Entrada sin proveedor | Enviar sin seleccionar proveedor | ¿Obliga a seleccionar proveedor? | | |
| E06 | Entrada de producto tipo suelto con decimales | Cantidad: 2.5 kg | ¿Acepta decimales en suelto? | | |
| E07 | Entrada de producto tipo unidad con decimales | Cantidad: 1.5 unidades | Debe rechazar (solo enteros) | | |
| E08 | Verificar en movimientos | Hacer entrada, ir a Movimientos | Debe aparecer el registro de entrada | | |
| E09 | Cantidad muy alta | Cantidad: 999999 | ¿Acepta sin error? ¿Hay límite? | | |

### 4.5 Salidas y Mermas
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| S01 | **BUG CONOCIDO** Buscador de productos | Escribir nombre en buscador | ¿Carga productos? (Bug: no carga) | | |
| S02 | Salida normal | Producto con 10 stock, salida de 3 | Stock baja a 7 | | |
| S03 | Salida mayor al stock disponible | Stock = 5, salida = 10 | Debe rechazar o advertir | | |
| S04 | Salida exacta al stock total | Stock = 5, salida = 5 | Stock llega a 0, se permite | | |
| S05 | Salida cantidad 0 | Cantidad: 0 | Debe rechazar | | |
| S06 | Salida cantidad negativa | Cantidad: -3 | Debe rechazar | | |
| S07 | Salida sin motivo | Dejar campo motivo/descripción vacío | ¿Obliga a poner motivo? | | |
| S08 | Salida de producto con stock = 0 | Intentar sacar producto agotado | Debe rechazar claramente | | |
| S09 | Verificar en movimientos | Hacer salida, ir a Movimientos | Aparece el registro de salida | | |

### 4.6 Movimientos
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| M01 | Ver historial completo | Abrir sección | Lista de todos los movimientos | | |
| M02 | Filtrar por producto | Seleccionar producto específico | Solo movimientos de ese producto | | |
| M03 | Filtrar por tipo (entrada/salida) | Filtro = "Entrada" | Solo entradas | | |
| M04 | Filtrar por fecha | Fecha de hoy | Solo movimientos de hoy | | |
| M05 | Filtrar fecha sin resultados | Fecha sin movimientos | Lista vacía o mensaje | | |

---

## MÓDULO 5 — PROVEEDORES

### 5.1 Proveedores
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| PR01 | Crear proveedor normal | Nombre, teléfono, dirección | Creado correctamente | | |
| PR02 | Crear proveedor sin nombre | Nombre vacío | Debe rechazar | | |
| PR03 | **BUG CONOCIDO** Nombre duplicado | Mismo nombre de proveedor existente | Debe rechazar (BUG-02 aplicado) | | |
| PR04 | **BUG CONOCIDO** Áreas que abastece (combo box) | Intentar agregar áreas | ¿Es combo box o buscador? (Bug: debería ser buscador) | | |
| PR05 | Buscar proveedor por nombre | Escribir nombre parcial | Filtra correctamente | | |
| PR06 | Buscar proveedor por teléfono | Escribir número | Encuentra por teléfono (BUG-04 aplicado) | | |
| PR07 | Eliminar proveedor con compras | Proveedor con historial de compras | ¿Bloquea o permite? | | |
| PR08 | XSS en nombre | `<script>alert(1)</script>` | No ejecuta el script | | |

### 5.2 Compras (Órdenes a proveedor)
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| CO01 | Crear compra normal | Proveedor, 2 productos, cantidades, precios | Compra registrada y stock actualizado | | |
| CO02 | Crear compra sin proveedor | Enviar sin seleccionar proveedor | Debe rechazar | | |
| CO03 | Crear compra sin productos | Enviar con lista vacía | Debe rechazar | | |
| CO04 | **BUG CONOCIDO** Producto duplicado en orden | Agregar mismo producto dos veces | Debe mostrar confirm() para sumar cantidades (BUG-05 aplicado) | | |
| CO05 | Cantidad 0 en producto | Poner 0 en cantidad de un producto | ¿Permite o rechaza? | | |
| CO06 | Precio unitario $0 | Poner $0 como precio | ¿Permite compra gratis? | | |
| CO07 | Precio negativo | Precio: -100 | Debe rechazar | | |
| CO08 | Verificar que stock aumenta | Hacer compra de 5 unidades | Stock del producto sube en 5 | | |
| CO09 | Ver historial de compras | Abrir sección historial | Lista de todas las compras al proveedor | | |
| CO10 | Cantidad decimal en producto unidad | Cantidad: 1.5 | Debe rechazar (solo enteros para unidades) | | |

---

## MÓDULO 6 — MÁS

### 6.1 Paquetes
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| PQ01 | Crear paquete normal | Nombre, precio, 2 productos con cantidades | Paquete creado correctamente | | |
| PQ02 | Crear paquete sin nombre | Nombre vacío | Debe rechazar | | |
| PQ03 | Crear paquete sin productos | 0 productos, enviar | Debe rechazar | | |
| PQ04 | Crear paquete con 1 solo producto | Solo 1 producto agregado | ¿Permite paquete de 1 item? | | |
| PQ05 | Precio paquete $0 | Precio: 0 | ¿Permite paquete gratis? | | |
| PQ06 | Precio paquete mayor que suma de componentes | Precio paquete $500, componentes suman $200 | ¿Avisa que el paquete es más caro que los individuales? | | |
| PQ07 | **BUG CONOCIDO** Producto duplicado en paquete | Agregar mismo producto dos veces | Debe mostrar confirm() para reemplazar (BUG-06 aplicado) | | |
| PQ08 | Vender paquete con un componente sin stock | Un componente del paquete = 0 stock | La venta debe rechazarse | | |
| PQ09 | Verificar repartición de precio | Paquete $100 con 3 componentes | La suma de los subtotales debe ser exactamente $100 | | |
| PQ10 | Devolución de paquete | Vender paquete, intentar devolver | ¿Devuelve como paquete o como productos individuales? | | |

### 6.2 Transferencias
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| T01 | Solicitar transferencia normal | Origen: Sucursal A, Destino: B, producto + cantidad | Transferencia creada en estado "Pendiente" | | |
| T02 | **BUG CONOCIDO** Sin sucursal origen | Enviar sin seleccionar origen | Debe mostrar alert() (BUG-07 aplicado) | | |
| T03 | Origen igual a destino | Misma sucursal en ambos campos | Debe rechazar | | |
| T04 | Cantidad mayor al stock del origen | Stock = 5, transferir 10 | Debe rechazar al enviar | | |
| T05 | Cantidad 0 | Cantidad: 0 | Debe rechazar | | |
| T06 | Sin productos | Enviar solicitud vacía | Debe rechazar | | |
| T07 | Aprobar transferencia | Como sucursal destino, aprobar solicitud | Estado cambia a "Aprobada" | | |
| T08 | Rechazar transferencia | Rechazar una solicitud | Estado cambia a "Rechazada", stock no cambia | | |
| T09 | Recibir transferencia aprobada | Marcar transferencia como recibida | Stock aumenta en destino, disminuye en origen | | |
| T10 | Aprobar transferencia ya aprobada | Intentar aprobar dos veces | Debe bloquear | | |
| T11 | **FALTANTE** Nota al aceptar transferencia | Aceptar transferencia | ¿Genera nota/notificación para la sucursal receptora? | | |

### 6.3 Promociones
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| PR01 | Ver promociones activas | Abrir sección | Lista de promociones vigentes | | |
| PR02 | Verificar que promoción aplica en venta | Hacer venta del producto con promoción activa | El precio con descuento se aplica automáticamente | | |
| PR03 | Verificar promoción expirada | Producto con promoción vencida | No aplica el descuento | | |
| PR04 | Promoción fuera de fecha | Hoy antes de la fecha inicio | No aplica | | |

### 6.4 Más Vendidos
| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| MV01 | Ver más vendidos | Abrir sección | Lista de productos ordenados por cantidad vendida | | |
| MV02 | Sin ventas registradas | Entrar a la sección con BD vacía de ventas | Mensaje de "sin datos" o lista vacía | | |
| MV03 | Filtrar por periodo | Filtrar por "esta semana" o "este mes" | Solo muestra ventas del periodo seleccionado | | |
| MV04 | Verificar que el ranking es correcto | Comparar con historial de ventas manualmente | El producto más vendido debe estar primero | | |

---

## MÓDULO 7 — SEGURIDAD GENERAL

| # | Descripción | Pasos / Datos | Resultado esperado | Resultado real | Estado |
|---|-------------|---------------|-------------------|----------------|--------|
| SEC01 | Acceso sin iniciar sesión | Abrir cajeroInventario/nuevaVenta.php directamente en URL | Redirige al login, no muestra la página | | |
| SEC02 | cajero accede a módulo de admin | Iniciar como cajero, intentar abrir admin/usuarios.php | Redirige o muestra "acceso denegado" | | |
| SEC03 | Sesión expirada | Cerrar sesión, presionar "Atrás" en el navegador | No debe mostrar la página protegida | | |
| SEC04 | SQL injection en buscadores | Buscar: `' OR '1'='1` en cualquier buscador | No debe devolver todos los registros | | |
| SEC05 | XSS en campos de texto | Ingresar `<script>alert(1)</script>` en cualquier campo | No debe ejecutar el script | | |
| SEC06 | Acceso de cajero a reportes de admin | Intentar abrir admin/reportes.php | Debe bloquear | | |

---

## RESUMEN DE BUGS CONOCIDOS A VERIFICAR

| Bug # | Módulo | Descripción | ¿Aplicado? | Estado en prueba |
|-------|--------|-------------|------------|-----------------|
| BUG-02 | Proveedores | Nombre de proveedor duplicado | ✅ Sí | |
| BUG-03 | Clientes | Eliminar cliente con crédito activo | ✅ Sí | |
| BUG-04 | Proveedores | Buscar por teléfono | ✅ Sí | |
| BUG-05 | Compras | Producto duplicado en orden | ✅ Sí | |
| BUG-06 | Paquetes | Producto duplicado en paquete | ✅ Sí | |
| BUG-07 | Transferencias | Sin sucursal origen | ✅ Sí | |
| BUG-08 | Nueva Venta | Redondeo en paquetes | ✅ Sí | |
| BUG-D1 | Devoluciones | Devolver más de lo comprado | ❌ Pendiente | |
| BUG-D2 | Historial | Total no actualiza tras devolución | ❌ Pendiente | |
| BUG-S1 | Salidas | Buscador no carga productos | ❌ Pendiente | |
| BUG-P1 | Productos | Sin confirmación al eliminar | ❌ Pendiente | |
| BUG-PR1 | Proveedores | Áreas como combo box en vez de buscador | ❌ Pendiente | |
| BUG-VP1 | Ventas Pendientes | Combo box en vez de buscador | ❌ Pendiente | |

---

## NOTAS DE PRUEBA

**Registra aquí observaciones generales durante las pruebas:**

- 
- 
- 

**Bugs nuevos encontrados (no en la lista):**

| # | Módulo | Descripción del bug | Cómo reproducirlo |
|---|--------|--------------------|--------------------|
| | | | |
| | | | |
| | | | |
