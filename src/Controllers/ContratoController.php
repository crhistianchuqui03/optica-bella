<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\ContratoRepository;
use App\Repositories\PacienteRepository;
use App\Support\Formato;
use App\Support\Logger;
use App\Support\Session;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Contratos de venta: consulta, creación con descuento de stock,
 * edición y anulación con restitución de stock y limpieza de caja.
 */
final class ContratoController
{
    private ContratoRepository $contratos;

    private PacienteRepository $pacientes;

    public function __construct()
    {
        $pdo = Database::getConnection();

        $this->contratos = new ContratoRepository($pdo);
        $this->pacientes = new PacienteRepository($pdo);
    }

    public function index(Request $request): void
    {
        $id = $request->query('id');

        if ($id !== null) {
            $idValido = filter_var($id, FILTER_VALIDATE_INT);

            if ($idValido === false || $idValido <= 0) {
                Response::error('Identificador de contrato inválido.', 400);
            }

            $contrato = $this->contratos->buscarDetalle($idValido);

            if ($contrato === false) {
                Response::error('Contrato no encontrado.', 404);
            }

            Response::json([
                'status'      => 'success',
                'data'        => $contrato,
                'movimientos' => $this->contratos->movimientos($idValido),
            ], 200);
        }

        $pacienteId = $request->query('paciente_id');

        if ($pacienteId !== null) {
            $pacienteIdValido = filter_var($pacienteId, FILTER_VALIDATE_INT);

            if ($pacienteIdValido === false || $pacienteIdValido <= 0) {
                Response::error('Identificador de paciente inválido.', 400);
            }

            Response::json([
                'status' => 'success',
                'data'   => $this->contratos->listarPorPaciente($pacienteIdValido),
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
                'data'   => $this->contratos->buscarPorTexto($texto),
            ], 200);
        }

        if ($request->query('siguiente') !== null) {
            Response::json([
                'status' => 'success',
                'data'   => ['siguiente' => $this->contratos->siguienteNumero()],
            ], 200);
        }

        Response::json([
            'status' => 'success',
            'data'   => $this->contratos->listar(),
        ], 200);
    }

    public function store(Request $request): void
    {
        $input = $request->json();

        $numeroContrato = trim((string) ($input['numero_contrato'] ?? ''));
        $detalle        = Formato::textoONulo($input['detalle_contrato'] ?? null);
        $fechaEntrega   = Formato::textoONulo($input['fecha_entrega'] ?? null);
        $comentarios    = Formato::textoONulo($input['comentarios'] ?? null);

        $pacienteId = filter_var($input['paciente_id'] ?? null, FILTER_VALIDATE_INT);

        $monturaRaw = $input['montura_id'] ?? null;
        $monturaId  = ($monturaRaw === null || $monturaRaw === '' || (int) $monturaRaw <= 0)
            ? null
            : (int) $monturaRaw;

        if ($numeroContrato === '') {
            Response::error('El campo numero_contrato es obligatorio.', 400);
        }

        if ($pacienteId === false || $pacienteId <= 0) {
            Response::error('Identificador de paciente inválido.', 400);
        }

        if (!isset($input['monto_total']) || !is_numeric($input['monto_total']) || (float) $input['monto_total'] < 0) {
            Response::error('El campo monto_total es inválido.', 400);
        }

        if (!isset($input['monto_a_cuenta']) || !is_numeric($input['monto_a_cuenta']) || (float) $input['monto_a_cuenta'] < 0) {
            Response::error('El campo monto_a_cuenta es inválido.', 400);
        }

        if ($fechaEntrega !== null && !Formato::fechaValida($fechaEntrega)) {
            Response::error('Fecha de entrega inválida (formato YYYY-MM-DD).', 400);
        }

        $montoTotal   = Formato::decimal($input['monto_total']);
        $montoACuenta = Formato::decimal($input['monto_a_cuenta']);

        if ((float) $montoACuenta > (float) $montoTotal) {
            Response::error('El abono inicial no puede ser mayor que el monto total.', 400);
        }

        $estado = (float) $montoACuenta >= (float) $montoTotal ? 'CANCELADO' : 'PENDIENTE';

        if ($this->contratos->existeNumero($numeroContrato)) {
            Response::error('El número de contrato ya existe.', 400);
        }

        if (!$this->pacientes->existe($pacienteId)) {
            Response::error('El paciente no existe.', 404);
        }

        if ($monturaId !== null) {
            $montura = $this->contratos->obtenerStock($monturaId);

            if ($montura === false) {
                Response::error('La montura no existe.', 400);
            }

            if ((int) $montura['stock'] < 1) {
                Response::error('No hay stock disponible para la montura seleccionada.', 400);
            }
        }

        try {
            $contratoId = $this->contratos->crear([
                'numero_contrato'  => $numeroContrato,
                'paciente_id'      => $pacienteId,
                'detalle_contrato' => $detalle,
                'monto_total'      => $montoTotal,
                'estado'           => $estado,
                'fecha_entrega'    => $fechaEntrega,
                'comentarios'      => $comentarios,
            ], $monturaId, $montoACuenta, Session::usuarioId());
        } catch (PDOException $excepcion) {
            error_log('[bellavista] Error al crear contrato: ' . $excepcion->getMessage());

            Response::error('Error interno al registrar el contrato.', 500);
        } catch (RuntimeException $excepcion) {
            Response::error($excepcion->getMessage(), 400);
        } catch (Throwable $excepcion) {
            error_log('[bellavista] Error al crear contrato: ' . $excepcion->getMessage());

            Response::error('Error interno al registrar el contrato.', 500);
        }

        Logger::evento('contract_created', 'SUCCESS', [
            'contrato_id'     => $contratoId,
            'numero_contrato' => $numeroContrato,
            'paciente_id'     => $pacienteId,
            'montura_id'      => $monturaId,
            'monto_total'     => $montoTotal,
            'monto_a_cuenta'  => $montoACuenta,
            'estado'          => $estado,
        ], Session::nombreUsuario(), $request->ip());

        Response::json([
            'status'  => 'success',
            'message' => 'Contrato registrado correctamente.',
            'data'    => ['id' => $contratoId],
        ], 200);
    }

    public function update(Request $request): void
    {
        $input = $request->json();
        $id    = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

        if ($id === false || $id <= 0) {
            Response::error('Identificador de contrato inválido.', 400);
        }

        $detalle      = Formato::textoONulo($input['detalle_contrato'] ?? null);
        $fechaEntrega = Formato::textoONulo($input['fecha_entrega'] ?? null);
        $comentarios  = Formato::textoONulo($input['comentarios'] ?? null);

        if ($fechaEntrega !== null && !Formato::fechaValida($fechaEntrega)) {
            Response::error('Fecha de entrega inválida (formato YYYY-MM-DD).', 400);
        }

        if (!$this->contratos->existe($id)) {
            Response::error('Contrato no encontrado.', 404);
        }

        $this->contratos->actualizar($id, [
            'detalle_contrato' => $detalle,
            'fecha_entrega'    => $fechaEntrega,
            'comentarios'      => $comentarios,
        ]);

        Response::json([
            'status'  => 'success',
            'message' => 'Contrato actualizado correctamente.',
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
            Response::error('Identificador de contrato inválido.', 400);
        }

        $contrato = $this->contratos->buscarParaAnular($idValido);

        if ($contrato === false) {
            Response::error('Contrato no encontrado.', 404);
        }

        try {
            $this->contratos->anular($idValido);
        } catch (PDOException $excepcion) {
            error_log('[bellavista] Error al anular contrato: ' . $excepcion->getMessage());

            Response::error('Error interno al anular el contrato.', 500);
        } catch (RuntimeException) {
            Response::error('Contrato no encontrado.', 404);
        } catch (Throwable $excepcion) {
            error_log('[bellavista] Error al anular contrato: ' . $excepcion->getMessage());

            Response::error('Error interno al anular el contrato.', 500);
        }

        Logger::evento('contract_cancelled', 'SUCCESS', [
            'contrato_id'     => $idValido,
            'numero_contrato' => (string) $contrato['numero_contrato'],
            'montura_id'      => $contrato['montura_id'],
        ], Session::nombreUsuario(), $request->ip());

        Response::json([
            'status'  => 'success',
            'message' => 'Contrato anulado correctamente; el stock se restituyó.',
        ], 200);
    }
}