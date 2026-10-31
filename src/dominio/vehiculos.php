<?php

const TIPOS_VEHICULO = ['auto', 'moto'];

// Mayúsculas y sin espacios ni guiones: "A 123 BCD" queda como "A123BCD"
function normalizar_patente(string $patente): string
{
    return strtoupper(preg_replace('/[\s\-]+/', '', $patente));
}

// Auto: AB123CD (nueva) o ABC123 (vieja)
// Moto: A123BCD (nueva) o 123BCD (vieja)
function patente_valida(string $tipo, string $patente): bool
{
    $patron = $tipo === 'moto'
        ? '/^([A-Z]\d{3}[A-Z]{3}|\d{3}[A-Z]{3})$/'
        : '/^([A-Z]{2}\d{3}[A-Z]{2}|[A-Z]{3}\d{3})$/';

    return preg_match($patron, $patente) === 1;
}