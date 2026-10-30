<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/views/layout.php';

// Solo clientes pueden pedir turno
requerir_rol('cliente');

$errores = [];
$mensaje = flash_get();
$valores = [
    'modelo' => '',
    'anio' => '',
    'patente' => '',
    'kilometros' => '',
    'fecha' => '',
    'hora' => '',
    'motivo' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    // Leer todos los campos y quitar espacios sobrantes
    foreach ($valores as $campo => $valor) {
        $valores[$campo] = trim($_POST[$campo] ?? '');
    }

    // La patente va en mayúsculas y sin espacios
    $patente = strtoupper(str_replace(' ', '', $valores['patente']));

    $anioMax = (int) date('Y') + 1;
    $anio = filter_var($valores['anio'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1950, 'max_range' => $anioMax],
    ]);
    $km = filter_var($valores['kilometros'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0],
    ]);

    if ($valores['modelo'] === '' || strlen($valores['modelo']) > 80) {
        $errores[] = 'El modelo es obligatorio (máximo 80 caracteres).';
    }
    if ($anio === false) {
        $errores[] = "El año tiene que estar entre 1950 y $anioMax.";
    }
    // Formatos argentinos: ABC123 (anterior a 2016) o AB123CD (Mercosur)
    if (!preg_match('/^([A-Z]{3}\d{3}|[A-Z]{2}\d{3}[A-Z]{2})$/', $patente)) {
        $errores[] = 'La patente no tiene un formato válido (ej: ABC123 o AB123CD).';
    }
    if ($km === false) {
        $errores[] = 'Los kilómetros tienen que ser un número mayor o igual a cero.';
    }
    if ($valores['motivo'] === '' || strlen($valores['motivo']) > 255) {
        $errores[] = 'El motivo es obligatorio (máximo 255 caracteres).';
    }

    // Fecha y hora: tiene que ser válida y futura
    $fechaHora = DateTime::createFromFormat('Y-m-d H:i', $valores['fecha'] . ' ' . $valores['hora']);
    if ($fechaHora === false) {
        $errores[] = 'La fecha u hora no son válidas.';
    } elseif ($fechaHora <= new DateTime()) {
        $errores[] = 'El turno tiene que ser en el futuro.';
    }

    if (empty($errores)) {
        $pdo = null;
        try {
            $pdo = conectar();
            $pdo->beginTransaction();

            $idCliente = (int) $_SESSION['usuario_id'];

            // ¿La patente ya existe?
            $stmt = $pdo->prepare("SELECT id_vehiculo, id_cliente FROM vehiculos WHERE patente = ?");
            $stmt->execute([$patente]);
            $vehiculo = $stmt->fetch();

            if ($vehiculo) {
                // Solo el dueño puede reutilizar su vehículo
                if ((int) $vehiculo['id_cliente'] !== $idCliente) {
                    throw new DomainException('Esa patente está registrada a nombre de otro cliente.');
                }
                $idVehiculo = (int) $vehiculo['id_vehiculo'];

                $stmt = $pdo->prepare("UPDATE vehiculos SET modelo = ?, anio = ?, kilometros = ? WHERE id_vehiculo = ?");
                $stmt->execute([$valores['modelo'], $anio, $km, $idVehiculo]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO vehiculos (id_cliente, patente, modelo, anio, kilometros) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$idCliente, $patente, $valores['modelo'], $anio, $km]);
                $idVehiculo = (int) $pdo->lastInsertId();
            }

            // El UNIQUE de fecha_hora bloquea los horarios ocupados
            $stmt = $pdo->prepare("INSERT INTO turnos (id_vehiculo, fecha_hora, motivo) VALUES (?, ?, ?)");
            $stmt->execute([$idVehiculo, $fechaHora->format('Y-m-d H:i:s'), $valores['motivo']]);
            $idTurno = (int) $pdo->lastInsertId();

            // Cada turno nace con su trabajo en estado "recibido"
            $stmt = $pdo->prepare("INSERT INTO trabajos (id_turno, estado) VALUES (?, 'recibido')");
            $stmt->execute([$idTurno]);

            $pdo->commit();

            // PRG: recargar no vuelve a pedir el turno
            flash_set('Turno registrado. Te avisamos cuando el taller reciba el vehículo.');
            header('Location: turno_nuevo.php');
            exit;
        } catch (DomainException $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errores[] = $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // SQLSTATE 23000: el horario ya está ocupado (UNIQUE de fecha_hora)
            if ($e->getCode() === '23000') {
                $errores[] = 'Ese horario ya está ocupado. Elegí otro.';
            } else {
                $errores[] = 'Error interno. Intentá de nuevo más tarde.';
                error_log($e->getMessage());
            }
        }
    }
}

inicio_html('Pedir turno');
mostrar_mensajes($mensaje, $errores);
?>
  <h1>Pedir turno</h1>

  <form method="POST" action="turno_nuevo.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

    <h2>Vehículo</h2>
    <label>Modelo <input type="text" name="modelo" maxlength="80" value="<?= htmlspecialchars($valores['modelo']) ?>" required></label><br>
    <label>Año <input type="number" name="anio" min="1950" max="<?= date('Y') + 1 ?>" value="<?= htmlspecialchars($valores['anio']) ?>" required></label><br>
    <label>Patente <input type="text" name="patente" maxlength="10" value="<?= htmlspecialchars($valores['patente']) ?>" required></label><br>
    <label>Kilómetros <input type="number" name="kilometros" min="0" value="<?= htmlspecialchars($valores['kilometros']) ?>" required></label><br>

    <h2>Turno</h2>
    <label>Fecha <input type="date" name="fecha" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($valores['fecha']) ?>" required></label><br>
    <label>Hora <input type="time" name="hora" value="<?= htmlspecialchars($valores['hora']) ?>" required></label><br>
    <label>Motivo <input type="text" name="motivo" maxlength="255" value="<?= htmlspecialchars($valores['motivo']) ?>" required></label><br>

    <button type="submit">Confirmar turno</button>
  </form>
<?php
link_panel();
fin_html();