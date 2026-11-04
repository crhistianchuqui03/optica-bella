# AGENTS.md

## Proyecto

Óptica Bellavista: ERP/POS en PHP 8.2 + Apache + MySQL 8. SPA en JavaScript vanilla con una API JSON en PHP puro. Sin Composer, framework, build, suite de pruebas, linter ni CI. El código, los comentarios y los textos visibles están en español.

## Ejecución y verificación

```bash
cp .env.example .env             # completar credenciales reales; .env no se versiona
docker compose up --build        # app en http://localhost:8081
docker compose down              # conserva el volumen de la BD
docker compose down -v           # reinicia la BD; schema.sql se ejecuta de nuevo en el próximo up
```

- `.env` es obligatorio: `docker compose` interpola `DB_*` y las credenciales de MySQL desde ahí. Si falta una variable, Compose falla de forma explícita.
- En local `DB_HOST=db` y `DB_PORT=3306`. En AWS, `DB_HOST` debe ser el endpoint privado de RDS y `DB_PORT=3306`; `config/database.php` los lee con `getenv()`.
- Health check público para el ALB: `GET /api/health` o `/api/health.php` responde `200 {"status":"ok"}` sin sesión ni consulta a la BD.
- MySQL NO publica el puerto 3306 al host. Solo es accesible dentro de la red interna de Docker mediante el servicio `db`.
- Usuario inicial: `admin` / `Admin123!` (definido en `docker/mysql/schema.sql` para el primer arranque del volumen). En producción debe rotarse.
- El código de la app se monta por bind, así que los cambios en PHP/JS/CSS no requieren rebuild. El Dockerfile no copia la aplicación; los bind mounts son obligatorios. `public/` se monta en solo lectura.
- No hay runner de pruebas. Validación de sintaxis:

```bash
docker compose exec app find src config public -name "*.php" -exec php -l {} \;
```

- Prueba de humo con cookie de sesión:

```bash
curl -c /tmp/bv-cookie -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"Admin123!"}' \
  http://localhost:8081/api/auth/login.php
curl -b /tmp/bv-cookie http://localhost:8081/api/pacientes.php
```

- El 6.º intento fallido de login con el mismo IP+usuario devuelve `429` y `Retry-After`. Para limpiar el contador en desarrollo:

```bash
docker compose exec app rm -rf /tmp/optica_bellavista_ratelimit
```

## Estructura

- `public/` es el único DocumentRoot de Apache. `src/` y `config/` están fuera del web root y se montan como solo lectura.
- `public/index.php` es el Front Controller único. `public/.htaccess` reescribe todo `/api/*` hacia él; no existen wrappers en `public/api/`.
- `src/Routes/api.php` mapea método + ruta + `[controlador, acción]` + roles. Para un endpoint nuevo se agrega una entrada ahí y un método en el controlador.
- `GET /api/health` y `/api/health.php` son públicos y no tocan la BD; el ALB debe apuntar su health check ahí con matcher `200`.
- `src/Controllers/` contiene la capa HTTP (`Auth`, `Paciente`, `Historia`, `Inventario`, `Contrato`, `Caja`, `Usuario` y `Health`); `src/Repositories/` concentra el acceso PDO; `src/Middlewares/` contiene autenticación, roles, rate limiting y cabeceras; `src/Http/` contiene `Request`, `Response` y `Router`; `src/Support/` contiene `Env`, `Session`, `Logger` y `Formato`.
- `src/bootstrap.php` registra el autoloader PSR-4 de `App\`, carga `.env` (sin sobrescribir variables de Docker), fija `America/Lima` y captura errores no controlados.
- `config/database.php` es un singleton PDO que lee `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS` con `getenv()`, sin fallbacks con credenciales reales. `DB_HOST` admite el endpoint privado de RDS. La sesión MySQL fija `time_zone='-05:00'` para conservar `CURDATE()` en America/Lima aunque RDS use UTC.
- `Response::json()` termina la ejecución; las respuestas conservan `{status, message, data}`.
- Los roles son `ADMIN` y `VENDEDOR`; `GET/POST /api/usuarios.php` es solo para ADMIN. La UI oculta Usuarios a los no administradores, pero el guard del servidor es la autoridad.
- Las rutas de la SPA usan hash. Para agregar un módulo: enlace de navegación en `public/index.html`, entrada en `MODULOS` de `public/js/app.js` y `window.Modulos.<name>.render(container, params)` en `public/js/<name>.js`. Los scripts de módulo se cargan bajo demanda.
- El frontend llama URLs relativas `api/...` con la cookie de sesión del mismo origen; no hay CORS ni JWT.
- `public/.htaccess` bloquea `.env`, `.json`, `.md`, `.yml`, `.sql`, `.log`, `.ini`, `.sh` y archivos ocultos. No coloques documentación ni configuración ahí.
- La CSP es `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;`. No agregues scripts inline: usa archivos en `public/js/`.

## Seguridad

- Secretos: solo en `.env` (ignorado por Git). `.env.example` no contiene claves reales. `.gitignore` excluye `.env`, `/var/log/`, `/tmp/`, `*.log`, `.vscode/` y `.idea/`.
- Sesión: expira por inactividad a los 30 minutos (`SESSION_IDLE_TIMEOUT=1800`), cookie `HttpOnly` + `SameSite=Strict` y `Secure` cuando hay HTTPS.
- Rate limiting: 5 fallos por IP+usuario cada 15 minutos, almacenados con `flock` en `/tmp/optica_bellavista_ratelimit`.
- Logs: una línea JSON por evento en `/var/log/app/security.log` con `timestamp`, `event`, `user`, `ip`, `status`, `details`, listo para Wazuh.
- Cabeceras: `Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` y `Referrer-Policy: strict-origin-when-cross-origin`.

## Invariantes de datos

- `docker/mysql/schema.sql` solo se ejecuta cuando se crea por primera vez el volumen `db_data`. Editarlo no tiene efecto sobre una BD existente; usa `down -v` (destruye datos) o aplica `ALTER` manualmente.
- La creación y anulación de contratos y los pagos de caja usan transacciones PDO y `FOR UPDATE` para mantener consistentes `inventario_monturas.stock`, `contratos.estado` y `movimientos_caja`. Conserva el bloqueo y el rollback al modificar estos flujos.
- Crear un contrato descuenta stock; eliminar un contrato restituye stock, borra sus movimientos de caja y luego borra el contrato (la acción de "anular" es un borrado físico).
- Un paciente con contratos no puede eliminarse. Los contratos pasan a `CANCELADO` cuando los movimientos `INGRESO` cubren el `monto_total`.
- MySQL corre en `America/Lima`; las fechas de caja usan por defecto `CURDATE()` de la BD.
- Todos los archivos PHP usan `declare(strict_types=1)`, consultas preparadas con parámetros nombrados y `filter_var(..., FILTER_VALIDATE_INT)` para IDs. No interpoles entrada de usuario en el SQL.