<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';

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
// Mensaje de la acción anterior (si viene de un redirect)
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

            // Insertar o actualizar: el UNIQUE de id_trabajo hace que haya un solo diagnóstico
            $stmt = $pdo->prepare(
                "INSERT INTO diagnosticos (id_trabajo, descripcion) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)"
            );
            $stmt->execute([$idTrabajo, $descripcion]);
            $mensaje = 'Diagnóstico guardado.';

        } elseif ($accion === 'presupuesto') {
            // Requisito: tiene que existir diagnóstico
            $stmt = $pdo->prepare("SELECT 1 FROM diagnosticos WHERE id_trabajo = ?");
            $stmt->execute([$idTrabajo]);
            if (!$stmt->fetch()) {
                throw new DomainException('Primero cargá el diagnóstico.');
            }
            if (!in_array($trabajo['estado'], ['en_diagnostico', 'presupuestado'], true)) {
                throw new DomainException('Este trabajo ya no admite presupuestos nuevos.');
            }

            // Leer las filas del formulario (son arrays paralelos)
            $idsRepuesto = $_POST['id_repuesto'] ?? [];
            $descripciones = $_POST['descripcion'] ?? [];
            $cantidades = $_POST['cantidad'] ?? [];
            $precios = $_POST['precio'] ?? [];

            $items = [];
            $total = 0.0;

            foreach ($descripciones as $i => $descripcionItem) {
                $descripcionItem = trim($descripcionItem);
                $idRepuesto = filter_var($idsRepuesto[$i] ?? '', FILTER_VALIDATE_INT);

                // Fila vacía: la ignoramos
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
                    // Mano de obra: el precio lo carga el mecánico
                    $precio = filter_var($precios[$i] ?? '', FILTER_VALIDATE_FLOAT);
                    if ($precio === false || $precio < 0) {
                        throw new DomainException('El precio de mano de obra no es válido.');
                    }
                    $tipo = 'mano_obra';
                    $descripcionFinal = $descripcionItem;
                    $idRepuesto = null;
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
                // El total se calcula acá, en el servidor
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

            $pdo->prepare("UPDATE trabajos SET estado = 'presupuestado' WHERE id_trabajo = ?")
                ->execute([$idTrabajo]);

            $pdo->commit();
            $mensaje = 'Presupuesto cargado por $ ' . number_format($total, 2, ',', '.') . '.';

        } elseif ($accion === 'listo') {
            if (!in_array($trabajo['estado'], ['aprobado', 'en_reparacion'], true)) {
                throw new DomainException('Solo se puede marcar listo un trabajo aprobado por el cliente.');
            }
            $pdo->prepare("UPDATE trabajos SET estado = 'listo' WHERE id_trabajo = ?")
                ->execute([$idTrabajo]);
            $mensaje = 'Vehículo marcado como listo.';

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

    // PRG: si todo salió bien, redirigir para que recargar no repita la acción
    if ($mensaje !== '' && empty($errores)) {
        flash_set($mensaje);
        header('Location: trabajo.php?id=' . $idTrabajo);
        exit;
    }

    // Releer el trabajo por si cambió de estado (solo llega acá si hubo error)
    $trabajo = cargar_trabajo($pdo, $idTrabajo, $idMecanico);
}

// Datos para mostrar
$stmt = $pdo->prepare("SELECT descripcion FROM diagnosticos WHERE id_trabajo = ?");
$stmt->execute([$idTrabajo]);
$diagnostico = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT id_presupuesto, total, estado, fecha_envio FROM presupuestos WHERE id_trabajo = ? ORDER BY fecha_envio DESC");
$stmt->execute([$idTrabajo]);
$presupuestos = $stmt->fetchAll();

$repuestos = $pdo->query("SELECT id_repuesto, nombre, precio FROM repuestos ORDER BY nombre")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Trabajo #<?= (int) $idTrabajo ?> - Taller Gómez</title>
</head>
<body>
  <h1>Trabajo #<?= (int) $idTrabajo ?></h1>

  <?php if ($mensaje): ?>
    <p style="color:green"><?= htmlspecialchars($mensaje) ?></p>
  <?php endif; ?>
  <?php foreach ($errores as $error): ?>
    <p style="color:red"><?= htmlspecialchars($error) ?></p>
  <?php endforeach; ?>

  <h2>Vehículo y cliente</h2>
  <p>
    <?= htmlspecialchars($trabajo['modelo']) ?> (<?= (int) $trabajo['anio'] ?>) · <?= htmlspecialchars($trabajo['patente']) ?><br>
    Kilómetros: <?= (int) $trabajo['kilometros'] ?><br>
    Dueño: <?= htmlspecialchars($trabajo['cliente']) ?> · Tel: <?= htmlspecialchars($trabajo['telefono'] ?? '-') ?><br>
    Motivo: <?= htmlspecialchars($trabajo['motivo']) ?><br>
    Estado: <strong><?= htmlspecialchars($trabajo['estado']) ?></strong>
  </p>

  <h2>Diagnóstico</h2>
  <?php if ($diagnostico): ?>
    <p><?= nl2br(htmlspecialchars($diagnostico)) ?></p>
  <?php endif; ?>
  <form method="POST" action="trabajo.php?id=<?= (int) $idTrabajo ?>">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="accion" value="diagnostico">
    <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">
    <textarea name="descripcion" rows="3" cols="50" maxlength="2000" required><?= htmlspecialchars($diagnostico ?: '') ?></textarea><br>
    <button type="submit">Guardar diagnóstico</button>
  </form>

  <?php if ($diagnostico): ?>
  <h2>Presupuesto</h2>
  <form method="POST" action="trabajo.php?id=<?= (int) $idTrabajo ?>">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="accion" value="presupuesto">
    <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">

    <?php for ($i = 0; $i < 5; $i++): ?>
      <div>
        <select name="id_repuesto[]">
          <option value="">Mano de obra</option>
          <?php foreach ($repuestos as $r): ?>
            <option value="<?= (int) $r['id_repuesto'] ?>"><?= htmlspecialchars($r['nombre']) ?> ($<?= number_format($r['precio'], 2, ',', '.') ?>)</option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="descripcion[]" maxlength="150" placeholder="Descripción">
        <input type="number" name="cantidad[]" min="1" value="1" style="width:4em">
        <input type="number" name="precio[]" min="0" step="0.01" placeholder="Precio (solo mano de obra)">
      </div>
    <?php endfor; ?>

    <button type="submit">Cargar presupuesto</button>
  </form>

  <?php if (!empty($presupuestos)): ?>
    <h3>Presupuestos anteriores</h3>
    <ul>
      <?php foreach ($presupuestos as $p): ?>
        <li>
          #<?= (int) $p['id_presupuesto'] ?> · $ <?= number_format($p['total'], 2, ',', '.') ?> · <?= htmlspecialchars($p['estado']) ?> · <?= htmlspecialchars($p['fecha_envio']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php endif; ?>

  <?php if (in_array($trabajo['estado'], ['aprobado', 'en_reparacion'], true)): ?>
    <h2>Entrega</h2>
    <form method="POST" action="trabajo.php?id=<?= (int) $idTrabajo ?>">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="accion" value="listo">
      <input type="hidden" name="id_trabajo" value="<?= (int) $idTrabajo ?>">
      <button type="submit">Marcar como listo</button>
    </form>
  <?php endif; ?>

  <p><a href="trabajos.php">Volver a trabajos</a></p>
</body>
</html>