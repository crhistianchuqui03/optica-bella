<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Respuestas JSON con el contrato {status, message, data} de la API.
 * Termina la ejecución, igual que el antiguo responderJSON().
 */
final class Response
{
    public static function json(array $datos, int $codigo = 200): never
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=UTF-8');

        $salida = json_encode($datos, JSON_UNESCAPED_UNICODE);

        if ($salida === false) {
            $salida = '{"status":"error","message":"Error interno del servidor."}';
        }

        echo $salida;
        exit;
    }

    public static function error(string $mensaje, int $codigo): never
    {
        self::json(['status' => 'error', 'message' => $mensaje], $codigo);
    }
}