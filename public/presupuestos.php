<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/views/layout.php';
require __DIR__ . '/../src/dominio/trabajos.php';

// Solo clientes ven esta página
requerir_rol('cliente');

$idCliente = (int) $_SESSION['usuario_id'];
$pdo = conectar();
$errores = [];
$mensaje = flash_get();

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
            "SELECT p.id_presupuesto, p.id_trabajo, t.estado
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

        // El nuevo estado del trabajo lo dicta el mapa de transiciones
        $nuevoEstado = $accion === 'aprobar' ? 'aprobado' : 'rechazado';
        if (!puede_pasar($presupuesto['estado'], $nuevoEstado)) {
            throw new DomainException('Ese cambio no está permitido para este trabajo.');
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

            $mensaje = 'Presupuesto aprobado. El taller ya puede empezar la reparación.';
        } else {
            $mensaje = 'Presupuesto rechazado.';
        }

        $pdo->prepare("UPDATE presupuestos SET estado = ?, fecha_respuesta = NOW() WHERE id_presupuesto = ?")
            ->execute([$nuevoEstado === 'aprobado' ? 'aprobado' : 'rechazado', $idPresupuesto]);
        $pdo->prepare("UPDATE trabajos SET estado = ? WHERE id_trabajo = ?")
            ->execute([$nuevoEstado, $presupuesto['id_trabajo']]);

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

    // PRG: si todo salió bien, redirigir para que recargar no repita la acción
    if ($mensaje !== '' && empty($errores)) {
        flash_set($mensaje);
        header('Location: presupuestos.php');
        exit;
    }
}

// Presupuestos de los vehículos de este cliente
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

inicio_html('Mis presupuestos');
?>
  <h1>Mis presupuestos</h1>
  <?php mostrar_mensajes($mensaje, $errores); ?>

  <?php if (empty($presupuestos)): ?>
    <p class="vacio">Todavía no tenés presupuestos.</p>
  <?php endif; ?>

  <?php foreach ($presupuestos as $p): ?>
    <div class="tarjeta <?= $p['estado'] === 'enviado' ? 'destacada' : '' ?>">
      <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <div>
          <h3 style="margin:0">Presupuesto #<?= (int) $p['id_presupuesto'] ?> · <?= htmlspecialchars($p['modelo']) ?> · <?= htmlspecialchars($p['patente']) ?></h3>
          <div class="detalle vacio">Enviado: <?= htmlspecialchars($p['fecha_envio']) ?></div>
        </div>
        <?= badge_estado($p['estado']) ?>
      </div>

      <div class="tabla-wrap" style="margin:14px 0;">
        <table class="tabla">
          <tr><th>Descripción</th><th class="num">Cant.</th><th class="num">Precio</th><th class="num">Subtotal</th></tr>
          <?php foreach ($p['items'] as $it): ?>
            <tr>
              <td><?= htmlspecialchars($it['descripcion']) ?></td>
              <td class="num"><?= (int) $it['cantidad'] ?></td>
              <td class="num">$ <?= number_format($it['precio_unitario'], 2, ',', '.') ?></td>
              <td class="num">$ <?= number_format($it['cantidad'] * $it['precio_unitario'], 2, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <h2 style="margin:0 0 4px; text-align:right;">Total: $ <?= number_format($p['total'], 2, ',', '.') ?></h2>

      <?php if ($p['estado'] === 'enviado'): ?>
        <div class="acciones">
          <form method="POST" action="presupuestos.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="id_presupuesto" value="<?= (int) $p['id_presupuesto'] ?>">
            <input type="hidden" name="accion" value="aprobar">
            <button type="submit" class="btn btn-grande">Aprobar</button>
          </form>
          <form method="POST" action="presupuestos.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="id_presupuesto" value="<?= (int) $p['id_presupuesto'] ?>">
            <input type="hidden" name="accion" value="rechazar">
            <button type="submit" class="btn btn-outline btn-grande">Rechazar</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

<?php
link_panel();
fin_html();