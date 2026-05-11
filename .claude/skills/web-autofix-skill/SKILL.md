---
name: web-autofix
description: >
  Skill para corregir automáticamente errores, bugs, vulnerabilidades y problemas
  detectados en sistemas web PHP, JavaScript y CSS. Úsala SIEMPRE que el usuario
  pida: arreglar el proyecto, corregir los errores, aplicar los fixes, solucionar
  los problemas del sistema, corregir vulnerabilidades, arreglar bugs, o cuando
  diga frases como "arréglame todo", "corrige los errores", "aplica los cambios",
  "soluciona lo que encontraste", "arregla mi proyecto", "fix everything", o
  cualquier variante que implique modificar archivos para corregir problemas ya
  detectados. También aplica cuando el usuario comparta resultados de un análisis
  previo y pida que se corrijan. No esperes que el usuario sea técnico — si pide
  que algo se arregle en su sistema web, usa esta skill.
---

# Web AutoFix Skill — PHP / JavaScript / CSS

## Propósito
Leer los problemas detectados (por la skill web-testing u otro análisis), corregir
automáticamente los archivos del proyecto, y verificar que los cambios funcionan
correctamente sin introducir nuevos errores.

---

## Flujo de trabajo obligatorio

### PASO 1 — Recopilar problemas a corregir
Antes de tocar cualquier archivo:
- Lee el reporte de la skill `web-testing` si está disponible en el contexto
- Si no hay reporte previo, ejecuta primero un análisis rápido del proyecto
- Agrupa los problemas por archivo y por prioridad:
  - 🔴 **Crítico** — seguridad, crashes, pérdida de datos (corregir primero)
  - 🟡 **Medio** — validaciones, lógica incorrecta, errores de flujo
  - 🟢 **Bajo** — rendimiento, organización, mensajes de UI

Muestra al usuario la lista de fixes que vas a aplicar ANTES de hacerlo:
```
Voy a aplicar los siguientes arreglos:
🔴 login.php — Agregar prepared statements para evitar SQL injection
🔴 registro.php — Usar password_hash() en lugar de MD5
🟡 productos.php — Agregar validación de campos vacíos
🟢 estilos.css — Agregar media queries para móvil
¿Procedo?
```

### PASO 2 — Hacer backup mental
Antes de editar cada archivo, lee su contenido completo para entender el contexto.
Nunca edites a ciegas. Si un arreglo puede romper otra parte del sistema, adviértelo.

### PASO 3 — Aplicar correcciones en orden de prioridad

Aplica los fixes de mayor a menor prioridad. Para cada corrección:

1. **Lee el archivo completo**
2. **Aplica el cambio mínimo necesario** — no reescribas lo que no está roto
3. **Documenta el cambio** con un comentario breve en el código:
   ```php
   // [AUTOFIX] Cambiado a prepared statement para prevenir SQL injection
   ```
4. **Guarda el archivo**
5. **Reporta qué cambió** en lenguaje simple

#### Correcciones de seguridad — ver `references/fixes-seguridad.md`
- SQL Injection → prepared statements / PDO
- XSS → htmlspecialchars() en outputs
- CSRF → tokens en formularios POST
- Contraseñas → password_hash() / password_verify()
- Sesiones → verificación al inicio de cada página protegida

#### Correcciones de validación — ver `references/fixes-validaciones.md`
- Campos requeridos sin validar → agregar isset(), empty(), filter_var()
- Validación solo en JS → agregar equivalente en PHP
- Tipos de dato → intval(), floatval(), htmlspecialchars()

#### Correcciones de lógica
- Condiciones invertidas → corregir la lógica
- Casos no manejados → agregar else / default / manejo de error
- Redirecciones faltantes → agregar header() + exit() tras acciones críticas

#### Correcciones de rendimiento
- SELECT * → reemplazar con columnas específicas
- Queries en loops → extraer fuera del loop o usar JOIN
- JS en `<head>` sin defer → agregar atributo defer

#### Correcciones responsive
- Ancho fijo → cambiar a max-width o porcentaje
- Tablas → envolver en div con overflow-x: auto
- Agregar media queries donde falten

### PASO 4 — Verificación después de cada archivo

Después de corregir cada archivo, verifica:

**Sintaxis PHP:**
```bash
php -l archivo.php
```
Si hay error de sintaxis, corrígelo antes de continuar.

**Consistencia:**
- ¿El fix rompe alguna otra parte del sistema?
- ¿Las variables que se modificaron se usan igual en otros archivos?
- ¿Las tablas/columnas de BD referenciadas existen?

**Lógica:**
- ¿El flujo sigue teniendo sentido después del cambio?
- ¿Los mensajes de error/éxito siguen apareciendo correctamente?

### PASO 5 — Pruebas funcionales por módulo

Después de corregir todos los archivos de un módulo, describe las pruebas
que el usuario debe ejecutar para confirmar que funciona:

```
✅ Pruebas para confirmar que login.php funciona:
1. Intenta entrar con usuario y contraseña correctos → debe redirigir al panel
2. Intenta entrar con contraseña incorrecta → debe mostrar mensaje de error
3. Deja los campos vacíos y envía → debe pedir que llenes los campos
4. Escribe ' OR '1'='1 en el usuario → no debe permitir acceso
```

Usa lenguaje simple, sin términos técnicos en las pruebas.

### PASO 6 — Reporte final

Al terminar todos los fixes, genera un reporte así:

```
## ✅ Reporte de correcciones aplicadas

### Archivos modificados:
| Archivo | Cambios aplicados | Estado |
|---------|------------------|--------|
| login.php | Prepared statements, verificación de sesión | ✅ Corregido |
| registro.php | password_hash(), validación de email | ✅ Corregido |
| productos.php | Validación de campos, htmlspecialchars() | ✅ Corregido |

### Problemas resueltos:
- 🔴 3 vulnerabilidades de seguridad corregidas
- 🟡 5 validaciones agregadas
- 🟢 2 mejoras de rendimiento aplicadas

### Pruebas pendientes (hazlas tú):
1. ...
2. ...

### Advertencias:
- [Si algo no se pudo corregir automáticamente y requiere intervención manual]
```

---

## Reglas importantes

- **Nunca borres funcionalidad existente** que esté funcionando bien
- **Nunca cambies el diseño visual** a menos que sea para arreglar responsive
- **Si un arreglo es muy arriesgado**, adviértelo y pide confirmación antes de aplicarlo
- **Si hay algo que no puedes arreglar automáticamente** (ej. requiere cambios en BD), explícalo claramente y da instrucciones manuales
- **Comenta cada cambio** en el código con `// [AUTOFIX]` para que el usuario sepa qué se tocó
- **Prioridad absoluta: no romper lo que ya funciona**

---

## Archivos de referencia
- `references/fixes-seguridad.md` — Código de ejemplo para cada tipo de fix de seguridad
- `references/fixes-validaciones.md` — Patrones de validación PHP y JS listos para usar
