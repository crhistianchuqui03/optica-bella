<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth/session.php';

requerirAutenticacion(['ADMIN', 'VENDEDOR']);

$pdo = Database::getConnection();

/**
 * Elimina un paciente validando su existencia y que no tenga contratos asociados.
 */
function eliminarPacienteRegistro(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('SELECT 1 FROM pacientes WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);

    if ($stmt->fetch() === false) {
        responderJSON(['status' => 'error', 'message' => 'Paciente no encontrado.'], 404);
    }

    $stmt = $pdo->prepare('SELECT 1 FROM contratos WHERE paciente_id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);

    if ($stmt->fetch() !== false) {
        responderJSON(['status' => 'error', 'message' => 'El paciente tiene contratos asociados y no puede eliminarse.'], 400);
    }

    $stmt = $pdo->prepare('DELETE FROM pacientes WHERE id = :id');
    $stmt->execute([':id' => $id]);

    responderJSON(['status' => 'success', 'message' => 'Paciente eliminado correctamente.'], 200);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (isset($_GET['id'])) {
            $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                responderJSON(['status' => 'error', 'message' => 'Identificador de paciente inválido.'], 400);
            }

            $stmt = $pdo->prepare(
                'SELECT id, nombres_apellidos, edad, celular, ocupacion, genero, creado_en
                 FROM pacientes
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $paciente = $stmt->fetch();

            if ($paciente === false) {
                responderJSON(['status' => 'error', 'message' => 'Paciente no encontrado.'], 404);
            }

            $stmtContratos = $pdo->prepare(
                'SELECT id, numero_contrato, monto_total, estado, fecha_entrega, creado_en
                 FROM contratos
                 WHERE paciente_id = :id
                 ORDER BY creado_en DESC'
            );
            $stmtContratos->execute([':id' => $id]);

            $stmtHistorias = $pdo->prepare(
                'SELECT *
                 FROM historias_clinicas
                 WHERE paciente_id = :id
                 ORDER BY fecha_consulta DESC'
            );
            $stmtHistorias->execute([':id' => $id]);

            responderJSON([
                'status' => 'success',
                'data'   => [
                    'paciente'          => $paciente,
                    'contratos'         => $stmtContratos->fetchAll(),
                    'historias_clinicas' => $stmtHistorias->fetchAll(),
                ],
            ], 200);
        }

        if (isset($_GET['q'])) {
            $q = trim((string) $_GET['q']);

            if ($q === '') {
                responderJSON(['status' => 'error', 'message' => 'Parámetro de búsqueda vacío.'], 400);
            }

            $patron = '%' . $q . '%';

            $stmt = $pdo->prepare(
                'SELECT id, nombres_apellidos, edad, celular, ocupacion, genero
                 FROM pacientes
                 WHERE nombres_apellidos LIKE :patron_nombre OR celular LIKE :patron_celular
                 ORDER BY nombres_apellidos ASC
                 LIMIT 50'
            );
            $stmt->execute([
                ':patron_nombre'  => $patron,
                ':patron_celular' => $patron,
            ]);

            responderJSON([
                'status' => 'success',
                'data'   => $stmt->fetchAll(),
            ], 200);
        }

        $stmt = $pdo->query(
            'SELECT id, nombres_apellidos, edad, celular, ocupacion, genero
             FROM pacientes
             ORDER BY nombres_apellidos ASC
             LIMIT 200'
        );

        responderJSON(['status' => 'success', 'data' => $stmt->fetchAll()], 200);

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            responderJSON(['status' => 'error', 'message' => 'JSON inválido.'], 400);
        }

        $accion = trim((string) ($input['accion'] ?? ''));

        if ($accion === 'eliminar') {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                responderJSON(['status' => 'error', 'message' => 'Identificador de paciente inválido.'], 400);
            }

            eliminarPacienteRegistro($pdo, $id);
        }

        if ($accion === 'actualizar') {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                responderJSON(['status' => 'error', 'message' => 'Identificador de paciente inválido.'], 400);
            }

            $stmt = $pdo->prepare('SELECT 1 FROM pacientes WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);

            if ($stmt->fetch() === false) {
                responderJSON(['status' => 'error', 'message' => 'Paciente no encontrado.'], 404);
            }

            $nombresApellidos = trim((string) ($input['nombres_apellidos'] ?? ''));

            if ($nombresApellidos === '') {
                responderJSON(['status' => 'error', 'message' => 'El campo nombres_apellidos es obligatorio.'], 400);
            }

            $edad    = isset($input['edad']) && $input['edad'] !== '' ? (int) $input['edad'] : null;
            $celular = trim((string) ($input['celular'] ?? '')) ?: null;
            $ocupacion = trim((string) ($input['ocupacion'] ?? '')) ?: null;
            $genero  = trim((string) ($input['genero'] ?? '')) ?: null;

            if ($genero !== null && !in_array($genero, ['MASCULINO', 'FEMENINO', 'OTRO'], true)) {
                responderJSON(['status' => 'error', 'message' => 'Género inválido.'], 400);
            }

            $stmt = $pdo->prepare(
                'UPDATE pacientes
                 SET nombres_apellidos = :nombres_apellidos, edad = :edad, celular = :celular,
                     ocupacion = :ocupacion, genero = :genero
                 WHERE id = :id'
            );
            $stmt->execute([
                ':nombres_apellidos' => $nombresApellidos,
                ':edad'              => $edad,
                ':celular'           => $celular,
                ':ocupacion'         => $ocupacion,
                ':genero'            => $genero,
                ':id'                => $id,
            ]);

            responderJSON([
                'status'  => 'success',
                'message' => 'Paciente actualizado correctamente.',
                'data'    => ['id' => $id],
            ], 200);
        }

        $nombresApellidos = trim((string) ($input['nombres_apellidos'] ?? ''));

        if ($nombresApellidos === '') {
            responderJSON(['status' => 'error', 'message' => 'El campo nombres_apellidos es obligatorio.'], 400);
        }

        $edad    = isset($input['edad']) && $input['edad'] !== '' ? (int) $input['edad'] : null;
        $celular = trim((string) ($input['celular'] ?? '')) ?: null;
        $ocupacion = trim((string) ($input['ocupacion'] ?? '')) ?: null;
        $genero  = trim((string) ($input['genero'] ?? '')) ?: null;

        if ($genero !== null && !in_array($genero, ['MASCULINO', 'FEMENINO', 'OTRO'], true)) {
            responderJSON(['status' => 'error', 'message' => 'Género inválido.'], 400);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO pacientes (nombres_apellidos, edad, celular, ocupacion, genero)
             VALUES (:nombres_apellidos, :edad, :celular, :ocupacion, :genero)'
        );
        $stmt->execute([
            ':nombres_apellidos' => $nombresApellidos,
            ':edad'              => $edad,
            ':celular'           => $celular,
            ':ocupacion'         => $ocupacion,
            ':genero'            => $genero,
        ]);

        responderJSON([
            'status'  => 'success',
            'message' => 'Paciente creado correctamente.',
            'data'    => ['id' => (int) $pdo->lastInsertId()],
        ], 200);

    case 'DELETE':
        $cuerpo = json_decode(file_get_contents('php://input'), true);

        if (!is_array($cuerpo)) {
            $cuerpo = [];
        }

        $id = filter_var($_GET['id'] ?? $cuerpo['id'] ?? null, FILTER_VALIDATE_INT);

        if ($id === false || $id <= 0) {
            responderJSON(['status' => 'error', 'message' => 'Identificador de paciente inválido.'], 400);
        }

        eliminarPacienteRegistro($pdo, $id);

    default:
        responderJSON(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}