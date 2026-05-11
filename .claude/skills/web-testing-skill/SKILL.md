---
name: web-testing
description: >
  Skill para analizar y probar sistemas web hechos en PHP, JavaScript y CSS. 
  Úsala SIEMPRE que el usuario pida: analizar flujo de un sistema, encontrar bugs, 
  proponer pruebas manuales, generar casos de prueba, detectar vulnerabilidades, 
  revisar responsive, validar frontend y backend, revisar rendimiento o sugerir 
  mejoras de código. También aplica cuando el usuario diga frases como "revisa mi 
  sistema", "encuentra errores", "prueba este código", "qué le falta a mi proyecto", 
  "es seguro esto", "ayúdame a probar", "genera casos de prueba", o cuando comparta 
  archivos .php, .js, .css para revisión. No esperes que el usuario use la palabra 
  "testing" — si comparte código de un sistema web y quiere retroalimentación, usa 
  esta skill.
---

# Web Testing Skill — PHP / JavaScript / CSS

## Propósito
Analizar sistemas web completos o parciales (PHP, JS, CSS) para:
1. Mapear el flujo del sistema
2. Detectar bugs y errores potenciales
3. Detectar vulnerabilidades de seguridad
4. Revisar validaciones (frontend y backend)
5. Revisar compatibilidad responsive
6. Generar casos de prueba manuales y automatizables
7. Sugerir mejoras de rendimiento y organización

---

## Flujo de trabajo al recibir código

### PASO 1 — Entender el contexto
Antes de analizar, identifica:
- ¿Qué módulo o funcionalidad es? (login, CRUD, carrito, reportes, etc.)
- ¿Hay base de datos involucrada?
- ¿Es código completo o fragmento?
- ¿Qué roles de usuario existen?

Si el usuario no lo dice, infierelo del código y confirma brevemente.

### PASO 2 — Análisis del flujo
Describe en pasos simples qué hace el código de principio a fin.
Formato sugerido:
```
Flujo detectado:
1. El usuario llena el formulario X
2. Se envía por POST a archivo.php
3. Se valida Y en el backend
4. Se guarda en tabla Z
5. Se redirige a página W
```

Señala si el flujo tiene saltos lógicos, pasos faltantes o caminos no manejados.

### PASO 3 — Bugs y errores potenciales
Revisa y reporta (con número de línea si es posible):
- Variables no inicializadas o mal usadas
- Lógica incorrecta (condiciones invertidas, casos no cubiertos)
- Errores de tipo (string vs int, null sin verificar)
- Consultas SQL mal construidas
- Manejo de errores ausente (try/catch, isset, empty)
- Redirecciones o respuestas faltantes tras acciones críticas

### PASO 4 — Seguridad
Revisa estos puntos clave (ver `references/seguridad.md` para detalle):
- [ ] SQL Injection — ¿Se usan prepared statements o PDO?
- [ ] XSS — ¿Se escapa el output con htmlspecialchars()?
- [ ] CSRF — ¿Hay tokens en formularios POST?
- [ ] Exposición de datos sensibles — ¿Errores con info técnica al usuario?
- [ ] Autenticación — ¿Se verifica sesión en cada página protegida?
- [ ] Contraseñas — ¿Se usa password_hash() / password_verify()?
- [ ] Subida de archivos — ¿Se valida tipo y tamaño?

### PASO 5 — Validaciones frontend y backend
Verifica que:
- Los campos requeridos se validan en JS Y en PHP (nunca solo en uno)
- Los mensajes de error son claros para el usuario
- Las validaciones de formato (email, teléfono, fechas) son correctas
- Los límites de longitud son consistentes entre frontend y BD

### PASO 6 — Responsive (CSS)
Si hay CSS o HTML, revisa:
- ¿Se usan media queries?
- ¿Hay elementos con ancho fijo que rompen en móvil?
- ¿Las tablas son scrollables en pantallas pequeñas?
- ¿Los botones tienen tamaño adecuado para touch?
- ¿Se usa viewport meta tag?

### PASO 7 — Casos de prueba
Genera una tabla con casos de prueba manuales. Lee `references/casos-prueba.md` 
para la estructura completa.

Formato mínimo:
| # | Descripción | Datos de entrada | Resultado esperado | Resultado real | Estado |
|---|-------------|-----------------|-------------------|----------------|--------|
| 1 | Login válido | user: admin, pass: 1234 | Redirige al dashboard | — | Pendiente |
| 2 | Login con contraseña incorrecta | user: admin, pass: mal | Mensaje de error | — | Pendiente |

Incluir siempre: casos felices, casos negativos, casos borde (vacío, máximo, caracteres especiales).

### PASO 8 — Rendimiento y organización
Sugiere mejoras en:
- Consultas N+1 (loops con queries adentro)
- Código repetido que puede extraerse a funciones
- Archivos muy largos que conviene separar
- Uso innecesario de SELECT * 
- Falta de índices en columnas usadas en WHERE/JOIN
- JS bloqueante en el `<head>` sin defer/async

---

## Formato de salida recomendado

Organiza tu respuesta con estas secciones claramente separadas:

```
## 🗺️ Flujo del sistema
...

## 🐛 Bugs encontrados
...

## 🔒 Seguridad
...

## ✅ Validaciones
...

## 📱 Responsive
...

## 🧪 Casos de prueba
...

## ⚡ Rendimiento y mejoras
...

## 📋 Resumen de prioridades
Alta prioridad: ...
Media prioridad: ...
Baja prioridad: ...
```

Usa emojis para que sea fácil de escanear visualmente.
Sé específico: indica líneas, nombres de variables y ejemplos de código corregido.

---

## Archivos de referencia
- `references/seguridad.md` — Checklist detallado de seguridad con ejemplos de código PHP seguro vs inseguro
- `references/casos-prueba.md` — Plantilla extendida para casos de prueba con categorías por módulo
