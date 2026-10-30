<?php
require __DIR__ . '/../src/config/database.php';
require __DIR__ . '/../src/auth/acceso.php';
require __DIR__ . '/../src/views/layout.php';

// Solo el administrador ve el dashboard
requerir_rol('admin');

$config = require __DIR__ . '/../config/local.php';
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

// Tabla de trabajos (se usa para activos y realizados)
function tablaTrabajos(array $filas): void
{
    if (empty($filas)) {
        echo '<p>No hay trabajos.</p>';
        return;
    }
    echo '<table border="1" cellpadding="4">';
    echo '<tr><th>N°</th><th>Diagnóstico</th><th>Auto</th><th>Dueño</th><th>Mecánico</th><th>Precio</th></tr>';
    foreach ($filas as $f) {
        echo '<tr>';
        echo '<td>' . (int) $f['id_trabajo'] . '</td>';
        echo '<td>' . htmlspecialchars($f['diagnostico'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($f['modelo']) . ' · ' . htmlspecialchars($f['patente']) . '</td>';
        echo '<td>' . htmlspecialchars($f['dueno']) . '</td>';
        echo '<td>' . htmlspecialchars($f['mecanico'] ?? 'Sin asignar') . '</td>';
        echo '<td>' . ($f['precio'] !== null ? '$ ' . number_format($f['precio'], 2, ',', '.') : '-') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

// Metas que el script necesita: token de la sesión y URL de la API (viene de la configuración)
$extraHead = '<meta name="api-token" content="' . htmlspecialchars($_SESSION['api_token'] ?? '') . '">'
    . '<meta name="api-base" content="' . htmlspecialchars($config['api_url']) . '">';

inicio_html('Dashboard', $extraHead);
?>
  <h1>Dashboard</h1>

  <h2>Caja</h2>
  <p>Total recaudado (presupuestos aprobados): <strong>$ <?= number_format($caja, 2, ',', '.') ?></strong></p>

  <h2>Insights</h2>
  <div id="insights">Cargando...</div>

  <h2>Trabajos activos</h2>
  <?php tablaTrabajos($activos); ?>

  <h2>Trabajos realizados</h2>
  <?php tablaTrabajos($realizados); ?>

  <h2>Stock de repuestos</h2>
  <table border="1" cellpadding="4">
    <tr><th>Repuesto</th><th>Stock</th><th>Precio</th></tr>
    <?php foreach ($stock as $r): ?>
      <tr style="<?= $r['stock'] < 5 ? 'background:#fdd;' : '' ?>">
        <td><?= htmlspecialchars($r['nombre']) ?></td>
        <td><?= (int) $r['stock'] ?><?= $r['stock'] < 5 ? ' (bajo)' : '' ?></td>
        <td>$ <?= number_format($r['precio'], 2, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <script>
    const API_BASE = document.querySelector('meta[name="api-base"]').content;
    const token = document.querySelector('meta[name="api-token"]').content;
    const contenedor = document.getElementById('insights');

    fetch(API_BASE + '/api/dashboard/resumen', {
      headers: { Authorization: 'Bearer ' + token },
    })
      .then((r) => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then((datos) => {
        contenedor.textContent = '';

        const titulo1 = document.createElement('h3');
        titulo1.textContent = 'Facturación por mes';
        contenedor.appendChild(titulo1);

        const max = Math.max(1, ...datos.facturacion_mensual.map((f) => f.total));
        datos.facturacion_mensual.forEach((f) => {
          const fila = document.createElement('div');
          fila.textContent = f.mes + ' · $ ' + f.total.toLocaleString('es-AR');
          const barra = document.createElement('div');
          barra.style.cssText = 'height:10px; background:#4a90d9; margin-bottom:6px; width:' + (f.total / max * 100) + '%';
          contenedor.appendChild(fila);
          contenedor.appendChild(barra);
        });

        const titulo2 = document.createElement('h3');
        titulo2.textContent = 'Trabajos realizados por mecánico';
        contenedor.appendChild(titulo2);

        datos.trabajos_por_mecanico.forEach((m) => {
          const fila = document.createElement('div');
          fila.textContent = m.nombre + ': ' + m.trabajos;
          contenedor.appendChild(fila);
        });
      })
      .catch(() => {
        contenedor.textContent = 'No se pudieron cargar los insights. Revisá que la API esté corriendo.';
      });
  </script>

  <?php link_panel(); ?>
<?php
fin_html();