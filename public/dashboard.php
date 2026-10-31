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
$stock = $pdo->query("SELECT nombre, stock, precio FROM repuestos ORDER BY stock ASC")->fetchAll();
$caja = $pdo->query("SELECT COALESCE(SUM(total), 0) FROM presupuestos WHERE estado = 'aprobado'")->fetchColumn();

$stockBajo = count(array_filter($stock, fn($r) => (int) $r['stock'] < 5));

// Tabla de trabajos (se usa para activos y realizados)
function tablaTrabajos(array $filas): void
{
    if (empty($filas)) {
        echo '<p class="vacio">No hay trabajos.</p>';
        return;
    }
    echo '<div class="tabla-wrap"><table class="tabla">';
    echo '<tr><th>N°</th><th>Estado</th><th>Diagnóstico</th><th>Auto</th><th>Dueño</th><th>Mecánico</th><th class="num">Precio</th></tr>';
    foreach ($filas as $f) {
        echo '<tr>';
        echo '<td>#' . (int) $f['id_trabajo'] . '</td>';
        echo '<td>' . badge_estado($f['estado']) . '</td>';
        echo '<td>' . htmlspecialchars($f['diagnostico'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($f['modelo']) . '<br><span class="vacio">' . htmlspecialchars($f['patente']) . '</span></td>';
        echo '<td>' . htmlspecialchars($f['dueno']) . '</td>';
        echo '<td>' . htmlspecialchars($f['mecanico'] ?? 'Sin asignar') . '</td>';
        echo '<td class="num">' . ($f['precio'] !== null ? '$ ' . number_format($f['precio'], 2, ',', '.') : '-') . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';
}

// Metas que necesita el script: token de la sesión y URL de la API
$extraHead = '<meta name="api-token" content="' . htmlspecialchars($_SESSION['api_token'] ?? '') . '">'
    . '<meta name="api-base" content="' . htmlspecialchars($config['api_url']) . '">';

inicio_html('Dashboard', $extraHead);
?>
  <h1>Dashboard</h1>

  <div class="stats">
    <div class="stat">
      <div class="etiqueta">Caja (presupuestos aprobados)</div>
      <div class="valor">$ <?= number_format($caja, 2, ',', '.') ?></div>
    </div>
    <div class="stat">
      <div class="etiqueta">Trabajos activos</div>
      <div class="valor"><?= count($activos) ?></div>
    </div>
    <div class="stat">
      <div class="etiqueta">Trabajos realizados</div>
      <div class="valor"><?= count($realizados) ?></div>
    </div>
    <div class="stat">
      <div class="etiqueta">Repuestos con stock bajo</div>
      <div class="valor"><?= $stockBajo ?></div>
    </div>
  </div>

  <h2>Insights</h2>
  <div class="tarjeta">
    <div id="insights" class="vacio">Cargando...</div>
  </div>

  <h2>Trabajos activos</h2>
  <?php tablaTrabajos($activos); ?>

  <h2>Trabajos realizados</h2>
  <?php tablaTrabajos($realizados); ?>

  <h2>Stock de repuestos</h2>
  <div class="tabla-wrap">
    <table class="tabla">
      <tr><th>Repuesto</th><th class="num">Stock</th><th class="num">Precio</th><th>Estado</th></tr>
      <?php foreach ($stock as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['nombre']) ?></td>
          <td class="num"><?= (int) $r['stock'] ?></td>
          <td class="num">$ <?= number_format($r['precio'], 2, ',', '.') ?></td>
          <td><?= (int) $r['stock'] < 5 ? '<span class="badge badge-activo">Bajo</span>' : '<span class="badge badge-cerrado">OK</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <script>
    const API_BASE = document.querySelector('meta[name="api-base"]').content;
    const token = document.querySelector('meta[name="api-token"]').content;
    const contenedor = document.getElementById('insights');

    // Crea una barra horizontal proporcional al valor máximo
    function barra(valor, maximo) {
      const pista = document.createElement('div');
      pista.style.cssText = 'background:#DADADA; height:10px; border-radius:999px; overflow:hidden; margin:4px 0 14px';
      const relleno = document.createElement('div');
      relleno.style.cssText = 'background:#E63946; height:100%; width:' + (valor / maximo * 100) + '%';
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
          fila.textContent = f.mes + ' · $ ' + f.total.toLocaleString('es-AR');
          contenedor.appendChild(fila);
          contenedor.appendChild(barra(f.total, max));
        });

        const titulo2 = document.createElement('h3');
        titulo2.textContent = 'Trabajos realizados por mecánico';
        contenedor.appendChild(titulo2);

        const maxTrabajos = Math.max(1, ...datos.trabajos_por_mecanico.map((m) => m.trabajos));
        datos.trabajos_por_mecanico.forEach((m) => {
          const fila = document.createElement('div');
          fila.textContent = m.nombre + ': ' + m.trabajos;
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