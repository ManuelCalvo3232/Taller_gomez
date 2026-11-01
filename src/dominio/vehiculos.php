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
// Valida los datos de un vehículo (alta o edición).
// Devuelve [errores, datos normalizados]. Si hay errores, los datos no se usan.
function validar_vehiculo(array $v): array
{
    $errores = [];
    $tipo = in_array($v['tipo'] ?? '', TIPOS_VEHICULO, true) ? $v['tipo'] : 'auto';
    $patente = normalizar_patente((string) ($v['patente'] ?? ''));
    $marca = trim((string) ($v['marca'] ?? ''));
    $textoModelo = trim((string) ($v['modelo'] ?? ''));

    // Para motos, marca y modelo se guardan juntos en "modelo" (ej: "Honda Wave")
    if ($tipo === 'moto') {
        if ($marca === '') {
            $errores[] = 'Indicá la marca de la moto.';
        }
        if ($textoModelo === '') {
            $errores[] = 'Indicá el modelo de la moto.';
        }
        $modelo = trim($marca . ' ' . $textoModelo);
    } else {
        $modelo = $textoModelo;
    }

    if ($modelo === '' || mb_strlen($modelo) > 100) {
        $errores[] = 'El modelo es obligatorio y puede tener hasta 100 caracteres.';
    }

    if (!patente_valida($tipo, $patente)) {
        $errores[] = $tipo === 'moto'
            ? 'La patente de la moto no es válida. Ejemplos: A123BCD o 123BCD.'
            : 'La patente del auto no es válida. Ejemplos: AB123CD o ABC123.';
    }

    $anioMax = (int) date('Y') + 1;
    $anio = filter_var($v['anio'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1950, 'max_range' => $anioMax]]);
    if ($anio === false) {
        $errores[] = "El año debe estar entre 1950 y $anioMax.";
    }

    $km = filter_var($v['kilometros'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($km === false) {
        $errores[] = 'Los kilómetros tienen que ser un número mayor o igual a 0.';
    }

    $cilindrada = null;
    if ($tipo === 'moto') {
        $cilindrada = filter_var($v['cilindrada'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 50, 'max_range' => 3000]]);
        if ($cilindrada === false) {
            $errores[] = 'La cilindrada tiene que estar entre 50 y 3000 cc.';
        }
    }

    return [$errores, [
        'tipo' => $tipo,
        'patente' => $patente,
        'modelo' => $modelo,
        'anio' => $anio,
        'kilometros' => $km,
        'cilindrada' => $cilindrada,
    ]];
}