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
        if (isset($_GET['q'])) {
            $q = trim((string) $_GET['q']);

            if ($q === '') {
                responderJSON(['status' => 'error', 'message' => 'Parámetro de búsqueda vacío.'], 400);
            }

            $patron = '%' . $q . '%';

            $stmt = $pdo->prepare(
                'SELECT mc.id, mc.fecha_registro, mc.tipo_movimiento, mc.monto, mc.concepto_descripcion,
                        mc.nro_comprobante_lab, mc.usuario_id,
                        c.numero_contrato,
                        p.nombres_apellidos AS paciente_nombre
                 FROM movimientos_caja mc
                 LEFT JOIN contratos c ON c.id = mc.contrato_id
                 LEFT JOIN pacientes p ON p.id = c.paciente_id
                 WHERE p.nombres_apellidos LIKE :patron_paciente
                    OR c.numero_contrato LIKE :patron_contrato
                    OR mc.nro_comprobante_lab LIKE :patron_comprobante
                 ORDER BY mc.fecha_registro DESC, mc.id DESC
                 LIMIT 100'
            );
            $stmt->execute([
                ':patron_paciente'    => $patron,
                ':patron_contrato'    => $patron,
                ':patron_comprobante' => $patron,
            ]);

            responderJSON(['status' => 'success', 'data' => $stmt->fetchAll()], 200);
        }

        $fecha = isset($_GET['fecha']) ? trim((string) $_GET['fecha']) : '';

        if ($fecha === '') {
            $fecha = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
        } elseif (!esFechaValida($fecha)) {
            responderJSON(['status' => 'error', 'message' => 'Fecha inválida (formato YYYY-MM-DD).'], 400);
        }

        $stmt = $pdo->prepare(
            'SELECT mc.fecha_registro,
                    c.id AS contrato_id,
                    c.numero_contrato,
                    p.nombres_apellidos AS paciente_nombre,
                    p.genero AS genero_paciente,
                    m.codigo_montura,
                    c.detalle_contrato,
                    c.monto_total AS monto_total_contrato,
                    mc.monto AS monto_abonado_hoy,
                    CASE
                        WHEN mc.concepto_descripcion LIKE "Abono inicial%" THEN "INICIAL"
                        ELSE "SALDO"
                    END AS tipo_abono,
                    (c.monto_total - COALESCE(
                        (SELECT SUM(mc2.monto) FROM movimientos_caja mc2
                         WHERE mc2.contrato_id = c.id AND mc2.tipo_movimiento = :tipo_ingreso_sub), 0)) AS saldo_pendiente_contrato,
                    c.estado AS estado_contrato
             FROM movimientos_caja mc
             INNER JOIN contratos c ON c.id = mc.contrato_id
             LEFT JOIN pacientes p ON p.id = c.paciente_id
             LEFT JOIN inventario_monturas m ON m.id = c.montura_id
             WHERE mc.tipo_movimiento = :tipo_ingreso AND mc.fecha_registro = :fecha
             ORDER BY mc.id ASC'
        );
        $stmt->execute([
            ':tipo_ingreso'    => 'INGRESO',
            ':tipo_ingreso_sub' => 'INGRESO',
            ':fecha'           => $fecha,
        ]);
        $ingresos = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT mc.id, mc.fecha_registro, mc.concepto_descripcion, mc.monto, mc.nro_comprobante_lab,
                    c.numero_contrato,
                    p.nombres_apellidos AS paciente_nombre
             FROM movimientos_caja mc
             LEFT JOIN contratos c ON c.id = mc.contrato_id
             LEFT JOIN pacientes p ON p.id = c.paciente_id
             WHERE mc.tipo_movimiento = :tipo_egreso AND mc.fecha_registro = :fecha
             ORDER BY mc.id ASC'
        );
        $stmt->execute([
            ':tipo_egreso' => 'EGRESO',
            ':fecha'       => $fecha,
        ]);
        $egresos = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(monto), 0) AS total
             FROM movimientos_caja
             WHERE tipo_movimiento = :tipo_ingreso AND fecha_registro = :fecha'
        );
        $stmt->execute([':tipo_ingreso' => 'INGRESO', ':fecha' => $fecha]);
        $totalIngresos = $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(monto), 0) AS total
             FROM movimientos_caja
             WHERE tipo_movimiento = :tipo_egreso AND fecha_registro = :fecha'
        );
        $stmt->execute([':tipo_egreso' => 'EGRESO', ':fecha' => $fecha]);
        $totalEgresos = $stmt->fetchColumn();

        $gananciaNeta = (float) $totalIngresos - (float) $totalEgresos;

        responderJSON([
            'status' => 'success',
            'data'   => [
                'fecha'          => $fecha,
                'ingresos'       => $ingresos,
                'egresos'        => $egresos,
                'total_ingresos'  => aDecimal($totalIngresos),
                'total_egresos'   => aDecimal($totalEgresos),
                'ganancia_neta'   => aDecimal($gananciaNeta),
            ],
        ], 200);

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            responderJSON(['status' => 'error', 'message' => 'JSON inválido.'], 400);
        }

        $accion = trim((string) ($input['accion'] ?? ''));

        if ($accion === 'abono_saldo') {
            $contratoId = filter_var($input['contrato_id'] ?? null, FILTER_VALIDATE_INT);

            if ($contratoId === false || $contratoId <= 0) {
                responderJSON(['status' => 'error', 'message' => 'Identificador de contrato inválido.'], 400);
            }

            $montoAbono = $input['monto_abono'] ?? null;

            if (!is_numeric($montoAbono) || (float) $montoAbono <= 0) {
                responderJSON(['status' => 'error', 'message' => 'El campo monto_abono es inválido.'], 400);
            }

            $montoAbonoDec = aDecimal($montoAbono);
            $comentarios = trim((string) ($input['comentarios'] ?? '')) ?: null;

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'SELECT id, numero_contrato, monto_total, estado
                     FROM contratos
                     WHERE id = :id
                     FOR UPDATE'
                );
                $stmt->execute([':id' => $contratoId]);
                $contrato = $stmt->fetch();

                if ($contrato === false) {
                    throw new RuntimeException('El contrato no existe.');
                }

                $montoTotal = (float) $contrato['monto_total'];

                $stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(monto), 0) AS total
                     FROM movimientos_caja
                     WHERE contrato_id = :id AND tipo_movimiento = :tipo_ingreso"
                );
                $stmt->execute([':id' => $contratoId, ':tipo_ingreso' => 'INGRESO']);
                $abonosPrevios = (float) $stmt->fetchColumn();

                $saldoPendiente = $montoTotal - $abonosPrevios;

                if ((float) $montoAbonoDec > $saldoPendiente + 0.0001) {
                    throw new RuntimeException('El monto del abono excede el saldo pendiente (' . aDecimal($saldoPendiente) . ').');
                }

                $concepto = 'Abono saldo contrato ' . $contrato['numero_contrato'];

                if ($comentarios !== null) {
                    $concepto .= ' - ' . $comentarios;
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO movimientos_caja
                        (fecha_registro, tipo_movimiento, contrato_id, monto, concepto_descripcion, usuario_id)
                     VALUES
                        (CURDATE(), :tipo_movimiento, :contrato_id, :monto, :concepto, :usuario_id)'
                );
                $stmt->execute([
                    ':tipo_movimiento' => 'INGRESO',
                    ':contrato_id'     => $contratoId,
                    ':monto'           => $montoAbonoDec,
                    ':concepto'        => $concepto,
                    ':usuario_id'      => $usuarioId,
                ]);

                $nuevoTotalAbonado = $abonosPrevios + (float) $montoAbonoDec;
                $contratoCancelado = $nuevoTotalAbonado >= $montoTotal - 0.0001;

                if ($contratoCancelado) {
                    $stmt = $pdo->prepare(
                        "UPDATE contratos SET estado = :estado WHERE id = :id"
                    );
                    $stmt->execute([':estado' => 'CANCELADO', ':id' => $contratoId]);
                }

                $pdo->commit();

                responderJSON([
                    'status'  => 'success',
                    'message' => 'Abono registrado correctamente.',
                    'data'    => [
                        'contrato_id'     => $contratoId,
                        'monto_abonado'   => $montoAbonoDec,
                        'saldo_pendiente' => aDecimal(max(0.0, $montoTotal - $nuevoTotalAbonado)),
                        'estado'          => $contratoCancelado ? 'CANCELADO' : 'PENDIENTE',
                    ],
                ], 200);
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                responderJSON(['status' => 'error', 'message' => $e->getMessage()], 400);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                responderJSON(['status' => 'error', 'message' => 'Error interno al registrar el abono.'], 500);
            }
        }

        if ($accion === 'registrar_egreso') {
            $concepto = trim((string) ($input['concepto_descripcion'] ?? ''));
            $monto = $input['monto'] ?? null;
            $nroComprobante = trim((string) ($input['nro_comprobante_lab'] ?? '')) ?: null;

            $contratoRaw = $input['contrato_id'] ?? null;
            $contratoIdEgreso = ($contratoRaw === null || $contratoRaw === '' || (int) $contratoRaw <= 0)
                ? null
                : (int) $contratoRaw;

            if ($concepto === '') {
                responderJSON(['status' => 'error', 'message' => 'El campo concepto_descripcion es obligatorio.'], 400);
            }

            if (!is_numeric($monto) || (float) $monto <= 0) {
                responderJSON(['status' => 'error', 'message' => 'El campo monto es inválido.'], 400);
            }

            if ($contratoIdEgreso !== null) {
                $stmt = $pdo->prepare('SELECT 1 FROM contratos WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $contratoIdEgreso]);

                if ($stmt->fetch() === false) {
                    responderJSON(['status' => 'error', 'message' => 'El contrato no existe.'], 400);
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO movimientos_caja
                    (fecha_registro, tipo_movimiento, contrato_id, monto, concepto_descripcion, nro_comprobante_lab, usuario_id)
                 VALUES
                    (CURDATE(), :tipo_movimiento, :contrato_id, :monto, :concepto, :nro_comprobante, :usuario_id)'
            );
            $stmt->execute([
                ':tipo_movimiento'   => 'EGRESO',
                ':contrato_id'       => $contratoIdEgreso,
                ':monto'             => aDecimal($monto),
                ':concepto'          => $concepto,
                ':nro_comprobante'   => $nroComprobante,
                ':usuario_id'        => $usuarioId,
            ]);

            responderJSON([
                'status'  => 'success',
                'message' => 'Egreso registrado correctamente.',
                'data'    => ['id' => (int) $pdo->lastInsertId()],
            ], 200);
        }

        responderJSON(['status' => 'error', 'message' => 'Acción inválida.'], 400);

    default:
        responderJSON(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}