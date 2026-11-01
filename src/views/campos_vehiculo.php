<?php

// Campos de vehículo para "Pedir turno" y "Mis vehículos".
// public/assets/vehiculo.js arma las listas de marca y modelo a partir del catálogo.
function campos_vehiculo(int $anioMax): void
{
    ?>
    <fieldset class="tipo completo">
        <legend>Tipo de vehículo</legend>
        <label><input type="radio" name="tipo" value="auto"> Auto</label>
        <label><input type="radio" name="tipo" value="moto"> Moto</label>
    </fieldset>

    <label class="campo">
        Patente
        <input type="text" name="patente" maxlength="10" placeholder="AB123CD">
        <small id="ayuda-patente"></small>
    </label>

    <label class="campo">
        Marca
        <select name="marca_id"></select>
    </label>
    <label class="campo" data-wrap="marca-otra" hidden>
        Otra marca
        <input type="text" name="marca_otra" maxlength="60" placeholder="Escribí la marca">
    </label>

    <label class="campo" data-wrap="modelo">
        Modelo
        <select name="modelo_id"></select>
    </label>
    <label class="campo" data-wrap="modelo-otro" hidden>
        Otro modelo (especificar)
        <input type="text" name="modelo_otro" maxlength="80" placeholder="Ej: Gol Trend 1.6 2015">
    </label>

    <label class="campo" data-solo="moto">
        Cilindrada (cc)
        <input type="number" name="cilindrada" min="50" max="3000">
    </label>

    <label class="campo">
        Año
        <input type="number" name="anio" min="1950" max="<?= $anioMax ?>">
    </label>

    <label class="campo">
        Kilómetros
        <input type="number" name="kilometros" min="0">
    </label>
    <?php
}