<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Http\Request;
use App\Http\Response;
use App\Middlewares\RateLimitMiddleware;
use App\Repositories\UsuarioRepository;
use App\Support\Logger;
use App\Support\Session;

/**
 * Autenticación: login con rate limiting, logout y verificación de sesión.
 */
final class AuthController
{
    private ?UsuarioRepository $usuarios = null;

    private RateLimitMiddleware $rateLimit;

    public function __construct()
    {
        $this->rateLimit = new RateLimitMiddleware();
    }

    public function login(Request $request): void
    {
        Session::iniciar();

        $input    = $request->json();
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($username === '' || $password === '') {
            Response::error('Usuario y contraseña son obligatorios.', 400);
        }

        $ip = $request->ip();

        $this->rateLimit->verificar($ip, $username);

        $usuario = $this->usuarios()->buscarPorUsername($username);

        $credencialesValidas = $usuario !== false
            && password_verify($password, $usuario['password_hash'])
            && (int) $usuario['estado'] === 1;

        if (!$credencialesValidas) {
            $this->rateLimit->registrarFallo($ip, $username);
            Logger::evento('login_failed', 'FAILED', ['motivo' => 'credenciales_invalidas'], $username, $ip);

            Response::error('Credenciales incorrectas.', 401);
        }

        $this->rateLimit->limpiar($ip, $username);

        Session::regenerar();

        $_SESSION['autenticado']      = true;
        $_SESSION['usuario_id']       = (int) $usuario['id'];
        $_SESSION['username']         = $usuario['username'];
        $_SESSION['nombre']           = $usuario['nombre_completo'];
        $_SESSION['rol']              = $usuario['rol'];
        $_SESSION['ultima_actividad'] = time();

        Logger::evento(
            'login_success',
            'SUCCESS',
            ['rol' => $usuario['rol']],
            (string) $usuario['username'],
            $ip
        );

        Response::json([
            'status'  => 'success',
            'message' => 'Inicio de sesión exitoso.',
            'data'    => [
                'usuario' => [
                    'usuario_id' => (int) $usuario['id'],
                    'username'   => $usuario['username'],
                    'nombre'     => $usuario['nombre_completo'],
                    'rol'        => $usuario['rol'],
                ],
            ],
        ], 200);
    }

    public function logout(Request $request): void
    {
        Session::iniciar();

        $autenticado = Session::estaAutenticada();
        $usuario     = Session::nombreUsuario();
        $ip          = $request->ip();

        Session::destruir();

        if ($autenticado) {
            Logger::evento('logout', 'SUCCESS', [], $usuario, $ip);
        }

        Response::json([
            'status'  => 'success',
            'message' => 'Sesión cerrada correctamente.',
        ], 200);
    }

    public function check(Request $request): void
    {
        Session::iniciar();

        if (!Session::estaAutenticada()) {
            Response::json([
                'status'        => 'error',
                'authenticated' => false,
                'message'       => 'No existe una sesión activa.',
            ], 401);
        }

        if (Session::expirada()) {
            $usuario = Session::nombreUsuario();
            $ip      = $request->ip();

            Logger::evento('session_expired', 'DENIED', ['motivo' => 'inactividad'], $usuario, $ip);
            Session::destruir();

            Response::json([
                'status'        => 'error',
                'authenticated' => false,
                'message'       => 'Sesión expirada por inactividad. Inicie sesión nuevamente.',
            ], 401);
        }

        Session::tocar();

        Response::json([
            'status'        => 'success',
            'authenticated' => true,
            'usuario'       => [
                'nombre' => (string) ($_SESSION['nombre'] ?? ''),
                'rol'    => (string) ($_SESSION['rol'] ?? ''),
            ],
        ], 200);
    }

    private function usuarios(): UsuarioRepository
    {
        if ($this->usuarios === null) {
            $this->usuarios = new UsuarioRepository(Database::getConnection());
        }

        return $this->usuarios;
    }
}