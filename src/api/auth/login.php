<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/session.php';

iniciarSesionSegura();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJSON(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    responderJSON(['status' => 'error', 'message' => 'JSON inválido.'], 400);
}

$username = trim((string) ($input['username'] ?? ''));
$password = (string) ($input['password'] ?? '');

if ($username === '' || $password === '') {
    responderJSON(['status' => 'error', 'message' => 'Usuario y contraseña son obligatorios.'], 400);
}

$stmt = Database::getInstance()->getPdo()->prepare(
    'SELECT id, username, password_hash, nombre_completo, rol, estado
     FROM usuarios
     WHERE username = :username
     LIMIT 1'
);
$stmt->execute([':username' => $username]);
$usuario = $stmt->fetch();

$credencialesValidas = $usuario !== false
    && password_verify($password, $usuario['password_hash'])
    && (int) $usuario['estado'] === 1;

if (!$credencialesValidas) {
    responderJSON(['status' => 'error', 'message' => 'Credenciales incorrectas.'], 401);
}

session_regenerate_id(true);

$_SESSION['autenticado'] = true;
$_SESSION['usuario_id'] = (int) $usuario['id'];
$_SESSION['username']   = $usuario['username'];
$_SESSION['nombre']     = $usuario['nombre_completo'];
$_SESSION['rol']        = $usuario['rol'];

responderJSON([
    'status'  => 'success',
    'message' => 'Inicio de sesión exitoso.',
    'data'    => [
        'usuario' => [
            'usuario_id' => (int) $usuario['id'],
            'username'   => $usuario['username'],
            'nombre'     => $usuario['nombre_completo'],
            'rol'        => $usuario['rol'],
        ],
    ],
], 200);
