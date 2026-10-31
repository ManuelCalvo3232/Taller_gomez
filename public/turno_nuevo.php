<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/views/layout.php';

// Solo clientes pueden pedir turno
requerir_rol('cliente');

$config = require __DIR__ . '/../config/config.php';

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

    foreach ($valores as $campo => $valor) {
        $valores[$campo] = trim($_POST[$campo] ?? '');
    }

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
    if (!preg_match('/^([A-Z]{3}\d{3}|[A-Z]{2}\d{3}[A-Z]{2})$/', $patente)) {
        $errores[] = 'La patente no tiene un formato válido (ej: ABC123 o AB123CD).';
    }
    if ($km === false) {
        $errores[] = 'Los kilómetros tienen que ser un número mayor o igual a cero.';
    }
    if ($valores['motivo'] === '' || strlen($valores['motivo']) > 255) {
        $errores[] = 'El motivo es obligatorio (máximo 255 caracteres).';
    }

    // Fecha y hora: tiene que ser válida y futura. La hora sale de los botones de horarios libres
    $fechaHora = DateTime::createFromFormat('Y-m-d H:i', $valores['fecha'] . ' ' . $valores['hora']);
    if ($fechaHora === false) {
        $errores[] = 'Elegí una fecha y un horario disponible.';
    } elseif ($fechaHora <= new DateTime()) {
        $errores[] = 'El turno tiene que ser en el futuro.';
    }

    if (empty($errores)) {
        $pdo = null;
        try {
            $pdo = conectar();
            $pdo->beginTransaction();

            $idCliente = (int) $_SESSION['usuario_id'];

            $stmt = $pdo->prepare("SELECT id_vehiculo, id_cliente FROM vehiculos WHERE patente = ?");
            $stmt->execute([$patente]);
            $vehiculo = $stmt->fetch();

            if ($vehiculo) {
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

            $stmt = $pdo->prepare("INSERT INTO turnos (id_vehiculo, fecha_hora, motivo) VALUES (?, ?, ?)");
            $stmt->execute([$idVehiculo, $fechaHora->format('Y-m-d H:i:s'), $valores['motivo']]);
            $idTurno = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO trabajos (id_turno, estado) VALUES (?, 'recibido')");
            $stmt->execute([$idTurno]);

            $pdo->commit();

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
            if ($e->getCode() === '23000') {
                $errores[] = 'Ese horario ya está ocupado. Elegí otro.';
            } else {
                $errores[] = 'Error interno. Intentá de nuevo más tarde.';
                error_log($e->getMessage());
            }
        }
    }
}

// La URL de la API para cargar los horarios libres desde el navegador
$extraHead = '<meta name="api-base" content="' . htmlspecialchars($config['api_url']) . '">';

inicio_html('Pedir turno', $extraHead);
?>
  <h1>Pedir turno</h1>
  <?php mostrar_mensajes($mensaje, $errores); ?>

  <form method="POST" action="turno_nuevo.php" class="tarjeta destacada">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

    <h2 style="margin-top:0">1. Tu vehículo</h2>
    <div class="formulario">
      <label class="campo">Modelo
        <input type="text" name="modelo" maxlength="80" value="<?= htmlspecialchars($valores['modelo']) ?>" required>
      </label>
      <label class="campo">Año
        <input type="number" name="anio" min="1950" max="<?= date('Y') + 1 ?>" value="<?= htmlspecialchars($valores['anio']) ?>" required>
      </label>
      <label class="campo">Patente
        <input type="text" name="patente" maxlength="10" value="<?= htmlspecialchars($valores['patente']) ?>" required>
      </label>
      <label class="campo">Kilómetros
        <input type="number" name="kilometros" min="0" value="<?= htmlspecialchars($valores['kilometros']) ?>" required>
      </label>
      <label class="campo completo">Motivo del ingreso
        <input type="text" name="motivo" maxlength="255" value="<?= htmlspecialchars($valores['motivo']) ?>" placeholder="Ej: hace ruido la correa" required>
      </label>
    </div>

    <h2>2. Fecha y horario</h2>
    <div class="formulario">
      <label class="campo completo">Fecha
        <input type="date" id="fecha" name="fecha" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($valores['fecha']) ?>" required>
      </label>
    </div>

    <p class="campo" style="margin:14px 0 8px">Horarios libres</p>
    <div id="chips" class="horarios">
      <span class="vacio">Elegí una fecha para ver los horarios.</span>
    </div>
    <input type="hidden" id="hora" name="hora" value="<?= htmlspecialchars($valores['hora']) ?>">

    <div style="margin-top:20px">
      <button type="submit" class="btn btn-grande">Confirmar turno</button>
    </div>
  </form>

  <script>
    const API_BASE = document.querySelector('meta[name="api-base"]').content;
    const fechaInput = document.getElementById('fecha');
    const horaInput = document.getElementById('hora');
    const contenedor = document.getElementById('chips');

    function cargarHorarios() {
      contenedor.textContent = '';
      horaInput.value = '';
      if (!fechaInput.value) {
        contenedor.innerHTML = '<span class="vacio">Elegí una fecha para ver los horarios.</span>';
        return;
      }

      fetch(API_BASE + '/api/turnos/disponibles?fecha=' + encodeURIComponent(fechaInput.value))
        .then((r) => {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then((datos) => {
          if (datos.horarios_libres.length === 0) {
            contenedor.innerHTML = '<span class="vacio">No hay horarios libres ese día. Probá otra fecha.</span>';
            return;
          }
          datos.horarios_libres.forEach((hora) => {
            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'chip';
            boton.textContent = hora;
            boton.addEventListener('click', () => {
              horaInput.value = hora;
              document.querySelectorAll('.chip').forEach((c) => c.classList.remove('seleccionado'));
              boton.classList.add('seleccionado');
            });
            contenedor.appendChild(boton);
          });
        })
        .catch(() => {
          contenedor.innerHTML = '<span class="vacio">No se pudieron cargar los horarios. Revisá la conexión.</span>';
        });
    }

    fechaInput.addEventListener('change', cargarHorarios);
    if (fechaInput.value) cargarHorarios();
  </script>
<?php
link_panel();
fin_html();