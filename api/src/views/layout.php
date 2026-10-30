<?php

// Inicio de página HTML común a todas las vistas.
// $extraHead permite agregar metas o scripts específicos de una página.
function inicio_html(string $titulo, string $extraHead = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($titulo) ?> - Taller Gómez</title>
  <?= $extraHead ?>
</head>
<body>
    <?php
}

function fin_html(): void
{
    ?>
</body>
</html>
    <?php
}

// Muestra el mensaje de éxito (si hay) y la lista de errores.
// Todo pasa por htmlspecialchars, así que nunca se imprime HTML del usuario.
function mostrar_mensajes(string $mensaje, array $errores): void
{
    if ($mensaje !== '') {
        echo '<p style="color:green">' . htmlspecialchars($mensaje) . '</p>';
    }
    foreach ($errores as $error) {
        echo '<p style="color:red">' . htmlspecialchars($error) . '</p>';
    }
}

// Enlace de vuelta al panel, usado en casi todas las páginas
function link_panel(): void
{
    echo '<p><a href="panel.php">Volver al panel</a></p>';
}