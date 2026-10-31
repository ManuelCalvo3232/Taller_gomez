<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/csrf.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/dominio/cuentas.php';
require __DIR__ . '/../src/views/layout.php';

$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $email = trim($_POST['email'] ?? '');

    if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
        try {
            $pdo = conectar();
            $stmt = $pdo->prepare(
                'SELECT id_usuario, nombre, email FROM usuarios
                 WHERE email = ? AND email_verificado_en IS NULL'
            );
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();

            if ($usuario) {
                $token = crear_token_verificacion($pdo, (int) $usuario['id_usuario']);
                enviar_verificacion_mail($usuario['nombre'], $usuario['email'], $token);
            }
        } catch (PDOException $e) {
            error_log('reenviar_verificacion: ' . $e->getMessage());
        }
    }

    // Misma respuesta exista o no el email, para no revelar qué cuentas hay
    flash_set('Si el email está registrado y sin verificar, te enviamos un nuevo enlace.');
    header('Location: login.php');
    exit;
}

inicio_html('Reenviar verificación');
?>
  <div class="auth">
    <div class="tarjeta destacada">
      <h1>Reenviar verificación</h1>
      <p>Ingresá el email de tu cuenta y te enviamos un nuevo enlace.</p>
      <form method="POST" action="reenviar_verificacion.php" class="formulario">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <label class="campo completo">Email
          <input type="email" name="email" maxlength="150" value="<?= htmlspecialchars($email) ?>" required>
        </label>
        <div class="completo">
          <button type="submit" class="btn btn-grande">Enviar enlace</button>
        </div>
      </form>
    </div>
  </div>
<?php
fin_html();