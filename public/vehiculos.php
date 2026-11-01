<?php
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/dominio/vehiculos.php';
require __DIR__ . '/../src/views/layout.php';
require __DIR__ . '/../src/views/campos_vehiculo.php';

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
        [$errores, $datos] = validar_vehiculo($pdo, $_POST);

        if (!$errores) {
            try {
                $columnas = [$datos['tipo'], $datos['patente'], $datos['id_marca'], $datos['id_modelo'],
                    $datos['marca_otra'], $datos['modelo_otro'], $datos['modelo'], $datos['anio'],
                    $datos['kilometros'], $datos['cilindrada']];

                if ($idPost > 0) {
                    if (vehiculo_propio($pdo, $idPost, $idUsuario) === null) {
                        throw new DomainException('Vehículo no encontrado.');
                    }
                    $pdo->prepare(
                        'UPDATE vehiculos SET tipo = ?, patente = ?, id_marca = ?, id_modelo = ?, marca_otra = ?,
                            modelo_otro = ?, modelo = ?, anio = ?, kilometros = ?, cilindrada = ?
                         WHERE id_vehiculo = ? AND id_cliente = ?'
                    )->execute([...$columnas, $idPost, $idUsuario]);
                    flash_set('Vehículo actualizado.');
                } else {
                    $pdo->prepare(
                        'INSERT INTO vehiculos (id_cliente, tipo, patente, id_marca, id_modelo, marca_otra,
                            modelo_otro, modelo, anio, kilometros, cilindrada)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$idUsuario, ...$columnas]);
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

$valores = [
    'tipo' => 'auto', 'patente' => '', 'marca_id' => '', 'marca_otra' => '', 'modelo_id' => '',
    'modelo_otro' => '', 'anio' => '', 'kilometros' => '', 'cilindrada' => '',
];

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
        $valores = valores_formulario_vehiculo($actual);
    }
}

$stmt = $pdo->prepare('SELECT * FROM vehiculos WHERE id_cliente = ? ORDER BY id_vehiculo');
$stmt->execute([$idUsuario]);
$misVehiculos = $stmt->fetchAll();

$anioMax = (int) date('Y') + 1;
$catalogo = catalogo_vehiculos($pdo);

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

    <?php campos_vehiculo($anioMax); ?>

    <div class="completo" style="display:flex; gap:12px; flex-wrap:wrap;">
      <button type="submit" class="btn btn-grande"><?= $idEdicion > 0 ? 'Guardar cambios' : 'Agregar vehículo' ?></button>
      <?php if ($idEdicion > 0): ?>
        <a class="btn btn-outline" href="vehiculos.php">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>
</section>

<script type="application/json" id="catalogo"><?= json_encode($catalogo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="inicial"><?= json_encode($valores, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/vehiculo.js"></script>
<script>
    Vehiculo.iniciar(
        document.getElementById('form-vehiculo'),
        JSON.parse(document.getElementById('catalogo').textContent),
        JSON.parse(document.getElementById('inicial').textContent)
    );
</script>
<?php
fin_html();