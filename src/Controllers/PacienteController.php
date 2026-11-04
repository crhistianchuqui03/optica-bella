<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\PacienteRepository;

/**
 * CRUD de pacientes y consulta de sus contratos e historias.
 */
final class PacienteController
{
    private PacienteRepository $pacientes;

    public function __construct()
    {
        $this->pacientes = new PacienteRepository(Database::getConnection());
    }

    public function index(Request $request): void
    {
        $id = $request->query('id');

        if ($id !== null) {
            $idValido = filter_var($id, FILTER_VALIDATE_INT);

            if ($idValido === false || $idValido <= 0) {
                Response::error('Identificador de paciente inválido.', 400);
            }

            $paciente = $this->pacientes->buscar($idValido);

            if ($paciente === false) {
                Response::error('Paciente no encontrado.', 404);
            }

            Response::json([
                'status' => 'success',
                'data'   => [
                    'paciente'           => $paciente,
                    'contratos'          => $this->pacientes->contratos($idValido),
                    'historias_clinicas' => $this->pacientes->historias($idValido),
                ],
            ], 200);
        }

        $q = $request->query('q');

        if ($q !== null) {
            $texto = trim((string) $q);

            if ($texto === '') {
                Response::error('Parámetro de búsqueda vacío.', 400);
            }

            Response::json([
                'status' => 'success',
                'data'   => $this->pacientes->buscarPorTexto($texto),
            ], 200);
        }

        Response::json([
            'status' => 'success',
            'data'   => $this->pacientes->listar(),
        ], 200);
    }

    public function store(Request $request): void
    {
        $input  = $request->json();
        $accion = trim((string) ($input['accion'] ?? ''));

        if ($accion === 'eliminar') {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                Response::error('Identificador de paciente inválido.', 400);
            }

            $this->eliminar($id);
        }

        if ($accion === 'actualizar') {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0) {
                Response::error('Identificador de paciente inválido.', 400);
            }

            if (!$this->pacientes->existe($id)) {
                Response::error('Paciente no encontrado.', 404);
            }

            $datos = $this->datosDesdeEntrada($input);

            $this->pacientes->actualizar($id, $datos);

            Response::json([
                'status'  => 'success',
                'message' => 'Paciente actualizado correctamente.',
                'data'    => ['id' => $id],
            ], 200);
        }

        $datos = $this->datosDesdeEntrada($input);
        $id    = $this->pacientes->crear($datos);

        Response::json([
            'status'  => 'success',
            'message' => 'Paciente creado correctamente.',
            'data'    => ['id' => $id],
        ], 200);
    }

    public function destroy(Request $request): void
    {
        $cuerpo = $request->jsonTolerante();
        $id     = $request->query('id');

        if ($id === null) {
            $id = $cuerpo['id'] ?? null;
        }

        $idValido = filter_var($id, FILTER_VALIDATE_INT);

        if ($idValido === false || $idValido <= 0) {
            Response::error('Identificador de paciente inválido.', 400);
        }

        $this->eliminar($idValido);
    }

    private function eliminar(int $id): void
    {
        if (!$this->pacientes->existe($id)) {
            Response::error('Paciente no encontrado.', 404);
        }

        if ($this->pacientes->tieneContratos($id)) {
            Response::error('El paciente tiene contratos asociados y no puede eliminarse.', 400);
        }

        $this->pacientes->eliminar($id);

        Response::json([
            'status'  => 'success',
            'message' => 'Paciente eliminado correctamente.',
        ], 200);
    }

    private function datosDesdeEntrada(array $input): array
    {
        $nombresApellidos = trim((string) ($input['nombres_apellidos'] ?? ''));

        if ($nombresApellidos === '') {
            Response::error('El campo nombres_apellidos es obligatorio.', 400);
        }

        $edad      = isset($input['edad']) && $input['edad'] !== '' ? (int) $input['edad'] : null;
        $celular   = trim((string) ($input['celular'] ?? '')) ?: null;
        $ocupacion = trim((string) ($input['ocupacion'] ?? '')) ?: null;
        $genero    = trim((string) ($input['genero'] ?? '')) ?: null;

        if ($genero !== null && !in_array($genero, ['MASCULINO', 'FEMENINO', 'OTRO'], true)) {
            Response::error('Género inválido.', 400);
        }

        return [
            'nombres_apellidos' => $nombresApellidos,
            'edad'              => $edad,
            'celular'           => $celular,
            'ocupacion'         => $ocupacion,
            'genero'            => $genero,
        ];
    }
}