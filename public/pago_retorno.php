<?php
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/views/layout.php';

requerir_login();

// Mercado Pago devuelve al cliente acá. El estado que se muestra sale de la base,
// que se actualiza con el webhook, no de lo que dice la URL.
$idPago = filter_var($_GET['external_reference'] ?? '', FILTER_VALIDATE_INT);
$estado = null;

if ($idPago) {
    $pdo = conectar();
    $stmt = $pdo->prepare(
        "SELECT pg.estado FROM pagos pg
         JOIN presupuestos p ON p.id_presupuesto = pg.id_presupuesto
         JOIN trabajos t ON t.id_trabajo = p.id_trabajo
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         WHERE pg.id_pago = ? AND v.id_cliente = ?"
    );
    $stmt->execute([$idPago, (int) $_SESSION['usuario_id']]);
    $estado = $stmt->fetchColumn() ?: null;
}

inicio_html('Resultado del pago');
?>
  <section class="tarjeta destacada">
    <?php if ($estado === 'aprobado'): ?>
      <h1>¡Pago acreditado!</h1>
      <p>Gracias. El taller ya tiene registrado tu pago.</p>
    <?php elseif ($estado === 'rechazado'): ?>
      <h1>El pago no se completó</h1>
      <p>Podés intentarlo de nuevo desde tus presupuestos.</p>
    <?php elseif ($estado === 'pendiente'): ?>
      <h1>Pago en proceso</h1>
      <p>Mercado Pago todavía no confirmó el pago. Revisá tus presupuestos en unos minutos.</p>
    <?php else: ?>
      <h1>No encontramos ese pago</h1>
      <p>Revisá tus presupuestos para ver el estado.</p>
    <?php endif; ?>
    <p><a href="presupuestos.php">Ir a mis presupuestos</a></p>
  </section>
<?php
fin_html();