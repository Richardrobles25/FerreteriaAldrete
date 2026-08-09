# Sistema Ferretería Aldrete — Contexto Completo

> Última actualización: 2026-07-20

## 1. Descripción General

Sistema web de punto de venta (POS), inventario y recursos humanos para **Ferretería Aldrete**, desarrollado en PHP con PDO/MySQL. Soporta múltiples sucursales, 4 roles de usuario, control de inventario, créditos a clientes con mora automática, gastos, nómina de empleados, transferencias entre sucursales y generación de reportes en PDF/Excel.

- **Servidor de producción:** DigitalOcean Droplet — `146.190.40.160` (SSH: `ssh root@146.190.40.160`)
- **Base de datos:** MySQL 8.0.45, BD `ferreteria_aldrete`. En el servidor corre en `127.0.0.1:3306`; acepta también conexión remota directa a `146.190.40.160:3306`.
- **Zona horaria:** `America/Mazatlan` (UTC-7)
- **Desarrollo local:** XAMPP (`php -S localhost:8000` o Apache de XAMPP) con MySQL local (`root` sin contraseña)
- **Rama de trabajo actual:** `nuevoCatalogo`
- **Módulo principal de desarrollo:** `cajeroInventario/` — es el que se modifica primero; `cajero/` e `inventario/` los ajusta el usuario por separado después.

---

## 2. Infraestructura y Configuración

### Conexión a BD (`config/database.php`)
El archivo trae **ambos bloques**, con el de LOCAL activo por defecto y el de SERVIDOR comentado — hay que alternar manualmente según dónde se despliegue:
```php
/*── SERVIDOR (DigitalOcean) ─────────────────────────────
$host     = '127.0.0.1';
$port     = '3306';
$db       = 'ferreteria_aldrete';
$user     = 'ferreteria';
$password = 'Ferreteria2024$';
//───────────────────────────────────────────────────────*/

// ── LOCAL (XAMPP) ─────────────────────────────────────
$host     = '127.0.0.1';
$port     = '3306';
$db       = 'ferreteria_aldrete';
$user     = 'root';
$password = ''; // XAMPP root sin contraseña
```

> ⚠️ Antes de subir cambios al servidor hay que recordar descomentar el bloque SERVIDOR y comentar el LOCAL (o viceversa al bajar). Es manual, no hay detección automática de entorno.
> Desde el servidor mismo, para entrar a MySQL por consola: `mysql -u ferreteria -p ferreteria_aldrete`.
> El cliente `mysql.exe` de XAMPP (MariaDB) **no puede** conectarse al MySQL 8 del servidor remoto (falla `caching_sha2_password`); para consultas remotas desde Windows usar un script PHP con PDO vía `C:\xampp\php\php.exe`.

- Archivo alternativo: `config/databaseRailway.php` (Railway — actualmente no usado)
- PDO con `ATTR_PERSISTENT => true` (conexiones persistentes — pendiente de quitar)
- Errores expuestos con `die()` — **pendiente ocultar con `error_log()`**

### Dependencias (`composer.json`)
```json
{
  "require": {
    "phpoffice/phpspreadsheet": "^5.5",
    "mpdf/mpdf": "^8.3"
  }
}
```

- **mpdf**: Generación de PDFs con encabezados, pies de página y marca de agua
- **phpspreadsheet**: Exportación a Excel `.xlsx` con estilos y logo incrustado

---

## 3. Estructura de Carpetas

```
C:\FerreteriaAldrete\
├── config/
│   ├── database.php              ← Conexión PDO activa
│   └── databaseRailway.php       ← Alternativa Railway (inactiva)
├── includes/
│   ├── auth.php                  ← Funciones de autenticación y CSRF
│   ├── inicio.php                ← Redirección según rol tras login
│   ├── topbar_info.php           ← Obtiene nombre de sucursal para topbar
│   └── auto_filter.js            ← Debounce 600ms para filtros automáticos
├── admin/                        ← Módulos del rol Administrador
├── cajero/                       ← Módulos del rol Cajero
├── cajeroInventario/             ← Módulos del rol Inventario/Cajero
├── inventario/                   ← Módulos del rol Inventario
├── vendor/                       ← Dependencias Composer
├── tmp/                          ← Salida temporal de PDFs
├── index.php                     ← Login (punto de entrada)
├── logout.php
├── baseDeDatos.sql               ← Esquema completo de la BD
├── ferreteria_aldrete.dump       ← Backup de la BD
├── logo.jpeg
├── logoDocumentos.jpeg
└── composer.json / composer.lock
```

---

## 4. Autenticación y Sesiones

### Punto de entrada: `index.php`
- Valida credenciales contra tabla `usuarios` con `password_verify()`
- Regenera sesión con `session_regenerate_id(true)` (previene session fixation)
- Genera CSRF token: `bin2hex(random_bytes(32))`
- Redirige según rol a su módulo correspondiente

### Variables de sesión
```php
$_SESSION['usuario_id']       // INT — PK de usuarios
$_SESSION['nombre_completo']  // STRING
$_SESSION['rol']              // Administrador | Inventario | Cajero | Inventario/Cajero
$_SESSION['sucursal_id']      // INT — NULL si es Administrador
$_SESSION['csrf_token']       // STRING hex(32)
```

### Funciones (`includes/auth.php`)
| Función | Descripción |
|--------|-------------|
| `verificarSesion()` | Redirige a login si no hay sesión activa |
| `verificarRol($rolesPermitidos)` | Controla acceso por array de roles permitidos |
| `verificarCSRF($token)` | Valida token CSRF |
| `requerirCSRF($token, $redirectUrl)` | Valida y redirige si falla |

### Logout: `logout.php`
`session_unset()` → `session_destroy()` → redirige a `index.php`

---

## 5. Roles de Usuario

| Rol | Módulo base | Alcance de datos |
|-----|------------|-----------------|
| **Administrador** | `/admin/` | Todas las sucursales |
| **Inventario** | `/inventario/` | Su sucursal |
| **Cajero** | `/cajero/` | Su sucursal |
| **Inventario/Cajero** | `/cajeroInventario/` | Su sucursal |

---

## 6. Base de Datos — Tablas

### Tabla: `sucursales`
| Campo | Tipo | Notas |
|-------|------|-------|
| sucursal_id | INT PK | |
| nombre | VARCHAR | |
| rfc | VARCHAR | |
| direccion | TEXT | |
| datos_ticket | TEXT | Datos impresos en ticket |
| banco | VARCHAR | |
| titular_cuenta | VARCHAR | |
| numero_cuenta | VARCHAR | |
| clabe | VARCHAR | |
| comision_terminal_pct | DECIMAL | % que se suma en pagos con terminal |
| activo | TINYINT | |

### Tabla: `usuarios`
| Campo | Tipo | Notas |
|-------|------|-------|
| usuario_id | INT PK | |
| sucursal_id | INT FK | NULL = Administrador global |
| nombre_completo | VARCHAR | |
| nombre_usuario | VARCHAR | Único |
| contrasena | VARCHAR | Hash bcrypt |
| rol | ENUM | Administrador/Inventario/Cajero/Inventario/Cajero |
| activo | TINYINT | |

### Tabla: `productos`
| Campo | Tipo | Notas |
|-------|------|-------|
| producto_id | INT PK | |
| categoria_id | INT FK | |
| codigo | VARCHAR | Código de barras |
| nombre_producto | VARCHAR | |
| precio_compra | DECIMAL | |
| precio_venta | DECIMAL | |
| precio_mayoreo | DECIMAL | |
| tipo_venta | ENUM | Unidad / Suelto |
| unidad_medida | VARCHAR | |
| activo | TINYINT | |

### Tabla: `stock_sucursal`
| Campo | Tipo | Notas |
|-------|------|-------|
| producto_id | INT FK | PK compuesto |
| sucursal_id | INT FK | PK compuesto |
| stock_actual | DECIMAL | |
| stock_minimo | DECIMAL | Alerta stock bajo |
| stock_maximo | DECIMAL | |
| activo | TINYINT | Producto activo en esta sucursal |

### Tabla: `ventas`
| Campo | Tipo | Notas |
|-------|------|-------|
| venta_id | INT PK | |
| folio | VARCHAR | Formato NNNN secuencial con mutex |
| caja_id | INT FK | |
| cliente_id | INT FK | Nullable |
| usuario_id | INT FK | |
| subtotal | DECIMAL | |
| descuento | DECIMAL | |
| comision_terminal | DECIMAL | |
| total | DECIMAL | |
| metodo_pago | ENUM | Efectivo/Terminal/Credito/Mixto/Transferencia |
| estado | ENUM | Pendiente/Completada/Cancelada/Devuelto/Modificado |

### Tabla: `venta_productos`
| Campo | Tipo | Notas |
|-------|------|-------|
| venta_id | INT FK | |
| producto_id | INT FK | |
| paquete_id | INT FK | Nullable |
| cantidad | DECIMAL | |
| precio_unitario | DECIMAL | |
| precio_final | DECIMAL | |
| descuento | DECIMAL | |
| nota_ajuste | TEXT | |

### Tabla: `cajas`
| Campo | Tipo | Notas |
|-------|------|-------|
| caja_id | INT PK | |
| sucursal_id | INT FK | |
| usuario_id | INT FK | |
| monto_apertura | DECIMAL | |
| monto_cierre | DECIMAL | |
| monto_esperado | DECIMAL | Calculado por sistema |
| diferencia | DECIMAL | monto_cierre - monto_esperado |
| estado | ENUM | Abierta / Cerrada |
| numero_turno | INT | |

### Tabla: `clientes`
| Campo | Tipo | Notas |
|-------|------|-------|
| cliente_id | INT PK | |
| nombre_completo | VARCHAR | |
| telefono | VARCHAR | |
| direccion | TEXT | |
| correo | VARCHAR | |
| descuento_fijo | DECIMAL | % descuento automático en ventas |
| credito_autorizado | TINYINT | Habilita ventas a crédito |
| limite_credito | DECIMAL | Máximo saldo pendiente permitido |
| activo | TINYINT | |

### Tabla: `creditos`
| Campo | Tipo | Notas |
|-------|------|-------|
| credito_id | INT PK | |
| cliente_id | INT FK | |
| venta_id | INT FK | |
| monto_total | DECIMAL | |
| saldo_pendiente | DECIMAL | |
| estado | ENUM | Activo/Liquidado/Vencido |
| fecha_limite | DATE | |

### Tabla: `abonos`
| Campo | Tipo | Notas |
|-------|------|-------|
| abono_id | INT PK | |
| credito_id | INT FK | |
| usuario_id | INT FK | |
| monto | DECIMAL | |
| comision_terminal | DECIMAL | |
| metodo_pago | ENUM | Efectivo/Terminal/Transferencia/Mixto |

### Tabla: `devoluciones`
| Campo | Tipo | Notas |
|-------|------|-------|
| devolucion_id | INT PK | |
| venta_id | INT FK | |
| usuario_id | INT FK | |
| procesada_en | DATETIME | |
| cancelada_en | DATETIME | |
| cancelada_por | INT FK | usuario_id |
| total_devuelto | DECIMAL | |
| comision_devuelta | DECIMAL | |

### Tabla: `movimientos_inventario`
| Campo | Tipo | Notas |
|-------|------|-------|
| movimientos_inventario_id | INT PK | |
| producto_id | INT FK | |
| usuario_id | INT FK | |
| sucursal_id | INT FK | |
| tipo | ENUM | Entrada/Salida/Ajuste/Transferencia |
| cantidad | DECIMAL | |
| stock_anterior | DECIMAL | |
| stock_nuevo | DECIMAL | |
| motivo | TEXT | |
| proveedor_id | INT FK | Nullable |
| devolucion_id | INT FK | Nullable |

### Tabla: `transferencias`
| Campo | Tipo | Notas |
|-------|------|-------|
| transferencias_id | INT PK | |
| producto_id | INT FK | |
| sucursal_origen_id | INT FK | |
| sucursal_destino_id | INT FK | |
| usuario_solicita_id | INT FK | |
| usuario_aprueba_id | INT FK | Nullable |
| cantidad | DECIMAL | |
| estado | ENUM | Pendiente/Aprobada/Modificada/En tránsito/Entregada/Rechazada |

### Tabla: `proveedores`
```
proveedor_id, nombre, telefono, correo, direccion, activo
```

### Tabla: `proveedor_categorias`
```
proveedor_id FK, categoria_id FK
```

### Tabla: `producto_proveedor`
```
producto_id FK, proveedor_id FK, codigo_proveedor
```

### Tabla: `paquetes`
```
paquete_id, codigo, nombre, precio_paquete, activo
```
> Catálogo global, sin asignación por sucursal.

### Tabla: `paquete_productos`
```
paquete_id FK, producto_id FK, cantidad
```

### Tabla: `compras_proveedor`
```
compras_proveedor_id, proveedor_id FK, usuario_id FK, sucursal_id FK, total
```
> Stock se actualiza inmediatamente al crear la compra (sin flujo Pendiente/Recibida).

### Tabla: `compra_productos`
```
compra_id FK, producto_id FK, cantidad, precio_unitario
```

### Tabla: `unidades_medida`
```
unidad_id, sucursal_id FK, nombre
```
> Por sucursal, no global.

### Tabla: `promociones`
```
promocion_id, producto_id FK, precio_promocional, fecha_inicio, fecha_fin,
descripcion, activo, usuario_id FK
```

### Tabla: `movimientos_caja`
```
movimiento_id, caja_id FK, usuario_id FK, sucursal_id FK,
tipo ENUM(Retiro/Ingreso), monto, nota, devolucion_id FK
```

### Tabla: `categorias`
```
categoria_id, nombre
```

### Tabla: `categorias_gastos`
```
categoria_gasto_id, nombre, activo
```
> Semilla inicial: Vehículos, Mantenimiento, Servicios básicos, Herramientas y equipo, Otros.

### Tabla: `gastos`
```
gasto_id, sucursal_id FK, usuario_id FK, categoria_gasto_id FK,
descripcion, monto, fecha, notas, created_at
```

### Tabla: `movimientos_mora`
```
mora_id, credito_id FK, monto, saldo_base, porcentaje, created_at
```
> `sucursales.porcentaje_mora` define el % aplicado; `creditos.mora_acumulada` acumula el total generado por crédito.

### Tabla: `empleados`
```
empleado_id, nombre, fecha_ingreso, sueldo_semanal, activo, created_at
```

### Tabla: `asistencia`
```
asistencia_id, empleado_id FK, fecha,
tipo ENUM(Asistencia normal/Tardanza/Falta/Salida temprana/Tiempo fuera/Horas extra),
hora_entrada, hora_salida, horas_no_trabajadas, horas_extra, razon,
resolucion ENUM(Pendiente/Deducido/Compensado/Justificado/Pagado integro), notas
```

### Tabla: `asistencia_tiempos_fuera`
```
tiempo_id, asistencia_id FK, hora_salida, hora_regreso
```
> Permite registrar múltiples salidas/regresos dentro del mismo día de asistencia.

### Tabla: `vacaciones`
```
vacacion_id, empleado_id FK, fecha_inicio, fecha_fin, dias_tomados, anio,
estado ENUM(Solicitado/Aprobado/Rechazado), notas, created_at
```

### Tabla: `adelantos_sueldo`
```
adelanto_id, empleado_id FK, monto, fecha, motivo,
estado ENUM(Pendiente/Liquidado), created_at
```

### Tabla: `pagos_nomina`
```
pago_id, empleado_id FK, semana_inicio (lunes), sueldo_base, deduccion, bono,
adelanto_descontado, monto_pagado, pagado_en
```
> Único por `(empleado_id, semana_inicio)` — un pago de nómina por empleado por semana.

### Columnas agregadas a tablas existentes
- `sucursales`: `porcentaje_mora`, `ticket_pie_efectivo`, `ticket_pie_credito`, `ticket_pie_terminal`, `ticket_nota_credito` (pie de ticket personalizado por método de pago)
- `creditos`: `mora_acumulada`

---

## 7. Módulos por Rol

### ADMINISTRADOR (`/admin/`)

| Archivo | Función |
|---------|---------|
| `inicioAdmin.php` | Dashboard: ventas hoy (todas las sucursales), cajas abiertas, stock bajo, créditos vencidos, transferencias pendientes |
| `usuarios.php`, `formUsuario.php` | CRUD usuarios, asignación de rol y sucursal |
| `sucursales.php`, `formSucursal.php` | CRUD sucursales, datos de ticket, datos bancarios, comisión terminal |
| `inventario_productos.php` | CRUD catálogo global de productos |
| `inventario_categorias.php` | CRUD categorías globales |
| `inventario_unidades.php` | CRUD unidades de medida por sucursal |
| `inventario_entradas.php` | Registrar entradas de mercancía |
| `inventario_salidas.php` | Registrar salidas/mermas |
| `inventario_historial.php` | Historial de movimientos de inventario |
| `inventario_proveedores.php` | CRUD proveedores, asignación de categorías |
| `inventario_compras.php` | Órdenes de compra (actualiza stock inmediatamente) |
| `inventario_paquetes.php` | CRUD paquetes de productos |
| `inventario_transferencias.php` | Gestión de transferencias entre sucursales |
| `inventario_promociones.php` | CRUD promociones con vigencia |
| `inventario_masVendidos.php` | Reporte de productos más vendidos |
| `cajero_nuevaVenta.php` | Acceso al módulo de nueva venta |
| `cajero_historialVentas.php` | Historial de ventas de todas las sucursales |
| `cajero_ventasPendientes.php` | Ventas pendientes de todas las sucursales |
| `cajero_devoluciones.php` | Devoluciones |
| `cajero_clientes.php`, `cajero_creditos.php`, `cajero_abonos.php` | Gestión de clientes y créditos |
| `cajero_abrirCaja.php`, `cajero_corteCaja.php`, `cajero_historialCortes.php` | Gestión de cajas |
| `reporteVentas.php` | Reporte de ventas con filtros, desglose por método de pago |
| `reporteProductos.php` | Reporte de productos con filtros |
| `cortes.php` | Historial de cortes de caja |
| `historial.php` | Historial de movimientos de inventario |
| `creditos.php`, `abonos.php`, `clientes.php` | Vista admin de créditos y clientes |
| `gastos.php`, `formGasto.php` | CRUD de gastos por sucursal y categoría |
| `empleados.php`, `formEmpleado.php` | CRUD de empleados (RH) |
| `asistencia.php`, `formAsistencia.php` | Registro de asistencia, tardanzas, faltas, horas extra |
| `vacaciones.php`, `formVacacion.php` | Solicitud/aprobación de vacaciones |
| `adelantos.php` | Adelantos de sueldo a empleados |
| `semanaLaboral.php` | Cálculo y pago de nómina semanal (usa `pagos_nomina`) |
| `export_helper.php` | Funciones compartidas de exportación PDF/Excel |
| `_admin_sidebar.php` | Componente sidebar del menú lateral |
| `_admin_sucursal_filtro.php` | Filtro de sucursal para reportes admin |
| `_sync_admin_modules.ps1` | Script PowerShell para sincronizar módulos entre roles (ver nota abajo) |

> El módulo RH (`empleados`, `asistencia`, `vacaciones`, `adelantos`, `semanaLaboral`) y `gastos` son exclusivos de Administrador — no existen versiones en `cajero/`, `inventario/` ni `cajeroInventario/`.

### INVENTARIO (`/inventario/`)

| Archivo | Función |
|---------|---------|
| `inicioInventario.php` | Dashboard: stock bajo, transferencias pendientes, últimas compras |
| `productos.php` | Ver catálogo — solo lectura, exportar PDF/Excel |
| `categorias.php` | Crear/editar categorías ⚠️ (debería ser solo admin) |
| `unidades.php` | Crear/editar unidades de medida ⚠️ |
| `entradas.php` | Registrar entrada de mercancía, actualiza `stock_sucursal` |
| `salidas.php` | Registrar salidas/mermas con motivo obligatorio |
| `historial.php` | Movimientos de inventario con filtros fecha/tipo/producto |
| `proveedores.php` | CRUD proveedores |
| `compras.php` | Órdenes de compra (actualiza stock inmediatamente) |
| `transferencias.php` | Flujo completo de transferencias |
| `paquetes.php` | Solo lectura del catálogo de paquetes |
| `promociones.php` | Solo lectura de promociones |
| `masVendidos.php` | Reporte con selector de sucursal ⚠️ (ve datos de otras sucursales) |
| `formProducto.php` | Formulario para productos |

### CAJERO (`/cajero/`)

| Archivo | Función |
|---------|---------|
| `inicioCajero.php` | Dashboard: estado de caja, stats del turno (ventas, pendientes, deuda, stock bajo), últimas ventas |
| `nuevaVenta.php` | Crear ventas (ver flujo detallado abajo) |
| `historialVentas.php` | Historial con filtros, stats por método de pago, reimpresión |
| `ventasPendientes.php` | Ventas sin descontar stock, liquidar/cancelar |
| `devoluciones.php` | Devoluciones parciales/totales |
| `abrirCaja.php` | Apertura de caja (mínimo $0.01, cierra huérfanas, asigna turno) |
| `corteCaja.php` | Cierre con resumen, captura monto contado, registra diferencia |
| `historialCortes.php` | Cortes del usuario actual |
| `clientes.php` | CRUD clientes, protección si tiene deuda activa |
| `creditos.php` | Pago de créditos distribuido FIFO por múltiples créditos |
| `abonos.php` | Flujo viejo de abonos, huérfano (sin enlaces en el menú). El flujo vigente es `creditos.php`. El usuario decidió conservarlo por ahora y borrarlo más adelante — **no reportar como hallazgo nuevo**. Tiene defectos conocidos: no marca `[Terminal]`/`[Transferencia]` en `movimientos_caja` (descuadra el corte), valida saldo contra el crédito del GET pero abona al del POST, no maneja Mixto, sin CSRF. |

### INVENTARIO/CAJERO (`/cajeroInventario/`)

Rol combinado con todas las funciones de Cajero + Inventario. Es el **módulo principal de desarrollo activo** (rama `nuevoCatalogo`); `cajero/` e `inventario/` se sincronizan manualmente después.

- **Estado actual del código (2026-08-02):** `categorias.php` y `unidades.php` de este módulo ya **no** permiten crear/editar/eliminar a Inventario/Cajero — solo Administrador (✅ resuelto, ver SEC-05 en §12). `formProducto.php` **sigue** permitiendo editar cualquier producto del catálogo global (incluso de otra sucursal) y crear productos nuevos pese al bloqueo aparente en pantalla — **decisión del dueño: se deja así** (SEC-05b en §12), el catálogo es global por diseño.
- **Diseño intencional según el usuario:** el rol Inventario/Cajero **no debería** crear ni editar categorías ni unidades — esas operaciones ya son exclusivas de Administrador (resuelto). Sobre productos, el dueño decidió mantener el comportamiento actual de `formProducto.php` tal cual está.
- Auditoría de seguridad completa del módulo realizada 2026-08-02 (ver `CAJERO_INVENTARIO_FUNCIONES.md`, sección final, y §12 de este documento). Se corrigieron 9 hallazgos (XSS almacenado, CSRF, condiciones de carrera, restricción de rol, y el display de cajas auto-cerradas).

Archivos: mismos que Cajero + mismos que Inventario dentro de `/cajeroInventario/`.

---

## 8. Flujos Principales

### Flujo: Nueva Venta

```
1. Cajero abre caja (abrirCaja.php)
2. Abre nuevaVenta.php
3. Sistema carga catálogo vía AJAX: nuevaVenta.php?get_productos_all
4. Cajero busca/escanea productos (por nombre o código de barras)
5. Agrega productos al carrito con cantidad
6. Opcionalmente selecciona cliente (aplica descuento_fijo si tiene)
7. Selecciona método de pago:
   - Efectivo: captura monto recibido, sistema calcula cambio
   - Terminal: aplica comisión_terminal_pct de la sucursal
   - Mixto: parte efectivo + parte terminal
   - Crédito: valida cliente.credito_autorizado = true y limite_credito disponible
8. Procesa la venta:
   INSERT ventas
   INSERT venta_productos
   UPDATE stock_sucursal (con SELECT...FOR UPDATE para concurrencia)
   Si crédito: INSERT creditos (saldo_pendiente = total)
   Genera folio secuencial NNNN (con mutex para evitar duplicados)
   INSERT movimientos_inventario (tipo='Salida')
9. Genera e imprime ticket con datos de la sucursal
```

> ⚠️ **Bug crítico:** El total se calcula en el navegador y no se revalida en el servidor. Un cliente puede modificar el precio desde las DevTools (F12).

### Flujo: Venta Pendiente

```
1. Crear venta con estado='Pendiente' — NO descuenta stock todavía
2. Venta aparece en ventasPendientes.php
3. Al liquidar: valida stock, descuenta, cambia estado a 'Completada'
4. Si crédito al liquidar: valida límite antes de procesar
5. Cancelar: solo cambia estado, sin stock que revertir
```

### Flujo: Devolución

```
1. Buscar por folio de venta original
2. Sistema muestra productos devolvibles (cantidad original - ya devuelto)
3. Cajero selecciona cantidades a devolver
4. Sistema calcula monto: precio_unitario × cantidad × factor_descuento_cliente
5. INSERT devoluciones
6. UPDATE stock_sucursal (incrementa)
7. UPDATE ventas (reduce totales proporcionalmente)
8. INSERT movimientos_inventario (tipo='Entrada', devolucion_id)
9. Si Terminal/Mixto/Transferencia: INSERT movimientos_caja (tipo='Retiro')
10. Si Crédito: UPDATE creditos (saldo_pendiente -= monto_devuelto)
11. Cancelable dentro de 24 horas (revierte todos los pasos anteriores)
```

> ⚠️ Sin límite de días para iniciar una devolución.

### Flujo: Transferencia entre Sucursales

```
Destino solicita:
  INSERT transferencias (estado='Pendiente')

Origen aprueba:
  UPDATE estado='Aprobada'
  (puede reducir cantidad si no hay stock suficiente)

Si cantidad modificada:
  Destino confirma la modificación → estado='Modificada'

Origen envía:
  UPDATE stock_sucursal origen (resta cantidad)
  UPDATE estado='En tránsito'

Destino recibe:
  UPDATE stock_sucursal destino (suma cantidad)
  UPDATE estado='Entregada'
  INSERT movimientos_inventario (ambas sucursales)
```

### Flujo: Crédito y Abono

```
Venta a crédito:
  Verifica: cliente.credito_autorizado = true
  Verifica: cliente.limite_credito ≥ total_venta
  INSERT creditos (saldo_pendiente = total, estado='Activo')

Pago / Abono:
  INSERT abonos (monto, metodo_pago, comision_terminal)
  UPDATE creditos (saldo_pendiente -= monto)
  Si Terminal/Transferencia: INSERT movimientos_caja (tipo='Ingreso')
  Distribución FIFO: si el pago cubre más de un crédito, se aplica en orden más antiguo→reciente
  Si saldo_pendiente = 0: UPDATE creditos (estado='Liquidado')
  Si fecha_limite < hoy y saldo > 0: estado='Vencido'
```

---

## 9. AJAX Endpoints

Todos en el mismo archivo PHP mediante parámetros GET:

| Endpoint | Archivo | Retorna |
|----------|---------|---------|
| `?get_productos_all` | `nuevaVenta.php` | JSON: todos los productos activos de la sucursal con stock |
| `?get_paquetes` | `nuevaVenta.php` | JSON: paquetes activos agrupados con sus componentes |
| `?buscar_paquete=TERM` | `nuevaVenta.php` | JSON: búsqueda de paquetes |

- `includes/auto_filter.js`: Script de debounce (600ms) para auto-submit de formularios de filtro.

---

## 10. Exportación de Documentos

### PDF (`mpdf/mpdf`)
- Marca de agua configurable
- Encabezado con logo y datos de sucursal
- Pie de página con número de página
- Estilos CSS embebidos

### Excel (`phpoffice/phpspreadsheet`)
- Logo incrustado en primera fila
- Estilos: bordes, colores de fila alternados, encabezados en negritas
- Filtros automáticos en columnas
- Salida directa al navegador o a archivo

### Archivo helper: `admin/export_helper.php`
Incluido en todos los módulos con opción de exportar. Contiene funciones reutilizables para ambos formatos.

---

## 11. Frontend

- **Sin frameworks JS** — vanilla JavaScript puro
- **Sin frameworks CSS** — CSS3 con variables: `--azul-principal`, `--rojo-marca`, etc.
- Cada módulo tiene su archivo CSS en `modulo/css/`
- Componentes PHP reutilizables: `_admin_sidebar.php`, `_admin_sucursal_filtro.php`, `topbar_info.php`
- Búsqueda de productos con input tipo texto + tecla Enter o clic en botón
- Carrito de compras manejado en JS con array de objetos en memoria

---

## 12. Seguridad — Issues Conocidos

> Última revisión: auditoría exhaustiva del rol Inventario/Cajero, 2026-08-02 (ver `CAJERO_INVENTARIO_FUNCIONES.md` para el detalle completo con reproducción/causa raíz/solución de cada hallazgo).

| ID | Severidad | Módulo | Problema | Estado |
|----|-----------|--------|----------|--------|
| SEC-01 | CRÍTICO | `nuevaVenta.php` | Total calculado en browser, sin revalidación server-side. Manipulable con DevTools. | ✅ Resuelto (ya recalcula todo server-side) |
| SEC-02 | CRÍTICO | `config/database.php` | Credenciales hardcodeadas en código. Errores de BD expuestos al usuario con `die()`. | ⚠️ Pendiente — el dueño del proyecto rotará la contraseña y la sacará del repositorio una vez cerrados los demás pendientes. El `die()` con mensaje técnico sigue igual. |
| SEC-03 | ALTO | Transferencias, entradas, salidas, nueva venta, corte/apertura de caja, devoluciones, compras | Falta CSRF | ✅ Resuelto 2026-08-02 en los 8 archivos (incluye las 7 acciones de transferencias) |
| SEC-04 | ALTO | `creditos.php` | Saldo pendiente no usa `FOR UPDATE` — race condition posible en abonos simultáneos. | ✅ Resuelto 2026-08-02 |
| SEC-05 | MEDIO | `categorias.php`, `unidades.php` | Inventario/Cajero podía crear/editar categorías y unidades del catálogo global | ✅ Resuelto 2026-08-02 — solo Administrador |
| SEC-05b | MEDIO | `cajeroInventario/formProducto.php` | Puede editar cualquier producto del catálogo (no solo los de su sucursal) y crear productos nuevos pese al bloqueo aparente | ❌ **Decisión del dueño (2026-08-02): se deja así** — catálogo compartido por diseño, riesgo de creación considerado bajo |
| SEC-09 | INFO | `cajeroInventario/abonos.php` | Flujo huérfano de abonos con varios defectos (sin CSRF, no maneja Mixto, no marca movimientos_caja). Conservado a propósito por el usuario — no tocar sin instrucción explícita. | Sin cambios |
| SEC-06 | INFO | `masVendidos.php` | Selector permite ver datos de otras sucursales. | ❌ **Confirmado intencional (2026-08-02)** — no es un bug, las sucursales trabajan coordinadas |
| SEC-07 | MEDIO | `devoluciones.php` | Sin límite de días para iniciar una devolución. | ✅ Ya resuelto antes de esta auditoría (límite de 7 días vigente) |
| SEC-08 | BAJO | `config/database.php` | `ATTR_PERSISTENT => true` puede causar fugas de conexión bajo carga. | ✅ Ya resuelto antes de esta auditoría (el array de opciones del PDO está vacío) |
| SEC-10 | CRÍTICO | `creditos.php`, `nuevaVenta.php` | XSS almacenado: un nombre de cliente con comilla rompía el atributo `onclick` y ejecutaba JS arbitrario | ✅ Resuelto 2026-08-02 |
| SEC-11 | CRÍTICO | `productos.php` | XSS almacenado en el modal "Agregar del catálogo" (nombre/código/categoría sin escapar bien) | ✅ Resuelto 2026-08-02 |
| SEC-12 | CRÍTICO | `proveedores.php` | XSS almacenado en el selector de áreas (sin ningún escape) | ✅ Resuelto 2026-08-02 |
| SEC-13 | CRÍTICO | `transferencias.php` | Recibir una transferencia dos veces (doble clic) duplicaba el stock del destino | ✅ Resuelto 2026-08-02 |
| SEC-14 | ALTO | `transferencias.php` | Enviar una transferencia dos veces (doble clic) duplicaba el descuento de stock en origen | ✅ Resuelto 2026-08-02 |
| SEC-15 | CRÍTICO | `historialCortes.php` | Cajas cerradas automáticamente (sesión abandonada) se mostraban como "Cuadrada" con $0.00 sin verificación real | ✅ Resuelto 2026-08-02 |
| SEC-16 | ALTO | `entradas.php`, `salidas.php` | Sin `FOR UPDATE` al actualizar stock — condición de carrera entre dos movimientos simultáneos del mismo producto | ✅ Resuelto 2026-08-02 |
| SEC-17 | BAJA | `productos.php` | Panel de "Importar Excel" sin botón que lo muestre (código sin usar); el handler de backend sigue sin verificación de rol propia | Sin cambios — bajo riesgo mientras no sea alcanzable desde la UI |
| SEC-18 | MEDIA | `corteCaja.php` | Cierre de caja sin candado contra doble envío — un doble clic podía sobrescribir el corte con otro monto | ✅ Resuelto 2026-08-02 |
| SEC-19 | MEDIA | `compras.php` | Detalle de compra (`?ver=`) sin filtro de sucursal — se podía ver el detalle de una compra de otra sucursal adivinando el ID | ✅ Resuelto 2026-08-02 |
| SEC-20 | ALTA | `proveedores.php` | El formulario de crear/editar proveedor no tenía CSRF (solo `?eliminar=`/`?toggle=` lo tenían) — sin ningún candado de rol que lo mitigara, se confirmó explotable creando un proveedor real sin token en una prueba en vivo | ✅ Resuelto 2026-08-02, verificado contra el sistema real |
| SEC-21 | MEDIA | `categorias.php`, `unidades.php` | Mismo caso que SEC-20 en sus formularios de crear/editar — mitigado desde SEC-05 (solo Administrador llega ahí), pero el candado de CSRF en si tambien faltaba | ✅ Resuelto 2026-08-02 |
| SEC-22 | CRÍTICO | `creditos.php` | Mora automática (aplicada al cargar la pantalla) podía aplicarse más de una vez por condición de carrera — bloque sin `FOR UPDATE`, distinto al del abono que ya lo tenía | ✅ Resuelto 2026-08-02, verificado con 5 peticiones simultáneas reales |
| SEC-23 | CRÍTICO | `ventasPendientes.php` | "Liquidar" y "Cancelar" (acciones distintas, ninguna protección de doble-clic existente las cubría) podían chocar entre sí y dejar stock descontado bajo una venta marcada como Cancelada | ✅ Resuelto 2026-08-02, verificado con 5 rondas de peticiones simultáneas reales |
| SEC-24 | CRÍTICO | `formProducto.php` | CSRF ausente en la edición del catálogo global — documentado desde la primera auditoría (ver SEC-05b) pero nunca corregido hasta ahora | ✅ Resuelto 2026-08-02, verificado en vivo (ataque bloqueado, edición legítima intacta) |
| SEC-25 | ALTA | `productos.php` | CSRF ausente en "eliminar producto" y "agregar del catálogo" (alcance: solo la sucursal propia, no cruza sucursales) | ✅ Resuelto 2026-08-02, verificado en vivo |

**Nota metodológica (2026-08-02):** tras corregir los hallazgos anteriores, se construyó una batería de ~116 pruebas automatizadas contra el sistema real (login, todas las pantallas, y pruebas de concurrencia genuina con peticiones simultáneas de verdad para los fixes de doble-clic) en vez de solo revisar el código. 114 pasaron a la primera; los 2 hallazgos SEC-20/SEC-21 se descubrieron precisamente gracias a esas pruebas y se corrigieron y reverificaron en vivo el mismo día.

**Cierre de la auditoría del rol Inventario/Cajero (2026-08-02):** con SEC-18 a SEC-21 se dan por atendidos y **verificados en vivo** todos los hallazgos Críticos y Altos de esta pasada, salvo SEC-02 (credencial de BD, pendiente de que el dueño la rote) y SEC-05b/SEC-06 (decisiones explícitas de dejarlos así). El dueño del proyecto continúa ahora con el rol Administrador; los hallazgos Medios/Bajos que quedaron sin tocar están listados en `PENDIENTES.md`.

**Cierre de la auditoría adversarial final (2026-08-02):** una segunda auditoría independiente, que ignoró deliberadamente las correcciones anteriores y reconcilió ~4 meses de datos reales de la base de datos (folios, stock, cajas, créditos), encontró SEC-22 a SEC-25 — ya corregidos y verificados en vivo con concurrencia real (peticiones HTTP simultáneas genuinas, no secuenciales). Con esto no queda ningún hallazgo Crítico ni Alto abierto en el rol Inventario/Cajero. Se registraron además dos observaciones operativas sin ID de SEC (no son bugs de código): un cliente inactivo con deuda que los listados no muestran por defecto, y una transferencia pendiente de aprobar desde hace 77 días sin recordatorio — ver `PENDIENTES.md`.

---

## 13. Archivos de Documentación

| Archivo | Contenido |
|---------|-----------|
| `CAJERO_INVENTARIO_FUNCIONES.md` | Inventario de archivos del módulo CajeroInventario con tablas BD y roles |
| `PENDIENTES.md` | TODOs: config VPS, TablePlus, fixes de seguridad críticos |
| `PRUEBAS_CAJERO.md` | ~346 casos de prueba: caja, ventas, clientes, inventario, proveedores, seguridad |
| `ContextoDeProyecto.docx` | Contexto inicial del proyecto |
| `entrevista1.docx` | Entrevista con el usuario/negocio |
| `Funcionalidades del Sistema Ferreteria.xlsx` | Matriz de funcionalidades |
| `ferreteria_aldrete.dump`, `ferreteria_aldrete.sql` | Backups del esquema/datos de la BD |

---

## 14. Resumen Numérico

| Métrica | Valor |
|---------|-------|
| Roles de usuario | 4 |
| Tablas en BD | ~31 (22 originales + gastos/mora + 5 de RH) |
| Módulos del sistema | Admin, Inventario, Cajero, Inventario/Cajero, RH, Gastos |
| Casos de prueba documentados | ~346 |
| Fixes de seguridad pendientes | 9 (ver §12) |
| Dependencias Composer | 2 (phpspreadsheet, mpdf) |

> Nota: los conteos de archivos/tablas de versiones anteriores de este documento quedaron desactualizados por el crecimiento del módulo RH/Gastos y el módulo `admin/` (que ahora replica sub-módulos `cajero_*` e `inventario_*` con prefijo). No se recalculó un conteo exacto de archivos PHP en esta actualización.
