<?php
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/dominio/trabajos.php';
require __DIR__ . '/../src/views/layout.php';

requerir_rol('cliente');

$pdo = conectar();
$stmt = $pdo->prepare(
    "SELECT t.id_trabajo, t.estado, tu.fecha_hora, tu.motivo, v.patente, v.modelo,
            d.descripcion AS diagnostico,
            (SELECT p.total FROM presupuestos p
             WHERE p.id_trabajo = t.id_trabajo AND p.estado = 'aprobado'
             ORDER BY p.id_presupuesto DESC LIMIT 1) AS total_aprobado
     FROM trabajos t
     JOIN turnos tu ON tu.id_turno = t.id_turno
     JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
     LEFT JOIN diagnosticos d ON d.id_trabajo = t.id_trabajo
     WHERE v.id_cliente = ?
     ORDER BY tu.fecha_hora DESC"
);
$stmt->execute([(int) $_SESSION['usuario_id']]);
$trabajos = $stmt->fetchAll();

inicio_html('Historial de trabajos');
?>
<h1>Historial de trabajos</h1>

<div class="lista">
  <?php if (!$trabajos): ?>
    <p class="vacio">Todavía no tenés trabajos registrados.</p>
  <?php endif; ?>
  <?php foreach ($trabajos as $t): ?>
    <div class="item <?= $t['estado'] === 'listo' ? '' : 'asignado' ?>">
      <div>
        <div class="titulo"><?= htmlspecialchars($t['modelo']) ?> · <?= htmlspecialchars($t['patente']) ?></div>
        <div class="detalle">
          <?= date('d/m/Y H:i', strtotime($t['fecha_hora'])) ?> · <?= htmlspecialchars($t['motivo']) ?>
        </div>
        <?php if (!empty($t['diagnostico'])): ?>
          <div class="detalle">Diagnóstico: <?= htmlspecialchars($t['diagnostico']) ?></div>
        <?php endif; ?>
        <?php if ($t['total_aprobado'] !== null): ?>
          <div class="detalle">Total aprobado: $ <?= number_format((float) $t['total_aprobado'], 2, ',', '.') ?></div>
        <?php endif; ?>
      </div>
      <div><?= badge_estado($t['estado']) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php
fin_html();