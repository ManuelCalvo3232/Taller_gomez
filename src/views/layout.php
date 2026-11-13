<?php

// Iconos SVG inline estilo Lucide (sin dependencias externas ni CDN)
function icono_svg(string $nombre, string $claseExtra = ''): string
{
    $attrs = 'class="svg-ico ' . htmlspecialchars($claseExtra) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';

    return match ($nombre) {
        'home' => "<svg $attrs><path d=\"m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z\"/><polyline points=\"9 22 9 12 15 12 15 22\"/></svg>",
        'calendar' => "<svg $attrs><rect width=\"18\" height=\"18\" x=\"3\" y=\"4\" rx=\"2\" ry=\"2\"/><line x1=\"16\" x2=\"16\" y1=\"2\" y2=\"6\"/><line x1=\"8\" x2=\"8\" y1=\"2\" y2=\"6\"/><line x1=\"3\" x2=\"21\" y1=\"10\" y2=\"10\"/></svg>",
        'credit-card' => "<svg $attrs><rect width=\"20\" height=\"14\" x=\"2\" y=\"5\" rx=\"2\"/><line x1=\"2\" x2=\"22\" y1=\"10\" y2=\"10\"/></svg>",
        'car' => "<svg $attrs><path d=\"M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2\"/><circle cx=\"7\" cy=\"17\" r=\"2\"/><path d=\"M9 17h6\"/><circle cx=\"17\" cy=\"17\" r=\"2\"/></svg>",
        'history' => "<svg $attrs><path d=\"M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8\"/><path d=\"M3 3v5h5\"/><path d=\"M12 7v5l4 2\"/></svg>",
        'user' => "<svg $attrs><path d=\"M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2\"/><circle cx=\"12\" cy=\"7\" r=\"4\"/></svg>",
        'wrench' => "<svg $attrs><path d=\"M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z\"/></svg>",
        'box' => "<svg $attrs><path d=\"M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z\"/><path d=\"m3.3 7 8.7 5 8.7-5\"/><path d=\"M12 22V12\"/></svg>",
        'chart' => "<svg $attrs><line x1=\"18\" x2=\"18\" y1=\"20\" y2=\"10\"/><line x1=\"12\" x2=\"12\" y1=\"20\" y2=\"4\"/><line x1=\"6\" x2=\"6\" y1=\"20\" y2=\"14\"/></svg>",
        'shield' => "<svg $attrs><path d=\"M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z\"/><path d=\"m9 12 2 2 4-4\"/></svg>",
        'whatsapp' => "<svg $attrs><path d=\"M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z\"/></svg>",
        'arrow-right' => "<svg $attrs><path d=\"M5 12h14\"/><path d=\"m12 5 7 7-7 7\"/></svg>",
        'check' => "<svg $attrs><polyline points=\"20 6 9 17 4 12\"/></svg>",
        'printer' => "<svg $attrs><polyline points=\"6 9 6 2 18 2 18 9\"/><path d=\"M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2\"/><rect width=\"12\" height=\"8\" x=\"6\" y=\"14\"/></svg>",
        default => '',
    };
}

// Inicio de página HTML común a todas las vistas.
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
        <a href="panel"<?= $enlaceActivo('panel') ?>><?= icono_svg('home') ?><span>Inicio</span></a>
        <?php if ($rol === 'cliente'): ?>
          <a href="turno_nuevo"<?= $enlaceActivo('turno_nuevo') ?>><?= icono_svg('calendar') ?><span>Pedir turno</span></a>
          <a href="presupuestos"<?= $enlaceActivo('presupuestos') ?>><?= icono_svg('credit-card') ?><span>Presupuestos</span></a>
          <a href="vehiculos"<?= $enlaceActivo('vehiculos') ?>><?= icono_svg('car') ?><span>Vehículos</span></a>
          <a href="historial"<?= $enlaceActivo('historial') ?>><?= icono_svg('history') ?><span>Historial</span></a>
          <a href="perfil"<?= $enlaceActivo('perfil') ?>><?= icono_svg('user') ?><span>Mi perfil</span></a>
        <?php elseif ($rol === 'mecanico'): ?>
          <a href="trabajos"<?= $enlaceActivo('trabajos') ?>><?= icono_svg('wrench') ?><span>Trabajos</span></a>
          <a href="stock"<?= $enlaceActivo('stock') ?>><?= icono_svg('box') ?><span>Repuestos</span></a>
        <?php elseif ($rol === 'admin'): ?>
          <a href="dashboard"<?= $enlaceActivo('dashboard') ?>><?= icono_svg('chart') ?><span>Dashboard</span></a>
          <a href="stock"<?= $enlaceActivo('stock') ?>><?= icono_svg('box') ?><span>Stock y proveedores</span></a>
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

function mostrar_mensajes(string $mensaje, array $errores): void
{
    if ($mensaje !== '') {
        echo '<div class="alerta alerta-ok">' . htmlspecialchars($mensaje) . '</div>';
    }
    foreach ($errores as $error) {
        echo '<div class="alerta alerta-error">' . htmlspecialchars($error) . '</div>';
    }
}

function link_panel(): void
{
    echo '<p class="no-print" style="margin-top:24px"><a href="panel">← Volver al inicio</a></p>';
}

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
