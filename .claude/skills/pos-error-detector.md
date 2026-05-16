---
name: pos-error-detector
description: >
  Detecta errores en código de sistemas de Punto de Venta (POS) e inventario.
  Úsala SIEMPRE que el usuario pegue código PHP, SQL, JavaScript o HTML/CSS
  relacionado con ventas, caja, stock, productos, clientes, créditos,
  devoluciones, transferencias, paquetes o cualquier módulo de un sistema POS.
  También úsala cuando el usuario diga que algo "no funciona", "falla", "no
  descuenta", "no guarda", "no muestra" u otro síntoma en su sistema de
  inventario o punto de venta. Activa esta skill incluso si el usuario solo
  pega un fragmento de código sin explicar el problema.
---

# POS Error Detector

Skill especializada en detectar errores en sistemas de Punto de Venta e
Inventario. Cubre PHP + PDO, SQL (MySQL), JavaScript y HTML/CSS.

## Contexto del dominio POS

Antes de analizar código, ten en cuenta estas reglas de negocio críticas que
generan errores silenciosos muy comunes en sistemas POS:

### Reglas de stock
- El stock se **descuenta al vender** y se **regresa al cancelar o devolver**
- En ventas de domicilio: el stock baja al **crear** la venta y regresa si se **cancela**
- Las transferencias mueven stock **solo al marcarse como Entregada** (no al aprobar)
- Nunca descontar stock dos veces ni omitir el retorno al cancelar

### Reglas de crédito
- El crédito solo se permite si `credito_autorizado = 1` en la tabla clientes
- Los abonos deben actualizar el saldo pendiente y marcar `Liquidado` cuando llega a 0
- Las devoluciones deben ajustar el crédito si la venta original fue a crédito

### Reglas de caja
- Puede haber múltiples cajas abiertas simultáneamente en la misma sucursal
- El número de turno = cajas actualmente abiertas en la sucursal + 1 (no es histórico)

### Reglas de productos y paquetes
- Los productos tienen `sucursal_id` (son por sucursal)
- Los paquetes son **globales** (sin `sucursal_id`)
- Los clientes son **globales** (sin `sucursal_id`)
- En JavaScript: todos los `producto_id` deben convertirse con `parseInt()` antes de comparar

---

## Proceso de detección

Cuando el usuario pegue código, analiza en este orden:

### 1. Errores críticos de lógica de negocio POS
Busca primero los errores que corrompen datos:
- Stock que se descuenta o regresa en el momento incorrecto
- Crédito otorgado sin verificar `credito_autorizado`
- Transferencias que mueven stock antes de `Entregada`
- Número de turno calculado como máximo histórico en vez de conteo activo
- Paquetes o clientes filtrados por `sucursal_id` cuando no deberían

### 2. Errores de PHP + PDO
- Consultas con concatenación de strings en vez de `bindParam`/`bindValue` → SQL injection
- Falta de `beginTransaction()` / `commit()` / `rollBack()` en operaciones que modifican múltiples tablas
- `fetch()` usado donde debería ir `fetchAll()` o viceversa
- `PDO::FETCH_ASSOC` ausente cuando se necesitan arrays asociativos
- Falta de verificación de `rowCount()` después de UPDATE/DELETE críticos
- Variables `$_POST` / `$_GET` sin sanitizar
- Sesión no verificada con `verificarSesion()` / `verificarRol()` al inicio del archivo
- Conexión a BD no importada (`require_once '../config/database.php'`)

### 3. Errores de SQL (MySQL)
- JOINs faltantes que causan datos incompletos
- WHERE sin índice en tablas grandes (productos, ventas, movimientos)
- Uso de `=` con NULL en vez de `IS NULL` / `IS NOT NULL`
- GROUP BY incompleto cuando se usan funciones de agregación
- Subconsultas correlated ineficientes que deberían ser JOINs
- Falta de `FOR UPDATE` en transacciones que leen y luego modifican stock
- Columnas ambiguas sin alias de tabla en JOINs

### 4. Errores de JavaScript (frontend POS)
- Comparación `==` entre `producto_id` (string del DOM) y entero → siempre falso
- Falta de `parseInt()` al leer IDs de atributos HTML o `dataset`
- `addEventListener` duplicado en botones que se regeneran dinámicamente
- Carrito que no limpia estado al abrir nueva venta
- Scanner de código de barras: no detecta `Enter` (keyCode 13) o no diferencia lectura rápida vs escritura manual
- `fetch()` sin manejo de errores (`catch`)
- Variables de totales calculadas con strings en vez de números

### 5. Errores de HTML/CSS
- Formularios con `action` apuntando a archivo incorrecto
- Inputs sin `name` que no envían datos al servidor
- Tablas de inventario sin `id` o `data-*` que JavaScript necesita para operar
- Modales que no limpian sus campos al cerrarse (datos de venta anterior visibles)
- Botones de acción con clases CSS incorrectas (editar/eliminar/activar/desactivar)

---

## Formato de reporte

Para cada error encontrado, reporta así:

```
🔴 [CRÍTICO] / 🟡 [ADVERTENCIA] / 🔵 [SUGERENCIA]
Archivo/sección: [nombre o línea aproximada]
Problema: [descripción clara en español]
Por qué es grave en un POS: [impacto en stock, dinero, datos]
Evidencia en el código: [fragmento exacto problemático]
```

Al final del reporte incluye un resumen:
- Total de errores críticos 🔴
- Total de advertencias 🟡
- Total de sugerencias 🔵
- Orden de prioridad para resolverlos

### Niveles de severidad

| Nivel | Cuándo usarlo |
|-------|---------------|
| 🔴 CRÍTICO | Corrompe datos: stock incorrecto, dinero mal calculado, acceso sin autenticación, SQL injection |
| 🟡 ADVERTENCIA | No rompe inmediatamente pero puede fallar: falta rollback, query ineficiente, comparación débil |
| 🔵 SUGERENCIA | Mejora de calidad: legibilidad, índices, UX del POS |

---

## Notas especiales para este sistema

- Stack: PHP puro + PDO, HTML/CSS/JS puro, MySQL en Railway
- Auth: `verificarSesion()` y `verificarRol()` de `includes/auth.php`
- Info de sucursal: `includes/topbar_info.php`
- Color principal: `#14ace7` (no es un error si aparece hardcoded, es intencional)
- Los archivos del rol Inventario/Cajero reutilizan los de cajero e inventario — no duplican lógica
