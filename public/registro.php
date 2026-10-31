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

            $hash = password_hash($clave, PASSWORD_DEFAULT);

            // El rol lo fija el servidor, no el formulario
            $stmt = $pdo->prepare("INSERT INTO usuarios (email, password_hash, nombre, rol) VALUES (?, ?, ?, 'cliente')");
            $stmt->execute([$email, $hash, $nombre]);

            // PRG: el mensaje se muestra en el login, y recargar no vuelve a crear la cuenta
            flash_set('Cuenta creada. Ya podés iniciar sesión.');
            header('Location: login.php');
            exit;
        } catch (PDOException $e) {
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
?>
  <div class="auth">
    <div class="tarjeta destacada">
      <h1>Crear cuenta</h1>
      <?php mostrar_mensajes('', $errores); ?>

      <form method="POST" action="registro.php" class="formulario">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label class="campo completo">Nombre
          <input type="text" name="nombre" maxlength="100" value="<?= htmlspecialchars($nombre) ?>" required>
        </label>
        <label class="campo completo">Email
          <input type="email" name="email" maxlength="150" value="<?= htmlspecialchars($email) ?>" required>
        </label>
        <label class="campo completo">Clave (mínimo 8 caracteres)
          <input type="password" name="clave" required>
        </label>
        <div class="completo">
          <button type="submit" class="btn btn-grande">Registrarme</button>
        </div>
      </form>

      <p style="margin-top:16px">¿Ya tenés cuenta? <a href="login.php">Iniciar sesión</a></p>
    </div>
  </div>
<?php
fin_html();