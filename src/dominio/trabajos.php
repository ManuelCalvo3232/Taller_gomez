<?php

// Reglas de negocio de los estados de un trabajo.
// Es la única fuente de verdad: las páginas y la API consultan acá, no escriben reglas propias.

const ESTADOS_TRABAJO = [
    'recibido',
    'en_diagnostico',
    'presupuestado',
    'aprobado',
    'rechazado',
    'en_reparacion',
    'listo',
];

// Desde cada estado, a cuáles se puede pasar
const TRANSICIONES_TRABAJO = [
    'recibido'       => ['en_diagnostico'],
    'en_diagnostico' => ['presupuestado'],
    'presupuestado'  => ['aprobado', 'rechazado'],
    'aprobado'       => ['en_reparacion', 'listo'],
    'en_reparacion'  => ['listo'],
    'rechazado'      => [],
    'listo'          => [],
];

function puede_pasar(string $desde, string $hacia): bool
{
    return in_array($hacia, TRANSICIONES_TRABAJO[$desde] ?? [], true);
}

// Estados donde todavía se puede cargar un presupuesto nuevo
function admite_presupuesto(string $estado): bool
{
    return in_array($estado, ['en_diagnostico', 'presupuestado'], true);
}