<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Router;
use App\Middlewares\SecurityHeadersMiddleware;

/**
 * Front Controller único de la API.
 * Apache reescribe /api/* hacia este archivo mediante public/.htaccess.
 */
require_once __DIR__ . '/../src/bootstrap.php';

(new SecurityHeadersMiddleware())->aplicar();

$rutas = require __DIR__ . '/../src/Routes/api.php';

(new Router())->despachar($rutas, Request::desdeGlobales());