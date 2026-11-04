<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\InventarioRepository;

/**
 * Consulta y mantenimiento del inventario de monturas.
 */
final class InventarioController
{
    private InventarioRepository $inventario;

    public function __construct()
    {
        $this->inventario = new InventarioRepository(Database::getConnection());
    }

    public function index(Request $request): void
    {
        $id = $request->query('id');

        if ($id !== null) {
            $idValido = filter_var($id, FILTER_VALIDATE_INT);

            if ($idValido === false || $idValido <= 0) {
                Response::error('Identificador de montura inválido.', 400);
            }

            $montura = $this->inventario->buscar($idValido);

            if ($montura === false) {
                Response::error('Montura no encontrada.', 404);
            }

            Response::json(['status' => 'success', 'data' => $montura], 200);
        }

        $q = $request->query('q');

        if ($q !== null) {
            $texto = trim((string) $q);

            if ($texto === '') {
                Response::error('Parámetro de búsqueda vacío.', 400);
            }

            Response::json([
                'status' => 'success',
                'data'   => $this->inventario->buscarPorTexto($texto),
            ], 200);
        }

        $todos = $request->query('todos') === '1';

        Response::json([
            'status' => 'success',
            'data'   => $this->inventario->listar($todos),
        ], 200);
    }

    public function store(Request $request): void
    {
        $input = $request->json();

        $codigo      = trim((string) ($input['codigo_montura'] ?? ''));
        $descripcion = trim((string) ($input['descripcion_material'] ?? ''));
        $marca       = trim((string) ($input['marca'] ?? ''));
        $genero      = trim((string) ($input['genero'] ?? ''));
        $tipoMontura = trim((string) ($input['tipo_montura'] ?? 'RECETADO'));
        $stock       = isset($input['stock']) && $input['stock'] !== '' ? $input['stock'] : null;

        if ($codigo === '' || $descripcion === '' || $marca === '' || $genero === '') {
            Response::error(
                'codigo_montura, descripcion_material, marca y genero son obligatorios.',
                400
            );
        }

        if (!in_array($genero, ['MASCULINO', 'FEMENINO', 'UNISEX', 'NIÑO'], true)) {
            Response::error('Género inválido.', 400);
        }

        if (!in_array($tipoMontura, ['SOL', '5EN1', 'AMARILLO', 'LECTURA', 'SEGURIDAD', 'RECETADO', 'DESCANSO'], true)) {
            Response::error('Tipo de montura inválido.', 400);
        }

        if ($stock === null || !is_numeric($stock) || (int) $stock < 0) {
            Response::error('Stock inválido.', 400);
        }

        $datos = [
            'codigo_montura'       => $codigo,
            'descripcion_material' => $descripcion,
            'genero'               => $genero,
            'marca'                => $marca,
            'tipo_montura'         => $tipoMontura,
            'stock'                => (int) $stock,
        ];

        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

        if (is_int($id) && $id > 0) {
            if (!$this->inventario->existe($id)) {
                Response::error('Montura no encontrada.', 404);
            }

            if ($this->inventario->existeCodigoEnOtro($codigo, $id)) {
                Response::error('El código de montura ya está en uso.', 400);
            }

            $this->inventario->actualizar($id, $datos);

            Response::json([
                'status'  => 'success',
                'message' => 'Montura actualizada correctamente.',
                'data'    => ['id' => $id, 'actualizado' => true],
            ], 200);
        }

        $existente = $this->inventario->buscarPorCodigo($codigo);

        if ($existente !== false) {
            $idExistente = (int) $existente['id'];

            $this->inventario->actualizarPorCodigo($idExistente, $datos);

            Response::json([
                'status'  => 'success',
                'message' => 'Montura actualizada correctamente.',
                'data'    => ['id' => $idExistente, 'actualizado' => true],
            ], 200);
        }

        $nuevoId = $this->inventario->crear($datos);

        Response::json([
            'status'  => 'success',
            'message' => 'Montura registrada correctamente.',
            'data'    => ['id' => $nuevoId, 'actualizado' => false],
        ], 200);
    }
}