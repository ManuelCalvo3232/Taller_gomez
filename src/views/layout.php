<?php

// Inicio de página HTML común a todas las vistas.
// $extraHead permite agregar metas o scripts específicos de una página.
function inicio_html(string $titulo, string $extraHead = ''): void
{
    $logueado = isset($_SESSION['usuario_id']);
    $rol = $_SESSION['rol'] ?? null;
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($titulo) ?> · Taller Gómez</title>
  <link rel="stylesheet" href="assets/app.css">
  <?= $extraHead ?>
</head>
<body>
  <header class="barra">
    <a class="marca" href="<?= $logueado ? 'panel.php' : 'login.php' ?>">TALLER GÓMEZ</a>

    <?php if ($logueado): ?>
      <nav class="menu">
        <a href="panel.php">Inicio</a>
        <?php if ($rol === 'cliente'): ?>
          <a href="turno_nuevo.php">Pedir turno</a>
          <a href="presupuestos.php">Presupuestos</a>
        <?php elseif ($rol === 'mecanico'): ?>
          <a href="trabajos.php">Trabajos</a>
        <?php elseif ($rol === 'admin'): ?>
          <a href="dashboard.php">Dashboard</a>
        <?php endif; ?>
      </nav>

      <form method="POST" action="logout.php" class="salir">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <button type="submit" class="btn-link">Salir</button>
      </form>
    <?php endif; ?>
  </header>

  <main class="contenedor">
    <?php
}

function fin_html(): void
{
    ?>
  </main>
</body>
</html>
    <?php
}

// Muestra el mensaje de éxito (si hay) y la lista de errores
function mostrar_mensajes(string $mensaje, array $errores): void
{
    if ($mensaje !== '') {
        echo '<div class="alerta alerta-ok">' . htmlspecialchars($mensaje) . '</div>';
    }
    foreach ($errores as $error) {
        echo '<div class="alerta alerta-error">' . htmlspecialchars($error) . '</div>';
    }
}

// Enlace de vuelta al panel
function link_panel(): void
{
    echo '<p><a href="panel.php">← Volver al panel</a></p>';
}

// Badge con color según el estado del trabajo:
// gris = pendiente, rojo = en curso, blanco con borde = terminado, negro con rojo = rechazado
function badge_estado(string $estado): string
{
    $clase = match ($estado) {
        'recibido' => 'badge-pendiente',
        'listo' => 'badge-cerrado',
        'rechazado' => 'badge-rechazado',
        default => 'badge-activo',
    };
    $texto = str_replace('_', ' ', $estado);
    return '<span class="badge ' . $clase . '">' . htmlspecialchars($texto) . '</span>';
}