# Referencia de Seguridad — PHP / JavaScript

## 1. SQL Injection

### ❌ Inseguro
```php
$user = $_POST['usuario'];
$query = "SELECT * FROM usuarios WHERE nombre = '$user'";
$result = mysqli_query($conn, $query);
```

### ✅ Seguro (PDO con prepared statements)
```php
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE nombre = ?");
$stmt->execute([$_POST['usuario']]);
$result = $stmt->fetchAll();
```

### ✅ Seguro (mysqli)
```php
$stmt = $conn->prepare("SELECT * FROM usuarios WHERE nombre = ?");
$stmt->bind_param("s", $_POST['usuario']);
$stmt->execute();
```

---

## 2. XSS (Cross-Site Scripting)

### ❌ Inseguro
```php
echo "Bienvenido, " . $_GET['nombre'];
```

### ✅ Seguro
```php
echo "Bienvenido, " . htmlspecialchars($_GET['nombre'], ENT_QUOTES, 'UTF-8');
```

En JavaScript, nunca uses `innerHTML` con datos del usuario:
```js
// ❌ Inseguro
document.getElementById('saludo').innerHTML = nombre;

// ✅ Seguro
document.getElementById('saludo').textContent = nombre;
```

---

## 3. CSRF (Cross-Site Request Forgery)

### Implementación de token CSRF en PHP
```php
// Al iniciar sesión o cargar el formulario:
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// En el formulario HTML:
echo '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';

// Al procesar el POST:
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    die('Token CSRF inválido');
}
```

---

## 4. Manejo seguro de contraseñas

### ❌ Inseguro
```php
// Nunca guardar en texto plano o MD5/SHA1
$pass = md5($_POST['password']);
```

### ✅ Seguro
```php
// Al registrar:
$hash = password_hash($_POST['password'], PASSWORD_BCRYPT);

// Al verificar login:
if (password_verify($_POST['password'], $hash_de_bd)) {
    // autenticado
}
```

---

## 5. Protección de páginas con sesión

### ❌ Inseguro — página sin verificación
```php
<?php
// dashboard.php — cualquiera puede acceder
echo "Bienvenido al panel";
```

### ✅ Seguro
```php
<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}
```

---

## 6. Subida de archivos

### ✅ Validación correcta
```php
$tipos_permitidos = ['image/jpeg', 'image/png', 'image/gif'];
$max_size = 2 * 1024 * 1024; // 2 MB

$finfo = new finfo(FILEINFO_MIME_TYPE);
$tipo_real = $finfo->file($_FILES['archivo']['tmp_name']);

if (!in_array($tipo_real, $tipos_permitidos)) {
    die('Tipo de archivo no permitido');
}
if ($_FILES['archivo']['size'] > $max_size) {
    die('Archivo demasiado grande');
}

// Renombrar el archivo para evitar ejecución de código
$nombre_nuevo = uniqid() . '.jpg';
move_uploaded_file($_FILES['archivo']['tmp_name'], 'uploads/' . $nombre_nuevo);
```

---

## 7. Manejo de errores — no exponer info técnica

### ❌ Inseguro
```php
// Mostrar errores en producción
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

### ✅ Seguro (producción)
```php
ini_set('display_errors', 0);
error_reporting(0);
ini_set('log_errors', 1);
ini_set('error_log', '/ruta/privada/errores.log');
```

---

## 8. Headers de seguridad HTTP (recomendados)

```php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
```

---

## Checklist rápido de seguridad

| Punto | Verificar |
|-------|-----------|
| SQL Injection | Todos los queries usan prepared statements |
| XSS | Todo output usa htmlspecialchars() |
| CSRF | Formularios POST tienen token |
| Autenticación | Cada página protegida verifica sesión |
| Contraseñas | Se usa password_hash/password_verify |
| Archivos | Se valida tipo real y tamaño |
| Errores | display_errors = Off en producción |
| Sesiones | session_start() al inicio, session_destroy() al cerrar |
