<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/csrf.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/views/layout.php';

$errores = [];
$nombre = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $clave = $_POST['clave'] ?? '';

    // Validaciones en el servidor (nunca confiar solo en el HTML)
    if ($nombre === '' || strlen($nombre) > 100) {
        $errores[] = 'El nombre es obligatorio (máximo 100 caracteres).';
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 150) {
        $errores[] = 'El email no es válido.';
    }
    if (strlen($clave) < 8) {
        $errores[] = 'La clave debe tener al menos 8 caracteres.';
    }

    if (empty($errores)) {
        try {
            $pdo = conectar();

            // La clave nunca se guarda en texto plano
            $hash = password_hash($clave, PASSWORD_DEFAULT);

            // El rol lo fija el servidor, no el formulario
            $stmt = $pdo->prepare("INSERT INTO usuarios (email, password_hash, nombre, rol) VALUES (?, ?, ?, 'cliente')");
            $stmt->execute([$email, $hash, $nombre]);

            // PRG: el mensaje se muestra en el login, y recargar no vuelve a crear la cuenta
            flash_set('Cuenta creada. Ya podés iniciar sesión.');
            header('Location: login.php');
            exit;
        } catch (PDOException $e) {
            // SQLSTATE 23000 = violación de restricción (email duplicado por el UNIQUE)
            if ($e->getCode() === '23000') {
                $errores[] = 'Ya existe una cuenta con ese email.';
            } else {
                $errores[] = 'Error interno. Intentá de nuevo más tarde.';
                error_log($e->getMessage());
            }
        }
    }
}

inicio_html('Crear cuenta');
mostrar_mensajes('', $errores);
?>
  <h1>Crear cuenta</h1>

  <form method="POST" action="registro.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <label>Nombre <input type="text" name="nombre" value="<?= htmlspecialchars($nombre) ?>" required></label><br>
    <label>Email <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required></label><br>
    <label>Clave (mínimo 8) <input type="password" name="clave" required></label><br>
    <button type="submit">Registrarme</button>
  </form>

  <p>¿Ya tenés cuenta? <a href="login.php">Iniciar sesión</a></p>
<?php
fin_html();