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
            header('Location: panel');
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

$stmt = $pdo->prepare('SELECT * FROM vehiculos WHERE id_cliente = ? ORDER BY id_vehiculo');
$stmt->execute([(int) $_SESSION['usuario_id']]);
$vehiculosGuardados = array_map(function (array $fila): array {
    return valores_formulario_vehiculo($fila) + [
        'modelo' => $fila['modelo'],
    ];
}, $stmt->fetchAll());

$inicialVehiculo = array_intersect_key($v, array_flip($camposVehiculo));
$catalogo = catalogo_vehiculos($pdo);
$apiBase = htmlspecialchars($config['api_url'], ENT_QUOTES);

inicio_html('Pedir turno', '<meta name="api-base" content="' . $apiBase . '">');
?>
  <div class="encabezado-pagina">
    <div>
      <h1>Reservar Turno Online</h1>
      <p class="subtitulo">Elegí tu vehículo guardado o cargá uno nuevo, indicá el motivo y reservá horario libre al instante.</p>
    </div>
    <a href="panel" class="btn btn-outline">← Volver al inicio</a>
  </div>

  <?php mostrar_mensajes('', $errores); ?>

  <div class="panel-grid">
    <div class="panel-principal">
      <form method="post" action="turno_nuevo" id="form-turno" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

        <!-- Paso 1: Vehículo -->
        <section class="tarjeta">
          <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px;">
            <span class="badge badge-activo">Paso 1</span>
            <h2 style="margin:0;">Datos del vehículo</h2>
          </div>

          <div class="formulario">
            <?php if ($vehiculosGuardados): ?>
              <label class="campo completo" id="bloque-guardado">Vehículo guardado en tu cuenta
                <select id="vehiculo-guardado">
                  <option value="">Cargar uno nuevo</option>
                </select>
                <small>Al elegir uno se completan patente, marca, modelo, año y kilometraje automáticamente.</small>
              </label>
            <?php endif; ?>

            <?php campos_vehiculo($anioMax); ?>
          </div>
        </section>

        <!-- Paso 2: Motivo -->
        <section class="tarjeta">
          <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px;">
            <span class="badge badge-activo">Paso 2</span>
            <h2 style="margin:0;">¿Qué necesita tu vehículo?</h2>
          </div>

          <div class="formulario">
            <div class="campo completo">
              <span>Motivo o síntoma principal</span>
              <input type="text" name="motivo" id="motivo-input" maxlength="255"
                     placeholder="Ej: Service 10.000 km, vibración al frenar, revisión pre-VTV..."
                     value="<?= htmlspecialchars($v['motivo']) ?>">
              <div class="quick-chips" aria-label="Motivos frecuentes">
                <button type="button" class="quick-chip" data-motivo="Service programado de 10.000 km">Service 10.000 km</button>
                <button type="button" class="quick-chip" data-motivo="Revisión y cambio de pastillas de freno">Frenos y pastillas</button>
                <button type="button" class="quick-chip" data-motivo="Revisión general Pre-VTV">Pre-VTV</button>
                <button type="button" class="quick-chip" data-motivo="Control de tren delantero y amortiguación">Tren delantero</button>
                <button type="button" class="quick-chip" data-motivo="Diagnóstico computarizado / luz de check engine">Escaneo computarizado</button>
              </div>
            </div>
          </div>
        </section>

        <!-- Paso 3: Fecha y Horario -->
        <section class="tarjeta destacada">
          <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px;">
            <span class="badge badge-activo">Paso 3</span>
            <h2 style="margin:0;">Fecha y horario disponible</h2>
          </div>

          <div class="formulario">
            <label class="campo">
              Fecha del turno
              <input type="date" name="fecha" id="fecha" min="<?= date('Y-m-d') ?>"
                     value="<?= htmlspecialchars($v['fecha']) ?>">
            </label>

            <div class="campo completo">
              <span>Horarios disponibles</span>
              <div id="horarios" class="horarios">
                <span class="vacio">Elegí una fecha para consultar los turnos libres.</span>
              </div>
              <input type="hidden" name="hora" id="hora" value="<?= htmlspecialchars($v['hora']) ?>">
            </div>

            <div class="completo" style="margin-top:6px;">
              <button type="submit" class="btn btn-grande">Confirmar Reserva de Turno</button>
            </div>
          </div>
        </section>
      </form>
    </div>

    <!-- Resumen lateral en vivo -->
    <aside class="panel-lateral" aria-label="Resumen de tu reserva">
      <div style="display:flex; align-items:center; gap:8px;">
        <?= icono_svg('calendar') ?>
        <h2 style="margin:0; font-size:1.05rem;">Resumen de Reserva</h2>
      </div>

      <div class="meta-tile">
        <span>Vehículo & Patente</span>
        <strong id="res-vehiculo">Completá los datos</strong>
        <div style="margin-top:6px;"><span class="patente-tag" id="res-patente">—</span></div>
      </div>

      <div class="meta-tile">
        <span>Motivo seleccionado</span>
        <strong id="res-motivo" style="font-weight:500;">Sin especificar</strong>
      </div>

      <div class="meta-tile">
        <span>Día y horario</span>
        <strong id="res-fecha-hora" class="mono">Elegí fecha y hora</strong>
      </div>

      <div style="font-size:0.78rem; color:var(--text-secondary); line-height:1.5; border-top:1px solid var(--border); padding-top:12px;">
        <strong style="color:#fff; display:block; margin-bottom:4px;">Sin compromiso de reparación</strong>
        Tu vehículo ingresa a diagnóstico y recibís el presupuesto detallado antes de autorizar cualquier arreglo.
      </div>
    </aside>
  </div>

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

    const guardados = JSON.parse(document.getElementById('guardados').textContent);
    const selectorGuardado = document.getElementById('vehiculo-guardado');

    function actualizarResumen() {
        const pat = (form.querySelector('[name="patente"]').value || '').trim().toUpperCase();
        const marcaSel = form.querySelector('[name="marca_id"]');
        const modeloSel = form.querySelector('[name="modelo_id"]');
        const marcaOtra = (form.querySelector('[name="marca_otra"]')?.value || '').trim();
        const modeloOtro = (form.querySelector('[name="modelo_otro"]')?.value || '').trim();
        const motivo = (document.getElementById('motivo-input').value || '').trim();

        const marcaTxt = marcaSel.value === 'otra'
            ? marcaOtra
            : (marcaSel.selectedOptions[0]?.value ? marcaSel.selectedOptions[0].textContent : '');
        const modeloTxt = (marcaSel.value === 'otra' || modeloSel.value === 'otro')
            ? modeloOtro
            : (modeloSel.selectedOptions[0]?.value ? modeloSel.selectedOptions[0].textContent : '');

        document.getElementById('res-vehiculo').textContent = (marcaTxt + ' ' + modeloTxt).trim() || 'Completá los datos';
        document.getElementById('res-patente').textContent = pat || 'SIN PATENTE';
        document.getElementById('res-motivo').textContent = motivo || 'Sin especificar';
        document.getElementById('res-fecha-hora').textContent = (fecha.value || 'Sin fecha') + (hora.value ? ' · ' + hora.value + ' hs' : '');
    }

    if (selectorGuardado && guardados.length > 0) {
        guardados.forEach((v, indice) => {
            const o = document.createElement('option');
            o.value = indice;
            o.textContent = `${v.modelo} (${v.patente})`;
            selectorGuardado.appendChild(o);
        });
        selectorGuardado.addEventListener('change', () => {
            if (selectorGuardado.value !== '') {
                vehiculo.aplicar(guardados[Number(selectorGuardado.value)]);
                actualizarResumen();
            }
        });
    }

    // Quick chips de motivo
    document.querySelectorAll('.quick-chip').forEach((chip) => {
        chip.addEventListener('click', () => {
            document.getElementById('motivo-input').value = chip.dataset.motivo || '';
            document.querySelectorAll('.quick-chip').forEach((c) => c.classList.remove('activo'));
            chip.classList.add('activo');
            actualizarResumen();
        });
    });

    form.addEventListener('input', actualizarResumen);
    form.addEventListener('change', actualizarResumen);

    async function cargarHorarios(horaElegida = '') {
        hora.value = horaElegida;
        contenedor.innerHTML = '';
        actualizarResumen();
        if (!fecha.value) {
            contenedor.innerHTML = '<span class="vacio">Elegí una fecha para consultar los turnos libres.</span>';
            return;
        }
        try {
            const respuesta = await fetch(`${API}/api/turnos/disponibles?fecha=${encodeURIComponent(fecha.value)}`);
            const datos = await respuesta.json();
            if (!respuesta.ok) {
                throw new Error(datos.error || 'Error');
            }
            if (datos.horarios_libres.length === 0) {
                contenedor.innerHTML = '<span class="vacio">No hay horarios libres ese día. Probá otra fecha.</span>';
                return;
            }
            datos.horarios_libres.forEach((h) => {
                const boton = document.createElement('button');
                boton.type = 'button';
                boton.className = 'chip' + (h === horaElegida ? ' seleccionado' : '');
                boton.textContent = h + ' hs';
                boton.addEventListener('click', () => {
                    contenedor.querySelectorAll('.chip').forEach((c) => c.classList.remove('seleccionado'));
                    boton.classList.add('seleccionado');
                    hora.value = h;
                    actualizarResumen();
                });
                contenedor.appendChild(boton);
            });
        } catch (e) {
            contenedor.innerHTML = '<span class="vacio">No pudimos cargar los horarios. Probá de nuevo.</span>';
        }
    }

    fecha.addEventListener('change', () => cargarHorarios(''));
    actualizarResumen();
    if (fecha.value) {
        cargarHorarios(hora.value);
    }
})();
</script>
<?php fin_html(); ?>
