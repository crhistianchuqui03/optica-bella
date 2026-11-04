<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Http\Response;
use App\Support\Env;
use App\Support\Logger;

/**
 * Limita a 5 los intentos fallidos de login por IP + usuario en una
 * ventana de 15 minutos. El estado se guarda en /tmp con flock.
 */
final class RateLimitMiddleware
{
    private const MAX_INTENTOS = 5;

    private const VENTANA_SEGUNDOS = 900;

    private string $directorio;

    public function __construct()
    {
        $porDefecto = sys_get_temp_dir() . '/optica_bellavista_ratelimit';

        $this->directorio = Env::obtener('RATE_LIMIT_DIR', $porDefecto) ?? $porDefecto;
    }

    public function verificar(string $ip, string $usuario): void
    {
        $intentos = $this->leerIntentos($ip, $usuario);

        if (count($intentos) < self::MAX_INTENTOS) {
            return;
        }

        $masAntiguo = (int) min($intentos);
        $restante   = max(1, self::VENTANA_SEGUNDOS - (time() - $masAntiguo));

        Logger::evento('login_rate_limited', 'DENIED', [
            'intentos'    => count($intentos),
            'retry_after' => $restante,
        ], $usuario, $ip);

        header('Retry-After: ' . $restante);
        Response::error('Demasiados intentos fallidos. Intente nuevamente más tarde.', 429);
    }

    public function registrarFallo(string $ip, string $usuario): void
    {
        $archivo = $this->archivo($ip, $usuario);
        $this->prepararDirectorio();

        $manejador = @fopen($archivo, 'c+');

        if ($manejador === false) {
            error_log('[bellavista] No se pudo abrir el archivo de rate limit: ' . $archivo);

            return;
        }

        try {
            if (!flock($manejador, LOCK_EX)) {
                return;
            }

            $contenido = stream_get_contents($manejador);
            $intentos  = $this->limpiarVentana($this->decodificar($contenido === false ? '' : $contenido));
            $intentos[] = time();

            rewind($manejador);
            ftruncate($manejador, 0);
            fwrite($manejador, (string) json_encode($intentos, JSON_UNESCAPED_UNICODE));
            fflush($manejador);
            flock($manejador, LOCK_UN);
            @chmod($archivo, 0600);
        } finally {
            fclose($manejador);
        }
    }

    public function limpiar(string $ip, string $usuario): void
    {
        $archivo = $this->archivo($ip, $usuario);

        if (is_file($archivo)) {
            @unlink($archivo);
        }
    }

    private function leerIntentos(string $ip, string $usuario): array
    {
        $archivo = $this->archivo($ip, $usuario);

        if (!is_file($archivo)) {
            return [];
        }

        $contenido = @file_get_contents($archivo);

        if ($contenido === false) {
            return [];
        }

        return $this->limpiarVentana($this->decodificar($contenido));
    }

    private function decodificar(string $contenido): array
    {
        $datos = json_decode($contenido, true);

        if (!is_array($datos)) {
            return [];
        }

        $intentos = [];

        foreach ($datos as $valor) {
            if (is_int($valor) || (is_string($valor) && ctype_digit($valor))) {
                $intentos[] = (int) $valor;
            }
        }

        return $intentos;
    }

    private function limpiarVentana(array $intentos): array
    {
        $limite = time() - self::VENTANA_SEGUNDOS;

        return array_values(array_filter(
            $intentos,
            static fn (int $intento): bool => $intento >= $limite
        ));
    }

    private function archivo(string $ip, string $usuario): string
    {
        $clave = hash('sha256', $ip . '|' . strtolower(trim($usuario)));

        return rtrim($this->directorio, '/') . '/' . $clave . '.json';
    }

    private function prepararDirectorio(): void
    {
        if (!is_dir($this->directorio)) {
            @mkdir($this->directorio, 0700, true);
        }
    }
}