# Plantilla de Casos de Prueba — Sistemas Web PHP/JS

## Estructura de una tabla de pruebas

| # | Módulo | Tipo | Descripción | Datos de entrada | Resultado esperado | Resultado real | Estado |
|---|--------|------|-------------|-----------------|-------------------|----------------|--------|
| 1 | Login | Positivo | Acceso con credenciales válidas | usuario: admin / pass: 1234 | Redirige al dashboard | — | ⬜ Pendiente |

**Tipos de prueba:**
- **Positivo** — Flujo normal exitoso
- **Negativo** — Entrada incorrecta o acción no permitida
- **Borde** — Valores límite (vacío, máximo permitido, caracteres especiales)
- **Seguridad** — Intento de ataque o acceso no autorizado
- **UI** — Comportamiento visual / responsive

**Estados:** ⬜ Pendiente | ✅ Pasó | ❌ Falló | ⚠️ Parcial

---

## Casos de prueba por módulo común

### Módulo: Login / Autenticación
| # | Tipo | Descripción | Entrada | Esperado |
|---|------|-------------|---------|----------|
| 1 | Positivo | Login con credenciales correctas | user válido + pass correcta | Redirige al panel |
| 2 | Negativo | Login con contraseña incorrecta | user válido + pass mal | Mensaje "Credenciales incorrectas" |
| 3 | Negativo | Usuario inexistente | user inventado + cualquier pass | Mensaje de error genérico |
| 4 | Borde | Campos vacíos | Sin datos | Validación indica campos requeridos |
| 5 | Borde | SQL en usuario | `' OR '1'='1` | No permite acceso (rechaza o error genérico) |
| 6 | Seguridad | Acceso directo a página protegida sin sesión | URL directa a dashboard.php | Redirige a login |
| 7 | Borde | Usuario con espacios | " admin " (con espacios) | Define comportamiento (trim o error) |

### Módulo: Registro de usuario
| # | Tipo | Descripción | Entrada | Esperado |
|---|------|-------------|---------|----------|
| 1 | Positivo | Registro con todos los datos válidos | Datos completos y válidos | Usuario creado, mensaje de éxito |
| 2 | Negativo | Email duplicado | Email ya registrado | Mensaje "Email ya en uso" |
| 3 | Negativo | Email inválido | "notvalid@" | Error de validación |
| 4 | Borde | Contraseña muy corta | "abc" | Error de validación |
| 5 | Borde | Campos obligatorios vacíos | Formulario en blanco | Todos los errores indicados |
| 6 | Seguridad | XSS en nombre | `<script>alert(1)</script>` | Se muestra como texto, no ejecuta |
| 7 | Seguridad | HTML en campos | `<b>hola</b>` | Se escapa o se muestra como texto |

### Módulo: CRUD (Crear / Leer / Actualizar / Eliminar)
| # | Tipo | Descripción | Entrada | Esperado |
|---|------|-------------|---------|----------|
| 1 | Positivo | Crear registro válido | Datos completos | Registro aparece en la lista |
| 2 | Positivo | Editar registro existente | Cambio de un campo | Cambio reflejado inmediatamente |
| 3 | Positivo | Eliminar registro | ID válido | Registro desaparece de la lista |
| 4 | Negativo | Crear con campos requeridos vacíos | Sin datos | Validación activa |
| 5 | Negativo | Editar ID inexistente | ID: 99999 | Error controlado (no crash) |
| 6 | Negativo | Eliminar ID inexistente | ID: 99999 | Error controlado |
| 7 | Borde | Texto muy largo en campos | 1000+ caracteres | Truncado o error de validación |
| 8 | Seguridad | Inyección SQL en búsqueda | `'; DROP TABLE productos; --` | No ejecuta, búsqueda segura |

### Módulo: Búsqueda / Filtros
| # | Tipo | Descripción | Entrada | Esperado |
|---|------|-------------|---------|----------|
| 1 | Positivo | Búsqueda con resultado | Término que existe | Lista con resultados |
| 2 | Positivo | Filtro por categoría | Categoría válida | Resultados filtrados correctamente |
| 3 | Negativo | Búsqueda sin resultados | Término inexistente | Mensaje "Sin resultados" (no pantalla vacía) |
| 4 | Borde | Búsqueda vacía | Sin texto | Muestra todos o mensaje de instrucción |
| 5 | Borde | Caracteres especiales | "café", "niño", "%" | Sin error, resultado correcto |

### Módulo: Formularios (general)
| # | Tipo | Descripción | Entrada | Esperado |
|---|------|-------------|---------|----------|
| 1 | Borde | Submit múltiple | Doble clic en botón enviar | Solo se procesa una vez |
| 2 | Borde | Números negativos en cantidades | -5 | Validación rechaza o error |
| 3 | Borde | Decimales en campos enteros | 3.5 | Validación o redondeo definido |
| 4 | UI | Responsive en móvil | Viewport 375px | Formulario usable sin scroll horizontal |
| 5 | UI | Tab order | Navegar con Tab | Orden lógico de campos |

### Módulo: Responsive / UI
| # | Tipo | Descripción | Viewport | Esperado |
|---|------|-------------|---------|----------|
| 1 | UI | Menú en móvil | 375px | Menú hamburguesa o colapsado |
| 2 | UI | Tablas anchas | 375px | Scroll horizontal o tabla adaptada |
| 3 | UI | Imágenes | Cualquier tamaño | No desbordan su contenedor |
| 4 | UI | Botones touch | 375px | Mínimo 44x44px para toque cómodo |
| 5 | UI | Texto legible | 375px | Sin texto cortado o solapado |

---

## Cómo documentar el resultado real

Cuando ejecutes cada caso, llena la columna "Resultado real" con lo que realmente pasó:
- Si pasó exactamente como se esperaba → escribe "Igual al esperado" y marca ✅
- Si falló → describe qué pasó diferente y marca ❌
- Si pasó parcialmente → describe qué funcionó y qué no, marca ⚠️

---

## Prioridad de corrección sugerida

| Prioridad | Tipo de falla |
|-----------|--------------|
| 🔴 Alta | Vulnerabilidades de seguridad, pérdida de datos, crashes |
| 🟡 Media | Validaciones faltantes, errores de lógica, flujos incompletos |
| 🟢 Baja | Mejoras de UI, mensajes poco claros, sugerencias de rendimiento |
