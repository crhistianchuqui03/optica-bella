<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\HistoriaRepository;
use App\Repositories\PacienteRepository;
use App\Support\Formato;

/**
 * Registro y edición de historias clínicas.
 */
final class HistoriaController
{
    private HistoriaRepository $historias;

    private PacienteRepository $pacientes;

    public function __construct()
    {
        $pdo = Database::getConnection();

        $this->historias = new HistoriaRepository($pdo);
        $this->pacientes = new PacienteRepository($pdo);
    }

    public function index(Request $request): void
    {
        $id = $request->query('id');

        if ($id !== null) {
            $idValido = filter_var($id, FILTER_VALIDATE_INT);

            if ($idValido === false || $idValido <= 0) {
                Response::error('Identificador de historia clínica inválido.', 400);
            }

            $historia = $this->historias->buscar($idValido);

            if ($historia === false) {
                Response::error('Historia clínica no encontrada.', 404);
            }

            Response::json(['status' => 'success', 'data' => $historia], 200);
        }

        $pacienteId = $request->query('paciente_id');

        if ($pacienteId === null) {
            Response::error('Parámetro requerido: paciente_id.', 400);
        }

        $pacienteIdValido = filter_var($pacienteId, FILTER_VALIDATE_INT);

        if ($pacienteIdValido === false || $pacienteIdValido <= 0) {
            Response::error('Identificador de paciente inválido.', 400);
        }

        Response::json([
            'status' => 'success',
            'data'   => $this->historias->listarPorPaciente($pacienteIdValido),
        ], 200);
    }

    public function save(Request $request): void
    {
        $input  = $request->json();
        $id     = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        $accion = trim((string) ($input['accion'] ?? ''));

        $esActualizacion = $request->metodo() === 'PUT'
            || $accion === 'actualizar'
            || (is_int($id) && $id > 0);

        $pacienteId = filter_var($input['paciente_id'] ?? null, FILTER_VALIDATE_INT);

        if ($pacienteId === false || $pacienteId <= 0) {
            Response::error('Identificador de paciente inválido.', 400);
        }

        if (!$this->pacientes->existe($pacienteId)) {
            Response::error('El paciente no existe.', 404);
        }

        $fechaConsulta = trim((string) ($input['fecha_consulta'] ?? ''));

        if ($fechaConsulta === '' || !Formato::fechaValida($fechaConsulta)) {
            Response::error('La fecha de consulta es obligatoria en formato YYYY-MM-DD.', 400);
        }

        $material     = Formato::textoONulo($input['material'] ?? null);
        $tipoLente    = Formato::textoONulo($input['tipo_lente'] ?? null);
        $proximaCita  = Formato::textoONulo($input['proxima_cita'] ?? null);

        if ($material !== null && !in_array($material, ['POLICARBONATO', 'CRISTAL', 'RESINA', 'ALTO INDICE', 'OTRO'], true)) {
            Response::error('Material inválido.', 400);
        }

        if ($tipoLente !== null && !in_array($tipoLente, ['MONOFOCAL', 'BIFOCAL', 'MULTIFOCAL'], true)) {
            Response::error('Tipo de lente inválido.', 400);
        }

        if ($proximaCita !== null && !Formato::fechaValida($proximaCita)) {
            Response::error('Próxima cita inválida (formato YYYY-MM-DD).', 400);
        }

        $mediciones = $this->recolectarMediciones($input);

        if ($esActualizacion) {
            if (!is_int($id) || $id <= 0) {
                Response::error('Identificador de historia clínica inválido.', 400);
            }

            if (!$this->historias->existe($id)) {
                Response::error('Historia clínica no encontrada.', 404);
            }

            $this->historias->actualizar(
                $id,
                $pacienteId,
                $fechaConsulta,
                $mediciones,
                $material,
                $tipoLente,
                $proximaCita
            );

            Response::json([
                'status'  => 'success',
                'message' => 'Historia clínica actualizada correctamente.',
                'data'    => ['id' => $id],
            ], 200);
        }

        $nuevoId = $this->historias->crear(
            $pacienteId,
            $fechaConsulta,
            $mediciones,
            $material,
            $tipoLente,
            $proximaCita
        );

        Response::json([
            'status'  => 'success',
            'message' => 'Historia clínica registrada correctamente.',
            'data'    => ['id' => $nuevoId],
        ], 200);
    }

    private function recolectarMediciones(array $input): array
    {
        $mediciones = [];

        foreach (HistoriaRepository::COLUMNAS_FORMULARIO as $columna) {
            $valor = $input[$columna] ?? null;

            if (is_string($valor)) {
                $valor = trim($valor);

                if ($valor === '') {
                    $valor = null;
                }
            }

            $mediciones[$columna] = $valor;
        }

        return $mediciones;
    }
}