<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\CajaRepository;
use App\Repositories\ContratoRepository;
use App\Support\Formato;
use App\Support\Logger;
use App\Support\Session;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Cuaderno de ventas diario: consultas, abonos de saldo y egresos.
 */
final class CajaController
{
    private CajaRepository $caja;

    private ContratoRepository $contratos;

    public function __construct()
    {
        $pdo = Database::getConnection();

        $this->caja      = new CajaRepository($pdo);
        $this->contratos = new ContratoRepository($pdo);
    }

    public function index(Request $request): void
    {
        $q = $request->query('q');

        if ($q !== null) {
            $texto = trim((string) $q);

            if ($texto === '') {
                Response::error('Parámetro de búsqueda vacío.', 400);
            }

            Response::json([
                'status' => 'success',
                'data'   => $this->caja->buscar($texto),
            ], 200);
        }

        $fecha = $request->query('fecha');
        $fecha = $fecha !== null ? trim((string) $fecha) : '';

        if ($fecha === '') {
            $fecha = $this->caja->fechaActual();
        } elseif (!Formato::fechaValida($fecha)) {
            Response::error('Fecha inválida (formato YYYY-MM-DD).', 400);
        }

        $resumen = $this->caja->resumenDia($fecha);

        Response::json([
            'status' => 'success',
            'data'   => [
                'fecha'          => $fecha,
                'ingresos'       => $resumen['ingresos'],
                'egresos'        => $resumen['egresos'],
                'total_ingresos' => Formato::decimal($resumen['total_ingresos']),
                'total_egresos'  => Formato::decimal($resumen['total_egresos']),
                'ganancia_neta'  => Formato::decimal($resumen['ganancia_neta']),
            ],
        ], 200);
    }

    public function store(Request $request): void
    {
        $input  = $request->json();
        $accion = trim((string) ($input['accion'] ?? ''));

        if ($accion === 'abono_saldo') {
            $contratoId = filter_var($input['contrato_id'] ?? null, FILTER_VALIDATE_INT);

            if ($contratoId === false || $contratoId <= 0) {
                Response::error('Identificador de contrato inválido.', 400);
            }

            $montoAbono = $input['monto_abono'] ?? null;

            if (!is_numeric($montoAbono) || (float) $montoAbono <= 0) {
                Response::error('El campo monto_abono es inválido.', 400);
            }

            $montoAbonoDec = Formato::decimal($montoAbono);
            $comentarios    = Formato::textoONulo($input['comentarios'] ?? null);

            try {
                $resultado = $this->caja->registrarAbono(
                    $contratoId,
                    $montoAbonoDec,
                    $comentarios,
                    Session::usuarioId()
                );
            } catch (PDOException $excepcion) {
                error_log('[bellavista] Error al registrar abono: ' . $excepcion->getMessage());

                Response::error('Error interno al registrar el abono.', 500);
            } catch (RuntimeException $excepcion) {
                Response::error($excepcion->getMessage(), 400);
            } catch (Throwable $excepcion) {
                error_log('[bellavista] Error al registrar abono: ' . $excepcion->getMessage());

                Response::error('Error interno al registrar el abono.', 500);
            }

            Logger::evento('payment_received', 'SUCCESS', [
                'contrato_id'     => $resultado['contrato_id'],
                'numero_contrato' => $resultado['numero_contrato'],
                'monto'           => $resultado['monto_abonado'],
                'saldo_pendiente' => $resultado['saldo_pendiente'],
                'estado'          => $resultado['estado'],
            ], Session::nombreUsuario(), $request->ip());

            Response::json([
                'status'  => 'success',
                'message' => 'Abono registrado correctamente.',
                'data'    => [
                    'contrato_id'     => $resultado['contrato_id'],
                    'monto_abonado'   => $resultado['monto_abonado'],
                    'saldo_pendiente' => $resultado['saldo_pendiente'],
                    'estado'          => $resultado['estado'],
                ],
            ], 200);
        }

        if ($accion === 'registrar_egreso') {
            $concepto       = trim((string) ($input['concepto_descripcion'] ?? ''));
            $monto          = $input['monto'] ?? null;
            $nroComprobante = Formato::textoONulo($input['nro_comprobante_lab'] ?? null);

            $contratoRaw = $input['contrato_id'] ?? null;
            $contratoIdEgreso = ($contratoRaw === null || $contratoRaw === '' || (int) $contratoRaw <= 0)
                ? null
                : (int) $contratoRaw;

            if ($concepto === '') {
                Response::error('El campo concepto_descripcion es obligatorio.', 400);
            }

            if (!is_numeric($monto) || (float) $monto <= 0) {
                Response::error('El campo monto es inválido.', 400);
            }

            if ($contratoIdEgreso !== null && !$this->contratos->existe($contratoIdEgreso)) {
                Response::error('El contrato no existe.', 400);
            }

            try {
                $id = $this->caja->registrarEgreso(
                    $contratoIdEgreso,
                    Formato::decimal($monto),
                    $concepto,
                    $nroComprobante,
                    Session::usuarioId()
                );
            } catch (Throwable $excepcion) {
                error_log('[bellavista] Error al registrar egreso: ' . $excepcion->getMessage());

                Response::error('Error interno al registrar el egreso.', 500);
            }

            Logger::evento('expense_created', 'SUCCESS', [
                'movimiento_id' => $id,
                'contrato_id'   => $contratoIdEgreso,
                'monto'         => Formato::decimal($monto),
                'concepto'      => $concepto,
                'comprobante'   => $nroComprobante,
            ], Session::nombreUsuario(), $request->ip());

            Response::json([
                'status'  => 'success',
                'message' => 'Egreso registrado correctamente.',
                'data'    => ['id' => $id],
            ], 200);
        }

        Response::error('Acción inválida.', 400);
    }
}