<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Gestión centralizada de la sesión: cookie segura, expiración por
 * inactividad (30 minutos por defecto) y destrucción completa.
 */
final class Session
{
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $tiempoInactividad = Env::entero('SESSION_IDLE_TIMEOUT', 1800);

        ini_set('session.gc_maxlifetime', (string) $tiempoInactividad);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');

        $segura = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $segura,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_name('PHPSESSID');
        session_start();
    }

    public static function estaAutenticada(): bool
    {
        return ($_SESSION['autenticado'] ?? false) === true;
    }

    public static function expirada(): bool
    {
        if (!self::estaAutenticada()) {
            return false;
        }

        $ultima = $_SESSION['ultima_actividad'] ?? null;

        if (!is_int($ultima) && !is_numeric($ultima)) {
            $_SESSION['ultima_actividad'] = time();

            return false;
        }

        $limite = Env::entero('SESSION_IDLE_TIMEOUT', 1800);

        return (time() - (int) $ultima) > $limite;
    }

    public static function tocar(): void
    {
        if (self::estaAutenticada()) {
            $_SESSION['ultima_actividad'] = time();
        }
    }

    public static function actual(): array
    {
        return $_SESSION;
    }

    public static function usuarioId(): int
    {
        return (int) ($_SESSION['usuario_id'] ?? 0);
    }

    public static function nombreUsuario(): string
    {
        return (string) ($_SESSION['username'] ?? '');
    }

    public static function regenerar(): void
    {
        session_regenerate_id(true);
    }

    public static function destruir(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $parametros = session_get_cookie_params();

            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $parametros['path'],
                'domain'   => $parametros['domain'],
                'secure'   => $parametros['secure'],
                'httponly' => $parametros['httponly'],
                'samesite' => $parametros['samesite'] ?? 'Strict',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}