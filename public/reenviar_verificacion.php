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
    header('Location: login');
    exit;
}

inicio_html('Reenviar verificación', '<script src="assets/validar.js" defer></script>');
?>
  <div class="auth">
    <aside class="hero">
      <div class="placa">TALLER GÓMEZ<small>Taller mecánico</small></div>
      <div>
        <p class="hero-titulo">Tu auto, con presupuesto antes de tocar nada.</p>
        <ul class="ficha">
          <li><span>Turno</span><span>Online, desde el celular</span></li>
          <li><span>Presupuesto</span><span>Lo aprobás antes de empezar</span></li>
          <li><span>Pago</span><span>Online o en el taller</span></li>
          <li><span>Avisos</span><span>Por mail cuando hay novedades</span></li>
        </ul>
      </div>
    </aside>
    <div class="tarjeta destacada">
      <h1>Reenviar verificación</h1>
      <p>Ingresá el email de tu cuenta y te enviamos un nuevo enlace.</p>
      <form method="POST" action="reenviar_verificacion" class="formulario" novalidate>
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