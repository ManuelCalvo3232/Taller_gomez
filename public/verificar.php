<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/csrf.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/views/layout.php';

// Página pública. El token llega en el enlace del mail.
$token = (string) ($_REQUEST['token'] ?? '');
$hash = preg_match('/^[a-f0-9]{64}$/', $token) ? hash('sha256', $token) : null;
$error = null;
$pdo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    try {
        if ($hash === null) {
            throw new DomainException('El enlace no es válido.');
        }
        $pdo = conectar();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id_usuario FROM tokens_verificacion
             WHERE token_hash = ? AND usado_en IS NULL AND expira_en > NOW()
             FOR UPDATE'
        );
        $stmt->execute([$hash]);
        $fila = $stmt->fetch();
        if (!$fila) {
            throw new DomainException('El enlace venció o ya se usó.');
        }

        $pdo->prepare('UPDATE usuarios SET email_verificado_en = NOW() WHERE id_usuario = ?')
            ->execute([$fila['id_usuario']]);
        $pdo->prepare('UPDATE tokens_verificacion SET usado_en = NOW() WHERE token_hash = ?')
            ->execute([$hash]);

        $pdo->commit();

        flash_set('Cuenta verificada. Ya podés iniciar sesión.');
        header('Location: login.php');
        exit;
    } catch (DomainException $e) {
        if ($pdo !== null && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo !== null && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('verificar: ' . $e->getMessage());
        $error = 'Error interno. Intentá de nuevo más tarde.';
    }
}

// GET: solo muestra la confirmación. El botón evita que un escáner de mail consuma el enlace.
$puedeConfirmar = false;
if ($error === null && $hash !== null && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $pdo = conectar();
    $stmt = $pdo->prepare(
        'SELECT 1 FROM tokens_verificacion
         WHERE token_hash = ? AND usado_en IS NULL AND expira_en > NOW()'
    );
    $stmt->execute([$hash]);
    $puedeConfirmar = (bool) $stmt->fetchColumn();
}

inicio_html('Verificar cuenta');
?>
  <div class="auth">
    <div class="tarjeta destacada">
      <?php if ($puedeConfirmar): ?>
        <h1>Confirmá tu email</h1>
        <p>Hacé clic en el botón para activar tu cuenta.</p>
        <form method="POST" action="verificar.php" class="formulario">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
          <div class="completo">
            <button type="submit" class="btn btn-grande">Confirmar mi email</button>
          </div>
        </form>
      <?php else: ?>
        <h1>Enlace no válido</h1>
        <?php mostrar_mensajes('', $error ? [$error] : ['El enlace venció o ya se usó.']); ?>
        <p><a href="reenviar_verificacion.php">Pedir un nuevo enlace</a></p>
      <?php endif; ?>
    </div>
  </div>
<?php
fin_html();