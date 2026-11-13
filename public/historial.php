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

$iniciales = strtoupper(mb_substr($usuario['nombre'], 0, 1) . mb_substr($usuario['apellido'], 0, 1));
$tieneTel = trim((string) ($usuario['telefono'] ?? '')) !== '';

inicio_html('Mi perfil');
?>
  <div class="encabezado-pagina">
    <div>
      <h1>Mi Perfil y Seguridad</h1>
      <p class="subtitulo">Mantené actualizado tu teléfono para recibir avisos por WhatsApp y confirmaciones de turnos.</p>
    </div>
    <a href="panel" class="btn btn-outline">← Volver al inicio</a>
  </div>

  <?php mostrar_mensajes($mensaje, $errores); ?>

  <div class="panel-grid">
    <div class="panel-principal">
      <section class="tarjeta">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px;">
          <div class="hub-icon orange" style="width:36px; height:36px;"><?= icono_svg('user') ?></div>
          <h2 style="margin:0;">Datos personales</h2>
        </div>

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
            Teléfono / WhatsApp de contacto
            <input type="tel" name="telefono" maxlength="30" value="<?= htmlspecialchars($usuario['telefono'] ?? '') ?>" placeholder="+54 11 2345-6789">
          </label>
          <label class="campo completo">
            Email de acceso
            <input type="email" value="<?= htmlspecialchars($usuario['email']) ?>" disabled>
            <small>El email no se puede modificar por seguridad.</small>
          </label>
          <div class="completo">
            <button type="submit" class="btn">Guardar datos</button>
          </div>
        </form>
      </section>

      <section class="tarjeta">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px;">
          <div class="hub-icon sky" style="width:36px; height:36px;"><?= icono_svg('shield') ?></div>
          <h2 style="margin:0;">Cambiar clave de acceso</h2>
        </div>

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
            <button type="submit" class="btn btn-outline">Actualizar clave</button>
          </div>
        </form>
      </section>
    </div>

    <aside class="panel-lateral" aria-label="Estado de cuenta">
      <div style="display:flex; align-items:center; gap:14px;">
        <div class="marca-iso" style="width:48px; height:48px; font-size:1.1rem; border-radius:12px;">
          <?= htmlspecialchars($iniciales) ?>
        </div>
        <div>
          <strong style="color:#fff; display:block;"><?= htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']) ?></strong>
          <span class="subtitulo"><?= htmlspecialchars($usuario['email']) ?></span>
        </div>
      </div>

      <div class="meta-tile">
        <span>Canal de avisos WhatsApp</span>
        <?php if ($tieneTel): ?>
          <strong style="color:#34d399;">Activo · <?= htmlspecialchars((string) $usuario['telefono']) ?></strong>
        <?php else: ?>
          <strong style="color:#fca5a5;">Pendiente — cargá tu teléfono</strong>
        <?php endif; ?>
      </div>

      <div style="font-size:0.78rem; color:var(--text-secondary); line-height:1.5;">
        Recibirás notificaciones por mail y contacto directo del mecánico cuando haya presupuestos listos o cuando tu vehículo esté terminado.
      </div>
    </aside>
  </div>
<?php fin_html(); ?>
