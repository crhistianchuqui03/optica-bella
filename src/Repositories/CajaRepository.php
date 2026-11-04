<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Formato;
use PDO;
use RuntimeException;

/**
 * Acceso a datos de caja: consultas del cuaderno diario, abonos y egresos.
 * Los abonos usan transacción y FOR UPDATE para no sobrepasar el saldo.
 */
final class CajaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function buscar(string $texto): array
    {
        $patron = '%' . $texto . '%';

        $stmt = $this->pdo->prepare(
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

        return $stmt->fetchAll();
    }

    public function fechaActual(): string
    {
        return (string) $this->pdo->query('SELECT CURDATE()')->fetchColumn();
    }

    public function resumenDia(string $fecha): array
    {
        $stmt = $this->pdo->prepare(
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
            ':tipo_ingreso'     => 'INGRESO',
            ':tipo_ingreso_sub' => 'INGRESO',
            ':fecha'            => $fecha,
        ]);
        $ingresos = $stmt->fetchAll();

        $stmt = $this->pdo->prepare(
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

        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(monto), 0) AS total
             FROM movimientos_caja
             WHERE tipo_movimiento = :tipo_ingreso AND fecha_registro = :fecha'
        );
        $stmt->execute([':tipo_ingreso' => 'INGRESO', ':fecha' => $fecha]);
        $totalIngresos = $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(monto), 0) AS total
             FROM movimientos_caja
             WHERE tipo_movimiento = :tipo_egreso AND fecha_registro = :fecha'
        );
        $stmt->execute([':tipo_egreso' => 'EGRESO', ':fecha' => $fecha]);
        $totalEgresos = $stmt->fetchColumn();

        $gananciaNeta = (float) $totalIngresos - (float) $totalEgresos;

        return [
            'ingresos'       => $ingresos,
            'egresos'        => $egresos,
            'total_ingresos' => $totalIngresos,
            'total_egresos'  => $totalEgresos,
            'ganancia_neta'  => $gananciaNeta,
        ];
    }

    public function registrarAbono(
        int $contratoId,
        string $montoAbono,
        ?string $comentarios,
        int $usuarioId
    ): array {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
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

            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(SUM(monto), 0) AS total
                 FROM movimientos_caja
                 WHERE contrato_id = :id AND tipo_movimiento = :tipo_ingreso'
            );
            $stmt->execute([':id' => $contratoId, ':tipo_ingreso' => 'INGRESO']);
            $abonosPrevios = (float) $stmt->fetchColumn();

            $saldoPendiente = $montoTotal - $abonosPrevios;

            if ((float) $montoAbono > $saldoPendiente + 0.0001) {
                throw new RuntimeException(
                    'El monto del abono excede el saldo pendiente (' . Formato::decimal($saldoPendiente) . ').'
                );
            }

            $concepto = 'Abono saldo contrato ' . $contrato['numero_contrato'];

            if ($comentarios !== null) {
                $concepto .= ' - ' . $comentarios;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO movimientos_caja
                    (fecha_registro, tipo_movimiento, contrato_id, monto, concepto_descripcion, usuario_id)
                 VALUES
                    (CURDATE(), :tipo_movimiento, :contrato_id, :monto, :concepto, :usuario_id)'
            );
            $stmt->execute([
                ':tipo_movimiento' => 'INGRESO',
                ':contrato_id'     => $contratoId,
                ':monto'           => $montoAbono,
                ':concepto'        => $concepto,
                ':usuario_id'      => $usuarioId,
            ]);

            $nuevoTotalAbonado = $abonosPrevios + (float) $montoAbono;
            $contratoCancelado = $nuevoTotalAbonado >= $montoTotal - 0.0001;

            if ($contratoCancelado) {
                $stmt = $this->pdo->prepare(
                    'UPDATE contratos SET estado = :estado WHERE id = :id'
                );
                $stmt->execute([':estado' => 'CANCELADO', ':id' => $contratoId]);
            }

            $this->pdo->commit();

            return [
                'contrato_id'      => $contratoId,
                'numero_contrato'  => (string) $contrato['numero_contrato'],
                'monto_abonado'    => $montoAbono,
                'saldo_pendiente'  => Formato::decimal(max(0.0, $montoTotal - $nuevoTotalAbonado)),
                'estado'           => $contratoCancelado ? 'CANCELADO' : 'PENDIENTE',
            ];
        } catch (\Throwable $excepcion) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $excepcion;
        }
    }

    public function registrarEgreso(
        ?int $contratoId,
        string $monto,
        string $concepto,
        ?string $nroComprobante,
        int $usuarioId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO movimientos_caja
                (fecha_registro, tipo_movimiento, contrato_id, monto, concepto_descripcion, nro_comprobante_lab, usuario_id)
             VALUES
                (CURDATE(), :tipo_movimiento, :contrato_id, :monto, :concepto, :nro_comprobante, :usuario_id)'
        );
        $stmt->execute([
            ':tipo_movimiento' => 'EGRESO',
            ':contrato_id'     => $contratoId,
            ':monto'           => $monto,
            ':concepto'        => $concepto,
            ':nro_comprobante' => $nroComprobante,
            ':usuario_id'      => $usuarioId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}