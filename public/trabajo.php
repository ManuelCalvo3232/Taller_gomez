<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/views/layout.php';
require __DIR__ . '/../src/dominio/trabajos.php';
require __DIR__ . '/../src/integraciones/api_node.php';
require __DIR__ . '/../src/integraciones/api_node.php';
$config = require __DIR__ . '/../config/config.php';

requerir_rol('mecanico');

$idMecanico = (int) $_SESSION['usuario_id'];
$idTrabajo = filter_var($_GET['id'] ?? $_POST['id_trabajo'] ?? '', FILTER_VALIDATE_INT);
if ($idTrabajo === false) {
    http_response_code(400);
    exit('Trabajo inválido.');
}

$pdo = conectar();

// Traer el trabajo solo si está asignado a este mecánico (control de acceso por dato)
function cargar_trabajo(PDO $pdo, int $idTrabajo, int $idMecanico): array|false
{
    $stmt = $pdo->prepare(
        "SELECT t.id_trabajo, t.estado, tu.fecha_hora, tu.motivo,
                v.patente, v.modelo, v.anio, v.kilometros,
                u.nombre AS cliente, u.telefono
         FROM trabajos t
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         JOIN usuarios u ON u.id_usuario = v.id_cliente
         WHERE t.id_trabajo = ? AND t.id_mecanico = ?"
    );
    $stmt->execute([$idTrabajo, $idMecanico]);
    return $stmt->fetch();
}

$trabajo = cargar_trabajo($pdo, $idTrabajo, $idMecanico);
if (!$trabajo) {
    http_response_code(404);
    exit('Trabajo no encontrado o no asignado a vos.');
}

$errores = [];
$mensaje = flash_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    $accion = $_POST['accion'] ?? '';

    try {
        if ($accion === 'diagnostico') {
            $descripcion = trim($_POST['descripcion'] ?? '');
            if ($descripcion === '' || strlen($descripcion) > 2000) {
                throw new DomainException('El diagnóstico es obligatorio (máximo 2000 caracteres).');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO diagnosticos (id_trabajo, descripcion) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)"
            );
            $stmt->execute([$idTrabajo, $descripcion]);
            $mensaje = 'Diagnóstico guardado.';

        } elseif ($accion === 'presupuesto') {
            if (!admite_presupuesto($trabajo['estado'])) {
                throw new DomainException('Este trabajo ya no admite presupuestos nuevos.');
            }

            $stmt = $pdo->prepare("SELECT 1 FROM diagnosticos WHERE id_trabajo = ?");
            $stmt->execute([$idTrabajo]);
            if (!$stmt->fetch()) {
                throw new DomainException('Primero cargá el diagnóstico.');
            }

            $idsRepuesto = $_POST['id_repuesto'] ?? [];
            $descripciones = $_POST['descripcion'] ?? [];
            $cantidades = $_POST['cantidad'] ?? [];
            $precios = $_POST['precio'] ?? [];

            $items = [];
            $total = 0.0;

            foreach ($descripciones as $i => $descripcionItem) {
                $descripcionItem = trim($descripcionItem);
                $idRepuesto = filter_var($idsRepuesto[$i] ?? '', FILTER_VALIDATE_INT);

                if ($descripcionItem === '' && $idRepuesto === false) {
                    continue;
                }

                $cantidad = filter_var($cantidades[$i] ?? '', FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                if ($cantidad === false) {
                    throw new DomainException('La cantidad de cada ítem tiene que ser al menos 1.');
                }

                if ($idRepuesto !== false) {
                    // Repuesto: el precio sale de la base, nunca del formulario
                    $stmt = $pdo->prepare("SELECT nombre, precio FROM repuestos WHERE id_repuesto = ?");
                    $stmt->execute([$idRepuesto]);
                    $repuesto = $stmt->fetch();
                    if (!$repuesto) {
                        throw new DomainException('Un repuesto seleccionado no existe.');
                    }
                    $tipo = 'repuesto';
                    $descripcionFinal = $descripcionItem !== '' ? $descripcionItem : $repuesto['nombre'];
                    $precio = (float) $repuesto['precio'];
                } else {
                    $precio = filter_var($precios[$i] ?? '', FILTER_VALIDATE_FLOAT);
                    if ($precio === false || $precio < 0) {
                        throw new DomainException('El precio de mano de obra no es válido.');
                    }
                    $tipo = 'mano_obra';
                    $descripcionFinal = $descripcionItem;
                    $idRepuesto = null;
                }

                if ($descripcionFinal === '') {
                    throw new DomainException('Cada ítem necesita una descripción.');
                }
                if (strlen($descripcionFinal) > 150) {
                    throw new DomainException('Una descripción supera los 150 caracteres.');
                }

                $items[] = [
                    'id_repuesto' => $idRepuesto,
                    'tipo' => $tipo,
                    'descripcion' => $descripcionFinal,
                    'cantidad' => $cantidad,
                    'precio' => $precio,
                ];
                $total += $cantidad * $precio;
            }

            if (empty($items)) {
                throw new DomainException('Agregá al menos un ítem al presupuesto.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO presupuestos (id_trabajo, total, estado) VALUES (?, ?, 'enviado')"
            );
            $stmt->execute([$idTrabajo, round($total, 2)]);
            $idPresupuesto = (int) $pdo->lastInsertId();

            $stmtItem = $pdo->prepare(
                "INSERT INTO items_presupuesto
                 (id_presupuesto, id_repuesto, tipo, descripcion, cantidad, precio_unitario)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($items as $it) {
                $stmtItem->execute([
                    $idPresupuesto,
                    $it['id_repuesto'],
                    $it['tipo'],
                    $it['descripcion'],
                    $it['cantidad'],
                    $it['precio'],
                ]);
            }

            if (puede_pasar($trabajo['estado'], 'presupuestado')) {
                $pdo->prepare("UPDATE trabajos SET estado = 'presupuestado' WHERE id_trabajo = ?")
                    ->execute([$idTrabajo]);
            }

            $pdo->commit();
            $enviado = notificar_presupuesto_api($idPresupuesto, $_SESSION['api_token'] ?? '', $config['api_url']);
            $mensaje = 'Presupuesto cargado por $ ' . number_format($total, 2, ',', '.') . '. '
                     . ($enviado ? 'Se envió el aviso al cliente.' : 'No se pudo enviar el aviso por mail.');

        } elseif ($accion === 'listo') {
            if (!puede_pasar($trabajo['estado'], 'listo')) {
                throw new DomainException('Solo se puede marcar listo un trabajo aprobado por el cliente.');
    }
            $pdo->prepare("UPDATE trabajos SET estado = 'listo' WHERE id_trabajo = ?")
                ->execute([$idTrabajo]);

        $config = require __DIR__ . '/../config/config.php';
        $avisado = notificar_listo_api($idTrabajo, $_SESSION['api_token'] ?? '', $config['api_url']);
        $mensaje = $avisado
            ? 'Vehículo marcado como listo. Se envió el aviso al cliente.'
            : 'Vehículo marcado como listo. No se pudo enviar el aviso por mail.';

        } else {
            throw new DomainException('Acción no válida.');
        }

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

    if ($mensaje !== '' && empty($errores)) {
        flash_set($mensaje);
        header('Location: trabajo.php?id=' . $idTrabajo);
        exit;
    }

    $trabajo = cargar_trabajo($pdo, $idTrabajo, $idMecanico);
}

$stmt = $pdo->prepare("SELECT descripcion FROM diagnosticos WHERE id_trabajo = ?");
$stmt->execute([$idTrabajo]);
$diagnostico = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT id_presupuesto, total, estado, fecha_envio FROM presupuestos WHERE id_trabajo = ? ORDER BY fecha_envio DESC");
$stmt->execute([$idTrabajo]);
$presupuestos = $stmt->fetchAll();

$repuestos = $pdo->query("SELECT id_repuesto, nombre, precio FROM repuestos ORDER BY nombre")->fetchAll();

// Etapas para la barra de progreso. Índice del estado actual dentro de la secuencia
$etapas = ['Recibido', 'Diagnóstico', 'Presupuesto', 'Aprobado', 'Listo'];
$indiceEstado = match ($trabajo['estado']) {
    'recibido' => 0,
    'en_diagnostico' => 1,
    'presupuestado' => 2,
    'aprobado', 'en_reparacion' => 3,
    'listo' => 4,
    default => -1, // rechazado: no se muestra progreso
};

inicio_html('Trabajo #' . $idTrabajo);
?>
  <h1>Trabajo #<?= (int) $idTrabajo ?> · <?= htmlspecialchars($trabajo['modelo']) ?> · <?= htmlspecialchars($trabajo['patente']) ?></h1>
  <?php mostrar_mensajes($mensaje, $errores); ?>

  <?php if ($indiceEstado >= 0): ?>
    <ol class="etapas">
      <?php foreach ($etapas as $i => $etapa): ?>
        <li class="<?= $i < $indiceEstado ? 'hecha' : ($i === $indiceEstado ? 'activa' : '') ?>"><?= $etapa ?></li>
      <?php endforeach; ?>
    </ol>
  <?php else: ?>
    <p><?= badge_estado($trabajo['estado']) ?></p>
  <?php endif; ?>

  <div class="tarjeta">
    <h3>Vehículo y cliente</h3>
    <div class="detalle">
      Dueño: <strong><?= htmlspecialchars($trabajo['cliente']) ?></strong> · Tel: <?= htmlspecialchars($trabajo['telefono'] ?? '-') ?><br>
      Año <?= (int) $trabajo['anio'] ?> · <?= (int) $trabajo['kilometros'] ?> km<br>
      Motivo del ingreso: <?= htmlspecialchars($trabajo['motivo']) ?>
    </div>
  </div>

  <div class="tarjeta <?= $diagnostico ? '' : 'destacada' ?>">
    <h3>Diagnóstico</h3>
    <?php if ($diagnostico): ?>
      <p><?= nl2br(htmlspecialchars($diagnostico)) ?></p>
    <?php endif; ?>
    <form method="POST" action="trabajo.php?id=<?= (int) $idTrabajo ?>" class="formulario">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="accion" value="diagnostico">
      <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">
      <label class="campo completo">Descripción del problema
        <textarea name="descripcion" rows="3" maxlength="2000" required><?= htmlspecialchars($diagnostico ?: '') ?></textarea>
      </label>
      <div class="completo"><button type="submit" class="btn">Guardar diagnóstico</button></div>
    </form>
  </div>

  <?php if ($diagnostico && admite_presupuesto($trabajo['estado'])): ?>
  <div class="tarjeta destacada">
    <h3>Presupuesto</h3>
    <p class="detalle">Completá hasta 5 ítems. Para repuestos elegí el producto (el precio sale del stock); para mano de obra, dejá el repuesto en "Mano de obra" y escribí el precio.</p>
    <form method="POST" action="trabajo.php?id=<?= (int) $idTrabajo ?>">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="accion" value="presupuesto">
      <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">

      <?php for ($i = 0; $i < 5; $i++): ?>
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:8px; margin-bottom:10px;">
          <select name="id_repuesto[]">
            <option value="">Mano de obra</option>
            <?php foreach ($repuestos as $r): ?>
              <option value="<?= (int) $r['id_repuesto'] ?>"><?= htmlspecialchars($r['nombre']) ?> ($<?= number_format($r['precio'], 2, ',', '.') ?>)</option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="descripcion[]" maxlength="150" placeholder="Descripción">
          <input type="number" name="cantidad[]" min="1" value="1">
          <input type="number" name="precio[]" min="0" step="0.01" placeholder="Precio (mano de obra)">
        </div>
      <?php endfor; ?>

      <button type="submit" class="btn btn-grande">Cargar presupuesto</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (!empty($presupuestos)): ?>
    <div class="tarjeta">
      <h3>Presupuestos anteriores</h3>
      <div class="lista">
        <?php foreach ($presupuestos as $p): ?>
          <div class="item">
            <div>
              <div class="titulo">Presupuesto #<?= (int) $p['id_presupuesto'] ?> · $ <?= number_format($p['total'], 2, ',', '.') ?></div>
              <div class="detalle"><?= htmlspecialchars($p['fecha_envio']) ?></div>
            </div>
            <?= badge_estado($p['estado']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (puede_pasar($trabajo['estado'], 'listo')): ?>
    <div class="tarjeta destacada">
      <h3>Entrega</h3>
      <p class="detalle">El cliente aprobó el presupuesto. Cuando el vehículo esté terminado, marcalo como listo.</p>
      <form method="POST" action="trabajo.php?id=<?= (int) $idTrabajo ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="accion" value="listo">
        <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">
        <button type="submit" class="btn btn-grande">Marcar como listo</button>
      </form>
    </div>
  <?php endif; ?>

  <p><a href="trabajos.php">← Volver a trabajos</a></p>
<?php
fin_html();