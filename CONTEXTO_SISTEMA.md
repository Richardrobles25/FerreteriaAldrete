# Sistema Ferretería Aldrete — Contexto Completo

## 1. Descripción General

Sistema web de punto de venta (POS) e inventario para **Ferretería Aldrete**, desarrollado en PHP con PDO/MySQL. Soporta múltiples sucursales, 4 roles de usuario, control de inventario, créditos a clientes, transferencias entre sucursales y generación de reportes en PDF/Excel.

- **Servidor:** DigitalOcean Droplet — `146.190.40.160`
- **Base de datos:** MySQL 8.0.45 en `146.190.40.160:3306`, BD `ferreteria_aldrete`
- **Zona horaria:** `America/Mazatlan` (UTC-7)
- **Desarrollo local:** `php -S localhost:8000` desde VS Code

---

## 2. Infraestructura y Configuración

### Conexión a BD (`config/database.php`)
```php
$host     = '146.190.40.160'; // Desarrollo local
$port     = '3306';
$db       = 'ferreteria_aldrete';
$user     = 'ferreteria';
$password = 'Ferreteria2024$';
```

> En el servidor de producción (Apache/Nginx) usar `$host = '127.0.0.1'` y `$port = '3306'`.

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
| `export_helper.php` | Funciones compartidas de exportación PDF/Excel |
| `_admin_sidebar.php` | Componente sidebar del menú lateral |
| `_admin_sucursal_filtro.php` | Filtro de sucursal para reportes admin |

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
| `abonos.php` | (posiblemente obsoleto, no aparece en menú) |

### INVENTARIO/CAJERO (`/cajeroInventario/`)

Rol combinado con todas las funciones de Cajero + Inventario. Diferencia importante:
- **Puede editar precios/datos de productos del catálogo global** ⚠️
- **Puede crear categorías y unidades** ⚠️ (inconsistencia con rol Administrador)

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

| ID | Severidad | Módulo | Problema |
|----|-----------|--------|----------|
| SEC-01 | CRÍTICO | `nuevaVenta.php` | Total calculado en browser, sin revalidación server-side. Manipulable con DevTools. |
| SEC-02 | CRÍTICO | `config/database.php` | Credenciales hardcodeadas en código. Errores de BD expuestos al usuario con `die()`. |
| SEC-03 | ALTO | Múltiples archivos | Falta CSRF en transferencias, entradas y salidas (solo aplica en algunos módulos). |
| SEC-04 | ALTO | `creditos.php` | Saldo pendiente no usa `FOR UPDATE` — race condition posible en abonos simultáneos. |
| SEC-05 | MEDIO | `cajeroInventario/formProducto.php` | Inventario/Cajero puede editar precios del catálogo global (debería ser solo admin). |
| SEC-06 | MEDIO | `inventario/masVendidos.php` | Selector permite ver datos de otras sucursales. |
| SEC-07 | MEDIO | `devoluciones.php` | Sin límite de días para iniciar una devolución. |
| SEC-08 | BAJO | `config/database.php` | `ATTR_PERSISTENT => true` puede causar fugas de conexión bajo carga. |

---

## 13. Archivos de Documentación

| Archivo | Contenido |
|---------|-----------|
| `CAJERO_INVENTARIO_FUNCIONES.md` | Inventario de 37 archivos del módulo CajeroInventario con tablas BD y roles |
| `PENDIENTES.md` | TODOs: config VPS, TablePlus, 8 fixes de seguridad críticos |
| `PRUEBAS_CAJERO.md` | ~346 casos de prueba: caja, ventas, clientes, inventario, proveedores, seguridad |
| `ContextoDeProyecto.docx` | Contexto inicial del proyecto |
| `Funcionalidades del Sistema Ferreteria.xlsx` | Matriz de funcionalidades |

---

## 14. Resumen Numérico

| Métrica | Valor |
|---------|-------|
| Roles de usuario | 4 |
| Tablas en BD | ~22 |
| Archivos PHP activos | ~94 |
| Módulos del sistema | 13+ |
| Casos de prueba documentados | ~346 |
| Fixes de seguridad pendientes | 8 |
| Dependencias Composer | 2 |
