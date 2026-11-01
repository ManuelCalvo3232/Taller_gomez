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

// Catálogo para el formulario: marcas y modelos (con su tipo), tomados de la base
function catalogo_vehiculos(PDO $pdo): array
{
    $marcas = $pdo->query('SELECT id_marca, nombre FROM marcas ORDER BY nombre')->fetchAll();
    $modelos = $pdo->query('SELECT id_modelo, id_marca, tipo, nombre FROM modelos ORDER BY nombre')->fetchAll();

    return [
        'marcas' => array_map(fn (array $m): array => [
            'id' => (int) $m['id_marca'],
            'nombre' => $m['nombre'],
        ], $marcas),
        'modelos' => array_map(fn (array $m): array => [
            'id' => (int) $m['id_modelo'],
            'marca' => (int) $m['id_marca'],
            'tipo' => $m['tipo'],
            'nombre' => $m['nombre'],
        ], $modelos),
    ];
}

// Pasa una fila de vehiculos a los valores del formulario.
// Si no hay marca o modelo de la lista, el formulario muestra "Otra" con el texto guardado.
function valores_formulario_vehiculo(array $fila): array
{
    return [
        'tipo' => $fila['tipo'],
        'patente' => $fila['patente'],
        'marca_id' => $fila['id_marca'] !== null ? (string) $fila['id_marca'] : 'otra',
        'marca_otra' => (string) ($fila['marca_otra'] ?? ''),
        'modelo_id' => $fila['id_modelo'] !== null ? (string) $fila['id_modelo'] : 'otro',
        'modelo_otro' => (string) ($fila['modelo_otro'] ?? ''),
        'anio' => (string) $fila['anio'],
        'kilometros' => (string) $fila['kilometros'],
        'cilindrada' => (string) ($fila['cilindrada'] ?? ''),
    ];
}

// Valida los datos de un vehículo (alta o edición).
// Marca y modelo: de la lista, o "otra" / "otro" con texto libre.
// Devuelve [errores, datos normalizados]. Si hay errores, los datos no se usan.
function validar_vehiculo(PDO $pdo, array $v): array
{
    $errores = [];
    $tipo = in_array($v['tipo'] ?? '', TIPOS_VEHICULO, true) ? $v['tipo'] : 'auto';
    $patente = normalizar_patente((string) ($v['patente'] ?? ''));
    $marcaId = (string) ($v['marca_id'] ?? '');
    $modeloId = (string) ($v['modelo_id'] ?? '');
    $marcaOtra = trim((string) ($v['marca_otra'] ?? ''));
    $modeloOtro = trim((string) ($v['modelo_otro'] ?? ''));

    $idMarca = null;
    $idModelo = null;
    $marcaOtraDato = null;
    $modeloOtroDato = null;
    $nombreMarca = '';
    $nombreModelo = '';

    if ($marcaId === 'otra') {
        if ($marcaOtra === '' || mb_strlen($marcaOtra) > 60) {
            $errores[] = 'Escribí la marca (hasta 60 caracteres).';
        }
        $marcaOtraDato = $marcaOtra;
        $nombreMarca = $marcaOtra;
    } else {
        $idMarca = filter_var($marcaId, FILTER_VALIDATE_INT) ?: null;
        $stmt = $pdo->prepare('SELECT nombre FROM marcas WHERE id_marca = ?');
        $stmt->execute([$idMarca ?? 0]);
        $nombreMarca = (string) $stmt->fetchColumn();
        if ($nombreMarca === '') {
            $errores[] = 'Elegí una marca de la lista o "Otra marca".';
        }
    }

    // Con marca "otra" el modelo también es libre
    if ($marcaId === 'otra' || $modeloId === 'otro') {
        if ($modeloOtro === '' || mb_strlen($modeloOtro) > 80) {
            $errores[] = 'Escribí el modelo (hasta 80 caracteres).';
        }
        $modeloOtroDato = $modeloOtro;
        $nombreModelo = $modeloOtro;
    } else {
        $idModelo = filter_var($modeloId, FILTER_VALIDATE_INT) ?: null;
        // El modelo tiene que ser de esa marca y del tipo elegido
        $stmt = $pdo->prepare('SELECT nombre FROM modelos WHERE id_modelo = ? AND id_marca = ? AND tipo = ?');
        $stmt->execute([$idModelo ?? 0, $idMarca ?? 0, $tipo]);
        $nombreModelo = (string) $stmt->fetchColumn();
        if ($nombreModelo === '') {
            $errores[] = 'Elegí un modelo de la lista o "Otro modelo".';
        }
    }

    $modelo = trim($nombreMarca . ' ' . $nombreModelo);

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
        'id_marca' => $idMarca,
        'id_modelo' => $idModelo,
        'marca_otra' => $marcaOtraDato,
        'modelo_otro' => $modeloOtroDato,
        'modelo' => $modelo,
        'anio' => $anio,
        'kilometros' => $km,
        'cilindrada' => $cilindrada,
    ]];
}