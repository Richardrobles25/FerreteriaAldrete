---
name: pos-error-resolver
description: >
  Resuelve y aplica fixes directamente en código de sistemas de Punto de Venta
  (POS) e inventario. Úsala SIEMPRE que el usuario quiera corregir código PHP,
  SQL, JavaScript o HTML/CSS de módulos de ventas, caja, stock, productos,
  clientes, créditos, devoluciones, transferencias o paquetes. Actívala cuando
  el usuario diga "corrígelo", "arréglalo", "aplica el fix", "resuélvelo" o
  cuando pida el código corregido de un módulo POS. También úsala si ya se
  detectaron errores (con pos-error-detector) y el usuario quiere que se
  apliquen las correcciones. Siempre aplica el fix directo en el archivo,
  no solo lo describes.
---

# POS Error Resolver

Skill especializada en **aplicar correcciones directamente** en código de
sistemas de Punto de Venta e Inventario. No solo describe el fix — lo
implementa en el archivo.

## Contexto del dominio POS

Las mismas reglas de negocio del detector aplican aquí. Tenlas siempre
presentes al reescribir código:

### Invariantes que NUNCA debes romper al hacer un fix
1. **Stock**: Solo se descuenta en venta confirmada. Solo se regresa en
   cancelación o devolución. En transferencias, solo al marcar `Entregada`.
2. **Transacciones**: Cualquier operación que toque stock + venta + movimientos
   DEBE estar en `beginTransaction()` / `commit()` / `rollBack()`.
3. **Crédito**: Verificar `credito_autorizado = 1` antes de permitir venta a crédito.
4. **IDs en JS**: Siempre `parseInt(producto_id)` antes de comparar o indexar.
5. **Auth**: Todo archivo PHP debe iniciar con `verificarSesion()` y `verificarRol()`.
6. **Paquetes y clientes**: Sin `sucursal_id` en sus queries. Productos sí lo tienen.

---

## Proceso de resolución

### Paso 1 — Entender el fix necesario
Antes de tocar el archivo:
- Identifica exactamente qué líneas cambian
- Confirma que el fix no rompe ninguna invariante del dominio POS
- Si el fix toca stock, crédito o caja: verifica que la transacción PDO esté completa

### Paso 2 — Aplicar el fix en el archivo

Usa `str_replace` para edits quirúrgicos cuando el cambio es pequeño y localizado.
Reescribe el archivo completo solo si hay múltiples errores dispersos o la estructura está muy comprometida.

**Reglas de edición segura:**
- Nunca eliminar validaciones de sesión/rol existentes
- Nunca quitar `rollBack()` de un bloque catch
- Nunca cambiar nombres de columnas sin verificar que existen en el esquema descrito
- Nunca agregar `sucursal_id` a queries de paquetes o clientes
- Nunca quitar `parseInt()` de comparaciones de IDs en JavaScript

### Paso 3 — Verificar el fix aplicado

Después de editar, revisa el archivo resultante y confirma:

```
✅ Fix aplicado en: [nombre del archivo y línea aproximada]
✅ Invariantes preservadas: [lista las que aplican al fix]
✅ Sin efectos secundarios en: [módulos relacionados si aplica]
```

Si hay algo que no puedes verificar sin correr el código, indícalo claramente.

---

## Patrones de fix por tipo de error

### PHP + PDO

**SQL injection → bindParam**
```php
// ❌ Antes
$stmt = $pdo->query("SELECT * FROM productos WHERE id = " . $_POST['id']);

// ✅ Después
$stmt = $pdo->prepare("SELECT * FROM productos WHERE id = :id");
$stmt->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
$stmt->execute();
```

**Falta de transacción en operación de venta**
```php
// ✅ Patrón correcto para cualquier operación que toque stock + venta
try {
    $pdo->beginTransaction();
    // 1. Insertar venta
    // 2. Insertar detalle_venta
    // 3. Descontar stock (UPDATE productos SET stock = stock - :cantidad)
    // 4. Registrar movimiento
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
```

**Falta de auth al inicio del archivo**
```php
// ✅ Siempre al inicio de cualquier archivo PHP del sistema
require_once '../includes/auth.php';
verificarSesion();
verificarRol(['cajero']); // o el rol que corresponda
```

### SQL

**Transferencia que mueve stock prematuramente**
```sql
-- ✅ Solo mover stock cuando status = 'Entregada'
UPDATE productos p
JOIN transferencia_detalle td ON td.producto_id = p.id
SET p.stock = p.stock + td.cantidad
WHERE td.transferencia_id = :id
  AND (SELECT status FROM transferencias WHERE id = :id) = 'Entregada';
```

**NULL comparison**
```sql
-- ❌ Antes
WHERE fecha_cierre = NULL

-- ✅ Después  
WHERE fecha_cierre IS NULL
```

### JavaScript

**parseInt en comparaciones de carrito**
```javascript
// ❌ Antes — falla silenciosamente (string vs int)
if (item.producto_id === producto_id) { ... }

// ✅ Después
if (parseInt(item.producto_id) === parseInt(producto_id)) { ... }
```

**Scanner de código de barras — detección de Enter**
```javascript
// ✅ Patrón correcto para scanner físico
inputScanner.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.keyCode === 13) {
        e.preventDefault();
        buscarProductoPorCodigo(this.value.trim());
        this.value = '';
    }
});
```

**fetch sin manejo de errores**
```javascript
// ✅ Siempre con catch
fetch('procesar_venta.php', {
    method: 'POST',
    body: formData
})
.then(res => res.json())
.then(data => { /* manejar respuesta */ })
.catch(err => {
    console.error('Error en venta:', err);
    mostrarAlerta('Error de conexión. Intenta de nuevo.', 'danger');
});
```

### HTML/CSS

**Modal que no limpia campos**
```javascript
// ✅ Limpiar modal al cerrarse
document.getElementById('modalVenta').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formVenta').reset();
    carritoItems = [];
    actualizarCarrito();
});
```

---

## Casos especiales del sistema Ferretería Aldrete

### Módulo nuevaVenta (el más complejo)
Al tocar este módulo ten cuidado extra con:
- El estado del carrito en memoria (array JS) debe sincronizarse con el DOM
- Los paquetes se detectan comparando `producto_id` del carrito vs los del paquete — requiere `parseInt` en ambos lados
- El scanner alterna entre modo búsqueda normal y modo scanner con un botón — no mezclar los dos flujos
- El ticket se genera del estado final de la venta — si reescribes la lógica de completar venta, no perder los datos del ticket

### Número de turno de caja
```php
// ✅ Correcto: contar cajas activas en la sucursal
$stmt = $pdo->prepare("
    SELECT COUNT(*) + 1 as turno 
    FROM cajas 
    WHERE sucursal_id = :sucursal_id 
      AND estado = 'abierta'
");
// ❌ Incorrecto: MAX(turno) + 1 acumula históricamente
```

### Rol Inventario/Cajero
- No crear archivos duplicados. Este rol reutiliza los archivos de cajero e inventario
- Solo tiene su propio dashboard — cualquier fix en cajero o inventario se refleja automáticamente

---

## Formato de confirmación final

Después de aplicar todos los fixes, muestra:

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✅ FIXES APLICADOS — [nombre del módulo]
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. [Descripción del fix 1] → línea ~XX
2. [Descripción del fix 2] → línea ~XX
...

⚠️  Requiere prueba manual:
- [Qué probar y cómo verificar que funciona]

🔗 Módulos que podrían verse afectados:
- [Lista si aplica, o "Ninguno" si el fix es aislado]
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
```
