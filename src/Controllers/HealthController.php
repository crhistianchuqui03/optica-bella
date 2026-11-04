<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;

/**
 * Health check público para el Application Load Balancer (ALB).
 * No inicia sesión, no consulta la base de datos y responde de inmediato.
 */
final class HealthController
{
    public function index(Request $request): void
    {
        Response::json(['status' => 'ok'], 200);
    }
}