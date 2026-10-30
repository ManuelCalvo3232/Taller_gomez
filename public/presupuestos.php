<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';

// Solo clientes ven esta página
requerir_rol('cliente');

$idCliente = (int) $_SESSION['usuario_id'];
$pdo = conectar();
$errores = [];
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    $idPresupuesto = filter_var($_POST['id_presupuesto'] ?? '', FILTER_VALIDATE_INT);
    $accion = $_POST['accion'] ?? '';

    try {
        if ($idPresupuesto === false || !in_array($accion, ['aprobar', 'rechazar'], true)) {
            throw new DomainException('Solicitud inválida.');
        }

        // El presupuesto tiene que ser de un vehículo de este cliente y estar pendiente
        $stmt = $pdo->prepare(
            "SELECT p.id_presupuesto, p.id_trabajo
             FROM presupuestos p
             JOIN trabajos t ON t.id_trabajo = p.id_trabajo
             JOIN turnos tu ON tu.id_turno = t.id_turno
             JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
             WHERE p.id_presupuesto = ? AND v.id_cliente = ? AND p.estado = 'enviado'"
        );
        $stmt->execute([$idPresupuesto, $idCliente]);
        $presupuesto = $stmt->fetch();

        if (!$presupuesto) {
            throw new DomainException('El presupuesto no existe o ya fue respondido.');
        }

        $pdo->beginTransaction();

        if ($accion === 'aprobar') {
            // Descontar el stock de cada repuesto del presupuesto
            $stmt = $pdo->prepare(
                "SELECT i.id_repuesto, i.cantidad, r.nombre
                 FROM items_presupuesto i
                 JOIN repuestos r ON r.id_repuesto = i.id_repuesto
                 WHERE i.id_presupuesto = ?"
            );
            $stmt->execute([$idPresupuesto]);
            $repuestos = $stmt->fetchAll();

            // "stock >= ?" evita dejar el stock en negativo; si falla, se revierte todo
            $stmtStock = $pdo->prepare(
                "UPDATE repuestos SET stock = stock - ? WHERE id_repuesto = ? AND stock >= ?"
            );
            foreach ($repuestos as $r) {
                $stmtStock->execute([$r['cantidad'], $r['id_repuesto'], $r['cantidad']]);
                if ($stmtStock->rowCount() === 0) {
                    throw new DomainException("No hay stock suficiente de {$r['nombre']}. Consultá con el taller.");
                }
            }

            $pdo->prepare("UPDATE presupuestos SET estado = 'aprobado', fecha_respuesta = NOW() WHERE id_presupuesto = ?")
                ->execute([$idPresupuesto]);
            $pdo->prepare("UPDATE trabajos SET estado = 'aprobado' WHERE id_trabajo = ?")
                ->execute([$presupuesto['id_trabajo']]);

            $mensaje = 'Presupuesto aprobado. El taller ya puede empezar la reparación.';
        } else {
            $pdo->prepare("UPDATE presupuestos SET estado = 'rechazado', fecha_respuesta = NOW() WHERE id_presupuesto = ?")
                ->execute([$idPresupuesto]);
            $pdo->prepare("UPDATE trabajos SET estado = 'rechazado' WHERE id_trabajo = ?")
                ->execute([$presupuesto['id_trabajo']]);

            $mensaje = 'Presupuesto rechazado.';
        }

        $pdo->commit();

    } catch (DomainException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errores[] = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errores[] = 'Error interno. Intentá de nuevo más tarde.';
        error_log($e->getMessage());
    }
}

// Listar los presupuestos de los vehículos de este cliente
$stmt = $pdo->prepare(
    "SELECT p.id_presupuesto, p.total, p.estado, p.fecha_envio, p.fecha_respuesta,
            v.patente, v.modelo
     FROM presupuestos p
     JOIN trabajos t ON t.id_trabajo = p.id_trabajo
     JOIN turnos tu ON tu.id_turno = t.id_turno
     JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
     WHERE v.id_cliente = ?
     ORDER BY p.fecha_envio DESC"
);
$stmt->execute([$idCliente]);
$presupuestos = $stmt->fetchAll();

$stmtItems = $pdo->prepare(
    "SELECT descripcion, cantidad, precio_unitario FROM items_presupuesto WHERE id_presupuesto = ?"
);
foreach ($presupuestos as &$p) {
    $stmtItems->execute([$p['id_presupuesto']]);
    $p['items'] = $stmtItems->fetchAll();
}
unset($p);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mis presupuestos - Taller Gómez</title>
</head>
<body>
  <h1>Mis presupuestos</h1>

  <?php if ($mensaje): ?>
    <p style="color:green"><?= htmlspecialchars($mensaje) ?></p>
  <?php endif; ?>
  <?php foreach ($errores as $error): ?>
    <p style="color:red"><?= htmlspecialchars($error) ?></p>
  <?php endforeach; ?>

  <?php if (empty($presupuestos)): ?>
    <p>Todavía no tenés presupuestos.</p>
  <?php endif; ?>

  <?php foreach ($presupuestos as $p): ?>
    <div style="border:1px solid #ccc; padding:8px; margin-bottom:12px;">
      <strong>Presupuesto #<?= (int) $p['id_presupuesto'] ?></strong> · <?= htmlspecialchars($p['modelo']) ?> · <?= htmlspecialchars($p['patente']) ?><br>
      Enviado: <?= htmlspecialchars($p['fecha_envio']) ?><br>

      <table border="1" cellpadding="4" style="margin:8px 0;">
        <tr><th>Descripción</th><th>Cant.</th><th>Precio unit.</th><th>Subtotal</th></tr>
        <?php foreach ($p['items'] as $it): ?>
          <tr>
            <td><?= htmlspecialchars($it['descripcion']) ?></td>
            <td><?= (int) $it['cantidad'] ?></td>
            <td>$ <?= number_format($it['precio_unitario'], 2, ',', '.') ?></td>
            <td>$ <?= number_format($it['cantidad'] * $it['precio_unitario'], 2, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>

      <strong>Total: $ <?= number_format($p['total'], 2, ',', '.') ?></strong><br>
      Estado: <?= htmlspecialchars($p['estado']) ?>

      <?php if ($p['estado'] === 'enviado'): ?>
        <div style="margin-top:8px;">
          <form method="POST" action="presupuestos.php" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="id_presupuesto" value="<?= (int) $p['id_presupuesto'] ?>">
            <input type="hidden" name="accion" value="aprobar">
            <button type="submit">Aprobar</button>
          </form>
          <form method="POST" action="presupuestos.php" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="id_presupuesto" value="<?= (int) $p['id_presupuesto'] ?>">
            <input type="hidden" name="accion" value="rechazar">
            <button type="submit">Rechazar</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <p><a href="panel.php">Volver al panel</a></p>
</body>
</html>