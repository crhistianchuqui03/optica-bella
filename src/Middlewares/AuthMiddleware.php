<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Http\Response;
use App\Support\Logger;
use App\Support\Session;

/**
 * Verifica que exista una sesión autenticada y que no haya expirado
 * por inactividad. Devuelve la sesión validada.
 */
final class AuthMiddleware
{
    public function autenticar(): array
    {
        Session::iniciar();

        if (!Session::estaAutenticada()) {
            Response::error('No autorizado. Inicie sesión.', 401);
        }

        if (Session::expirada()) {
            $usuario = Session::nombreUsuario();
            $ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? 'desconocida');

            Logger::evento('session_expired', 'DENIED', ['motivo' => 'inactividad'], $usuario, $ip);
            Session::destruir();

            Response::error('Sesión expirada por inactividad. Inicie sesión nuevamente.', 401);
        }

        Session::tocar();

        return Session::actual();
    }
}