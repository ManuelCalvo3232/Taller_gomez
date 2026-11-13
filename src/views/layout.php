<?php

// Inicio de página HTML común a todas las vistas.
// $extraHead permite agregar metas o scripts específicos de una página.
function inicio_html(string $titulo, string $extraHead = ''): void
{
    $logueado = isset($_SESSION['usuario_id']);
    $rol = $_SESSION['rol'] ?? null;
    $nombreUsuario = $_SESSION['nombre'] ?? '';
    $paginaActual = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');

    $etiquetaRol = match ($rol) {
        'admin' => 'Administrador',
        'mecanico' => 'Mecánico',
        'cliente' => 'Cliente',
        default => '',
    };

    $enlaceActivo = function (string $ruta) use ($paginaActual): string {
        if ($paginaActual === $ruta || ($ruta === 'trabajos' && $paginaActual === 'trabajo')) {
            return ' class="activo" aria-current="page"';
        }
        return '';
    };
    ?><!DOCTYPE html>
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
    <a class="marca" href="<?= $logueado ? 'panel' : 'login' ?>">
      <span class="marca-iso" aria-hidden="true">TG</span>
      <span>TALLER GÓMEZ</span>
    </a>

    <?php if ($logueado): ?>
      <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="menu-principal" aria-label="Abrir menú">
        <span></span><span></span><span></span>
      </button>

      <nav class="menu" id="menu-principal">
        <a href="panel"<?= $enlaceActivo('panel') ?>>Inicio</a>
        <?php if ($rol === 'cliente'): ?>
          <a href="turno_nuevo"<?= $enlaceActivo('turno_nuevo') ?>>Pedir turno</a>
          <a href="presupuestos"<?= $enlaceActivo('presupuestos') ?>>Presupuestos</a>
          <a href="vehiculos"<?= $enlaceActivo('vehiculos') ?>>Vehículos</a>
          <a href="historial"<?= $enlaceActivo('historial') ?>>Historial</a>
          <a href="perfil"<?= $enlaceActivo('perfil') ?>>Mi perfil</a>
        <?php elseif ($rol === 'mecanico'): ?>
          <a href="trabajos"<?= $enlaceActivo('trabajos') ?>>Trabajos</a>
          <a href="stock"<?= $enlaceActivo('stock') ?>>Repuestos</a>
        <?php elseif ($rol === 'admin'): ?>
          <a href="dashboard"<?= $enlaceActivo('dashboard') ?>>Dashboard</a>
          <a href="stock"<?= $enlaceActivo('stock') ?>>Stock y proveedores</a>
        <?php endif; ?>
      </nav>

      <div class="barra-usuario">
        <span class="rol-tag">
          <span><?= htmlspecialchars($etiquetaRol) ?></span>
          <?php if ($nombreUsuario !== ''): ?>
            · <strong><?= htmlspecialchars($nombreUsuario) ?></strong>
          <?php endif; ?>
        </span>
        <form method="POST" action="logout" class="salir">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
          <button type="submit" class="btn-link">Salir</button>
        </form>
      </div>
    <?php endif; ?>
  </header>

  <script>
    document.querySelectorAll('.menu-toggle').forEach((boton) => {
      boton.addEventListener('click', () => {
        const barra = boton.closest('.barra');
        const abierta = barra.classList.toggle('abierta');
        boton.setAttribute('aria-expanded', String(abierta));
        boton.setAttribute('aria-label', abierta ? 'Cerrar menú' : 'Abrir menú');
      });
    });
  </script>

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
    echo '<p style="margin-top:24px"><a href="panel">← Volver al panel</a></p>';
}

// Badge con color y etiqueta legible según el estado del trabajo o presupuesto
function badge_estado(string $estado): string
{
    $clase = match ($estado) {
        'recibido' => 'badge-pendiente',
        'listo', 'aprobado', 'pagado' => 'badge-cerrado',
        'rechazado' => 'badge-rechazado',
        default => 'badge-activo',
    };

    $texto = match ($estado) {
        'recibido' => 'Recibido',
        'en_diagnostico' => 'En diagnóstico',
        'presupuestado' => 'Presupuestado',
        'aprobado' => 'Aprobado',
        'en_reparacion' => 'En reparación',
        'listo' => 'Listo para retirar',
        'rechazado' => 'Rechazado',
        'enviado' => 'Pendiente de aprobación',
        default => ucfirst(str_replace('_', ' ', $estado)),
    };

    return '<span class="badge ' . $clase . '">' . htmlspecialchars($texto) . '</span>';
}
