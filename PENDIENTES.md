# Pendientes del Proyecto — Ferretería Aldrete

---

## 🖥️ Configuración del Servidor VPS (DigitalOcean)

### Paso 1 — Instalar LAMP Stack en el servidor
Conectarse al VPS por consola y ejecutar:
```bash
sudo apt update
sudo apt install apache2 mysql-server php php-mysql libapache2-mod-php -y
```

### Paso 2 — Crear la base de datos y usuario MySQL
```bash
sudo mysql
CREATE DATABASE ferreteria_aldrete CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ferreteria_user'@'localhost' IDENTIFIED BY 'tu_contraseña';
GRANT ALL PRIVILEGES ON ferreteria_aldrete.* TO 'ferreteria_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Paso 3 — Subir los archivos del proyecto
Subir todos los archivos de C:\FerreteriaAldrete al servidor (por SFTP con FileZilla o similar).
Carpeta destino en el servidor: `/var/www/html/`

### Paso 4 — Configurar config/database.php
Cambiar las credenciales al usuario MySQL del servidor:
```php
$host   = '127.0.0.1';
$port   = '3306';
$dbname = 'ferreteria_aldrete';
$user   = 'ferreteria_user';
$pass   = 'tu_contraseña';
```

---

## 🗄️ Configurar TablePlus (gestor gráfico de base de datos)

### Paso 1 — Descargar e instalar TablePlus
- Sitio oficial: https://tableplus.com
- Instalar en la computadora local (no en el servidor)

### Paso 2 — Crear la conexión en TablePlus
1. Abrir TablePlus → **Create a new connection** → elegir **MySQL**
2. Llenar los datos de MySQL:
   - Host: `127.0.0.1`
   - Port: `3306`
   - User: `ferreteria_user`
   - Password: la contraseña del usuario MySQL
   - Database: `ferreteria_aldrete`
3. Activar la pestaña **SSH** y llenar:
   - SSH Host: la IP del VPS (ej. `143.198.x.x`)
   - SSH Port: `22`
   - SSH User: `root`
   - SSH Password: la contraseña del VPS (o seleccionar el archivo `.pem` si se usa llave SSH)
4. Click en **Test** — debe decir "Connected successfully"
5. Click en **Save**

> No es necesario abrir el puerto 3306 al internet. TablePlus se conecta de forma segura por SSH.

---

## 🔒 Fixes de seguridad — rol Inventario/Cajero

### ✅ Ya corregidos (auditoría 2026-08-02)

- ✅ `nuevaVenta.php`: el total ya se recalcula y valida completo en el servidor (subtotal, descuento, comisión, límite de crédito) — no confía en lo que manda el navegador.
- ✅ `transferencias.php`: CSRF agregado en los 7 disparadores (aprobar/rechazar/enviar/recibir/editar cantidad/aceptar-rechazar modificación). Acciones `recibir` y `enviar` envueltas en transacción con `FOR UPDATE` — ya no se duplica el stock con doble clic.
- ✅ `entradas.php`: CSRF agregado. `UPDATE stock + INSERT movimiento` envuelto en transacción con `FOR UPDATE`.
- ✅ `salidas.php`: mismo fix que entradas — CSRF y transacción con `FOR UPDATE`, revalidando el stock disponible con el valor recién bloqueado.
- ✅ `creditos.php`: el `SELECT` de saldo pendiente ahora corre dentro de la transacción con `FOR UPDATE`, ya no hay condición de carrera en abonos simultáneos.
- ✅ CSRF agregado también en `corteCaja.php`, `abrirCaja.php`, `devoluciones.php` y `compras.php` (formularios principales).
- ✅ Categorías y unidades de medida: solo `Administrador` puede crear/editar/eliminar (antes cualquier Inventario/Cajero podía).
- ✅ XSS almacenado corregido en: nombre de cliente (`creditos.php`, `nuevaVenta.php`), modal de catálogo (`productos.php`), selector de áreas de proveedor (`proveedores.php`).
- ✅ Cajas cerradas automáticamente por sesión abandonada ya no se muestran como "Cuadrada" — se distingue como "Sin verificar" (`historialCortes.php`).
- ✅ `corteCaja.php`: el cierre de caja ya no se puede sobrescribir con un doble envío del formulario.
- ✅ `compras.php`: el detalle de una compra (`?ver=`) ya filtra por sucursal — no se puede ver el detalle de otra sucursal adivinando el ID.

- ✅ `proveedores.php`: su formulario de crear/editar no tenía CSRF (encontrado probando en vivo, no en la revisión de código original) — corregido y reverificado contra el sistema real.
- ✅ `categorias.php`/`unidades.php`: mismo caso en sus formularios de crear/editar — corregido.
- ✅ `creditos.php`: la mora automática (aplicada al cargar la pantalla) ahora también corre con `FOR UPDATE` — antes solo el abono tenía ese candado, la mora podía duplicarse con dos cargas simultáneas de la pantalla.
- ✅ `ventasPendientes.php`: "Liquidar" y "Cancelar" ya no pueden chocar entre sí — ambos `UPDATE` validan `estado='Pendiente'` en su propio `WHERE`, evitando que una venta quede Cancelada con el stock ya descontado.
- ✅ `formProducto.php`: le faltaba CSRF en el formulario de edición del catálogo global — hallazgo documentado desde la primera auditoría pero nunca corregido hasta la auditoría adversarial final. Ya agregado.
- ✅ `productos.php`: CSRF agregado también en "eliminar producto" y "agregar del catálogo" (alcance: solo la sucursal propia).

### Verificación en vivo (2026-08-02)

Después de corregir todo lo anterior, se armó una batería de ~116 pruebas automatizadas contra el sistema real (no solo revisión de código): login, apertura/cierre de caja, los 5 métodos de pago de venta, devoluciones, transferencias, entradas/salidas, créditos, compras, clientes, ventas pendientes, catálogo, proveedores y reportes. Se incluyeron pruebas de **concurrencia real** (dos peticiones simultáneas de verdad) para los fixes de doble-clic (C8, A2, A5, M1), confirmando que el stock/saldo nunca se duplica ni se pierde. De 116 pruebas, 114 pasaron a la primera; las otras 2 revelaron los hallazgos de `proveedores.php`/`categorias.php`/`unidades.php` de arriba, ya corregidos y reverificados. Todos los datos de prueba se limpiaron de la base de datos al terminar — no queda nada mezclado con información real.

### Estado del rol Inventario/Cajero (2026-08-02)

Con esto se cierran y **verifican en vivo** todos los hallazgos Críticos y Altos de la auditoría de este rol, salvo la credencial de base de datos (ver abajo) y las dos decisiones explícitas de dejar el comportamiento tal cual. El dueño del proyecto continúa ahora con el rol Administrador. Quedan sin tocar los hallazgos Medios/Bajos menores listados más abajo (KPIs, validaciones, mensajes de error) — no son bloqueantes.

### Segunda pasada — auditoría adversarial final (2026-08-02)

Una segunda auditoría independiente (mentalidad adversarial, sin confiar en las correcciones anteriores, con forense de ~4 meses de datos reales y pruebas de concurrencia genuina) encontró 4 hallazgos nuevos que ninguna auditoría anterior había cerrado: NC1 (mora de créditos duplicable por condición de carrera), NC2 (Liquidar/Cancelar de venta pendiente podían chocar y dejar stock perdido), NC3 (CSRF en `formProducto.php`, documentado desde la primera auditoría y nunca corregido) y NA1 (CSRF en `productos.php` al eliminar producto o agregar del catálogo). Los 4 ya están corregidos y verificados en vivo — detalle en `CAJERO_INVENTARIO_FUNCIONES.md` y `CONTEXTO_SISTEMA.md` (SEC-22 a SEC-25). Con esto no queda ningún hallazgo Crítico ni Alto abierto en este rol.

### ⚠️ Pendiente — `config/database.php`

El bloque de conexión al servidor (comentado) sigue teniendo usuario y contraseña de producción en texto plano dentro del archivo. **El dueño del proyecto la manejará directamente** (rotar contraseña + limpiar del repositorio) una vez cerrados los demás pendientes — no requiere cambio de lógica, solo cambiar la contraseña en el servidor y sacarla del código.

También sigue pendiente revisar el mensaje de error de conexión (`die("Error de conexión: " . $e->getMessage())`), que podría exponer detalles técnicos si la base de datos no responde.

### Decisiones tomadas — no se modifican

- `formProducto.php` permite editar cualquier producto del catálogo (aunque no sea de la sucursal propia) y bypassear el bloqueo de creación de productos nuevos. **Decisión (2026-08-02): se deja así** — el catálogo es compartido entre sucursales por diseño, y el riesgo de la creación de productos se considera bajo.
- `masVendidos.php` permite ver el reporte de cualquier sucursal desde el selector. **Decisión (2026-08-02): es intencional**, las sucursales trabajan coordinadas.

### Pendiente — no relacionado a lo de arriba

- El panel de "Importar Excel" en `productos.php` existe en el código pero no tiene ningún botón que lo muestre (no es alcanzable desde la pantalla actual). El backend que lo procesa tampoco tiene su propia verificación de rol. Bajo riesgo mientras siga sin un botón que lo active — revisar si algún día se reactiva.
- Inconsistencia `'Crédito'` vs `'Credito'` (con/sin acento) guardada en la BD, usada en varias comparaciones de `ventasPendientes.php` y otros archivos.
- KPIs de resumen en `historialVentas.php` y `historial.php` no siempre coinciden con la tabla mostrada cuando no hay filtro de fecha (el resumen suma todo el histórico, la tabla solo muestra un límite fijo de filas).
- Falta validar nombre duplicado antes de guardar en varios formularios de catálogo, y validación de valores negativos incompleta para productos tipo "Suelto".

### Observaciones operativas (no son bugs de código — auditoría adversarial final, 2026-08-02)

- **NM1** — Un cliente inactivo (`activo=0`) puede seguir con deuda activa (caso real: $80.04). Es el comportamiento documentado a propósito (no se bloquea ni se toca la deuda al desactivar), pero como los listados por defecto ocultan inactivos, esa deuda es fácil de perder de vista operativamente. Vale la pena revisar el listado de clientes inactivos de vez en cuando si tienen saldo pendiente.
- **NM2** — Puede quedar una transferencia en estado "Pendiente" por semanas sin que nadie la apruebe ni la rechace (caso real: 77 días). No existe expiración ni recordatorio automático para solicitudes de transferencia olvidadas.

---

## 🖥️ Migración de submenús Inventario/Cajero dentro de `admin/` (2026-08-19)

Se refrescaron los 19 archivos de los submenús "Inventario" y "Cajero" en `admin/` (ya existían pero con versiones viejas) usando la lógica ya auditada de `cajeroInventario/`, con un selector de sucursal para que el Administrador opere cualquier sucursal, no solo la suya. Detalle completo en `CONTEXTO_SISTEMA.md` §12.5.

**No se tocaron** (decisión del dueño): `inventario_productos.php`, `inventario_formProducto.php`, `inventario_categorias.php`, `inventario_unidades.php`, `promociones.php`. Tampoco la lógica de `inventario_paquetes.php` (admin ya tiene su propio alta/edición, distinto al de cajeroInventario) — solo CSRF.

### ⚠️ Aviso importante sobre las pruebas de esta migración

Se corrieron 128 pruebas automatizadas contra un servidor local aislado durante la migración (CSRF, aislamiento entre sucursales específicas, condiciones de carrera), y todo pasó. **Pero esas pruebas no cubrían consistentemente el caso "Todas las sucursales"** (que es el valor *por defecto* al iniciar sesión como Administrador, no algo que hay que elegir a propósito) — solo se probó con sucursales específicas. Ese hueco dejó pasar un bug real: varios reportes (`inventario_masVendidos.php`, `cajero_historialVentas.php`, el detalle de compra en `inventario_compras.php`, `inventario_inicio.php`, `cajero_inicio.php`) mostraban "sin datos" o estadísticas en cero al ver "Todas las sucursales", aunque sí había información. **El dueño del proyecto lo encontró probando manualmente en su propio servidor con datos reales**, no las pruebas automatizadas.

Ya corregido en los 5 archivos (condicionando el filtro de sucursal para que "todas" realmente agregue en vez de exigir una sucursal_id que no existe) y re-verificado con 12 pruebas nuevas más las 128 anteriores repetidas, todas sin regresión — pero el aviso queda documentado porque es la prueba de que las pruebas automatizadas de esta sesión, aunque útiles, no sustituyen abrir la pantalla real con datos reales. Si se encuentra otro caso similar en algún reporte que no esté en esta lista, es el mismo patrón: buscar un filtro `sucursal_id = ?` que no esté condicionado a "si la sucursal elegida no es 0".
