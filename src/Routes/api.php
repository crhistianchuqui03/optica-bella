<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CajaController;
use App\Controllers\ContratoController;
use App\Controllers\HealthController;
use App\Controllers\HistoriaController;
use App\Controllers\InventarioController;
use App\Controllers\PacienteController;
use App\Controllers\UsuarioController;

/**
 * Mapa central de rutas HTTP.
 * Formato: [método, ruta, [controlador, acción], roles permitidos].
 * Un arreglo de roles vacío indica que la ruta es pública.
 */
return [
    // Health check público para el ALB: sin sesión, sin BD y sin rate limiting.
    ['GET', '/api/health',     [HealthController::class, 'index'], []],
    ['GET', '/api/health.php', [HealthController::class, 'index'], []],

    ['POST', '/api/auth/login.php',  [AuthController::class, 'login'],  []],
    ['GET',  '/api/auth/logout.php', [AuthController::class, 'logout'], []],
    ['POST', '/api/auth/logout.php', [AuthController::class, 'logout'], []],
    ['GET',  '/api/auth/check.php',  [AuthController::class, 'check'],  []],

    ['GET',    '/api/pacientes.php',  [PacienteController::class, 'index'],   ['ADMIN', 'VENDEDOR']],
    ['POST',   '/api/pacientes.php',  [PacienteController::class, 'store'],   ['ADMIN', 'VENDEDOR']],
    ['DELETE', '/api/pacientes.php',  [PacienteController::class, 'destroy'], ['ADMIN', 'VENDEDOR']],

    ['GET',  '/api/historias.php', [HistoriaController::class, 'index'], ['ADMIN', 'VENDEDOR']],
    ['POST', '/api/historias.php', [HistoriaController::class, 'save'],  ['ADMIN', 'VENDEDOR']],
    ['PUT',  '/api/historias.php', [HistoriaController::class, 'save'],  ['ADMIN', 'VENDEDOR']],

    ['GET',  '/api/inventario.php', [InventarioController::class, 'index'], ['ADMIN', 'VENDEDOR']],
    ['POST', '/api/inventario.php', [InventarioController::class, 'store'], ['ADMIN', 'VENDEDOR']],

    ['GET',    '/api/contratos.php', [ContratoController::class, 'index'],   ['ADMIN', 'VENDEDOR']],
    ['POST',   '/api/contratos.php', [ContratoController::class, 'store'],   ['ADMIN', 'VENDEDOR']],
    ['PUT',    '/api/contratos.php', [ContratoController::class, 'update'],  ['ADMIN', 'VENDEDOR']],
    ['DELETE', '/api/contratos.php', [ContratoController::class, 'destroy'], ['ADMIN', 'VENDEDOR']],

    ['GET',  '/api/caja.php', [CajaController::class, 'index'], ['ADMIN', 'VENDEDOR']],
    ['POST', '/api/caja.php', [CajaController::class, 'store'], ['ADMIN', 'VENDEDOR']],

    ['GET',  '/api/usuarios.php', [UsuarioController::class, 'index'], ['ADMIN']],
    ['POST', '/api/usuarios.php', [UsuarioController::class, 'store'], ['ADMIN']],
];