<?php

require __DIR__ . '/../src/auth/csrf.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/auth/flash.php';
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/dominio/vehiculos.php';
require __DIR__ . '/../src/views/layout.php';

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
$v = [
    'tipo' => 'auto', 'patente' => '', 'marca' => '', 'modelo' => '', 'anio' => '',
    'cilindrada' => '', 'kilometros' => '', 'fecha' => '', 'hora' => '', 'motivo' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();

    foreach (array_keys($v) as $campo) {
        $v[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }

    $tipo = in_array($v['tipo'], TIPOS_VEHICULO, true) ? $v['tipo'] : 'auto';
    $patente = normalizar_patente($v['patente']);

    // Para motos, marca y modelo se guardan juntos en "modelo" (ej: "Honda Wave")
    if ($tipo === 'moto') {
        if ($v['marca'] === '') {
            $errores[] = 'Indicá la marca de la moto.';
        }
        $modelo = trim($v['marca'] . ' ' . $v['modelo']);
        if ($v['modelo'] === '') {
            $errores[] = 'Indicá el modelo de la moto.';
        }
    } else {
        $modelo = $v['modelo'];
    }

    if ($modelo === '' || mb_strlen($modelo) > 100) {
        $errores[] = 'El modelo es obligatorio y puede tener hasta 100 caracteres.';
    }

    if (!patente_valida($tipo, $patente)) {
        $errores[] = $tipo === 'moto'
            ? 'La patente de la moto no es válida. Ejemplos: A123BCD o 123BCD.'
            : 'La patente del auto no es válida. Ejemplos: AB123CD o ABC123.';
    }

    $anio = filter_var($v['anio'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1950, 'max_range' => $anioMax]]);
    if ($anio === false) {
        $errores[] = "El año debe estar entre 1950 y $anioMax.";
    }

    $km = filter_var($v['kilometros'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($km === false) {
        $errores[] = 'Los kilómetros tienen que ser un número mayor o igual a 0.';
    }

    $cilindrada = null;
    if ($tipo === 'moto') {
        $cilindrada = filter_var($v['cilindrada'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 50, 'max_range' => 3000]]);
        if ($cilindrada === false) {
            $errores[] = 'La cilindrada tiene que estar entre 50 y 3000 cc.';
        }
    }

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

            // Si el cliente ya tiene ese vehículo, se actualizan sus datos
            $stmt = $pdo->prepare('SELECT id_vehiculo FROM vehiculos WHERE patente = ? AND id_cliente = ?');
            $stmt->execute([$patente, $_SESSION['usuario_id']]);
            $idVehiculo = $stmt->fetchColumn();

            if ($idVehiculo) {
                $pdo->prepare(
                    'UPDATE vehiculos SET tipo = ?, modelo = ?, anio = ?, kilometros = ?, cilindrada = ?
                     WHERE id_vehiculo = ?'
                )->execute([$tipo, $modelo, $anio, $km, $cilindrada, $idVehiculo]);
            } else {
                $pdo->prepare(
                    'INSERT INTO vehiculos (id_cliente, patente, tipo, modelo, anio, kilometros, cilindrada)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$_SESSION['usuario_id'], $patente, $tipo, $modelo, $anio, $km, $cilindrada]);
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
$stmt = $pdo->prepare('SELECT tipo, patente, modelo, anio, kilometros, cilindrada FROM vehiculos WHERE id_cliente = ? ORDER BY id_vehiculo');
$stmt->execute([(int) $_SESSION['usuario_id']]);
$vehiculosGuardados = array_map(function (array $v): array {
    [$marca, $modeloCorto] = $v['tipo'] === 'moto'
        ? array_pad(explode(' ', $v['modelo'], 2), 2, '')
        : ['', ''];
    return [
        'tipo' => $v['tipo'],
        'patente' => $v['patente'],
        'modelo' => $v['modelo'],
        'marca' => $marca,
        'modelo_corto' => $modeloCorto,
        'anio' => (string) $v['anio'],
        'kilometros' => (string) $v['kilometros'],
        'cilindrada' => (string) ($v['cilindrada'] ?? ''),
    ];
}, $stmt->fetchAll());

$apiBase = htmlspecialchars($config['api_url'], ENT_QUOTES);
inicio_html('Pedir turno', '<meta name="api-base" content="' . $apiBase . '">');
mostrar_mensajes('', $errores);
?>

<section class="tarjeta">
    <h1>Pedir turno</h1>

    <form method="post" action="turno_nuevo.php" id="form-turno" class="formulario" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

        <fieldset class="tipo completo">
            <legend>¿Qué vehículo traés?</legend>
            <label>
                <input type="radio" name="tipo" value="auto" <?= $v['tipo'] !== 'moto' ? 'checked' : '' ?>>
                Auto
            </label>
            <label>
                <input type="radio" name="tipo" value="moto" <?= $v['tipo'] === 'moto' ? 'checked' : '' ?>>
                Moto
            </label>
        </fieldset>

        <?php if ($vehiculosGuardados): ?>
        <label class="campo completo" id="bloque-guardado">Vehículo guardado
            <select id="vehiculo-guardado">
                <option value="">Cargar uno nuevo</option>
            </select>
            <small>Elegí uno para completar los datos. Podés ajustarlos antes de reservar.</small>
        </label>
        <?php endif; ?>

        <label class="campo">
            Patente
            <input type="text" name="patente" id="patente" maxlength="10"
                   value="<?= htmlspecialchars($v['patente']) ?>" placeholder="AB123CD">
            <small id="ayuda-patente"></small>
        </label>

        <div data-solo="auto" class="grupo">
            <label class="campo">
                Modelo
                <input type="text" name="modelo" maxlength="100"
                       value="<?= htmlspecialchars($v['tipo'] !== 'moto' ? $v['modelo'] : '') ?>"
                       placeholder="VW Gol 1.6">
            </label>
        </div>

        <div data-solo="moto" class="grupo">
            <label class="campo">
                Marca
                <input type="text" name="marca" maxlength="50"
                       value="<?= htmlspecialchars($v['marca']) ?>" placeholder="Honda">
            </label>
            <label class="campo">
                Modelo
                <input type="text" name="modelo" maxlength="50"
                       value="<?= htmlspecialchars($v['tipo'] === 'moto' ? $v['modelo'] : '') ?>"
                       placeholder="Wave 110">
            </label>
            <label class="campo">
                Cilindrada (cc)
                <input type="number" name="cilindrada" min="50" max="3000"
                       value="<?= htmlspecialchars($v['cilindrada']) ?>">
            </label>
        </div>

        <label class="campo">
            Año
            <input type="number" name="anio" min="1950" max="<?= $anioMax ?>"
                   value="<?= htmlspecialchars($v['anio']) ?>">
        </label>

        <label class="campo">
            Kilómetros
            <input type="number" name="kilometros" min="0" value="<?= htmlspecialchars($v['kilometros']) ?>">
        </label>

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

<script>
(() => {
    const API = document.querySelector('meta[name="api-base"]').content;
    const form = document.getElementById('form-turno');
    const fecha = document.getElementById('fecha');
    const hora = document.getElementById('hora');
    const contenedor = document.getElementById('horarios');
    const ayuda = document.getElementById('ayuda-patente');
    const textoAyuda = {
        auto: 'Auto: AB123CD o ABC123',
        moto: 'Moto: A123BCD o 123BCD',
    };

    // Muestra los campos del tipo elegido. Los campos ocultos se deshabilitan para que no se envíen.
    function mostrarTipo() {
        const tipo = form.querySelector('input[name="tipo"]:checked').value;
        form.querySelectorAll('[data-solo]').forEach((bloque) => {
            const activo = bloque.dataset.solo === tipo;
            bloque.hidden = !activo;
            bloque.querySelectorAll('input').forEach((input) => {
                input.disabled = !activo;
            });
        });
        ayuda.textContent = textoAyuda[tipo];
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

    form.querySelectorAll('input[name="tipo"]').forEach((radio) => {
        radio.addEventListener('change', alCambiarTipo);
    });

    // Vehículos guardados: primero se elige auto o moto, y el selector muestra solo los de ese tipo
    const guardados = <?= json_encode($vehiculosGuardados, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const bloqueGuardado = document.getElementById('bloque-guardado');
    const selectorGuardado = document.getElementById('vehiculo-guardado');

    function tipoElegido() {
        return form.querySelector('input[name="tipo"]:checked').value;
    }

    // Vuelve a llenar el selector con los vehículos del tipo elegido
    function llenarSelector() {
        if (!selectorGuardado) {
            return;
        }
        const opciones = Object.entries(guardados).filter(([, v]) => v.tipo === tipoElegido());
        selectorGuardado.innerHTML = '';

        const vacia = document.createElement('option');
        vacia.value = '';
        vacia.textContent = 'Cargar uno nuevo';
        selectorGuardado.appendChild(vacia);

        opciones.forEach(([indice, v]) => {
            const op = document.createElement('option');
            op.value = indice;
            op.textContent = v.modelo + ' · ' + v.patente;
            selectorGuardado.appendChild(op);
        });

        bloqueGuardado.hidden = opciones.length === 0;
    }

    function limpiarDatos() {
        form.querySelector('[name="patente"]').value = '';
        form.querySelectorAll('[name="modelo"], [name="marca"], [name="cilindrada"]').forEach((input) => {
            input.value = '';
        });
        form.querySelector('[name="anio"]').value = '';
        form.querySelector('[name="kilometros"]').value = '';
    }

    function alCambiarTipo() {
        mostrarTipo();
        const seleccion = selectorGuardado ? guardados[selectorGuardado.value] : null;
        // Si el vehículo elegido era de otro tipo, se borran sus datos del formulario
        if (seleccion && seleccion.tipo !== tipoElegido()) {
            limpiarDatos();
        }
        llenarSelector();
    }

    if (selectorGuardado) {
        selectorGuardado.addEventListener('change', () => {
            const v = guardados[selectorGuardado.value];
            if (!v) {
                return;
            }
            form.querySelector('[name="patente"]').value = v.patente;
            form.querySelector('[data-solo="auto"] [name="modelo"]').value = v.tipo === 'auto' ? v.modelo : '';
            form.querySelector('[data-solo="moto"] [name="marca"]').value = v.marca;
            form.querySelector('[data-solo="moto"] [name="modelo"]').value = v.tipo === 'moto' ? v.modelo_corto : '';
            form.querySelector('[data-solo="moto"] [name="cilindrada"]').value = v.cilindrada;
            form.querySelector('[name="anio"]').value = v.anio;
            form.querySelector('[name="kilometros"]').value = v.kilometros;
        });
    }

    fecha.addEventListener('change', () => cargarHorarios(''));

    mostrarTipo();
    llenarSelector();
    if (fecha.value) {
        cargarHorarios(hora.value);
    }
})();
</script>

<?php fin_html(); ?>