<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;

/**
 * Acceso a datos de contratos y sus movimientos de caja.
 * Las operaciones que alteran stock usan transacciones y FOR UPDATE.
 */
final class ContratoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function buscarDetalle(int $id): array|false
    {
        $stmt = $this->pdo->prepare(
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

        return $stmt->fetch();
    }

    public function movimientos(int $contratoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, fecha_registro, tipo_movimiento, monto, concepto_descripcion
             FROM movimientos_caja
             WHERE contrato_id = :id
             ORDER BY id ASC'
        );
        $stmt->execute([':id' => $contratoId]);

        return $stmt->fetchAll();
    }

    public function listarPorPaciente(int $pacienteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, numero_contrato, monto_total, estado, fecha_entrega, creado_en
             FROM contratos
             WHERE paciente_id = :paciente_id
             ORDER BY creado_en DESC'
        );
        $stmt->execute([':paciente_id' => $pacienteId]);

        return $stmt->fetchAll();
    }

    public function buscarPorTexto(string $texto): array
    {
        $patron = '%' . $texto . '%';

        $stmt = $this->pdo->prepare(
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

        return $stmt->fetchAll();
    }

    public function listar(): array
    {
        $stmt = $this->pdo->prepare(
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

        return $stmt->fetchAll();
    }

    public function siguienteNumero(): string
    {
        $stmt = $this->pdo->query(
            'SELECT COALESCE(MAX(CAST(numero_contrato AS UNSIGNED)), 0) + 1 AS siguiente FROM contratos'
        );
        $fila = $stmt->fetch();

        return str_pad((string) ((int) $fila['siguiente']), 7, '0', STR_PAD_LEFT);
    }

    public function existeNumero(string $numero): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM contratos WHERE numero_contrato = :numero LIMIT 1');
        $stmt->execute([':numero' => $numero]);

        return $stmt->fetch() !== false;
    }

    public function existe(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM contratos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() !== false;
    }

    public function obtenerStock(int $monturaId): array|false
    {
        $stmt = $this->pdo->prepare('SELECT stock FROM inventario_monturas WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $monturaId]);

        return $stmt->fetch();
    }

    public function crear(array $datos, ?int $monturaId, string $montoACuenta, int $usuarioId): int
    {
        try {
            $this->pdo->beginTransaction();

            if ($monturaId !== null) {
                $stmt = $this->pdo->prepare('SELECT stock FROM inventario_monturas WHERE id = :id FOR UPDATE');
                $stmt->execute([':id' => $monturaId]);
                $monturaLock = $stmt->fetch();

                if ($monturaLock === false || (int) $monturaLock['stock'] < 1) {
                    throw new RuntimeException('No hay stock disponible para la montura seleccionada.');
                }

                $stmt = $this->pdo->prepare('UPDATE inventario_monturas SET stock = stock - 1 WHERE id = :id');
                $stmt->execute([':id' => $monturaId]);
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO contratos
                    (numero_contrato, paciente_id, montura_id, detalle_contrato, monto_total, estado, fecha_entrega, comentarios)
                 VALUES
                    (:numero_contrato, :paciente_id, :montura_id, :detalle_contrato, :monto_total, :estado, :fecha_entrega, :comentarios)'
            );
            $stmt->execute([
                ':numero_contrato'  => $datos['numero_contrato'],
                ':paciente_id'      => $datos['paciente_id'],
                ':montura_id'       => $monturaId,
                ':detalle_contrato' => $datos['detalle_contrato'],
                ':monto_total'      => $datos['monto_total'],
                ':estado'           => $datos['estado'],
                ':fecha_entrega'    => $datos['fecha_entrega'],
                ':comentarios'      => $datos['comentarios'],
            ]);
            $contratoId = (int) $this->pdo->lastInsertId();

            if ((float) $montoACuenta > 0) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO movimientos_caja
                        (fecha_registro, tipo_movimiento, contrato_id, monto, concepto_descripcion, usuario_id)
                     VALUES
                        (CURDATE(), :tipo_movimiento, :contrato_id, :monto, :concepto, :usuario_id)'
                );
                $stmt->execute([
                    ':tipo_movimiento' => 'INGRESO',
                    ':contrato_id'     => $contratoId,
                    ':monto'           => $montoACuenta,
                    ':concepto'        => 'Abono inicial contrato ' . $datos['numero_contrato'],
                    ':usuario_id'      => $usuarioId,
                ]);
            }

            $this->pdo->commit();

            return $contratoId;
        } catch (\Throwable $excepcion) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $excepcion;
        }
    }

    public function actualizar(int $id, array $datos): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE contratos
             SET detalle_contrato = :detalle, fecha_entrega = :fecha_entrega, comentarios = :comentarios
             WHERE id = :id'
        );
        $stmt->execute([
            ':detalle'       => $datos['detalle_contrato'],
            ':fecha_entrega' => $datos['fecha_entrega'],
            ':comentarios'   => $datos['comentarios'],
            ':id'            => $id,
        ]);
    }

    public function buscarParaAnular(int $id): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, montura_id, numero_contrato FROM contratos WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        return $stmt->fetch();
    }

    public function anular(int $id): void
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare('SELECT montura_id FROM contratos WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $contrato = $stmt->fetch();

            if ($contrato === false) {
                throw new RuntimeException('Contrato no encontrado.');
            }

            if ($contrato['montura_id'] !== null) {
                $stmt = $this->pdo->prepare('UPDATE inventario_monturas SET stock = stock + 1 WHERE id = :id');
                $stmt->execute([':id' => $contrato['montura_id']]);
            }

            $stmt = $this->pdo->prepare('DELETE FROM movimientos_caja WHERE contrato_id = :id');
            $stmt->execute([':id' => $id]);

            $stmt = $this->pdo->prepare('DELETE FROM contratos WHERE id = :id');
            $stmt->execute([':id' => $id]);

            $this->pdo->commit();
        } catch (\Throwable $excepcion) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $excepcion;
        }
    }
}