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

## 🔒 Fixes de seguridad pendientes en el código

Estos fixes deben aplicarse antes de pasar a producción:

- **CRÍTICO** — `nuevaVenta.php`: El total de la venta se calcula en el navegador y llega al servidor sin verificarse. Cualquier persona puede modificar el precio antes de enviar. Hay que recalcular el total server-side en PHP.
- **CRÍTICO** — `config/database.php`: Quitar `PDO::ATTR_PERSISTENT`, ocultar mensaje de error real con `error_log()`.
- **ALTO** — `transferencias.php`: Agregar CSRF en los links de aprobar/rechazar/enviar/recibir. Envolver acción `recibir` en transacción con `FOR UPDATE`.
- **ALTO** — `entradas.php`: Agregar CSRF en el formulario POST. Envolver `UPDATE stock + INSERT movimiento` en una transacción.
- **ALTO** — `salidas.php`: Igual que entradas — CSRF y transacción.
- **ALTO** — `creditos.php`: Mover el SELECT de saldo pendiente dentro de la transacción con `FOR UPDATE` para evitar condición de carrera en abonos simultáneos.
