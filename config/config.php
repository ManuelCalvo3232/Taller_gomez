<?php

// Configuración leída del entorno. No contiene claves: en local van los valores por defecto
// y en Dokploy las variables se definen en el panel del servicio.
return [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'base' => getenv('DB_NAME') ?: 'taller_gomez',
    'usuario' => getenv('DB_USER') ?: 'taller_app',
    'clave' => getenv('DB_PASS') ?: '',

    // URL pública de la API de Node, sin barra final
    'api_url' => getenv('API_URL') ?: 'http://localhost:3000',

    // true cuando la app está detrás de un proxy (Traefik en Dokploy)
    'trust_proxy' => getenv('TRUST_PROXY') === '1',
];