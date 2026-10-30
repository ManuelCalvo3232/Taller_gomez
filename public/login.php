<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/csrf.php';

// La sesión ya se inicia dentro de csrf.php
if (isset($_SESSION['usuario_id'])) {
    header('Location: panel.php');
    exit;
}

$errores = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    $email = trim($_POST['email'] ?? '');
    $clave = $_POST['clave'] ?? '';

    if ($email === '' || $clave === '') {
        $errores[] = 'Completá el email y la clave.';
    } else {
        try {
            $pdo = conectar();

            $stmt = $pdo->prepare("SELECT id_usuario, nombre, password_hash, rol FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();

            if ($usuario && password_verify($clave, $usuario['password_hash'])) {
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

            $errores[] = 'Email o clave incorrectos.';
        } catch (PDOException $e) {
            $errores[] = 'Error interno. Intentá de nuevo más tarde.';
            error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Iniciar sesión - Taller Gómez</title>
</head>
<body>
  <h1>Iniciar sesión</h1>

  <?php foreach ($errores as $error): ?>
    <p style="color:red"><?= htmlspecialchars($error) ?></p>
  <?php endforeach; ?>

  <form method="POST" action="login.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <label>Email <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required></label><br>
    <label>Clave <input type="password" name="clave" required></label><br>
    <button type="submit">Entrar</button>
  </form>

  <p>¿No tenés cuenta? <a href="registro.php">Registrate</a></p>
</body>
</html>