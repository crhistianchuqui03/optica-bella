<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Http\Response;

/**
 * Autoriza al usuario autenticado según los roles permitidos por ruta.
 */
final class RoleMiddleware
{
    public function autorizar(array $rolesPermitidos, array $sesion): void
    {
        if ($rolesPermitidos === []) {
            return;
        }

        $rol = (string) ($sesion['rol'] ?? '');

        if (!in_array($rol, $rolesPermitidos, true)) {
            Response::error('Acceso denegado: permisos insuficientes.', 403);
        }
    }
}