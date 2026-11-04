<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Acceso a datos de historias clínicas.
 */
final class HistoriaRepository
{
    public const COLUMNAS_FORMULARIO = [
        'esf_od_vl', 'cyl_od_vl', 'eje_od_vl', 'dip_od_vl', 'av_od_vl',
        'esf_oi_vl', 'cyl_oi_vl', 'eje_oi_vl', 'dip_oi_vl', 'av_oi_vl',
        'add_medicion',
        'esf_od_vc', 'cyl_od_vc', 'eje_od_vc', 'dip_od_vc', 'av_od_vc',
        'esf_oi_vc', 'cyl_oi_vc', 'eje_oi_vc', 'dip_oi_vc', 'av_oi_vc',
        'tratamiento', 'indicaciones',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function buscar(int $id): array|false
    {
        $stmt = $this->pdo->prepare('SELECT * FROM historias_clinicas WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch();
    }

    public function listarPorPaciente(int $pacienteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM historias_clinicas
             WHERE paciente_id = :paciente_id
             ORDER BY fecha_consulta DESC'
        );
        $stmt->execute([':paciente_id' => $pacienteId]);

        return $stmt->fetchAll();
    }

    public function existe(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM historias_clinicas WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() !== false;
    }

    public function crear(
        int $pacienteId,
        string $fechaConsulta,
        array $mediciones,
        ?string $material,
        ?string $tipoLente,
        ?string $proximaCita
    ): int {
        $valores = $this->normalizarMediciones($mediciones);
        $columnas = self::COLUMNAS_FORMULARIO;

        $sql = 'INSERT INTO historias_clinicas (paciente_id, fecha_consulta, material, tipo_lente, proxima_cita, '
            . implode(', ', $columnas) . ') VALUES (:paciente_id, :fecha_consulta, :material, :tipo_lente, :proxima_cita, :'
            . implode(', :', $columnas) . ')';

        $parametros = [
            ':paciente_id'    => $pacienteId,
            ':fecha_consulta' => $fechaConsulta,
            ':material'       => $material,
            ':tipo_lente'     => $tipoLente,
            ':proxima_cita'   => $proximaCita,
        ];

        foreach ($columnas as $columna) {
            $parametros[':' . $columna] = $valores[$columna];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parametros);

        return (int) $this->pdo->lastInsertId();
    }

    public function actualizar(
        int $id,
        int $pacienteId,
        string $fechaConsulta,
        array $mediciones,
        ?string $material,
        ?string $tipoLente,
        ?string $proximaCita
    ): void {
        $valores = $this->normalizarMediciones($mediciones);

        $setPartes = ['paciente_id = :paciente_id', 'fecha_consulta = :fecha_consulta'];

        $parametros = [
            ':paciente_id'    => $pacienteId,
            ':fecha_consulta' => $fechaConsulta,
            ':id'             => $id,
        ];

        foreach (self::COLUMNAS_FORMULARIO as $columna) {
            $setPartes[] = $columna . ' = :' . $columna;
            $parametros[':' . $columna] = $valores[$columna];
        }

        $setPartes[] = 'material = :material';
        $parametros[':material'] = $material;

        $setPartes[] = 'tipo_lente = :tipo_lente';
        $parametros[':tipo_lente'] = $tipoLente;

        $setPartes[] = 'proxima_cita = :proxima_cita';
        $parametros[':proxima_cita'] = $proximaCita;

        $stmt = $this->pdo->prepare(
            'UPDATE historias_clinicas SET ' . implode(', ', $setPartes) . ' WHERE id = :id'
        );
        $stmt->execute($parametros);
    }

    private function normalizarMediciones(array $mediciones): array
    {
        $valores = [];

        foreach (self::COLUMNAS_FORMULARIO as $columna) {
            $valores[$columna] = $mediciones[$columna] ?? null;
        }

        return $valores;
    }
}