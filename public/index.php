<?php

// Entrada del sitio: el panel pide sesión y, si no hay, redirige al login
header('Location: panel.php');
exit;