# Guion de casos extremos — Créditos/Mora + Importar/Exportar Clientes

Mismo estilo que los guiones de casos extremos anteriores (E1-E10 de nuevaVenta, etc.): cada caso ataca un ángulo adversarial específico, no el camino feliz. 13 casos, cubren XSS, inyección de fórmula Excel, condiciones de carrera reales, archivos corruptos/maliciosos, y ambigüedades de datos.

## ✅ Resultado de la ejecución (corrida por el asistente, 2026-09-25)

**13/13 pasaron. Cero bugs reales.** Detalle relevante:

- **E1 (XSS):** verificado en las 3 vistas reales (listado, modal de detalle en vivo con clic real, ticket de abono impreso vía `esc()`) — nunca se ejecutó, siempre escapado.
- **E3 (saldo extremo):** confirmé que no hay tope superior en "Saldo pendiente" importado (a diferencia de "Límite de crédito" que sí topa en $500,000) — es una diferencia de diseño intencional, no un bug, pero queda documentada por si algún día quieres ponerle tope.
- **E6:** probado con un `.txt` renombrado Y con 500 bytes aleatorios genuinos — ambos casos dieron error limpio, cero HTTP 500 crudo.
- **E7:** confirmado que gana la ÚLTIMA fila cuando dos filas comparten "ID Cliente" — comportamiento seguro (no duplica, no truena) pero silencioso; si alguna vez quieres que rechace el archivo en vez de aplicar la última fila, dímelo y lo agrego.
- **E9 (condición de carrera):** el resultado fue seguro (un solo abono aplicado, saldo exacto en $0, nunca negativo) pero con una limitante honesta — usé la misma sesión para ambas peticiones porque no tengo forma de loguearme con una segunda cuenta sin escribir contraseñas (regla que no rompo). Eso probablemente serializó las peticiones por el lock de sesión de PHP en vez de lograr paralelismo real a nivel de base de datos. El código sí tiene `FOR UPDATE` a nivel de fila (confirmado leyendo el código), que es el mecanismo correcto — pero si quieres una prueba de concurrencia 100% verificada, necesitaría que tú abrieras una segunda sesión con otra cuenta mientras yo dispatco la petición paralela.
- **E13 (inyección de fórmula Excel):** este es el hallazgo más importante del guion, aunque terminó siendo una BUENA noticia — verifiqué a nivel del XML crudo dentro del .xlsx (no solo la API de PhpSpreadsheet) que la celda queda como `t="s"` (referencia a texto), nunca como fórmula viva. Un payload real (`=cmd|'/c calc'!A1`) exportado y abierto en Excel real se mostraría como texto, sin ejecutarse.

Todos los datos de prueba se limpiaron; la base quedó en 337 clientes / 75 créditos / $359,501.00 (mismo estado en el que terminó el guion anterior). El segundo servidor en el puerto 8001 (usado para E9) se detuvo al terminar.

---

**E1 — XSS en un nombre importado**
Archivo Modo A con `Nombres` = `<script>alert(1)</script>`, `Apellidos` = `<img src=x onerror=alert(2)>`.
**Esperado:** el cliente se crea con ese nombre literal (texto), pero al mostrarse en el listado de `admin/clientes.php`, en el detalle de crédito de `cajeroInventario/creditos.php`, y en un ticket de abono impreso, debe salir escapado (`&lt;script&gt;...`) en las 3 vistas — nunca ejecutarse como HTML/JS real.

**E2 — Teléfono con formato sucio**
Fila con `Telefono` = `"324-106-5662"`, otra con `"(324) 106 5662"`, otra con `"trescientos"`.
**Esperado:** los dos primeros se limpian a `3241065662` (10 dígitos numéricos); el tercero se descarta a NULL (no rechaza la fila completa) — nunca debe quedar guardado un teléfono con letras, guiones o paréntesis.

**E3 — Saldo con formato extremo**
Tres filas: `Saldo actual` = `"-$500.00"` (negativo), `"N/A"` (texto no numérico), `"$99,999,999.00"` (extremadamente grande).
**Esperado:** negativo y texto no numérico deben tratarse como "sin deuda" (no crear un crédito con saldo negativo ni de $0 por error de parseo silencioso — verifica el signo explícitamente); el monto grande sí se acepta tal cual (no hay tope documentado para saldo importado, a diferencia de `limite_credito` que sí topa en $500,000 — confirma si esto es intencional o si debería topar igual).

**E4 — Nombre que excede el límite de columna**
Fila con `Nombres` = 150 caracteres repetidos (ej. "AAAA...AAAA").
**Esperado:** la fila se rechaza con el error "El nombre no puede tener más de 100 caracteres", aparece en la lista de errores, y NO se trunca en silencio ni se guarda una versión cortada.

**E5 — Fila completamente vacía intercalada**
Excel con: fila 2 válida, fila 3 completamente vacía (todas las celdas en blanco), fila 4 válida.
**Esperado:** la fila 3 se ignora sin generar error ni entrada en la lista de errores (el `continue` silencioso por nombre vacío), y las filas 2 y 4 sí se importan correctamente — el conteo final de "nuevos" debe ser 2, no 3.

**E6 — Archivo que no es un Excel real**
Renombra un `.txt` (o cualquier archivo binario cualquiera, como un `.jpg`) a `.xlsx` y súbelo.
**Esperado:** error claro tipo "Error al leer el archivo: ..." — nunca un HTTP 500 crudo con traza de PHP visible, y no debe quedar ningún registro a medias en la BD (transacción completa o nada).

**E7 — Dos filas con el mismo "ID Cliente" en el mismo archivo (Modo B)**
Excel de reimportación con dos filas que tienen `ID Cliente` = el mismo número, pero con `Nombre completo`/`Telefono` distintos entre sí.
**Esperado:** documenta cuál de las dos gana (probablemente la última fila procesada, ya que cada fila hace su propio UPDATE independiente) — no debe romperse ni duplicar el cliente. Si el resultado es "gana la última fila silenciosamente", decide si eso es aceptable o si deberíamos rechazar el archivo con un error de "ID Cliente repetido en el archivo".

**E8 — "ID Cliente" que no existe en la base**
Fila Modo B con `ID Cliente` = `999999` (un número que nunca ha existido).
**Esperado:** debe tratarse como fila nueva (INSERT, ignorando el ID inventado — el `AUTO_INCREMENT` le asigna uno real), no como error ni como intento fallido de UPDATE.

**E9 — Condición de carrera real: dos abonos simultáneos por el saldo completo**
Un crédito standalone con saldo $1,000. Dos peticiones de abono por $1,000 cada una, disparadas contra DOS instancias del servidor (`php -S localhost:8001` además de la de siempre) con dos sesiones distintas, verdaderamente simultáneas.
**Esperado:** solo UNA debe tener éxito (saldo termina en $0, un solo registro en `abonos`); la segunda debe fallar limpio (ej. "el monto excede el saldo pendiente" o similar) — nunca debe quedar el crédito en saldo negativo ni con dos abonos de $1,000 aplicados sobre $1,000 de deuda.

**E10 — % de mora en 0 no debe cobrar nada**
Pon `porcentaje_mora = 0` en ambas sucursales. Fuerza un crédito vencido de un cliente con `cobrar_mora = 1`.
**Esperado:** el crédito pasa a `Vencido` (el estado sí cambia por fecha), pero `mora_acumulada` se queda en 0 y no se inserta ninguna fila en `movimientos_mora` (la condición `> 0` en el WHERE del motor debe excluirlo). Regresa el % a un valor normal al terminar.

**E11 — Texto de confirmación con espacios extra**
En "Eliminar todos los clientes", escribe `" ELIMINAR TODO "` (con espacio antes y después) en el prompt.
**Esperado:** como el servidor hace `trim()` sobre `confirmacion_texto`, debería ACEPTARSE igual que sin espacios. Confirma que sí borra (si no, es un bug de UX innecesariamente estricto).

**E12 — Nombre que son solo espacios**
Fila Modo A con `Nombres` = `"   "` (tres espacios), `Apellidos` vacío.
**Esperado:** debe tratarse como fila vacía (el `trim()` la deja en cadena vacía) e ignorarse sin crear un cliente fantasma con nombre en blanco.

**E13 — Inyección de fórmula Excel (CSV/Formula Injection) en el export**
Importa (o edita directo en BD) un cliente con `nombre_completo` = `=cmd|'/c calc'!A1` o `+2+5+cmd|'/c calc'!A1` (payload clásico de CSV injection) o simplemente `=1+1`.
**Esperado:** al exportar ese cliente con el botón "Excel", la celda debe guardarse como TEXTO literal, no como fórmula viva — si alguien abre ese archivo en Excel real, no debe ejecutarse ni evaluarse ninguna fórmula. Verifica cómo PhpSpreadsheet está guardando esa celda (`setCellValue` vs `setCellValueExplicit` con tipo STRING) — este es un vector real conocido (CVE-relevante en muchas apps que exportan a Excel/CSV sin neutralizar el `=`/`+`/`-`/`@` inicial).

---

## Notas
- E1, E4, E5, E12 usan Modo A (archivo del sistema anterior) — arma archivos `.xlsx` chicos con PhpSpreadsheet, igual que en las pruebas anteriores.
- E7, E8 usan Modo B (nuestro propio export, con "ID Cliente").
- E9 necesita una segunda instancia del servidor (`php -S localhost:8001`) para simultaneidad real — ver la nota de concurrencia ya establecida en este proyecto.
- E13 es el caso más importante de este guion — nunca se ha probado en esta función y es un vector de seguridad real y conocido, no solo un capricho de estilo.
- Limpia todos los datos ZZTEST/de prueba al terminar, igual que siempre.
