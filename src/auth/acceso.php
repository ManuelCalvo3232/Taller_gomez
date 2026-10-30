<?php

// Carga la sesión y las funciones CSRF
require_once __DIR__ . '/csrf.php';

// Cualquier página protegida empieza con requerir_login() o requerir_rol()

function requerir_login(): void
{
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: login.php');
        exit;
    }
}

function requerir_rol(string ...$roles): void
{
    requerir_login();

    // strict = true: compara tipos además de valores
    if (!in_array($_SESSION['rol'], $roles, true)) {
        http_response_code(403);
        exit('No tenés permiso para ver esta página.');
    }
}