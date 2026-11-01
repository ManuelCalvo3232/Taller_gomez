<?php
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/dominio/cuentas.php';
require __DIR__ . '/../src/views/layout.php';

requerir_rol('cliente');

$pdo = conectar();
$idUsuario = (int) $_SESSION['usuario_id'];
$errores = [];
$mensaje = flash_get();

$stmt = $pdo->prepare('SELECT nombre, apellido, email, telefono FROM usuarios WHERE id_usuario = ?');
$stmt->execute([$idUsuario]);
$usuario = $stmt->fetch();

// Teléfono: opcional. Acepta +, números, espacios, guiones y paréntesis (6 a 30 caracteres)
function telefono_valido(string $telefono): bool
{
    return preg_match('/^\+?[0-9 ()\-]{6,30}$/', $telefono) === 1
        && preg_match_all('/\d/', $telefono) >= 6;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'datos') {
        $nombre = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');

        if ($nombre === '' || mb_strlen($nombre) > 100) {
            $errores[] = 'El nombre es obligatorio (máximo 100 caracteres).';
        }
        if ($apellido === '' || mb_strlen($apellido) > 100) {
            $errores[] = 'El apellido es obligatorio (máximo 100 caracteres).';
        }
        if ($telefono !== '' && !telefono_valido($telefono)) {
            $errores[] = 'El teléfono no es válido. Ejemplo: +54 11 2345-6789.';
        }

        if (!$errores) {
            $pdo->prepare('UPDATE usuarios SET nombre = ?, apellido = ?, telefono = ? WHERE id_usuario = ?')
                ->execute([$nombre, $apellido, $telefono === '' ? null : $telefono, $idUsuario]);
            $_SESSION['nombre'] = $nombre;

            flash_set('Datos actualizados.');
            header('Location: perfil');
            exit;
        }

        // Si hay error, se muestran los valores que cargó el usuario
        $usuario['nombre'] = $nombre;
        $usuario['apellido'] = $apellido;
        $usuario['telefono'] = $telefono;
    }

    if ($accion === 'clave') {
        $actual = $_POST['clave_actual'] ?? '';
        $nueva = $_POST['clave_nueva'] ?? '';
        $repetir = $_POST['clave_repetir'] ?? '';

        $stmt = $pdo->prepare('SELECT password_hash FROM usuarios WHERE id_usuario = ?');
        $stmt->execute([$idUsuario]);
        $hashActual = $stmt->fetchColumn();

        if (!password_verify($actual, $hashActual)) {
            $errores[] = 'La clave actual no es correcta.';
        } elseif (!clave_valida($nueva)) {
            $errores[] = 'La clave nueva debe tener al menos 8 caracteres, con una mayúscula, una minúscula, un número y un carácter especial.';
        } elseif ($nueva !== $repetir) {
            $errores[] = 'La clave nueva y su repetición no coinciden.';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id_usuario = ?')
                ->execute([password_hash($nueva, PASSWORD_DEFAULT), $idUsuario]);

            // Se cierran las sesiones de la API abiertas con la clave anterior y se abre una nueva para esta sesión
            $pdo->prepare('DELETE FROM tokens_api WHERE id_usuario = ?')->execute([$idUsuario]);
            $tokenApi = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO tokens_api (token_hash, id_usuario, expira_en) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 8 HOUR))')
                ->execute([hash('sha256', $tokenApi), $idUsuario]);
            $pdo->commit();

            $_SESSION['api_token'] = $tokenApi;
            flash_set('Clave actualizada.');
            header('Location: perfil');
            exit;
        }
    }
}

inicio_html('Mi perfil');
?>
<h1>Mi perfil</h1>
<?php mostrar_mensajes($mensaje, $errores); ?>

<section class="tarjeta">
  <h2>Mis datos</h2>
  <form method="POST" action="perfil" class="formulario">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="accion" value="datos">

    <label class="campo">
      Nombre
      <input type="text" name="nombre" maxlength="100" value="<?= htmlspecialchars($usuario['nombre']) ?>" required>
    </label>
    <label class="campo">
      Apellido
      <input type="text" name="apellido" maxlength="100" value="<?= htmlspecialchars($usuario['apellido']) ?>" required>
    </label>
    <label class="campo completo">
      Teléfono
      <input type="tel" name="telefono" maxlength="30" value="<?= htmlspecialchars($usuario['telefono'] ?? '') ?>" placeholder="+54 11 2345-6789">
    </label>
    <label class="campo completo">
      Email
      <input type="email" value="<?= htmlspecialchars($usuario['email']) ?>" disabled>
      <small>El email no se puede cambiar por ahora.</small>
    </label>
    <div class="completo">
      <button type="submit" class="btn btn-grande">Guardar datos</button>
    </div>
  </form>
</section>

<section class="tarjeta" style="margin-top:24px">
  <h2>Cambiar clave</h2>
  <form method="POST" action="perfil" class="formulario">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="accion" value="clave">

    <label class="campo completo">
      Clave actual
      <input type="password" name="clave_actual" required>
    </label>
    <label class="campo">
      Clave nueva
      <input type="password" name="clave_nueva" required>
      <small>Mínimo 8 caracteres, con mayúscula, minúscula, número y símbolo.</small>
    </label>
    <label class="campo">
      Repetir clave nueva
      <input type="password" name="clave_repetir" required>
    </label>
    <div class="completo">
      <button type="submit" class="btn btn-grande">Cambiar clave</button>
    </div>
  </form>
</section>
<?php
fin_html();