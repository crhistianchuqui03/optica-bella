<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth/session.php';

$sesion = requerirAutenticacion(['ADMIN', 'VENDEDOR']);

$pdo = Database::getConnection();
$usuarioId = (int) $sesion['usuario_id'];

/**
 * Valida una fecha en formato YYYY-MM-DD.
 */
function esFechaValida(string $fecha): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $fecha);

    return $d !== false && $d->format('Y-m-d') === $fecha;
}

/**
 * Convierte un valor a DECIMAL con dos decimales.
 */
function aDecimal(mixed $valor): string
{
    return sprintf('%.2f', (float) $valor);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (isset($_GET['id'])) {
            $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                responderJSON(['status' => 'error', 'message' => 'Identificador de contrato inválido.'], 400);
            }

            $stmt = $pdo->prepare(
                'SELECT c.*,
                        p.nombres_apellidos AS paciente_nombre,
                        p.celular AS paciente_celular,
                        p.genero AS paciente_genero,
                        m.codigo_montura,
                        m.marca AS montura_marca,
                        m.descripcion_material AS montura_descripcion
                 FROM contratos c
                 LEFT JOIN pacientes p ON p.id = c.paciente_id
                 LEFT JOIN inventario_monturas m ON m.id = c.montura_id
                 WHERE c.id = :id
                 LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $contrato = $stmt->fetch();

            if ($contrato === false) {
                responderJSON(['status' => 'error', 'message' => 'Contrato no encontrado.'], 404);
            }

            $stmtAbonos = $pdo->prepare(
                'SELECT id, fecha_registro, tipo_movimiento, monto, concepto_descripcion
                 FROM movimientos_caja
                 WHERE contrato_id = :id
                 ORDER BY id ASC'
            );
            $stmtAbonos->execute([':id' => $id]);

            responderJSON([
                'status' => 'success',
                'data'   => $contrato,
                'movimientos' => $stmtAbonos->fetchAll(),
            ], 200);
        }

        if (isset($_GET['paciente_id'])) {
            $pacienteId = filter_var($_GET['paciente_id'], FILTER_VALIDATE_INT);

            if ($pacienteId === false || $pacienteId <= 0) {
                responderJSON(['status' => 'error', 'message' => 'Identificador de paciente inválido.'], 400);
            }

            $stmt = $pdo->prepare(
                'SELECT id, numero_contrato, monto_total, estado, fecha_entrega, creado_en
                 FROM contratos
                 WHERE paciente_id = :paciente_id
                 ORDER BY creado_en DESC'
            );
            $stmt->execute([':paciente_id' => $pacienteId]);

            responderJSON(['status' => 'success', 'data' => $stmt->fetchAll()], 200);
        }

        if (isset($_GET['q'])) {
            $q = trim((string) $_GET['q']);

            if ($q === '') {
                responderJSON(['status' => 'error', 'message' => 'Parámetro de búsqueda vacío.'], 400);
            }

            $patron = '%' . $q . '%';

            $stmt = $pdo->prepare(
                'SELECT c.id, c.numero_contrato, c.monto_total, c.estado, c.creado_en, c.fecha_entrega,
                        p.nombres_apellidos AS paciente_nombre,
                        m.codigo_montura, m.marca AS montura_marca,
                        (c.monto_total - COALESCE(
                            (SELECT SUM(mc.monto) FROM movimientos_caja mc
                             WHERE mc.contrato_id = c.id AND mc.tipo_movimiento = :tipo_ingreso), 0)) AS saldo_pendiente
                 FROM contratos c
                 INNER JOIN pacientes p ON p.id = c.paciente_id
                 LEFT JOIN inventario_monturas m ON m.id = c.montura_id
                 WHERE c.numero_contrato LIKE :patron_contrato
                    OR p.nombres_apellidos LIKE :patron_paciente
                    OR m.codigo_montura LIKE :patron_montura
                 ORDER BY c.creado_en DESC
                 LIMIT 200'
            );
            $stmt->execute([
                ':tipo_ingreso'    => 'INGRESO',
                ':patron_contrato' => $patron,
                ':patron_paciente' => $patron,
                ':patron_montura'  => $patron,
            ]);

            responderJSON(['status' => 'success', 'data' => $stmt->fetchAll()], 200);
        }

        if (isset($_GET['siguiente'])) {
            $stmt = $pdo->query('SELECT COALESCE(MAX(CAST(numero_contrato AS UNSIGNED)), 0) + 1 AS siguiente FROM contratos');
            $fila = $stmt->fetch();

            responderJSON([
                'status' => 'success',
                'data'   => [
                    'siguiente' => str_pad((string) ((int) $fila['siguiente']), 7, '0', STR_PAD_LEFT),
                ],
            ], 200);
        }

        $stmt = $pdo->prepare(
            'SELECT c.id, c.numero_contrato, c.monto_total, c.estado, c.creado_en, c.fecha_entrega,
                    p.nombres_apellidos AS paciente_nombre,
                    m.codigo_montura, m.marca AS montura_marca,
                    (c.monto_total - COALESCE(
                        (SELECT SUM(mc.monto) FROM movimientos_caja mc
                         WHERE mc.contrato_id = c.id AND mc.tipo_movimiento = :tipo_ingreso), 0)) AS saldo_pendiente
             FROM contratos c
             INNER JOIN pacientes p ON p.id = c.paciente_id
             LEFT JOIN inventario_monturas m ON m.id = c.montura_id
             ORDER BY c.creado_en DESC
             LIMIT 200'
        );
        $stmt->execute([':tipo_ingreso' => 'INGRESO']);

        responderJSON(['status' => 'success', 'data' => $stmt->fetchAll()], 200);

        responderJSON(['status' => 'error', 'message' => 'Parámetro requerido: id, paciente_id o q.'], 400);

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            responderJSON(['status' => 'error', 'message' => 'JSON inválido.'], 400);
        }

        $numeroContrato = trim((string) ($input['numero_contrato'] ?? ''));
        $detalle        = trim((string) ($input['detalle_contrato'] ?? '')) ?: null;
        $fechaEntrega   = trim((string) ($input['fecha_entrega'] ?? '')) ?: null;
        $comentarios    = trim((string) ($input['comentarios'] ?? '')) ?: null;

        $pacienteId = filter_var($input['paciente_id'] ?? null, FILTER_VALIDATE_INT);

        $monturaRaw = $input['montura_id'] ?? null;
        $monturaId = ($monturaRaw === null || $monturaRaw === '' || (int) $monturaRaw <= 0) ? null : (int) $monturaRaw;

        if ($numeroContrato === '') {
            responderJSON(['status' => 'error', 'message' => 'El campo numero_contrato es obligatorio.'], 400);
        }

        if ($pacienteId === false || $pacienteId <= 0) {
            responderJSON(['status' => 'error', 'message' => 'Identificador de paciente inválido.'], 400);
        }

        if (!isset($input['monto_total']) || !is_numeric($input['monto_total']) || (float) $input['monto_total'] < 0) {
            responderJSON(['status' => 'error', 'message' => 'El campo monto_total es inválido.'], 400);
        }

        if (!isset($input['monto_a_cuenta']) || !is_numeric($input['monto_a_cuenta']) || (float) $input['monto_a_cuenta'] < 0) {
            responderJSON(['status' => 'error', 'message' => 'El campo monto_a_cuenta es inválido.'], 400);
        }

        if ($fechaEntrega !== null && !esFechaValida($fechaEntrega)) {
            responderJSON(['status' => 'error', 'message' => 'Fecha de entrega inválida (formato YYYY-MM-DD).'], 400);
        }

        $montoTotal    = aDecimal($input['monto_total']);
        $montoACuenta  = aDecimal($input['monto_a_cuenta']);

        if ((float) $montoACuenta > (float) $montoTotal) {
            responderJSON(['status' => 'error', 'message' => 'El abono inicial no puede ser mayor que el monto total.'], 400);
        }

        $estado = (float) $montoACuenta >= (float) $montoTotal ? 'CANCELADO' : 'PENDIENTE';

        $stmt = $pdo->prepare('SELECT 1 FROM contratos WHERE numero_contrato = :numero LIMIT 1');
        $stmt->execute([':numero' => $numeroContrato]);

        if ($stmt->fetch() !== false) {
            responderJSON(['status' => 'error', 'message' => 'El número de contrato ya existe.'], 400);
        }

        $stmt = $pdo->prepare('SELECT 1 FROM pacientes WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $pacienteId]);

        if ($stmt->fetch() === false) {
            responderJSON(['status' => 'error', 'message' => 'El paciente no existe.'], 404);
        }

        if ($monturaId !== null) {
            $stmt = $pdo->prepare('SELECT stock FROM inventario_monturas WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $monturaId]);
            $montura = $stmt->fetch();

            if ($montura === false) {
                responderJSON(['status' => 'error', 'message' => 'La montura no existe.'], 400);
            }

            if ((int) $montura['stock'] < 1) {
                responderJSON(['status' => 'error', 'message' => 'No hay stock disponible para la montura seleccionada.'], 400);
            }
        }

        try {
            $pdo->beginTransaction();

            if ($monturaId !== null) {
                $stmt = $pdo->prepare('SELECT stock FROM inventario_monturas WHERE id = :id FOR UPDATE');
                $stmt->execute([':id' => $monturaId]);
                $monturaLock = $stmt->fetch();

                if ($monturaLock === false || (int) $monturaLock['stock'] < 1) {
                    throw new RuntimeException('No hay stock disponible para la montura seleccionada.');
                }

                $stmt = $pdo->prepare('UPDATE inventario_monturas SET stock = stock - 1 WHERE id = :id');
                $stmt->execute([':id' => $monturaId]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO contratos
                    (numero_contrato, paciente_id, montura_id, detalle_contrato, monto_total, estado, fecha_entrega, comentarios)
                 VALUES
                    (:numero_contrato, :paciente_id, :montura_id, :detalle_contrato, :monto_total, :estado, :fecha_entrega, :comentarios)'
            );
            $stmt->execute([
                ':numero_contrato'  => $numeroContrato,
                ':paciente_id'      => $pacienteId,
                ':montura_id'       => $monturaId,
                ':detalle_contrato' => $detalle,
                ':monto_total'      => $montoTotal,
                ':estado'           => $estado,
                ':fecha_entrega'    => $fechaEntrega,
                ':comentarios'      => $comentarios,
            ]);
            $contratoId = (int) $pdo->lastInsertId();

            if ((float) $montoACuenta > 0) {
                $stmt = $pdo->prepare(
                    'INSERT INTO movimientos_caja
                        (fecha_registro, tipo_movimiento, contrato_id, monto, concepto_descripcion, usuario_id)
                     VALUES
                        (CURDATE(), :tipo_movimiento, :contrato_id, :monto, :concepto, :usuario_id)'
                );
                $stmt->execute([
                    ':tipo_movimiento' => 'INGRESO',
                    ':contrato_id'     => $contratoId,
                    ':monto'           => $montoACuenta,
                    ':concepto'        => 'Abono inicial contrato ' . $numeroContrato,
                    ':usuario_id'      => $usuarioId,
                ]);
            }

            $pdo->commit();
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            responderJSON(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            responderJSON(['status' => 'error', 'message' => 'Error interno al registrar el contrato.'], 500);
        }

        responderJSON([
            'status'  => 'success',
            'message' => 'Contrato registrado correctamente.',
            'data'    => ['id' => $contratoId],
        ], 200);

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            responderJSON(['status' => 'error', 'message' => 'JSON inválido.'], 400);
        }

        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

        if ($id === false || $id <= 0) {
            responderJSON(['status' => 'error', 'message' => 'Identificador de contrato inválido.'], 400);
        }

        $detalle      = trim((string) ($input['detalle_contrato'] ?? '')) ?: null;
        $fechaEntrega = trim((string) ($input['fecha_entrega'] ?? '')) ?: null;
        $comentarios  = trim((string) ($input['comentarios'] ?? '')) ?: null;

        if ($fechaEntrega !== null && !esFechaValida($fechaEntrega)) {
            responderJSON(['status' => 'error', 'message' => 'Fecha de entrega inválida (formato YYYY-MM-DD).'], 400);
        }

        $stmt = $pdo->prepare('SELECT 1 FROM contratos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        if ($stmt->fetch() === false) {
            responderJSON(['status' => 'error', 'message' => 'Contrato no encontrado.'], 404);
        }

        $stmt = $pdo->prepare(
            'UPDATE contratos
             SET detalle_contrato = :detalle, fecha_entrega = :fecha_entrega, comentarios = :comentarios
             WHERE id = :id'
        );
        $stmt->execute([
            ':detalle'       => $detalle,
            ':fecha_entrega' => $fechaEntrega,
            ':comentarios'   => $comentarios,
            ':id'            => $id,
        ]);

        responderJSON([
            'status'  => 'success',
            'message' => 'Contrato actualizado correctamente.',
            'data'    => ['id' => $id],
        ], 200);

    case 'DELETE':
        $cuerpo = json_decode(file_get_contents('php://input'), true);

        if (!is_array($cuerpo)) {
            $cuerpo = [];
        }

        $id = filter_var($_GET['id'] ?? $cuerpo['id'] ?? null, FILTER_VALIDATE_INT);

        if ($id === false || $id <= 0) {
            responderJSON(['status' => 'error', 'message' => 'Identificador de contrato inválido.'], 400);
        }

        $stmt = $pdo->prepare('SELECT id, montura_id, numero_contrato FROM contratos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        if ($stmt->fetch() === false) {
            responderJSON(['status' => 'error', 'message' => 'Contrato no encontrado.'], 404);
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT montura_id FROM contratos WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $contrato = $stmt->fetch();

            if ($contrato['montura_id'] !== null) {
                $stmt = $pdo->prepare('UPDATE inventario_monturas SET stock = stock + 1 WHERE id = :id');
                $stmt->execute([':id' => $contrato['montura_id']]);
            }

            $stmt = $pdo->prepare('DELETE FROM movimientos_caja WHERE contrato_id = :id');
            $stmt->execute([':id' => $id]);

            $stmt = $pdo->prepare('DELETE FROM contratos WHERE id = :id');
            $stmt->execute([':id' => $id]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            responderJSON(['status' => 'error', 'message' => 'Error interno al anular el contrato.'], 500);
        }

        responderJSON([
            'status'  => 'success',
            'message' => 'Contrato anulado correctamente; el stock se restituyó.',
        ], 200);

    default:
        responderJSON(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}