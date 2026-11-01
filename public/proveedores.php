<?php
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/views/layout.php';

requerir_rol('admin');

$pdo = conectar();
$errores = [];
$mensaje = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'alta') {
        $nombre = trim($_POST['nombre'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $cuit = trim($_POST['cuit'] ?? '');

        if ($nombre === '' || mb_strlen($nombre) > 120) {
            $errores[] = 'El nombre es obligatorio (hasta 120 caracteres).';
        }
        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 150)) {
            $errores[] = 'El email no es válido.';
        }
        if ($cuit !== '' && preg_match('/^\d{2}-?\d{8}-?\d$/', $cuit) !== 1) {
            $errores[] = 'El CUIT no es válido. Ejemplo: 30-12345678-9.';
        }

        if (!$errores) {
            $pdo->prepare('INSERT INTO proveedores (nombre, telefono, email, cuit) VALUES (?, ?, ?, ?)')
                ->execute([$nombre, $telefono ?: null, $email ?: null, $cuit ?: null]);
            flash_set('Proveedor agregado.');
            header('Location: proveedores.php');
            exit;
        }
    }

    if ($accion === 'eliminar') {
        $id = (int) ($_POST['id_proveedor'] ?? 0);
        try {
            $pdo->prepare('DELETE FROM proveedores WHERE id_proveedor = ?')->execute([$id]);
            flash_set('Proveedor eliminado.');
            header('Location: proveedores.php');
            exit;
        } catch (PDOException $e) {
            // Si tiene compras registradas no se puede borrar, para no perder el historial
            if ($e->getCode() === '23000') {
                $errores[] = 'No podés eliminar un proveedor con compras registradas.';
            } else {
                error_log('proveedores: ' . $e->getMessage());
                $errores[] = 'Error interno. Intentá de nuevo más tarde.';
            }
        }
    }
}

$proveedores = $pdo->query('SELECT * FROM proveedores ORDER BY nombre')->fetchAll();

inicio_html('Proveedores');
?>
<h1>Proveedores</h1>
<?php mostrar_mensajes($mensaje, $errores); ?>

<div class="lista">
  <?php if (!$proveedores): ?>
    <p class="vacio">Todavía no cargaste proveedores.</p>
  <?php endif; ?>
  <?php foreach ($proveedores as $p): ?>
    <div class="item">
      <div>
        <div class="titulo"><?= htmlspecialchars($p['nombre']) ?></div>
        <div class="detalle">
          <?= htmlspecialchars($p['telefono'] ?? 'Sin teléfono') ?>
          · <?= htmlspecialchars($p['email'] ?? 'Sin email') ?>
          · CUIT <?= htmlspecialchars($p['cuit'] ?? '—') ?>
        </div>
      </div>
      <form method="POST" action="proveedores.php" onsubmit="return confirm('¿Eliminar este proveedor?');">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="accion" value="eliminar">
        <input type="hidden" name="id_proveedor" value="<?= (int) $p['id_proveedor'] ?>">
        <button type="submit" class="btn btn-outline">Eliminar</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<section class="tarjeta" style="margin-top:24px">
  <h2>Agregar proveedor</h2>
  <form method="POST" action="proveedores.php" class="formulario">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="accion" value="alta">

    <label class="campo completo">Nombre
      <input type="text" name="nombre" maxlength="120" required>
    </label>
    <label class="campo">Teléfono
      <input type="tel" name="telefono" maxlength="30">
    </label>
    <label class="campo">Email
      <input type="email" name="email" maxlength="150">
    </label>
    <label class="campo completo">CUIT (opcional)
      <input type="text" name="cuit" maxlength="13" placeholder="30-12345678-9">
    </label>
    <div class="completo">
      <button type="submit" class="btn btn-grande">Agregar proveedor</button>
    </div>
  </form>
</section>
<?php
fin_html();