<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';

iniciarSesionSegura();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responderJSON(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}

if (empty($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    responderJSON([
        'status'        => 'error',
        'authenticated' => false,
        'message'       => 'No existe una sesión activa.',
    ], 401);
}

responderJSON([
    'status'        => 'success',
    'authenticated' => true,
    'usuario'       => [
        'nombre' => $_SESSION['nombre'],
        'rol'    => $_SESSION['rol'],
    ],
], 200);
