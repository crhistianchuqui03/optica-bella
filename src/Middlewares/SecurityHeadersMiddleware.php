<?php

declare(strict_types=1);

namespace App\Middlewares;

/**
 * Cabeceras de seguridad para todas las respuestas dinámicas.
 * Los archivos estáticos reciben las mismas cabeceras desde .htaccess.
 */
final class SecurityHeadersMiddleware
{
    public function aplicar(): void
    {
        header_remove('X-Powered-By');

        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}