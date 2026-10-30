<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/csrf.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/auth/limite_login.php';
require __DIR__ . '/../src/views/layout.php';

// La sesión ya se inicia dentro de csrf.php
if (isset($_SESSION['usuario_id'])) {
    header('Location: panel.php');
    exit;
}

$errores = [];
$email = '';
$mensaje = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    $email = trim($_POST['email'] ?? '');
    $clave = $_POST['clave'] ?? '';
    $ip = ip_cliente();

    if ($email === '' || $clave === '') {
        $errores[] = 'Completá el email y la clave.';
    } else {
        try {
            $pdo = conectar();

            // Antes de verificar la clave: si la IP superó el límite, no seguimos
            if (login_bloqueado($pdo, $ip)) {
                $errores[] = 'Demasiados intentos fallidos. Esperá ' . VENTANA_LOGIN_MINUTOS . ' minutos y probá de nuevo.';
            } else {
                $stmt = $pdo->prepare("SELECT id_usuario, nombre, password_hash, rol FROM usuarios WHERE email = ?");
                $stmt->execute([$email]);
                $usuario = $stmt->fetch();

                if ($usuario && password_verify($clave, $usuario['password_hash'])) {
                    limpiar_fallos_login($pdo, $ip);

                    // Cambiar el id de sesión evita la fijación de sesión
                    session_regenerate_id(true);

                    $_SESSION['usuario_id'] = $usuario['id_usuario'];
                    $_SESSION['nombre'] = $usuario['nombre'];
                    $_SESSION['rol'] = $usuario['rol'];

                    // Token para la API de Node: se guarda solo su hash en la base
                    $tokenApi = bin2hex(random_bytes(32));
                    $stmt = $pdo->prepare(
                        "INSERT INTO tokens_api (token_hash, id_usuario, expira_en)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 8 HOUR))"
                    );
                    $stmt->execute([hash('sha256', $tokenApi), $usuario['id_usuario']]);
                    $_SESSION['api_token'] = $tokenApi;

                    header('Location: panel.php');
                    exit;
                }

                // Fallo: lo registramos para el límite. Mismo mensaje si el email no existe
                registrar_fallo_login($pdo, $ip);
                $errores[] = 'Email o clave incorrectos.';
            }
        } catch (PDOException $e) {
            $errores[] = 'Error interno. Intentá de nuevo más tarde.';
            error_log($e->getMessage());
        }
    }
}

inicio_html('Iniciar sesión');
mostrar_mensajes($mensaje, $errores);
?>
  <h1>Iniciar sesión</h1>

  <form method="POST" action="login.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <label>Email <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required></label><br>
    <label>Clave <input type="password" name="clave" required></label><br>
    <button type="submit">Entrar</button>
  </form>

  <p>¿No tenés cuenta? <a href="registro.php">Registrate</a></p>
<?php
fin_html();