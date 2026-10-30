<?php
require __DIR__ . '/../src/config/database.php';

// Iniciar la sesión en cada request, antes de cualquier salida HTML
session_start();

// Si ya hay una sesión activa, no mostramos el login de nuevo
if (isset($_SESSION['usuario_id'])) {
    header('Location: panel.php');
    exit;
}

$errores = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $clave = $_POST['clave'] ?? '';

    if ($email === '' || $clave === '') {
        $errores[] = 'Completá el email y la clave.';
    } else {
        try {
            $pdo = conectar();

            // Sentencia preparada: el email nunca se mete directo en el SQL
            $stmt = $pdo->prepare("SELECT id_usuario, nombre, password_hash, rol FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();

            // Mismo mensaje si el email no existe o la clave es incorrecta,
            // así no le revelamos a un atacante qué emails están registrados
            if ($usuario && password_verify($clave, $usuario['password_hash'])) {
                // Cambiar el id de sesión evita que alguien robe una sesión previa
                session_regenerate_id(true);

                $_SESSION['usuario_id'] = $usuario['id_usuario'];
                $_SESSION['nombre'] = $usuario['nombre'];
                $_SESSION['rol'] = $usuario['rol'];

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
    <label>Email <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required></label><br>
    <label>Clave <input type="password" name="clave" required></label><br>
    <button type="submit">Entrar</button>
  </form>

  <p>¿No tenés cuenta? <a href="registro.php">Registrate</a></p>
</body>
</html>