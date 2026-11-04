<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Encapsula la petición HTTP actual para desacoplar a los controladores
 * de las superglobales de PHP.
 */
final class Request
{
    private ?array $json = null;

    private ?string $cuerpo = null;

    public function __construct(
        private readonly string $metodo,
        private readonly string $ruta,
        private readonly array $query,
        private readonly array $servidor
    ) {
    }

    public static function desdeGlobales(): self
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $ruta = parse_url($uri, PHP_URL_PATH);
        $ruta = is_string($ruta) ? self::normalizarRuta($ruta) : '/';

        $metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        return new self($metodo, $ruta, $_GET, $_SERVER);
    }

    public function metodo(): string
    {
        return $this->metodo;
    }

    public function ruta(): string
    {
        return $this->ruta;
    }

    public function query(string $clave, mixed $porDefecto = null): mixed
    {
        return $this->query[$clave] ?? $porDefecto;
    }

    public function servidor(string $clave, mixed $porDefecto = null): mixed
    {
        return $this->servidor[$clave] ?? $porDefecto;
    }

    public function ip(): string
    {
        $ip = $this->servidor['REMOTE_ADDR'] ?? '';

        return is_string($ip) && $ip !== '' ? $ip : 'desconocida';
    }

    /**
     * Decodifica el cuerpo JSON y responde 400 si es inválido.
     */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        $datos = json_decode($this->cuerpo(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Response::error('JSON inválido.', 400);
        }

        $this->json = is_array($datos) ? $datos : [];

        return $this->json;
    }

    /**
     * Variante tolerante para DELETE: un cuerpo vacío o inválido equivale a [].
     */
    public function jsonTolerante(): array
    {
        $datos = json_decode($this->cuerpo(), true);

        return is_array($datos) ? $datos : [];
    }

    public function entrada(string $clave, mixed $porDefecto = null): mixed
    {
        $datos = $this->json();

        return $datos[$clave] ?? $porDefecto;
    }

    private function cuerpo(): string
    {
        if ($this->cuerpo === null) {
            $contenido = file_get_contents('php://input');
            $this->cuerpo = $contenido === false ? '' : $contenido;
        }

        return $this->cuerpo;
    }

    private static function normalizarRuta(string $ruta): string
    {
        $ruta = '/' . ltrim($ruta, '/');
        $ruta = preg_replace('#/+#', '/', $ruta) ?? $ruta;

        if ($ruta !== '/') {
            $ruta = rtrim($ruta, '/');
        }

        return $ruta;
    }
}