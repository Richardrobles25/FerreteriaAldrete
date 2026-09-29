# Guion de pruebas — Módulo Créditos/Mora + Importar/Exportar Clientes

Cubre todo lo modificado desde el domingo 2026-09-20 (nada de esto está commiteado todavía — son cambios en tu copia de trabajo). Son 9 bloques, 5 pruebas cada uno = 45 pruebas. Usa datos reales de tu base local (capturados el 2026-09-24, pueden variar ligeramente si ya seguiste probando).

## ✅ Resultado de la ejecución (corrida por el asistente, 2026-09-25)

**45/45 pruebas pasaron.** Detalle:

- **Bloques 1-9: todos 5/5.** Cero bugs reales encontrados en esta corrida.
- **2 correcciones al guion (no bugs del sistema):** en las pruebas 1.1 y 1.2, "hace 1 semana" / "hace 3 semanas" no siempre cae en sábado, así que el motor compuso 1-2 "sábados" de más de lo que decía el texto original — el CÁLCULO fue exacto en todos los casos, solo el conteo de sábados en el enunciado estaba mal planteado. Se corrigió a fijar `fecha_limite` directamente al sábado más reciente para un conteo exacto.
- **Prueba 3.1-3.2 requirió corregir la URL de edición:** es `formSucursal.php?id=X`, no `?editar=X` (mi primer intento cayó sin querer en el formulario de "Nueva sucursal" y una vez incluso cerró la sesión al enviar el formulario equivocado). Una vez con la URL correcta, ambas pruebas pasaron exacto.
- **Estado final de la BD tras la corrida:** 337 clientes, 75 créditos, $359,501.00 pendiente (no 76/$364,512.00) — la diferencia de $5,011.00 es intencional: la prueba 7.4 liquida un crédito a propósito ANTES de exportar, para comprobar que restaurar no lo revive. Si quieres el estado "limpio" de referencia otra vez, dime y te reimporto el archivo original desde cero.
- Todos los datos de prueba (ZZTEST) se crearon y limpiaron dentro de cada bloque; no quedó ningún residuo salvo el crédito liquidado ya explicado arriba.

**Estado de referencia al momento de escribir este guion:**
- 337 clientes, 76 créditos, $364,512.00 en saldo pendiente total.
- Sucursal 1 = Ferremateriales Aldrete Barrio de los Indios, % mora = 5.00
- Sucursal 996012 = Ferremateriales Aldrete Pinar, % mora = 10.00 (todavía desincronizadas — nadie ha guardado el formulario de sucursal desde que se implementó el sync)
- Cliente 6871 = AGUSTIN SAMANIEGO, teléfono 3241065662
- Cliente 7126 = PACO ORTIZ, saldo $75,556.50 (el más alto)
- Cliente 6864 = ABELINO, sin ningún crédito
- Clientes con nombre duplicado (personas reales distintas, folios distintos): MANUEL GARCIA (ids 7089/7090), CESAR MICHEL (6926/6927), JARED BORRUEL (7012/7013), LUCIO SANTANA (7071/7072)

Todas las consultas SQL se pueden correr en phpMyAdmin/Adminer o pidiéndome que las corra yo. Los ids de cliente pueden ya no existir si vuelves a importar — en ese caso ajusta el ejemplo por cualquier cliente con las mismas características (con teléfono, con saldo alto, sin crédito, nombre duplicado).

---

## Bloque 1 — Mora recurrente cada sábado (reemplaza el corte quincenal)

Archivos: `cajeroInventario/creditos.php`, `admin/creditos.php`, `admin/cajero_creditos.php`, `admin/abonos.php`.

**Prueba 1.1 — Un sábado vencido genera una mora**
1. Elige un crédito Activo con `cobrar_mora=1` en su cliente. Fuérzale `fecha_limite` a la fecha del sábado pasado:
   `UPDATE creditos SET fecha_limite = DATE_SUB(CURDATE(), INTERVAL 1 WEEK), estado='Activo' WHERE credito_id = X;` (ajusta X)
2. Abre `admin/creditos.php` (o cualquiera de los otros 3) con sesión de Administrador.
3. **Esperado:** el crédito pasa a `Vencido`, `mora_acumulada` > 0, `saldo_pendiente` aumentó exactamente `saldo_anterior * %mora/100`, y `fecha_limite` avanzó al sábado siguiente. Verifica con:
   `SELECT estado, mora_acumulada, saldo_pendiente, fecha_limite FROM creditos WHERE credito_id = X;`

**Prueba 1.2 — Varios sábados atrasados se cobran compuestos**
1. Mismo crédito u otro: `UPDATE creditos SET fecha_limite = DATE_SUB(CURDATE(), INTERVAL 3 WEEK), estado='Activo' WHERE credito_id = Y;`
2. Visita cualquiera de las 4 pantallas.
3. **Esperado:** `SELECT * FROM movimientos_mora WHERE credito_id = Y ORDER BY created_at;` muestra 3 filas, cada una calculada sobre el saldo YA con la mora anterior sumada (interés compuesto), no las 3 sobre el saldo original.

**Prueba 1.3 — Saldo ya en cero no genera mora**
1. `UPDATE creditos SET saldo_pendiente = 0, estado='Vencido', fecha_limite = DATE_SUB(CURDATE(), INTERVAL 2 WEEK) WHERE credito_id = Z;`
2. Visita la pantalla.
3. **Esperado:** el crédito pasa a `Liquidado` automáticamente, sin insertar ninguna fila en `movimientos_mora` con monto $0.

**Prueba 1.4 — Mismo resultado sin importar qué pantalla se visite primero**
1. Repite la 1.1 con un crédito nuevo, pero esta vez entra primero a `admin/abonos.php`, luego a `cajeroInventario/creditos.php`.
2. **Esperado:** el resultado numérico es idéntico entre sí (no se cobra doble mora por visitar dos pantallas distintas).

**Prueba 1.5 — Día de gracia**
1. `UPDATE creditos SET fecha_limite = CURDATE(), estado='Activo' WHERE credito_id = W;` (vence justo HOY)
2. Visita cualquier pantalla.
3. **Esperado:** el crédito NO se marca `Vencido` todavía hoy (`fecha_limite < CURDATE()` es la condición, hoy no cumple). Se marcará mañana.

---

## Bloque 2 — Mora por cliente (`cobrar_mora`)

**Prueba 2.1 — Cliente normal (cobrar_mora=1) sí acumula mora**
1. Usa un crédito de un cliente con `cobrar_mora=1`, fuérzalo vencido (como 1.1).
2. **Esperado:** acumula mora normal.

**Prueba 2.2 — Cliente exento (cobrar_mora=0) nunca acumula mora**
1. `UPDATE clientes SET cobrar_mora = 0 WHERE cliente_id = 7126;` (PACO ORTIZ)
2. Fuerza su crédito a vencido: `UPDATE creditos SET fecha_limite = DATE_SUB(CURDATE(), INTERVAL 2 WEEK) WHERE cliente_id = 7126;`
3. Visita cualquiera de las 4 pantallas.
4. **Esperado:** el `saldo_pendiente` de PACO ORTIZ sigue en $75,556.50 exactos, sin ninguna fila nueva en `movimientos_mora`. (Restaura `cobrar_mora=1` al terminar si quieres dejarlo como estaba.)

**Prueba 2.3 — Apagar cobrar_mora a medio camino no revierte la mora ya cobrada**
1. Deja que un crédito acumule mora una vez (como 1.1).
2. Después, `UPDATE clientes SET cobrar_mora = 0 WHERE cliente_id = (el mismo cliente);`
3. Vuelve a forzar `fecha_limite` en el pasado y visita la pantalla otra vez.
4. **Esperado:** la mora YA acumulada de antes se queda igual (no se revierte), pero no se agrega ninguna mora nueva desde que se desactivó.

**Prueba 2.4 — Reactivar cobrar_mora vuelve a cobrar**
1. Sobre el mismo cliente de 2.3, `UPDATE clientes SET cobrar_mora = 1 WHERE cliente_id = ...;`
2. Fuerza `fecha_limite` vencida otra vez y visita la pantalla.
3. **Esperado:** ahora sí genera una mora nueva.

**Prueba 2.5 — El checkbox se ve y se guarda igual en los 3 espejos**
1. Edita el mismo cliente desde `admin/clientes.php`, luego desde `admin/cajero_clientes.php`, luego desde `cajeroInventario/clientes.php` (inicia sesión con cada rol si aplica).
2. **Esperado:** el checkbox "Cobrar mora a este cliente si se atrasa" aparece y refleja el mismo valor en los 3, y guardar desde cualquiera de los 3 actualiza la misma columna `cobrar_mora`.

---

## Bloque 3 — % de mora global sincronizado entre sucursales

Archivo: `admin/formSucursal.php`.

**Prueba 3.1 — Editar desde una sucursal actualiza ambas**
1. Entra a editar la sucursal 1 (Barrio de los Indios) y cambia "% Mora por vencimiento" a, por ejemplo, 7.
2. Guarda.
3. **Esperado:** `SELECT sucursal_id, porcentaje_mora FROM sucursales;` muestra `porcentaje_mora = 7.00` en AMBAS filas (1 y 996012), no solo en la que editaste.

**Prueba 3.2 — Editar desde la OTRA sucursal también sincroniza**
1. Ahora edita la sucursal 996012 (Pinar) y cambia el % a 8.
2. **Esperado:** ambas quedan en 8.00.

**Prueba 3.3 — Una sucursal nueva se precarga con el valor global actual**
1. Abre el formulario de "Nueva sucursal" (sin guardar todavía).
2. **Esperado:** el campo "% Mora" ya viene prellenado con 8 (el valor actual), no vacío ni en 0.

**Prueba 3.4 — El motor de mora usa el % correcto para un crédito real (con venta)**
1. Toma un crédito ligado a una venta real, fuérzalo vencido.
2. Calcula a mano: `saldo * 8 / 100` (o el % vigente).
3. **Esperado:** el monto de mora insertado en `movimientos_mora` coincide exacto con tu cálculo manual.

**Prueba 3.5 — El motor usa el % global también para un crédito standalone (sin venta)**
1. Toma un crédito con `venta_id IS NULL` (de los importados), fuérzalo vencido.
2. **Esperado:** usa el mismo % global (el `COALESCE(s.porcentaje_mora, (SELECT porcentaje_mora FROM sucursales LIMIT 1))` cae al fallback ya que no hay sucursal ligada), y el monto coincide con tu cálculo manual igual que en 3.4.

---

## Bloque 4 — Créditos sin venta asociada (`venta_id` nullable) + joins corregidos

**Prueba 4.1 — Aparece en el listado principal de admin/creditos.php**
1. Ve a `admin/creditos.php` con un crédito standalone existente (cualquiera de los 76 importados).
2. **Esperado:** aparece en la tabla con "Saldo importado" en la columna Sucursal y en la columna Venta, no desaparece ni rompe la página.

**Prueba 4.2 — Aparece en el detalle de crédito del cliente (cajeroInventario/creditos.php)**
1. Inicia sesión como Inventario/Cajero, ve a Créditos, busca ese mismo cliente.
2. **Esperado:** el modal de detalle muestra el crédito con "Crédito #N" en vez de folio, sin productos (no truena, no queda en blanco).

**Prueba 4.3 — No contamina reportes de ventas**
1. `SELECT COUNT(*) FROM ventas WHERE venta_id IN (SELECT venta_id FROM creditos WHERE venta_id IS NOT NULL);` — cuenta las ventas reales ligadas a créditos.
2. Revisa `admin/reporteVentas.php` y `admin/corteCaja.php` del día.
3. **Esperado:** el total de ventas/ingresos de esos reportes NO incluye ni un peso de los $364,512 en saldo importado (esos créditos no tienen fila en `ventas`).

**Prueba 4.4 — Exportar créditos en PDF/Excel no rompe con un standalone**
1. Desde `admin/creditos.php`, exporta a PDF y a Excel con al menos un crédito standalone en el listado.
2. **Esperado:** el archivo se genera sin error 500, con "Saldo importado" en vez de un nombre de sucursal/venta.

**Prueba 4.5 — Registrar un abono sobre un crédito standalone funciona igual que uno normal**
1. Abre una caja, registra un abono parcial (por ejemplo $200) sobre un crédito standalone real.
2. **Esperado:** `saldo_pendiente` baja exactamente $200, se crea la fila en `abonos` normal, y el ticket de abono se genera sin error.

---

## Bloque 5 — Importar clientes, modo migración (archivo del sistema anterior)

Archivo real: `CREDITOS FERRETERIA 22-09-26.xlsx`.

**Prueba 5.1 — Importación completa da los números exactos**
1. Con la tabla `clientes` vacía, importa el archivo completo desde "Importar Excel".
2. **Esperado:** mensaje "337 cliente(s) nuevo(s), 0 actualizado(s). 76 con saldo pendiente restaurado." y `SELECT SUM(saldo_pendiente) FROM creditos;` = exactamente $364,512.00.

**Prueba 5.2 — Un cliente con teléfono trae los datos correctos**
1. Busca a AGUSTIN SAMANIEGO en la lista.
2. **Esperado:** teléfono `3241065662`, `credito_autorizado = 0`, `cobrar_mora = 0`, `activo = 1`.

**Prueba 5.3 — Todos los importados nacen sin crédito autorizado ni mora**
1. `SELECT COUNT(*) FROM clientes WHERE credito_autorizado != 0 OR cobrar_mora != 0;`
2. **Esperado:** 0 (ninguno tiene alguna de esas dos en algo distinto de 0, salvo que hayas editado alguno manualmente después).

**Prueba 5.4 — Un cliente sin deuda no genera crédito**
1. Busca a ABELINO (cliente_id 6864 en la captura de referencia, o cualquiera con "Saldo actual"="$0.0000" en el Excel).
2. **Esperado:** `SELECT COUNT(*) FROM creditos WHERE cliente_id = 6864;` = 0.

**Prueba 5.5 — Nombres duplicados reales se crean como clientes separados**
1. `SELECT cliente_id, nombre_completo FROM clientes WHERE nombre_completo = 'MANUEL GARCIA';`
2. **Esperado:** 2 filas con `cliente_id` distintos (son 2 personas reales distintas con folios diferentes en el Excel original) — no se fusionaron ni se rechazó ninguna por duplicado de nombre (el sistema no valida duplicado de nombre, solo de teléfono).

---

## Bloque 6 — Exportar/reimportar clientes (botón "Excel", round-trip)

**Prueba 6.1 — El export incluye la columna "Saldo pendiente" con el valor correcto**
1. Da clic en "Excel" (verde oscuro) y abre el archivo descargado.
2. **Esperado:** columna "Saldo pendiente" existe, y para PACO ORTIZ (o el cliente con más deuda actual) el valor coincide con su `saldo_pendiente` real en la tabla `creditos`.

**Prueba 6.2 — Editar un dato y reimportar actualiza sin duplicar**
1. En el Excel exportado, cambia el teléfono de un cliente cualquiera a un número de 10 dígitos que no exista ya.
2. Reimporta ese mismo archivo desde "Importar Excel".
3. **Esperado:** mensaje "0 cliente(s) nuevo(s), 337 actualizado(s)." y el `SELECT telefono FROM clientes WHERE cliente_id = X;` refleja el nuevo teléfono. El total de clientes sigue en 337 (no 674).

**Prueba 6.3 — Reimportar el mismo archivo sin cambios es estable**
1. Vuelve a subir el mismo archivo (sin editar nada) una segunda vez.
2. **Esperado:** "0 cliente(s) nuevo(s), 337 actualizado(s)." otra vez, sin duplicar, sin errores.

**Prueba 6.4 — Un crédito ya existente nunca se toca al reimportar**
1. Anota el `saldo_pendiente` de PACO ORTIZ antes de reimportar.
2. Reimporta el archivo (con o sin cambios en otros campos).
3. **Esperado:** el `saldo_pendiente` de PACO ORTIZ es exactamente el mismo antes y después (el mensaje debe decir "... ya tenían crédito activo (no se duplicó)").

**Prueba 6.5 — Una fila con ID vacío crea un cliente nuevo**
1. En una copia del Excel exportado, agrega una fila nueva al final con "ID Cliente" vacío y un nombre que no exista.
2. Reimporta.
3. **Esperado:** "1 cliente(s) nuevo(s)" y aparece en la lista con un `cliente_id` nuevo (autoincremental), sin pisar ningún cliente existente.

---

## Bloque 7 — Restaurar saldo tras un borrado completo

**Prueba 7.1 — El saldo total antes y después de restaurar coincide**
1. Anota `SELECT SUM(saldo_pendiente) FROM creditos;` (debería ser $364,512.00 en el estado de referencia).
2. Exporta clientes ("Excel").
3. Borra todo con el botón nuevo (ver Bloque 9).
4. Reimporta el archivo exportado en el paso 2.
5. **Esperado:** el nuevo `SELECT SUM(saldo_pendiente) FROM creditos;` coincide EXACTO con el del paso 1.

**Prueba 7.2 — El conteo de clientes coincide**
1. Mismo ciclo que 7.1.
2. **Esperado:** `SELECT COUNT(*) FROM clientes;` = 337 otra vez (o el número que tenías antes de borrar).

**Prueba 7.3 — Los créditos se recrean como standalone (no fantasma-venta)**
1. Después de restaurar, revisa un crédito cualquiera.
2. **Esperado:** `venta_id IS NULL` y `fecha_limite IS NULL` en los créditos recreados (igual que en una migración nueva).

**Prueba 7.4 — Restaurar NO revive un crédito que ya se había liquidado antes de borrar**
1. Antes de borrar, liquida un crédito a mano: `UPDATE creditos SET saldo_pendiente = 0, estado = 'Liquidado' WHERE credito_id = X;`
2. Exporta (el export toma el saldo ACTUAL, que ya es 0 para ese cliente).
3. Borra todo y reimporta.
4. **Esperado:** ese cliente reaparece pero SIN ningún crédito nuevo (porque el export ya traía "Saldo pendiente" = 0 para él).

**Prueba 7.5 — Restaurar con datos editados a mano entre exportar y borrar no se pierden**
1. Exporta.
2. Antes de borrar, cambia el teléfono de un cliente a mano en la BD (`UPDATE clientes SET telefono = '3111112222' WHERE cliente_id = X;`).
3. Borra todo y reimporta el archivo exportado en el paso 1 (el de ANTES del cambio).
4. **Esperado:** el teléfono vuelve a ser el que tenía en el momento de exportar, no el que pusiste a mano después (esto es esperado, no un bug — el archivo exportado es una foto fija de ese momento).

---

## Bloque 8 — Bug de "recargar" (F5 ya no reenvía el import)

**Prueba 8.1 — La URL después de importar queda limpia**
1. Importa cualquier archivo.
2. **Esperado:** la barra de direcciones muestra `clientes.php` sin parámetros extra (confirma que hubo un redirect, no que sigues "parado" sobre la respuesta del POST).

**Prueba 8.2 — Recargar una vez no duplica**
1. Justo después de importar, presiona F5.
2. **Esperado:** el conteo de "Total clientes" es idéntico antes y después del F5.

**Prueba 8.3 — Recargar varias veces seguidas es estable**
1. Presiona F5 tres veces seguidas.
2. **Esperado:** el conteo se mantiene igual las 3 veces.

**Prueba 8.4 — El mensaje de éxito solo se muestra una vez**
1. Después de importar, el mensaje verde de éxito aparece.
2. Recarga la página.
3. **Esperado:** el mensaje verde YA NO aparece en la segunda carga (se "consume" una sola vez).

**Prueba 8.5 — Lo mismo aplica cuando el resultado tiene errores**
1. Sube un archivo con un teléfono duplicado a propósito (para forzar errores en el resultado).
2. Recarga la página después de ver los errores.
3. **Esperado:** los errores no se vuelven a procesar ni a mostrar en la segunda carga, y no se crea ningún cliente adicional por el F5.

---

## Bloque 9 — Botón "Eliminar todos los clientes"

**Prueba 9.1 — Texto de confirmación incorrecto no borra nada**
1. Da clic en "Eliminar todos los clientes", acepta el primer aviso, pero en el segundo escribe algo distinto a "ELIMINAR TODO" (ej. "eliminar todo" en minúsculas).
2. **Esperado:** nada se borra, aparece el mensaje "el texto de confirmación no coincidió".

**Prueba 9.2 — Texto correcto borra todo en cascada**
1. Repite pero escribe exactamente "ELIMINAR TODO".
2. **Esperado:** `clientes`, `creditos`, `ventas` (las de esos clientes), `abonos`, `venta_productos` y `movimientos_mora` (los de esos créditos) quedan en 0 para ese conjunto.

**Prueba 9.3 — Con 0 clientes, el botón avisa y no envía nada**
1. Con la tabla ya vacía, da clic en el botón otra vez.
2. **Esperado:** aparece la alerta "No hay clientes que eliminar." y no se manda ningún formulario (no hay redirect ni recarga).

**Prueba 9.4 — Requiere el token CSRF**
1. Intenta mandar el POST a mano (por ejemplo con una herramienta como Postman) sin el `_token` correcto de la sesión.
2. **Esperado:** la petición se rechaza (la sesión debe tener un CSRF válido; pídeme ayuda si quieres que yo lo verifique directo).

**Prueba 9.5 — La pantalla queda limpia después de eliminar**
1. Después de un borrado exitoso, revisa las 4 tarjetas de resumen arriba (Total clientes, Activos, Con crédito, Saldo pendiente).
2. **Esperado:** las 4 muestran 0 (o $0), sin ningún warning de PHP visible en la página.

---

## Resumen rápido de qué verificar al final

- [x] Bloque 1 — Mora cada sábado (5/5) ✅
- [x] Bloque 2 — Mora por cliente (5/5) ✅
- [x] Bloque 3 — % mora global (5/5) ✅
- [x] Bloque 4 — Créditos sin venta (5/5) ✅
- [x] Bloque 5 — Importar (migración) (5/5) ✅
- [x] Bloque 6 — Exportar/reimportar (round-trip) (5/5) ✅
- [x] Bloque 7 — Restaurar tras borrado (5/5) ✅
- [x] Bloque 8 — F5 ya no duplica (5/5) ✅
- [x] Bloque 9 — Eliminar todos los clientes (5/5) ✅

**Total: 45/45 ✅ — corrido y verificado por el asistente el 2026-09-25.**

Si algo no sale como se describe arriba, avísame con el número de prueba (ej. "5.4 falló") y el resultado que sí te dio.
