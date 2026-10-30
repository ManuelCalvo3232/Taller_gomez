<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/csrf.php';

$errores = [];
$exito = false;
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

            // Sentencia preparada. El rol lo fija el servidor, no el formulario
            $sql = "INSERT INTO usuarios (email, password_hash, nombre, rol) VALUES (?, ?, ?, 'cliente')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$email, $hash, $nombre]);

            $exito = true;
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registro - Taller Gómez</title>
</head>
<body>
  <h1>Crear cuenta</h1>

  <?php if ($exito): ?>
    <p>Cuenta creada. <a href="login.php">Iniciar sesión</a></p>
  <?php else: ?>
    <?php foreach ($errores as $error): ?>
      <p style="color:red"><?= htmlspecialchars($error) ?></p>
    <?php endforeach; ?>

    <form method="POST" action="registro.php">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <label>Nombre <input type="text" name="nombre" value="<?= htmlspecialchars($nombre) ?>" required></label><br>
      <label>Email <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required></label><br>
      <label>Clave (mínimo 8) <input type="password" name="clave" required></label><br>
      <button type="submit">Registrarme</button>
    </form>
  <?php endif; ?>
</body>
</html>