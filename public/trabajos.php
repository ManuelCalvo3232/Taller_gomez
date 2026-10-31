<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/views/layout.php';
require __DIR__ . '/../src/dominio/trabajos.php';

// Solo mecánicos ven esta página
requerir_rol('mecanico');

$idMecanico = (int) $_SESSION['usuario_id'];
$errores = [];
$mensaje = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    $idTrabajo = filter_var($_POST['id_trabajo'] ?? '', FILTER_VALIDATE_INT);
    if ($idTrabajo === false) {
        $errores[] = 'Trabajo inválido.';
    } else {
        try {
            $pdo = conectar();

            // "id_mecanico IS NULL" evita que dos mecánicos tomen el mismo trabajo.
            // La transición recibido -> en_diagnostico sale del mapa de estados
            $stmt = $pdo->prepare(
                "UPDATE trabajos
                 SET id_mecanico = ?, estado = ?
                 WHERE id_trabajo = ? AND id_mecanico IS NULL AND estado = ?"
            );
            $stmt->execute([$idMecanico, 'en_diagnostico', $idTrabajo, 'recibido']);

            if ($stmt->rowCount() === 1) {
                $mensaje = 'Trabajo asignado. Ya podés cargar el diagnóstico.';
            } else {
                $errores[] = 'Ese trabajo ya fue tomado por otro mecánico o ya no está disponible.';
            }
        } catch (PDOException $e) {
            $errores[] = 'Error interno. Intentá de nuevo más tarde.';
            error_log($e->getMessage());
        }
    }

    // PRG: si todo salió bien, redirigir para que recargar no repita la acción
    if ($mensaje !== '' && empty($errores)) {
        flash_set($mensaje);
        header('Location: trabajos.php');
        exit;
    }
}

// Trabajos activos: los que no están asignados y los que son míos
try {
    $pdo = conectar();
    $stmt = $pdo->prepare(
        "SELECT t.id_trabajo, t.estado, t.id_mecanico,
                tu.fecha_hora, tu.motivo,
                v.patente, v.modelo, v.anio, v.kilometros,
                u.nombre AS cliente, u.telefono
         FROM trabajos t
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         JOIN usuarios u ON u.id_usuario = v.id_cliente
         WHERE t.estado NOT IN ('listo', 'rechazado')
           AND (t.id_mecanico IS NULL OR t.id_mecanico = ?)
         ORDER BY tu.fecha_hora"
    );
    $stmt->execute([$idMecanico]);
    $trabajos = $stmt->fetchAll();
} catch (PDOException $e) {
    $trabajos = [];
    $errores[] = 'No se pudieron cargar los trabajos.';
    error_log($e->getMessage());
}

$sinAsignar = array_filter($trabajos, fn($t) => $t['id_mecanico'] === null);
$misTrabajos = array_filter($trabajos, fn($t) => $t['id_mecanico'] !== null);

inicio_html('Trabajos');
?>
  <h1>Trabajos</h1>
  <?php mostrar_mensajes($mensaje, $errores); ?>

  <h2>Sin asignar</h2>
  <?php if (empty($sinAsignar)): ?>
    <p class="vacio">No hay trabajos sin asignar.</p>
  <?php endif; ?>
  <div class="lista">
    <?php foreach ($sinAsignar as $t): ?>
      <div class="item">
        <div>
          <div class="titulo">#<?= (int) $t['id_trabajo'] ?> · <?= htmlspecialchars($t['modelo']) ?> (<?= (int) $t['anio'] ?>) · <?= htmlspecialchars($t['patente']) ?></div>
          <div class="detalle">
            Motivo: <?= htmlspecialchars($t['motivo']) ?> · <?= (int) $t['kilometros'] ?> km<br>
            Dueño: <?= htmlspecialchars($t['cliente']) ?> · Tel: <?= htmlspecialchars($t['telefono'] ?? '-') ?><br>
            Turno: <?= htmlspecialchars($t['fecha_hora']) ?>
          </div>
        </div>
        <form method="POST" action="trabajos.php">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="id_trabajo" value="<?= (int) $t['id_trabajo'] ?>">
          <button type="submit" class="btn">Asignarme</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

  <h2>Mis trabajos</h2>
  <?php if (empty($misTrabajos)): ?>
    <p class="vacio">No tenés trabajos asignados.</p>
  <?php endif; ?>
  <div class="lista">
    <?php foreach ($misTrabajos as $t): ?>
      <div class="item asignado">
        <div>
          <div class="titulo">#<?= (int) $t['id_trabajo'] ?> · <?= htmlspecialchars($t['modelo']) ?> · <?= htmlspecialchars($t['patente']) ?></div>
          <div class="detalle">Dueño: <?= htmlspecialchars($t['cliente']) ?></div>
          <div style="margin-top:6px"><?= badge_estado($t['estado']) ?></div>
        </div>
        <a class="btn btn-outline" href="trabajo.php?id=<?= (int) $t['id_trabajo'] ?>">Ver trabajo</a>
      </div>
    <?php endforeach; ?>
  </div>

<?php
link_panel();
fin_html();