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
                header('Location: vehiculos');
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
                header('Location: vehiculos');
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
  <div class="encabezado-pagina">
    <div>
      <h1>Mi Garage · Vehículos</h1>
      <p class="subtitulo">Administrá tus autos y motos guardados para pedir turnos en 1 clic.</p>
    </div>
    <a href="turno_nuevo" class="btn"><?= icono_svg('calendar') ?><span>Pedir turno</span></a>
  </div>

  <?php mostrar_mensajes($mensaje, $errores); ?>

  <div class="panel-grid" style="grid-template-columns: minmax(0, 1fr) 390px;">
    <div class="panel-principal">
      <?php if (!$misVehiculos): ?>
        <div class="tarjeta">
          <p class="vacio" style="margin:0;">Todavía no tenés vehículos cargados. Agregá el primero desde el panel derecho.</p>
        </div>
      <?php else: ?>
        <div class="garage-grid">
          <?php foreach ($misVehiculos as $vehiculo): ?>
            <article class="vehiculo-card <?= $idEdicion === (int) $vehiculo['id_vehiculo'] ? 'editando' : '' ?>">
              <div class="vehiculo-top">
                <div>
                  <span class="badge <?= $vehiculo['tipo'] === 'moto' ? 'badge-activo' : 'badge-pendiente' ?>" style="margin-bottom:8px;">
                    <?= $vehiculo['tipo'] === 'moto' ? 'Moto' : 'Auto' ?>
                  </span>
                  <h3 style="margin:0; font-size:1.05rem;"><?= htmlspecialchars($vehiculo['modelo']) ?></h3>
                </div>
                <span class="patente-placa"><?= htmlspecialchars($vehiculo['patente']) ?></span>
              </div>

              <div class="vehiculo-specs">
                <div class="spec-box">
                  <span>Año</span>
                  <strong class="mono"><?= (int) $vehiculo['anio'] ?></strong>
                </div>
                <div class="spec-box" style="<?= $vehiculo['cilindrada'] ? '' : 'grid-column: span 2;' ?>">
                  <span>Kilometraje</span>
                  <strong class="mono"><?= number_format((int) $vehiculo['kilometros'], 0, ',', '.') ?> km</strong>
                </div>
                <?php if ($vehiculo['cilindrada']): ?>
                  <div class="spec-box">
                    <span>Cilindrada</span>
                    <strong class="mono"><?= (int) $vehiculo['cilindrada'] ?> cc</strong>
                  </div>
                <?php endif; ?>
              </div>

              <div class="acciones" style="margin-top:auto; padding-top:4px; border-top:1px solid var(--border);">
                <a class="btn btn-outline" style="flex:1; min-height:38px;" href="vehiculos?id=<?= (int) $vehiculo['id_vehiculo'] ?>">Editar</a>
                <form method="POST" action="vehiculos" onsubmit="return confirm('¿Eliminar este vehículo?');">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                  <input type="hidden" name="accion" value="eliminar">
                  <input type="hidden" name="id_vehiculo" value="<?= (int) $vehiculo['id_vehiculo'] ?>">
                  <button type="submit" class="btn btn-peligro" style="min-height:38px;">Eliminar</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <aside class="panel-lateral" aria-label="Formulario de vehículo">
      <h2 style="margin:0 0 4px; font-size:1.1rem;"><?= $idEdicion > 0 ? 'Editar vehículo' : 'Agregar vehículo' ?></h2>
      <p class="subtitulo" style="margin-bottom:6px;">Catálogo oficial de marcas y modelos para autos y motos.</p>

      <form method="post" action="vehiculos" id="form-vehiculo" class="formulario" novalidate style="grid-template-columns:1fr;">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id_vehiculo" value="<?= $idEdicion ?>">
        <?php campos_vehiculo($anioMax); ?>
        <div class="completo" style="display:flex; gap:10px; flex-wrap:wrap; margin-top:4px;">
          <button type="submit" class="btn btn-grande"><?= $idEdicion > 0 ? 'Guardar cambios' : 'Agregar vehículo' ?></button>
          <?php if ($idEdicion > 0): ?>
            <a class="btn btn-outline btn-grande" href="vehiculos">Cancelar edición</a>
          <?php endif; ?>
        </div>
      </form>
    </aside>
  </div>

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
link_panel();
fin_html();
