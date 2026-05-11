# Fixes de Validaciones — Patrones listos para aplicar

## 1. Validación básica de campos requeridos (PHP)

```php
// [AUTOFIX] Validación de campos requeridos
$errores = [];

if (empty(trim($_POST['nombre'] ?? ''))) {
    $errores[] = 'El nombre es requerido.';
}
if (empty(trim($_POST['email'] ?? ''))) {
    $errores[] = 'El email es requerido.';
}

if (!empty($errores)) {
    // Mostrar errores y detener ejecución
    foreach ($errores as $error) {
        echo '<p class="error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    exit();
}
```

---

## 2. Validación de email (PHP)

```php
// [AUTOFIX] Validar formato de email
$email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errores[] = 'El email no tiene un formato válido.';
}
```

---

## 3. Validación de números enteros (PHP)

```php
// [AUTOFIX] Validar que sea número entero positivo
$cantidad = filter_var($_POST['cantidad'], FILTER_VALIDATE_INT);
if ($cantidad === false || $cantidad <= 0) {
    $errores[] = 'La cantidad debe ser un número entero mayor a 0.';
}
```

---

## 4. Validación de decimales / precios (PHP)

```php
// [AUTOFIX] Validar precio decimal positivo
$precio = filter_var($_POST['precio'], FILTER_VALIDATE_FLOAT);
if ($precio === false || $precio < 0) {
    $errores[] = 'El precio debe ser un número válido mayor o igual a 0.';
}
```

---

## 5. Sanitizar texto (PHP)

```php
// [AUTOFIX] Limpiar texto de entrada
$nombre = htmlspecialchars(trim($_POST['nombre']), ENT_QUOTES, 'UTF-8');
$descripcion = htmlspecialchars(trim($_POST['descripcion']), ENT_QUOTES, 'UTF-8');
```

---

## 6. Validar longitud de campos (PHP)

```php
// [AUTOFIX] Validar longitud mínima y máxima
$password = $_POST['password'] ?? '';
if (strlen($password) < 8) {
    $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
}
if (strlen($nombre) > 100) {
    $errores[] = 'El nombre no puede tener más de 100 caracteres.';
}
```

---

## 7. Verificar que un ID existe antes de usarlo (PHP)

```php
// [AUTOFIX] Verificar que el ID es válido antes de consultar
$id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
if (!$id || $id <= 0) {
    header('Location: lista.php');
    exit();
}

// Verificar que existe en BD
$stmt = $conn->prepare("SELECT id FROM tabla WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    header('Location: lista.php');
    exit();
}
```

---

## 8. Validación en JavaScript (Frontend)

```js
// [AUTOFIX] Validación frontend antes de enviar formulario
document.getElementById('miFormulario').addEventListener('submit', function(e) {
    let errores = [];
    
    const nombre = document.getElementById('nombre').value.trim();
    const email = document.getElementById('email').value.trim();
    const cantidad = document.getElementById('cantidad').value;
    
    if (!nombre) errores.push('El nombre es requerido.');
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errores.push('El email no es válido.');
    }
    if (!cantidad || isNaN(cantidad) || parseInt(cantidad) <= 0) {
        errores.push('La cantidad debe ser mayor a 0.');
    }
    
    if (errores.length > 0) {
        e.preventDefault();
        alert(errores.join('\n'));
    }
});
```

---

## 9. Prevenir doble envío de formulario (JS)

```js
// [AUTOFIX] Deshabilitar botón al enviar para evitar doble submit
document.getElementById('miFormulario').addEventListener('submit', function() {
    const btn = this.querySelector('button[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Procesando...';
    }
});
```

---

## 10. Redirigir siempre después de procesar POST (PHP)

```php
// [AUTOFIX] Redirigir después de procesar para evitar reenvío del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ... procesar datos ...
    
    $_SESSION['mensaje'] = 'Operación exitosa.';
    header('Location: lista.php');
    exit(); // SIEMPRE exit() después de header()
}

// Mostrar mensaje de sesión si existe
if (isset($_SESSION['mensaje'])) {
    echo '<p class="exito">' . htmlspecialchars($_SESSION['mensaje'], ENT_QUOTES, 'UTF-8') . '</p>';
    unset($_SESSION['mensaje']);
}
```

---

## 11. Responsive — fixes CSS comunes

```css
/* [AUTOFIX] Viewport meta tag — agregar en el <head> del HTML */
/* <meta name="viewport" content="width=device-width, initial-scale=1.0"> */

/* [AUTOFIX] Tablas responsivas */
.tabla-contenedor {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* [AUTOFIX] Imágenes responsivas */
img {
    max-width: 100%;
    height: auto;
}

/* [AUTOFIX] Media query básica para móvil */
@media (max-width: 768px) {
    .columna {
        width: 100%;
        float: none;
    }
    
    button, .btn {
        width: 100%;
        min-height: 44px; /* tamaño mínimo para touch */
    }
}
```
