<?php

require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/views/layout.php';

// Solo el administrador ve el dashboard
requerir_rol('admin');

$config = require __DIR__ . '/../config/config.php';
$pdo = conectar();

// Lista de trabajos. $condicion es una constante interna, nunca viene del usuario
function listarTrabajos(PDO $pdo, string $condicion): array
{
    return $pdo->query(
        "SELECT t.id_trabajo, t.estado, d.descripcion AS diagnostico,
                v.modelo, v.patente, u.nombre AS dueno,
                m.nombre AS mecanico, p.total AS precio
         FROM trabajos t
         JOIN turnos tu ON tu.id_turno = t.id_turno
         JOIN vehiculos v ON v.id_vehiculo = tu.id_vehiculo
         JOIN usuarios u ON u.id_usuario = v.id_cliente
         LEFT JOIN usuarios m ON m.id_usuario = t.id_mecanico
         LEFT JOIN diagnosticos d ON d.id_trabajo = t.id_trabajo
         LEFT JOIN presupuestos p ON p.id_trabajo = t.id_trabajo AND p.estado = 'aprobado'
         WHERE $condicion
         ORDER BY t.id_trabajo DESC"
    )->fetchAll();
}

$realizados = listarTrabajos($pdo, "t.estado = 'listo'");
$activos = listarTrabajos($pdo, "t.estado NOT IN ('listo', 'rechazado')");
$stock = $pdo->query("SELECT nombre, stock, stock_minimo, precio FROM repuestos ORDER BY stock ASC")->fetchAll();

// Cobrado: pagos aprobados (Mercado Pago y taller). Pendiente: aprobados sin ningún pago aprobado
$cobrado = $pdo->query("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE estado = 'aprobado'")->fetchColumn();
$cobradoEnTaller = $pdo->query(
    "SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE estado = 'aprobado' AND metodo <> 'mercadopago'"
)->fetchColumn();
$pendienteCobro = $pdo->query(
    "SELECT COALESCE(SUM(p.total), 0) FROM presupuestos p
     WHERE p.estado = 'aprobado'
       AND NOT EXISTS (SELECT 1 FROM pagos pg WHERE pg.id_presupuesto = p.id_presupuesto AND pg.estado = 'aprobado')"
)->fetchColumn();
$stockBajo = count(array_filter($stock, fn($r) => (int) $r['stock'] <= (int) ($r['stock_minimo'] ?? 4)));

// Tabla de trabajos (se usa para activos y realizados)
function tablaTrabajos(array $filas): void
{
    if (empty($filas)) {
        echo '<div class="tarjeta"><p class="vacio" style="margin:0;">No hay trabajos.</p></div>';
        return;
    }
    echo '<div class="tabla-wrap"><table class="tabla">';
    echo '<tr><th>N°</th><th>Estado</th><th>Diagnóstico</th><th>Vehículo</th><th>Dueño</th><th>Mecánico</th><th class="num">Total</th></tr>';
    foreach ($filas as $f) {
        echo '<tr>';
        echo '<td class="mono">#' . (int) $f['id_trabajo'] . '</td>';
        echo '<td>' . badge_estado($f['estado']) . '</td>';
        echo '<td>' . htmlspecialchars($f['diagnostico'] ?? '—') . '</td>';
        echo '<td><strong>' . htmlspecialchars($f['modelo']) . '</strong><br><span class="patente-tag" style="margin-top:4px;">' . htmlspecialchars($f['patente']) . '</span></td>';
        echo '<td>' . htmlspecialchars($f['dueno']) . '</td>';
        echo '<td>' . htmlspecialchars($f['mecanico'] ?? 'Sin asignar') . '</td>';
        echo '<td class="num"><strong>' . ($f['precio'] !== null ? '$ ' . number_format($f['precio'], 2, ',', '.') : '—') . '</strong></td>';
        echo '</tr>';
    }
    echo '</table></div>';
}

// Metas que necesita el script: token de la sesión y URL de la API
$extraHead = '<meta name="api-token" content="' . htmlspecialchars($_SESSION['api_token'] ?? '') . '">'
    . '<meta name="api-base" content="' . htmlspecialchars($config['api_url']) . '">';
inicio_html('Dashboard', $extraHead);
?>
  <div class="encabezado-pagina">
    <div>
      <h1>Dashboard del taller</h1>
      <p class="subtitulo">Resumen financiero, trabajos en curso, stock crítico e insights de la API.</p>
    </div>
    <a href="stock" class="btn btn-outline">Gestionar stock y proveedores</a>
  </div>

  <div class="stats">
    <div class="stat" style="border-top-color: var(--ok);">
      <div class="etiqueta">Cobrado</div>
      <div class="valor">$ <?= number_format($cobrado, 2, ',', '.') ?></div>
      <div class="detalle">En el taller: $ <?= number_format($cobradoEnTaller, 2, ',', '.') ?></div>
    </div>
    <div class="stat" style="border-top-color: var(--warn);">
      <div class="etiqueta">Pendiente de cobro</div>
      <div class="valor">$ <?= number_format($pendienteCobro, 2, ',', '.') ?></div>
      <div class="detalle">Presupuestos aprobados por cobrar</div>
    </div>
    <div class="stat" style="border-top-color: var(--info);">
      <div class="etiqueta">Trabajos activos</div>
      <div class="valor"><?= count($activos) ?></div>
      <div class="detalle">En recepción, diagnóstico o reparación</div>
    </div>
    <div class="stat" style="border-top-color: var(--accent);">
      <div class="etiqueta">Trabajos realizados</div>
      <div class="valor"><?= count($realizados) ?></div>
      <div class="detalle">Finalizados y listos para entrega</div>
    </div>
    <div class="stat" style="border-top-color: <?= $stockBajo > 0 ? 'var(--error)' : 'var(--ok)' ?>;">
      <div class="etiqueta">Stock bajo</div>
      <div class="valor"><?= $stockBajo ?></div>
      <div class="detalle">Repuestos en mínimo o críticos</div>
    </div>
  </div>

  <div class="panel-grid">
    <div class="panel-principal">
      <h2>Trabajos activos</h2>
      <?php tablaTrabajos($activos); ?>

      <h2>Trabajos realizados</h2>
      <?php tablaTrabajos($realizados); ?>

      <h2>Stock de repuestos</h2>
      <div class="tabla-wrap">
        <table class="tabla">
          <tr><th>Repuesto</th><th class="num">Stock</th><th class="num">Precio</th><th>Estado</th></tr>
          <?php foreach ($stock as $r): ?>
            <?php $bajo = (int) $r['stock'] <= (int) ($r['stock_minimo'] ?? 4); ?>
            <tr>
              <td><?= htmlspecialchars($r['nombre']) ?></td>
              <td class="num"><?= (int) $r['stock'] ?></td>
              <td class="num">$ <?= number_format($r['precio'], 2, ',', '.') ?></td>
              <td><?= $bajo ? '<span class="badge badge-rechazado">Bajo</span>' : '<span class="badge badge-cerrado">OK</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <aside class="panel-lateral" aria-label="Insights">
      <h2 style="margin:0 0 8px; font-size:1.05rem;">Insights del taller</h2>
      <div id="insights" class="vacio">Cargando resumen desde la API...</div>
    </aside>
  </div>

  <script>
    const API_BASE = document.querySelector('meta[name="api-base"]').content;
    const token = document.querySelector('meta[name="api-token"]').content;
    const contenedor = document.getElementById('insights');

    function barra(valor, maximo) {
      const pista = document.createElement('div');
      pista.style.cssText = 'background:#0e1217; border:1px solid #262e38; height:10px; border-radius:999px; overflow:hidden; margin:6px 0 14px';
      const relleno = document.createElement('div');
      relleno.style.cssText = 'background:#ea580c; height:100%; border-radius:999px; width:' + (valor / maximo * 100) + '%';
      pista.appendChild(relleno);
      return pista;
    }

    fetch(API_BASE + '/api/dashboard/resumen', {
      headers: { Authorization: 'Bearer ' + token },
    })
      .then((r) => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then((datos) => {
        contenedor.textContent = '';
        contenedor.className = '';

        const titulo1 = document.createElement('h3');
        titulo1.textContent = 'Facturación por mes';
        titulo1.style.marginBottom = '10px';
        contenedor.appendChild(titulo1);

        if (datos.facturacion_mensual.length === 0) {
          const v = document.createElement('p');
          v.className = 'vacio';
          v.textContent = 'Todavía no hay presupuestos aprobados.';
          contenedor.appendChild(v);
        }

        const max = Math.max(1, ...datos.facturacion_mensual.map((f) => f.total));
        datos.facturacion_mensual.forEach((f) => {
          const fila = document.createElement('div');
          fila.style.cssText = 'display:flex; justify-content:space-between; font-size:0.84rem; color:#f3f4f6;';
          const izq = document.createElement('span');
          izq.textContent = f.mes;
          const der = document.createElement('strong');
          der.className = 'mono';
          der.textContent = '$ ' + f.total.toLocaleString('es-AR');
          fila.appendChild(izq);
          fila.appendChild(der);
          contenedor.appendChild(fila);
          contenedor.appendChild(barra(f.total, max));
        });

        const titulo2 = document.createElement('h3');
        titulo2.textContent = 'Trabajos por mecánico';
        titulo2.style.cssText = 'margin:18px 0 10px;';
        contenedor.appendChild(titulo2);

        const maxTrabajos = Math.max(1, ...datos.trabajos_por_mecanico.map((m) => m.trabajos));
        datos.trabajos_por_mecanico.forEach((m) => {
          const fila = document.createElement('div');
          fila.style.cssText = 'display:flex; justify-content:space-between; font-size:0.84rem; color:#f3f4f6;';
          const izq = document.createElement('span');
          izq.textContent = m.nombre;
          const der = document.createElement('strong');
          der.className = 'mono';
          der.textContent = m.trabajos;
          fila.appendChild(izq);
          fila.appendChild(der);
          contenedor.appendChild(fila);
          contenedor.appendChild(barra(m.trabajos, maxTrabajos));
        });
      })
      .catch(() => {
        contenedor.textContent = 'No se pudieron cargar los insights. Revisá que la API esté corriendo.';
      });
  </script>
<?php
link_panel();
fin_html();
