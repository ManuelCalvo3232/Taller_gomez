<?php

require __DIR__ . '/../src/auth/csrf.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/dominio/vehiculos.php';
require __DIR__ . '/../src/views/layout.php';
require __DIR__ . '/../src/views/campos_vehiculo.php';

requerir_rol('cliente');

$config = require __DIR__ . '/../config/config.php';
$pdo = conectar();

// Horarios de turno: cada 30 minutos entre 08:00 y 17:30
$horarios = [];
for ($min = 8 * 60; $min <= 17 * 60 + 30; $min += 30) {
    $horarios[] = sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

$anioMax = (int) date('Y') + 1;
$errores = [];
$camposVehiculo = [
    'tipo', 'patente', 'marca_id', 'marca_otra', 'modelo_id', 'modelo_otro',
    'anio', 'kilometros', 'cilindrada',
];
$v = array_fill_keys(array_merge($camposVehiculo, ['fecha', 'hora', 'motivo']), '');
$v['tipo'] = 'auto';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    foreach (array_keys($v) as $campo) {
        $v[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }

    [$erroresVehiculo, $datos] = validar_vehiculo($pdo, $v);
    $errores = array_merge($errores, $erroresVehiculo);

    if ($v['motivo'] === '' || mb_strlen($v['motivo']) > 255) {
        $errores[] = 'Contanos el motivo (hasta 255 caracteres).';
    }

    $fecha = DateTime::createFromFormat('Y-m-d', $v['fecha']);
    $fechaValida = $fecha !== false && $fecha->format('Y-m-d') === $v['fecha'];
    if (!$fechaValida) {
        $errores[] = 'Elegí una fecha válida.';
    }

    if (!in_array($v['hora'], $horarios, true)) {
        $errores[] = 'Elegí un horario de la lista.';
    }

    $fechaHora = $fechaValida && in_array($v['hora'], $horarios, true)
        ? $v['fecha'] . ' ' . $v['hora'] . ':00'
        : null;

    if ($fechaHora !== null && $fechaHora <= date('Y-m-d H:i:s')) {
        $errores[] = 'Elegí un horario futuro.';
    }

    if (!$errores) {
        try {
            $pdo->beginTransaction();

            // Si el cliente ya tiene ese vehículo (misma patente), se actualizan sus datos
            $stmt = $pdo->prepare('SELECT id_vehiculo FROM vehiculos WHERE patente = ? AND id_cliente = ?');
            $stmt->execute([$datos['patente'], $_SESSION['usuario_id']]);
            $idVehiculo = $stmt->fetchColumn();

            $columnas = [$datos['tipo'], $datos['id_marca'], $datos['id_modelo'], $datos['marca_otra'],
                $datos['modelo_otro'], $datos['modelo'], $datos['anio'], $datos['kilometros'], $datos['cilindrada']];

            if ($idVehiculo) {
                $pdo->prepare(
                    'UPDATE vehiculos SET tipo = ?, id_marca = ?, id_modelo = ?, marca_otra = ?, modelo_otro = ?,
                        modelo = ?, anio = ?, kilometros = ?, cilindrada = ?
                     WHERE id_vehiculo = ?'
                )->execute([...$columnas, $idVehiculo]);
            } else {
                $pdo->prepare(
                    'INSERT INTO vehiculos (id_cliente, patente, tipo, id_marca, id_modelo, marca_otra, modelo_otro,
                        modelo, anio, kilometros, cilindrada)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$_SESSION['usuario_id'], $datos['patente'], ...$columnas]);
                $idVehiculo = $pdo->lastInsertId();
            }

            $pdo->prepare('INSERT INTO turnos (id_vehiculo, fecha_hora, motivo) VALUES (?, ?, ?)')
                ->execute([$idVehiculo, $fechaHora, $v['motivo']]);
            $idTurno = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO trabajos (id_turno, estado) VALUES (?, 'recibido')")
                ->execute([$idTurno]);

            $pdo->commit();

            flash_set('Turno reservado. Te avisamos por mail cuando haya novedades.');
            header('Location: panel.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                $errores[] = 'Ese horario ya está reservado o la patente figura con otro cliente. Elegí otro horario o consultá con el taller.';
            } else {
                error_log('turno_nuevo: ' . $e->getMessage());
                $errores[] = 'Ocurrió un error al guardar el turno. Intentá de nuevo.';
            }
        }
    }
}

// Vehículos ya guardados del cliente, para completar el formulario con uno de ellos
$stmt = $pdo->prepare('SELECT * FROM vehiculos WHERE id_cliente = ? ORDER BY id_vehiculo');
$stmt->execute([(int) $_SESSION['usuario_id']]);
$vehiculosGuardados = array_map(function (array $fila): array {
    return valores_formulario_vehiculo($fila) + [
        'modelo' => $fila['modelo'],
    ];
}, $stmt->fetchAll());

// Valores iniciales del formulario de vehículo: lo que se envió, o vacío
$inicialVehiculo = array_intersect_key($v, array_flip($camposVehiculo));
$catalogo = catalogo_vehiculos($pdo);

$apiBase = htmlspecialchars($config['api_url'], ENT_QUOTES);
inicio_html('Pedir turno', '<meta name="api-base" content="' . $apiBase . '">');
mostrar_mensajes('', $errores);
?>

<section class="tarjeta">
    <h1>Pedir turno</h1>

    <form method="post" action="turno_nuevo.php" id="form-turno" class="formulario" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

        <?php if ($vehiculosGuardados): ?>
        <label class="campo completo" id="bloque-guardado">Vehículo guardado
            <select id="vehiculo-guardado">
                <option value="">Cargar uno nuevo</option>
            </select>
            <small>Elegí uno para completar los datos. Podés ajustarlos antes de reservar.</small>
        </label>
        <?php endif; ?>

        <?php campos_vehiculo($anioMax); ?>

        <label class="campo completo">
            Motivo
            <input type="text" name="motivo" maxlength="255" value="<?= htmlspecialchars($v['motivo']) ?>">
        </label>

        <label class="campo">
            Fecha
            <input type="date" name="fecha" id="fecha" min="<?= date('Y-m-d') ?>"
                   value="<?= htmlspecialchars($v['fecha']) ?>">
        </label>

        <div id="horarios" class="horarios completo"></div>
        <input type="hidden" name="hora" id="hora" value="<?= htmlspecialchars($v['hora']) ?>">

        <button type="submit" class="btn btn-grande">Reservar turno</button>
    </form>
</section>

<script type="application/json" id="catalogo"><?= json_encode($catalogo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="inicial"><?= json_encode($inicialVehiculo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="guardados"><?= json_encode($vehiculosGuardados, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/vehiculo.js"></script>
<script>
(() => {
    const API = document.querySelector('meta[name="api-base"]').content;
    const form = document.getElementById('form-turno');
    const fecha = document.getElementById('fecha');
    const hora = document.getElementById('hora');
    const contenedor = document.getElementById('horarios');

    const vehiculo = Vehiculo.iniciar(
        form,
        JSON.parse(document.getElementById('catalogo').textContent),
        JSON.parse(document.getElementById('inicial').textContent)
    );

    // Vehículos guardados: el selector muestra solo los del tipo elegido
    const guardados = JSON.parse(document.getElementById('guardados').textContent);
    const bloqueGuardado = document.getElementById('bloque-guardado');
    const selectorGuardado = document.getElementById('vehiculo-guardado');
    const tipoElegido = () => form.querySelector('input[name="tipo"]:checked').value;

    function llenarSelector() {
        if (!selectorGuardado) {
            return;
        }
        const opciones = guardados
            .map((v, indice) => ({ v, indice }))
            .filter(({ v }) => v.tipo === tipoElegido());
        selectorGuardado.innerHTML = '';
        const vacia = document.createElement('option');
        vacia.value = '';
        vacia.textContent = 'Cargar uno nuevo';
        selectorGuardado.appendChild(vacia);
        opciones.forEach(({ v, indice }) => {
            const op = document.createElement('option');
            op.value = String(indice);
            op.textContent = v.modelo + ' · ' + v.patente;
            selectorGuardado.appendChild(op);
        });
        bloqueGuardado.hidden = opciones.length === 0;
    }

    if (selectorGuardado) {
        form.querySelectorAll('input[name="tipo"]').forEach((radio) => {
            radio.addEventListener('change', llenarSelector);
        });
        selectorGuardado.addEventListener('change', () => {
            if (selectorGuardado.value !== '') {
                vehiculo.aplicar(guardados[Number(selectorGuardado.value)]);
            }
        });
    }

    async function cargarHorarios(horaElegida = '') {
        hora.value = horaElegida;
        contenedor.innerHTML = '';
        if (!fecha.value) {
            return;
        }
        try {
            const respuesta = await fetch(`${API}/api/turnos/disponibles?fecha=${encodeURIComponent(fecha.value)}`);
            const datos = await respuesta.json();
            if (!respuesta.ok) {
                throw new Error(datos.error || 'Error');
            }
            if (datos.horarios_libres.length === 0) {
                contenedor.textContent = 'No hay horarios libres ese día.';
                return;
            }
            datos.horarios_libres.forEach((h) => {
                const boton = document.createElement('button');
                boton.type = 'button';
                boton.className = 'chip' + (h === horaElegida ? ' seleccionado' : '');
                boton.textContent = h;
                boton.addEventListener('click', () => {
                    contenedor.querySelectorAll('.chip').forEach((c) => c.classList.remove('seleccionado'));
                    boton.classList.add('seleccionado');
                    hora.value = h;
                });
                contenedor.appendChild(boton);
            });
        } catch (e) {
            contenedor.textContent = 'No pudimos cargar los horarios. Probá de nuevo.';
        }
    }

    fecha.addEventListener('change', () => cargarHorarios(''));

    llenarSelector();
    if (fecha.value) {
        cargarHorarios(hora.value);
    }
})();
</script>

<?php fin_html(); ?>