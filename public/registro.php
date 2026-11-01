<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/csrf.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/dominio/cuentas.php';
require __DIR__ . '/../src/views/layout.php';

$errores = [];
$nombre = '';
$apellido = '';
$email = '';
$pdo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $clave = $_POST['clave'] ?? '';

    if ($nombre === '' || mb_strlen($nombre) > 100) {
        $errores[] = 'El nombre es obligatorio (máximo 100 caracteres).';
    }
    if ($apellido === '' || mb_strlen($apellido) > 100) {
        $errores[] = 'El apellido es obligatorio (máximo 100 caracteres).';
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 150) {
        $errores[] = 'El email no es válido.';
    }
    if (!clave_valida($clave)) {
        $errores[] = 'La clave debe tener al menos 8 caracteres, con una mayúscula, una minúscula, un número y un carácter especial.';
    }

    if (!$errores) {
        try {
            $pdo = conectar();
            $pdo->beginTransaction();

            // El rol lo fija el servidor, no el formulario
            $stmt = $pdo->prepare(
                "INSERT INTO usuarios (email, password_hash, nombre, apellido, rol) VALUES (?, ?, ?, ?, 'cliente')"
            );
            $stmt->execute([$email, password_hash($clave, PASSWORD_DEFAULT), $nombre, $apellido]);
            $idUsuario = (int) $pdo->lastInsertId();

            $token = crear_token_verificacion($pdo, $idUsuario);
            $pdo->commit();

            // El mail sale después de guardar: si falla, la cuenta igual queda creada
            $enviado = enviar_verificacion_mail($nombre, $email, $token);

            flash_set($enviado
                ? 'Cuenta creada. Te enviamos un mail para confirmar tu dirección.'
                : 'Cuenta creada, pero no pudimos enviar el mail de confirmación. Pedí uno nuevo desde "Reenviar verificación".');
            header('Location: login');
            exit;
        } catch (PDOException $e) {
            if ($pdo !== null && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
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

      <form method="POST" action="registro" class="formulario">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label class="campo">Nombre(s)
          <input type="text" name="nombre" maxlength="100" value="<?= htmlspecialchars($nombre) ?>" required>
        </label>
        <label class="campo">Apellido(s)
          <input type="text" name="apellido" maxlength="100" value="<?= htmlspecialchars($apellido) ?>" required>
        </label>
        <label class="campo completo">Email
          <input type="email" name="email" maxlength="150" value="<?= htmlspecialchars($email) ?>" required>
        </label>
        <label class="campo completo">Clave
          <input type="password" name="clave" required>
          <small>Mínimo 8 caracteres, con mayúscula, minúscula, número y símbolo.</small>
        </label>
        <div class="completo">
          <button type="submit" class="btn btn-grande">Registrarme</button>
        </div>
      </form>

      <p style="margin-top:16px">¿Ya tenés cuenta? <a href="login">Iniciar sesión</a></p>
    </div>
  </div>
<?php
fin_html();