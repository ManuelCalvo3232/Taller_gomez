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

// Traer los trabajos activos: los que no están asignados y los que son míos
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
mostrar_mensajes($mensaje, $errores);
?>
  <h1>Trabajos</h1>

  <h2>Sin asignar</h2>
  <?php if (empty($sinAsignar)): ?>
    <p>No hay trabajos sin asignar.</p>
  <?php endif; ?>
  <?php foreach ($sinAsignar as $t): ?>
    <div style="border:1px solid #ccc; padding:8px; margin-bottom:8px;">
      <strong>#<?= (int) $t['id_trabajo'] ?></strong> · <?= htmlspecialchars($t['modelo']) ?> (<?= (int) $t['anio'] ?>) · <?= htmlspecialchars($t['patente']) ?><br>
      Dueño: <?= htmlspecialchars($t['cliente']) ?> · Tel: <?= htmlspecialchars($t['telefono'] ?? '-') ?><br>
      Motivo: <?= htmlspecialchars($t['motivo']) ?> · Km: <?= (int) $t['kilometros'] ?><br>
      Turno: <?= htmlspecialchars($t['fecha_hora']) ?>
      <form method="POST" action="trabajos.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="id_trabajo" value="<?= (int) $t['id_trabajo'] ?>">
        <button type="submit">Asignarme</button>
      </form>
    </div>
  <?php endforeach; ?>

  <h2>Mis trabajos</h2>
  <?php if (empty($misTrabajos)): ?>
    <p>No tenés trabajos asignados.</p>
  <?php endif; ?>
  <?php foreach ($misTrabajos as $t): ?>
    <div style="border:1px solid #ccc; padding:8px; margin-bottom:8px;">
      <strong>#<?= (int) $t['id_trabajo'] ?></strong> · <?= htmlspecialchars($t['modelo']) ?> · <?= htmlspecialchars($t['patente']) ?><br>
      Estado: <?= htmlspecialchars($t['estado']) ?> · Dueño: <?= htmlspecialchars($t['cliente']) ?><br>
      <a href="trabajo.php?id=<?= (int) $t['id_trabajo'] ?>">Ver trabajo</a>
    </div>
  <?php endforeach; ?>

<?php
link_panel();
fin_html();