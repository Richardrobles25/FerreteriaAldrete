# Fixes de Seguridad — Código listo para aplicar

## 1. SQL Injection → Prepared Statements

### Patrón a buscar (inseguro):
```php
$query = "SELECT * FROM tabla WHERE campo = '$variable'";
$result = mysqli_query($conn, $query);
```

### Reemplazar por (mysqli):
```php
// [AUTOFIX] Prepared statement para prevenir SQL injection
$stmt = $conn->prepare("SELECT * FROM tabla WHERE campo = ?");
$stmt->bind_param("s", $variable); // s=string, i=integer, d=decimal
$stmt->execute();
$result = $stmt->get_result();
```

### Reemplazar por (PDO):
```php
// [AUTOFIX] PDO prepared statement para prevenir SQL injection
$stmt = $pdo->prepare("SELECT * FROM tabla WHERE campo = ?");
$stmt->execute([$variable]);
$result = $stmt->fetchAll();
```

### Tipos de bind_param:
- `s` → string (texto)
- `i` → integer (número entero)
- `d` → double (decimal)
- `b` → blob (archivos)

---

## 2. XSS → htmlspecialchars()

### Patrón a buscar (inseguro):
```php
echo $_GET['nombre'];
echo $_POST['campo'];
echo $variable_de_bd;
```

### Reemplazar por:
```php
// [AUTOFIX] Escapar output para prevenir XSS
echo htmlspecialchars($_GET['nombre'], ENT_QUOTES, 'UTF-8');
echo htmlspecialchars($_POST['campo'], ENT_QUOTES, 'UTF-8');
echo htmlspecialchars($variable_de_bd, ENT_QUOTES, 'UTF-8');
```

### En JavaScript (inseguro):
```js
elemento.innerHTML = dato;
```
### Reemplazar por:
```js
// [AUTOFIX] Usar textContent en lugar de innerHTML para prevenir XSS
elemento.textContent = dato;
```

---

## 3. CSRF → Token en formularios

### Agregar al inicio del archivo PHP (antes del HTML):
```php
<?php
session_start();
// [AUTOFIX] Generar token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
```

### Agregar dentro de cada formulario POST:
```html
<!-- [AUTOFIX] Token CSRF para proteger el formulario -->
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
```

### Agregar al procesar el POST:
```php
// [AUTOFIX] Verificar token CSRF
if (!isset($_POST['csrf_token']) || 
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('Solicitud inválida. Por favor recarga la página.');
}
```

---

## 4. Contraseñas → password_hash()

### Patrón a buscar (inseguro):
```php
$pass = md5($_POST['password']);
$pass = sha1($_POST['password']);
$pass = $_POST['password']; // texto plano
```

### Al registrar (reemplazar por):
```php
// [AUTOFIX] Hashear contraseña de forma segura
$pass = password_hash($_POST['password'], PASSWORD_BCRYPT);
```

### Al verificar login (reemplazar por):
```php
// [AUTOFIX] Verificar contraseña hasheada
if (password_verify($_POST['password'], $hash_guardado_en_bd)) {
    // contraseña correcta
} else {
    // contraseña incorrecta
}
```

> ⚠️ ADVERTENCIA: Si ya hay usuarios registrados con MD5/SHA1, cambiar esto
> requiere que los usuarios restablezcan su contraseña. Avisar al usuario.

---

## 5. Verificación de sesión en páginas protegidas

### Agregar al inicio de CADA página que requiera login:
```php
<?php
// [AUTOFIX] Verificar sesión activa
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}
```

---

## 6. Subida de archivos segura

### Reemplazar validación por extensión (inseguro) con validación por tipo real:
```php
// [AUTOFIX] Validación segura de archivos subidos
$tipos_permitidos = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$max_size = 2 * 1024 * 1024; // 2MB

$finfo = new finfo(FILEINFO_MIME_TYPE);
$tipo_real = $finfo->file($_FILES['archivo']['tmp_name']);

if (!in_array($tipo_real, $tipos_permitidos)) {
    $error = 'Solo se permiten imágenes JPG, PNG o GIF.';
} elseif ($_FILES['archivo']['size'] > $max_size) {
    $error = 'El archivo no debe superar 2MB.';
} else {
    // Renombrar para evitar ejecución de código malicioso
    $extension = pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION);
    $nombre_seguro = uniqid('img_', true) . '.' . strtolower($extension);
    move_uploaded_file($_FILES['archivo']['tmp_name'], 'uploads/' . $nombre_seguro);
}
```

---

## 7. Ocultar errores PHP en producción

### Agregar al inicio del archivo principal (index.php o config.php):
```php
// [AUTOFIX] Ocultar errores al usuario en producción
ini_set('display_errors', 0);
error_reporting(0);
ini_set('log_errors', 1);
// ini_set('error_log', '/ruta/a/errores.log'); // opcional
```

---

## 8. Headers de seguridad HTTP

### Agregar en el archivo de configuración o al inicio de index.php:
```php
// [AUTOFIX] Headers de seguridad
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
```
