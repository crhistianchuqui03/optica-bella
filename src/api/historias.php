<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth/session.php';

requerirAutenticacion(['ADMIN', 'VENDEDOR']);

$pdo = Database::getConnection();

/**
 * Valida una fecha en formato YYYY-MM-DD.
 */
function esFechaValida(string $fecha): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $fecha);

    return $d !== false && $d->format('Y-m-d') === $fecha;
}

/**
 * Verifica si un paciente existe en la base de datos.
 */
function pacienteExiste(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM pacientes WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);

    return $stmt->fetch() !== false;
}

/**
 * Verifica si una historia clínica existe en la base de datos.
 */
function historiaExiste(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM historias_clinicas WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);

    return $stmt->fetch() !== false;
}

/**
 * Recolecta y limpia las medidas de refracción, tratamiento e indicaciones
 * enviadas desde el formulario (común a creación y actualización).
 */
function recogerCamposHistoria(array $input): array
{
    $columnas = [
        'esf_od_vl', 'cyl_od_vl', 'eje_od_vl', 'dip_od_vl', 'av_od_vl',
        'esf_oi_vl', 'cyl_oi_vl', 'eje_oi_vl', 'dip_oi_vl', 'av_oi_vl',
        'add_medicion',
        'esf_od_vc', 'cyl_od_vc', 'eje_od_vc', 'dip_od_vc', 'av_od_vc',
        'esf_oi_vc', 'cyl_oi_vc', 'eje_oi_vc', 'dip_oi_vc', 'av_oi_vc',
        'tratamiento', 'indicaciones',
    ];

    $campos = [];

    foreach ($columnas as $columna) {
        $valor = $input[$columna] ?? null;

        if (is_string($valor)) {
            $valor = trim($valor);

            if ($valor === '') {
                $valor = null;
            }
        }

        $campos[$columna] = $valor;
    }

    return $campos;
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (isset($_GET['id'])) {
            $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                responderJSON(['status' => 'error', 'message' => 'Identificador de historia clínica inválido.'], 400);
            }

            $stmt = $pdo->prepare('SELECT * FROM historias_clinicas WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $historia = $stmt->fetch();

            if ($historia === false) {
                responderJSON(['status' => 'error', 'message' => 'Historia clínica no encontrada.'], 404);
            }

            responderJSON(['status' => 'success', 'data' => $historia], 200);
        }

        if (!isset($_GET['paciente_id'])) {
            responderJSON(['status' => 'error', 'message' => 'Parámetro requerido: paciente_id.'], 400);
        }

        $pacienteId = filter_var($_GET['paciente_id'], FILTER_VALIDATE_INT);

        if ($pacienteId === false || $pacienteId <= 0) {
            responderJSON(['status' => 'error', 'message' => 'Identificador de paciente inválido.'], 400);
        }

        $stmt = $pdo->prepare(
            'SELECT *
             FROM historias_clinicas
             WHERE paciente_id = :paciente_id
             ORDER BY fecha_consulta DESC'
        );
        $stmt->execute([':paciente_id' => $pacienteId]);

        responderJSON([
            'status' => 'success',
            'data'   => $stmt->fetchAll(),
        ], 200);

    case 'POST':
    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            responderJSON(['status' => 'error', 'message' => 'JSON inválido.'], 400);
        }

        $id       = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        $accion   = trim((string) ($input['accion'] ?? ''));
        $esActualizacion = $_SERVER['REQUEST_METHOD'] === 'PUT'
            || $accion === 'actualizar'
            || (is_int($id) && $id > 0);

        $pacienteId = filter_var($input['paciente_id'] ?? null, FILTER_VALIDATE_INT);

        if ($pacienteId === false || $pacienteId <= 0) {
            responderJSON(['status' => 'error', 'message' => 'Identificador de paciente inválido.'], 400);
        }

        if (!pacienteExiste($pdo, $pacienteId)) {
            responderJSON(['status' => 'error', 'message' => 'El paciente no existe.'], 404);
        }

        $fechaConsulta = trim((string) ($input['fecha_consulta'] ?? ''));

        if ($fechaConsulta === '' || !esFechaValida($fechaConsulta)) {
            responderJSON(['status' => 'error', 'message' => 'La fecha de consulta es obligatoria en formato YYYY-MM-DD.'], 400);
        }

        $material = trim((string) ($input['material'] ?? '')) ?: null;
        $tipoLente = trim((string) ($input['tipo_lente'] ?? '')) ?: null;
        $proximaCita = trim((string) ($input['proxima_cita'] ?? '')) ?: null;

        if ($material !== null && !in_array($material, ['POLICARBONATO', 'CRISTAL', 'RESINA', 'ALTO INDICE', 'OTRO'], true)) {
            responderJSON(['status' => 'error', 'message' => 'Material inválido.'], 400);
        }

        if ($tipoLente !== null && !in_array($tipoLente, ['MONOFOCAL', 'BIFOCAL', 'MULTIFOCAL'], true)) {
            responderJSON(['status' => 'error', 'message' => 'Tipo de lente inválido.'], 400);
        }

        if ($proximaCita !== null && !esFechaValida($proximaCita)) {
            responderJSON(['status' => 'error', 'message' => 'Próxima cita inválida (formato YYYY-MM-DD).'], 400);
        }

        $campos = recogerCamposHistoria($input);

        if ($esActualizacion) {
            if (!is_int($id) || $id <= 0) {
                responderJSON(['status' => 'error', 'message' => 'Identificador de historia clínica inválido.'], 400);
            }

            if (!historiaExiste($pdo, $id)) {
                responderJSON(['status' => 'error', 'message' => 'Historia clínica no encontrada.'], 404);
            }

            $setPartes = ['paciente_id = :paciente_id', 'fecha_consulta = :fecha_consulta'];
            $params = [
                ':paciente_id'    => $pacienteId,
                ':fecha_consulta' => $fechaConsulta,
                ':id'             => $id,
            ];

            foreach ($campos as $columna => $valor) {
                $setPartes[] = $columna . ' = :' . $columna;
                $params[':' . $columna] = $valor;
            }

            $setPartes[] = 'material = :material';
            $params[':material'] = $material;

            $setPartes[] = 'tipo_lente = :tipo_lente';
            $params[':tipo_lente'] = $tipoLente;

            $setPartes[] = 'proxima_cita = :proxima_cita';
            $params[':proxima_cita'] = $proximaCita;

            $stmt = $pdo->prepare(
                'UPDATE historias_clinicas SET ' . implode(', ', $setPartes) . ' WHERE id = :id'
            );
            $stmt->execute($params);

            responderJSON([
                'status'  => 'success',
                'message' => 'Historia clínica actualizada correctamente.',
                'data'    => ['id' => $id],
            ], 200);
        }

        $params = [':paciente_id' => $pacienteId, ':fecha_consulta' => $fechaConsulta];

        foreach ($campos as $columna => $valor) {
            $params[':' . $columna] = $valor;
        }

        $params[':material']      = $material;
        $params[':tipo_lente']    = $tipoLente;
        $params[':proxima_cita']  = $proximaCita;

        $sql = 'INSERT INTO historias_clinicas (paciente_id, fecha_consulta, material, tipo_lente, proxima_cita, '
            . implode(', ', array_keys($campos)) . ') VALUES (:paciente_id, :fecha_consulta, :material, :tipo_lente, :proxima_cita, :'
            . implode(', :', array_keys($campos)) . ')';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        responderJSON([
            'status'  => 'success',
            'message' => 'Historia clínica registrada correctamente.',
            'data'    => ['id' => (int) $pdo->lastInsertId()],
        ], 200);

    default:
        responderJSON(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}