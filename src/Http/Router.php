<?php

declare(strict_types=1);

namespace App\Http;

use App\Middlewares\AuthMiddleware;
use App\Middlewares\RoleMiddleware;

/**
 * Enrutador central: relaciona método + ruta con un controlador y
 * aplica autenticación y roles antes de ejecutarlo.
 */
final class Router
{
    /**
     * @param array<int, array{0:string,1:string,2:array{0:class-string,1:string},3:array<int,string>}> $rutas
     */
    public function despachar(array $rutas, Request $request): void
    {
        $metodo = $request->metodo();

        if ($metodo === 'HEAD') {
            $metodo = 'GET';
        }

        $ruta       = $request->ruta();
        $rutaExiste = false;

        foreach ($rutas as $definicion) {
            [$metodoRuta, $patron, $manejador, $roles] = $definicion;

            if ($patron !== $ruta) {
                continue;
            }

            $rutaExiste = true;

            if ($metodoRuta !== $metodo) {
                continue;
            }

            if ($roles !== []) {
                $sesion = (new AuthMiddleware())->autenticar();
                (new RoleMiddleware())->autorizar($roles, $sesion);
            }

            [$clase, $accion] = $manejador;
            $controlador = new $clase();
            $controlador->{$accion}($request);

            return;
        }

        if ($rutaExiste) {
            Response::error('Método no permitido.', 405);
        }

        Response::error('Recurso no encontrado.', 404);
    }
}