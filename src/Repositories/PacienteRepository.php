<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Acceso a datos de pacientes.
 */
final class PacienteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function buscar(int $id): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nombres_apellidos, edad, celular, ocupacion, genero, creado_en
             FROM pacientes
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        return $stmt->fetch();
    }

    public function listar(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, nombres_apellidos, edad, celular, ocupacion, genero
             FROM pacientes
             ORDER BY nombres_apellidos ASC
             LIMIT 200'
        );

        return $stmt->fetchAll();
    }

    public function buscarPorTexto(string $texto): array
    {
        $patron = '%' . $texto . '%';

        $stmt = $this->pdo->prepare(
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

        return $stmt->fetchAll();
    }

    public function existe(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM pacientes WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() !== false;
    }

    public function tieneContratos(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM contratos WHERE paciente_id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() !== false;
    }

    public function crear(array $datos): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pacientes (nombres_apellidos, edad, celular, ocupacion, genero)
             VALUES (:nombres_apellidos, :edad, :celular, :ocupacion, :genero)'
        );
        $stmt->execute([
            ':nombres_apellidos' => $datos['nombres_apellidos'],
            ':edad'              => $datos['edad'],
            ':celular'           => $datos['celular'],
            ':ocupacion'         => $datos['ocupacion'],
            ':genero'            => $datos['genero'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(int $id, array $datos): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pacientes
             SET nombres_apellidos = :nombres_apellidos, edad = :edad, celular = :celular,
                 ocupacion = :ocupacion, genero = :genero
             WHERE id = :id'
        );
        $stmt->execute([
            ':nombres_apellidos' => $datos['nombres_apellidos'],
            ':edad'              => $datos['edad'],
            ':celular'           => $datos['celular'],
            ':ocupacion'         => $datos['ocupacion'],
            ':genero'            => $datos['genero'],
            ':id'                => $id,
        ]);
    }

    public function eliminar(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM pacientes WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function contratos(int $pacienteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, numero_contrato, monto_total, estado, fecha_entrega, creado_en
             FROM contratos
             WHERE paciente_id = :id
             ORDER BY creado_en DESC'
        );
        $stmt->execute([':id' => $pacienteId]);

        return $stmt->fetchAll();
    }

    public function historias(int $pacienteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM historias_clinicas
             WHERE paciente_id = :id
             ORDER BY fecha_consulta DESC'
        );
        $stmt->execute([':id' => $pacienteId]);

        return $stmt->fetchAll();
    }
}