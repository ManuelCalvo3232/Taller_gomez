<?php
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/dominio/vehiculos.php';
require __DIR__ . '/../src/views/layout.php';

requerir_rol('cliente');

$pdo = conectar();
$idUsuario = (int) $_SESSION['usuario_id'];
$errores = [];
$mensaje = flash_get();

// Trae un vehículo solo si pertenece al cliente logueado
function vehiculo_propio(PDO $pdo, int $id, int $idUsuario): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM vehiculos WHERE id_vehiculo = ? AND id_cliente = ?');
    $stmt->execute([$id, $idUsuario]);
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $accion = $_POST['accion'] ?? '';
    $idPost = (int) ($_POST['id_vehiculo'] ?? 0);

    if ($accion === 'eliminar') {
        if ($idPost === 0 || vehiculo_propio($pdo, $idPost, $idUsuario) === null) {
            $errores[] = 'Vehículo no encontrado.';
        } else {
            try {
                $pdo->prepare('DELETE FROM vehiculos WHERE id_vehiculo = ? AND id_cliente = ?')
                    ->execute([$idPost, $idUsuario]);
                flash_set('Vehículo eliminado.');
                header('Location: vehiculos.php');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errores[] = 'No podés eliminar un vehículo que tiene turnos. Podés editarlo.';
                } else {
                    error_log('vehiculos eliminar: ' . $e->getMessage());
                    $errores[] = 'Error interno. Intentá de nuevo más tarde.';
                }
            }
        }
    }

    if ($accion === 'guardar') {
        [$errores, $datos] = validar_vehiculo($_POST);

        if (!$errores) {
            try {
                if ($idPost > 0) {
                    if (vehiculo_propio($pdo, $idPost, $idUsuario) === null) {
                        throw new DomainException('Vehículo no encontrado.');
                    }
                    $pdo->prepare(
                        'UPDATE vehiculos SET tipo = ?, patente = ?, modelo = ?, anio = ?, kilometros = ?, cilindrada = ?
                         WHERE id_vehiculo = ? AND id_cliente = ?'
                    )->execute([$datos['tipo'], $datos['patente'], $datos['modelo'], $datos['anio'],
                        $datos['kilometros'], $datos['cilindrada'], $idPost, $idUsuario]);
                    flash_set('Vehículo actualizado.');
                } else {
                    $pdo->prepare(
                        'INSERT INTO vehiculos (id_cliente, tipo, patente, modelo, anio, kilometros, cilindrada)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$idUsuario, $datos['tipo'], $datos['patente'], $datos['modelo'],
                        $datos['anio'], $datos['kilometros'], $datos['cilindrada']]);
                    flash_set('Vehículo agregado.');
                }
                header('Location: vehiculos.php');
                exit;
            } catch (DomainException $e) {
                $errores[] = $e->getMessage();
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errores[] = 'Esa patente ya está registrada en el taller.';
                } else {
                    error_log('vehiculos guardar: ' . $e->getMessage());
                    $errores[] = 'Error interno. Intentá de nuevo más tarde.';
                }
            }
        }
    }
}

// Qué vehículo se está editando (GET para cargarlo, POST para mantenerlo si hubo error)
$idEdicion = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (int) ($_POST['id_vehiculo'] ?? 0)
    : (int) ($_GET['id'] ?? 0);

$valores = ['tipo' => 'auto', 'patente' => '', 'marca' => '', 'modelo' => '', 'anio' => '', 'kilometros' => '', 'cilindrada' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($valores) as $campo) {
        $valores[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }
} elseif ($idEdicion > 0) {
    $actual = vehiculo_propio($pdo, $idEdicion, $idUsuario);
    if ($actual === null) {
        $errores[] = 'Vehículo no encontrado.';
        $idEdicion = 0;
    } else {
        $valores['tipo'] = $actual['tipo'];
        $valores['patente'] = $actual['patente'];
        $valores['anio'] = (string) $actual['anio'];
        $valores['kilometros'] = (string) $actual['kilometros'];
        $valores['cilindrada'] = (string) ($actual['cilindrada'] ?? '');
        if ($actual['tipo'] === 'moto') {
            [$marca, $modelo] = array_pad(explode(' ', $actual['modelo'], 2), 2, '');
            $valores['marca'] = $marca;
            $valores['modelo'] = $modelo;
        } else {
            $valores['modelo'] = $actual['modelo'];
        }
    }
}

$stmt = $pdo->prepare('SELECT * FROM vehiculos WHERE id_cliente = ? ORDER BY id_vehiculo');
$stmt->execute([$idUsuario]);
$misVehiculos = $stmt->fetchAll();

$anioMax = (int) date('Y') + 1;

inicio_html('Mis vehículos');
?>
<h1>Mis vehículos</h1>
<?php mostrar_mensajes($mensaje, $errores); ?>

<div class="lista">
  <?php if (!$misVehiculos): ?>
    <p class="vacio">Todavía no tenés vehículos cargados.</p>
  <?php endif; ?>
  <?php foreach ($misVehiculos as $vehiculo): ?>
    <div class="item <?= $idEdicion === (int) $vehiculo['id_vehiculo'] ? 'asignado' : '' ?>">
      <div>
        <div class="titulo"><?= htmlspecialchars($vehiculo['modelo']) ?>
          <span class="badge badge-cerrado"><?= htmlspecialchars($vehiculo['tipo']) ?></span></div>
        <div class="detalle">
          Patente <?= htmlspecialchars($vehiculo['patente']) ?> · Año <?= (int) $vehiculo['anio'] ?>
          · <?= number_format((int) $vehiculo['kilometros'], 0, ',', '.') ?> km
          <?= $vehiculo['cilindrada'] ? ' · ' . (int) $vehiculo['cilindrada'] . ' cc' : '' ?>
        </div>
      </div>
      <div class="acciones">
        <a class="btn btn-outline" href="vehiculos.php?id=<?= (int) $vehiculo['id_vehiculo'] ?>">Editar</a>
        <form method="POST" action="vehiculos.php" onsubmit="return confirm('¿Eliminar este vehículo?');">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="accion" value="eliminar">
          <input type="hidden" name="id_vehiculo" value="<?= (int) $vehiculo['id_vehiculo'] ?>">
          <button type="submit" class="btn btn-outline">Eliminar</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<section class="tarjeta" style="margin-top:24px">
  <h2><?= $idEdicion > 0 ? 'Editar vehículo' : 'Agregar vehículo' ?></h2>

  <form method="post" action="vehiculos.php" id="form-vehiculo" class="formulario" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id_vehiculo" value="<?= $idEdicion ?>">

    <fieldset class="tipo completo">
      <legend>Tipo de vehículo</legend>
      <label>
        <input type="radio" name="tipo" value="auto" <?= $valores['tipo'] !== 'moto' ? 'checked' : '' ?>>
        Auto
      </label>
      <label>
        <input type="radio" name="tipo" value="moto" <?= $valores['tipo'] === 'moto' ? 'checked' : '' ?>>
        Moto
      </label>
    </fieldset>

    <label class="campo">
      Patente
      <input type="text" name="patente" id="patente" maxlength="10"
             value="<?= htmlspecialchars($valores['patente']) ?>" placeholder="AB123CD">
      <small id="ayuda-patente"></small>
    </label>

    <div data-solo="auto" class="grupo">
      <label class="campo">
        Modelo
        <input type="text" name="modelo" maxlength="100"
               value="<?= htmlspecialchars($valores['tipo'] !== 'moto' ? $valores['modelo'] : '') ?>"
               placeholder="VW Gol 1.6">
      </label>
    </div>

    <div data-solo="moto" class="grupo">
      <label class="campo">
        Marca
        <input type="text" name="marca" maxlength="50" value="<?= htmlspecialchars($valores['marca']) ?>" placeholder="Honda">
      </label>
      <label class="campo">
        Modelo
        <input type="text" name="modelo" maxlength="50"
               value="<?= htmlspecialchars($valores['tipo'] === 'moto' ? $valores['modelo'] : '') ?>"
               placeholder="Wave 110">
      </label>
      <label class="campo">
        Cilindrada (cc)
        <input type="number" name="cilindrada" min="50" max="3000" value="<?= htmlspecialchars($valores['cilindrada']) ?>">
      </label>
    </div>

    <label class="campo">
      Año
      <input type="number" name="anio" min="1950" max="<?= $anioMax ?>" value="<?= htmlspecialchars($valores['anio']) ?>">
    </label>

    <label class="campo">
      Kilómetros
      <input type="number" name="kilometros" min="0" value="<?= htmlspecialchars($valores['kilometros']) ?>">
    </label>

    <div class="completo" style="display:flex; gap:12px; flex-wrap:wrap;">
      <button type="submit" class="btn btn-grande"><?= $idEdicion > 0 ? 'Guardar cambios' : 'Agregar vehículo' ?></button>
      <?php if ($idEdicion > 0): ?>
        <a class="btn btn-outline" href="vehiculos.php">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>
</section>

<script>
(() => {
    const form = document.getElementById('form-vehiculo');
    const ayuda = document.getElementById('ayuda-patente');
    const textoAyuda = {
        auto: 'Auto: AB123CD o ABC123',
        moto: 'Moto: A123BCD o 123BCD',
    };

    // Muestra los campos del tipo elegido. Los ocultos se deshabilitan para que no se envíen.
    function mostrarTipo() {
        const tipo = form.querySelector('input[name="tipo"]:checked').value;
        form.querySelectorAll('[data-solo]').forEach((bloque) => {
            const activo = bloque.dataset.solo === tipo;
            bloque.hidden = !activo;
            bloque.querySelectorAll('input').forEach((input) => {
                input.disabled = !activo;
            });
        });
        ayuda.textContent = textoAyuda[tipo];
    }

    form.querySelectorAll('input[name="tipo"]').forEach((radio) => {
        radio.addEventListener('change', mostrarTipo);
    });
    mostrarTipo();
})();
</script>
<?php
fin_html();