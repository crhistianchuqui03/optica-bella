<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth/session.php';

$sesion = requerirAutenticacion(['ADMIN']);

$pdo = Database::getConnection();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $stmt = $pdo->query(
            'SELECT id, username, nombre_completo, rol, estado, creado_en
             FROM usuarios
             ORDER BY nombre_completo ASC'
        );

        responderJSON(['status' => 'success', 'data' => $stmt->fetchAll()], 200);

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            responderJSON(['status' => 'error', 'message' => 'JSON inválido.'], 400);
        }

        $accion = trim((string) ($input['accion'] ?? ''));

        if ($accion === 'cambiar_estado') {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                responderJSON(['status' => 'error', 'message' => 'Identificador de usuario inválido.'], 400);
            }

            $estado = filter_var($input['estado'] ?? 0, FILTER_VALIDATE_INT) === 1 ? 1 : 0;

            if ($estado === 0 && (int) $sesion['usuario_id'] === $id) {
                responderJSON(['status' => 'error', 'message' => 'No puede desactivar su propia cuenta.'], 400);
            }

            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);

            if ($stmt->fetch() === false) {
                responderJSON(['status' => 'error', 'message' => 'Usuario no encontrado.'], 404);
            }

            $stmt = $pdo->prepare('UPDATE usuarios SET estado = :estado WHERE id = :id');
            $stmt->execute([':estado' => $estado, ':id' => $id]);

            responderJSON([
                'status'  => 'success',
                'message' => $estado === 1 ? 'Usuario activado correctamente.' : 'Usuario desactivado correctamente.',
            ], 200);
        }

        if ($accion === 'actualizar') {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                responderJSON(['status' => 'error', 'message' => 'Identificador de usuario inválido.'], 400);
            }

            $stmt = $pdo->prepare('SELECT id, rol FROM usuarios WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $usuario = $stmt->fetch();

            if ($usuario === false) {
                responderJSON(['status' => 'error', 'message' => 'Usuario no encontrado.'], 404);
            }

            $nombreCompleto = trim((string) ($input['nombre_completo'] ?? ''));

            if ($nombreCompleto === '') {
                responderJSON(['status' => 'error', 'message' => 'El campo nombre_completo es obligatorio.'], 400);
            }

            $rol = trim((string) ($input['rol'] ?? $usuario['rol']));

            if (!in_array($rol, ['ADMIN', 'VENDEDOR'], true)) {
                responderJSON(['status' => 'error', 'message' => 'Rol inválido.'], 400);
            }

            if ((int) $sesion['usuario_id'] === $id && $rol !== 'ADMIN') {
                responderJSON(['status' => 'error', 'message' => 'No puede cambiar el rol de su propia cuenta de administrador.'], 400);
            }

            $stmt = $pdo->prepare('UPDATE usuarios SET nombre_completo = :nombre, rol = :rol WHERE id = :id');
            $stmt->execute([
                ':nombre' => $nombreCompleto,
                ':rol'    => $rol,
                ':id'     => $id,
            ]);

            responderJSON([
                'status'  => 'success',
                'message' => 'Usuario actualizado correctamente.',
            ], 200);
        }

        if ($accion === 'resetear_password') {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                responderJSON(['status' => 'error', 'message' => 'Identificador de usuario inválido.'], 400);
            }

            $password = trim((string) ($input['password'] ?? ''));

            if (strlen($password) < 6) {
                responderJSON(['status' => 'error', 'message' => 'La contraseña debe tener al menos 6 caracteres.'], 400);
            }

            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);

            if ($stmt->fetch() === false) {
                responderJSON(['status' => 'error', 'message' => 'Usuario no encontrado.'], 404);
            }

            $stmt = $pdo->prepare('UPDATE usuarios SET password_hash = :hash WHERE id = :id');
            $stmt->execute([':hash' => password_hash($password, PASSWORD_BCRYPT), ':id' => $id]);

            responderJSON([
                'status'  => 'success',
                'message' => 'Contraseña actualizada correctamente.',
            ], 200);
        }

        $username      = trim((string) ($input['username'] ?? ''));
        $nombreCompleto = trim((string) ($input['nombre_completo'] ?? ''));
        $rol           = trim((string) ($input['rol'] ?? 'VENDEDOR'));
        $password      = (string) ($input['password'] ?? '');

        if ($username === '') {
            responderJSON(['status' => 'error', 'message' => 'El campo username es obligatorio.'], 400);
        }

        if (strlen($username) < 3) {
            responderJSON(['status' => 'error', 'message' => 'El usuario debe tener al menos 3 caracteres.'], 400);
        }

        if ($nombreCompleto === '') {
            responderJSON(['status' => 'error', 'message' => 'El campo nombre_completo es obligatorio.'], 400);
        }

        if (!in_array($rol, ['ADMIN', 'VENDEDOR'], true)) {
            responderJSON(['status' => 'error', 'message' => 'Rol inválido.'], 400);
        }

        if (strlen($password) < 6) {
            responderJSON(['status' => 'error', 'message' => 'La contraseña debe tener al menos 6 caracteres.'], 400);
        }

        $stmt = $pdo->prepare('SELECT 1 FROM usuarios WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);

        if ($stmt->fetch() !== false) {
            responderJSON(['status' => 'error', 'message' => 'El nombre de usuario ya existe.'], 400);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (username, password_hash, nombre_completo, rol)
             VALUES (:username, :password_hash, :nombre_completo, :rol)'
        );
        $stmt->execute([
            ':username'        => $username,
            ':password_hash'   => password_hash($password, PASSWORD_BCRYPT),
            ':nombre_completo' => $nombreCompleto,
            ':rol'             => $rol,
        ]);

        responderJSON([
            'status'  => 'success',
            'message' => 'Usuario creado correctamente.',
            'data'    => ['id' => (int) $pdo->lastInsertId()],
        ], 200);

    default:
        responderJSON(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}