# MEMORY.md — Óptica Bellavista ERP/POS

## 1. Contexto del Proyecto y Dominio
- Sistema ERP/POS diseñado para la Óptica Bellavista en Juliaca, Perú.
- Automatiza 4 instrumentos físicos: Ficha de Contrato, Historia Clínica (refracción oftalmológica), Inventario de Monturas y Cuaderno de Ventas Diario.
- Stack base: PHP 8.2 puro (sin frameworks pesados), MySQL 8.0/8.4, Vanilla JS SPA (hash router), CSS puro (#D32F2F rojo, #424242 gris, #FFFFFF).
- Entorno de ejecución: Docker Compose en WSL2 (desarrollo local) -> Preparado para AWS EC2 (evaluación académica) y Mini PC N100 Cosmos Cloud (producción local).

## 2. Invariantes de Negocio y Reglas Financieras Críticas
- **Trazabilidad de Caja Diaria:** 
  - La tabla `movimientos_caja` registra cada ingreso y egreso en su fecha real (`fecha_registro`).
  - NUNCA se edita el abono original de un contrato cuando el cliente paga días después. Los saldos se liquidan mediante un nuevo registro de tipo `INGRESO` en `movimientos_caja` en la fecha actual.
  - El balance diario (`caja.php` o `CajaController`) se calcula dinámicamente: `Ganancia Neta = Total Ingresos del Día - Total Egresos del Día`.
  - Un contrato pasa a estado `CANCELADO` de forma automática cuando la suma de todos sus abonos cubre el `monto_total`.
  - Si un contrato se cancela el 100% el mismo día de creación, su estado inicial debe ser `CANCELADO`, no `PENDIENTE`.
- **Integridad de Stock:**
  - Crear un contrato descuenta stock (`stock - 1`) mediante bloqueo de fila (`SELECT ... FOR UPDATE`).
  - Anular o eliminar un contrato restituye el stock (`stock + 1`), elimina sus movimientos de caja asociados y elimina el contrato.
  - Las monturas marcadas como "propia del cliente" no descuentan stock.
- **Historias Clínicas:**
  - Registran mediciones de Lejos (V.L.), Cerca (V.C.), Adición (A.D.D.), materiales de luna, tratamientos e indicaciones.
  - Son editables por el optometrista o vendedor para corregir errores de tipeo.

## 3. Estado de la Base de Datos (6 Tablas Relacionales)
1. `usuarios`: `id`, `username`, `password_hash`, `nombre_completo`, `rol` ('ADMIN','VENDEDOR'), `estado`, `creado_en`.
2. `pacientes`: `id`, `nombres_apellidos`, `edad`, `celular`, `ocupacion`, `genero`, `creado_en`.
3. `historias_clinicas`: `id`, `paciente_id`, `fecha_consulta`, fórmulas VL (OD/OI), fórmulas VC (OD/OI), `add_medicion`, `material`, `tratamiento`, `tipo_lente`, `indicaciones`, `proxima_cita`.
4. `inventario_monturas`: `id`, `codigo_montura`, `descripcion_material`, `genero`, `marca`, `tipo_montura`, `stock`.
5. `contratos`: `id`, `numero_contrato`, `paciente_id`, `montura_id`, `detalle_contrato`, `monto_total`, `estado` ('PENDIENTE','CANCELADO'), `fecha_entrega`, `comentarios`.
6. `movimientos_caja`: `id`, `fecha_registro`, `tipo_movimiento` ('INGRESO','EGRESO'), `contrato_id`, `monto`, `concepto_descripcion`, `nro_comprobante_lab`, `usuario_id`.

## 4. Auditoría de Seguridad: 11 Hallazgos Identificados
1. Credenciales hardcodeadas en `docker-compose.yml` y fallback de base de datos.
2. Contraseña por defecto `admin`/`Admin123!` en `schema.sql`.
3. Puerto `3306` de MySQL expuesto directamente al host.
4. Falta de forzado TLS/HTTPS y flags estrictos en cookies de producción.
5. Ausencia de protección contra fuerza bruta / rate limiting en `/api/auth/login.php`.
6. Falta de token anti-CSRF en operaciones de mutación de estado.
7. Falta de cabecera Content-Security-Policy (CSP) estricta contra XSS.
8. Directorio `public/` montado como lectura-escritura en contenedor.
9. Falta de expiración de sesión por inactividad.
10. Falta de registro estructurado (JSON logs) para auditoría e ingesta hacia Wazuh SIEM.
11. Riesgo de condición de carrera en sugerencia de `numero_contrato` secuencial.

## 5. Refactor MVC y Estado de Hardening (2026-11)
- **Front Controller único:** `public/index.php` + `public/.htaccess`; se eliminaron los wrappers `public/api/*.php` y los handlers `src/api/` y `src/auth/`.
- **Capas:** `src/Controllers/`, `src/Repositories/`, `src/Middlewares/`, `src/Routes/api.php`, `src/Http/`, `src/Support/` y `src/bootstrap.php`.
- **Mitigado:** hallazgos 1, 3, 5, 7, 8, 9 y 10.
  - Secretos en `.env` no versionado; `config/database.php` sin fallbacks.
  - `3306` solo en la red interna de Docker.
  - Rate limiting 5/15 min con `429` y `Retry-After`.
  - CSP estricta y cabeceras en middleware + `.htaccess`.
  - Sesión con expiración por inactividad de 30 min y cookie `HttpOnly`/`SameSite=Strict`.
  - Logs JSON en `/var/log/app/security.log` para Wazuh.
  - `public/` montado en solo lectura.
- **Pendiente / residual:**
  - Hallazgo 2: el seed de `admin` sigue en `schema.sql` para las pruebas; rotar en producción.
  - Hallazgo 4: TLS/HSTS depende del despliegue; la cookie usa `Secure` cuando detecta HTTPS.
  - Hallazgo 6: sin token CSRF; mitigado por `SameSite=Strict`, API solo JSON y ausencia de CORS.
  - Hallazgo 11: el `UNIQUE` evita duplicados, pero la sugerencia `MAX+1` puede competir; una tabla de secuencias queda para fase 2.
- **Preparación AWS (2026-11):**
  - `GET /api/health` y `/api/health.php` públicos para el ALB; responden `200 {"status":"ok"}` sin sesión ni consulta a la BD.
  - `DB_HOST` y `DB_PORT` se leen con `getenv()`; en AWS `DB_HOST` será el endpoint privado de RDS y `DB_PORT=3306`.
  - La sesión MySQL fija `time_zone='-05:00'` para conservar `CURDATE()` en America/Lima aunque RDS use UTC.
  - `.gitignore` reforzado con `.env`, `/var/log/`, `/tmp/`, `*.log`, `.vscode/` y `.idea/`; `.env.example` queda con secretos vacíos.
- **Pruebas ejecutadas:** lint de los 29 PHP; login/check/logout; rutas y roles; CRUD de pacientes, historias, inventario, contratos y usuarios; creación/anulación de contrato con stock; abono parcial y liquidación; caja; `429` al 6.º fallo; cabeceras; expiración de sesión con override temporal; `nc -zv localhost 3306` falla; `public/` es read-only; logs JSON validados; health check sin cookie y sin `Set-Cookie`.
- **No ejecutar `docker compose down -v`** salvo que se quiera destruir la BD; el volumen existente se conserva.