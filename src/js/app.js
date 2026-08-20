/**
 * Router SPA, controlador de sesión y navegación.
 */
window.App = (function () {
    const MODULOS = {
        dashboard:  { js: 'js/dashboard.js', etiqueta: 'Inicio' },
        pacientes:  { js: 'js/pacientes.js', etiqueta: 'Pacientes' },
        historias:  { js: 'js/historias.js', etiqueta: 'Historias Clínicas' },
        inventario: { js: 'js/inventario.js', etiqueta: 'Inventario de Monturas' },
        contratos:  { js: 'js/contratos.js', etiqueta: 'Contratos de Venta' },
        caja:       { js: 'js/caja.js', etiqueta: 'Cuaderno de Ventas (Caja)' },
        usuarios:   { js: 'js/usuarios.js', etiqueta: 'Gestión de Usuarios' }
    };

    const usuario = { nombre: '—', rol: '—' };

    function formatearFecha(fecha) {
        return fecha.toLocaleDateString('es-PE', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
    }

    function cargarScript(src) {
        if (window.__modulosCargados && window.__modulosCargados[src]) {
            return Promise.resolve();
        }

        return new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src = src;
            script.onload = function () {
                window.__modulosCargados = window.__modulosCargados || {};
                window.__modulosCargados[src] = true;
                resolve();
            };
            script.onerror = function () {
                reject(new Error('No se pudo cargar el módulo ' + src));
            };
            document.head.appendChild(script);
        });
    }

    function obtenerModuloDesdeHash() {
        const partes = window.location.hash.replace(/^#\//, '').split('/');
        const modulo = partes[0] || '';
        return Object.prototype.hasOwnProperty.call(MODULOS, modulo) ? modulo : 'dashboard';
    }

    function obtenerParametrosRuta() {
        const partes = window.location.hash.replace(/^#\//, '').split('/');
        return partes.slice(1).filter(Boolean);
    }

    function marcarActivo(modulo) {
        document.querySelectorAll('.nav-link').forEach(function (enlace) {
            enlace.classList.toggle('active', enlace.dataset.module === modulo);
        });
    }

    function renderPlaceholder(modulo, contenedor) {
        contenedor.innerHTML =
            '<div class="placeholder-module">' +
                '<h2>' + MODULOS[modulo].etiqueta + '</h2>' +
                '<p>Este módulo se implementará en las próximas fases del proyecto.</p>' +
            '</div>';
    }

    function aplicarRuta() {
        const modulo = obtenerModuloDesdeHash();
        const parametros = obtenerParametrosRuta();
        const contenedor = document.getElementById('main-content');

        if (modulo === 'usuarios' && usuario.rol !== 'ADMIN') {
            if (window.location.hash !== '#/dashboard') {
                window.App.notificar('Acceso restringido. Solo el administrador puede gestionar usuarios.', 'error');
                window.location.hash = '#/dashboard';
            } else {
                aplicarRutaSegura('dashboard', parametros, contenedor);
            }
            return;
        }

        aplicarRutaSegura(modulo, parametros, contenedor);
    }

    function aplicarRutaSegura(modulo, parametros, contenedor) {
        marcarActivo(modulo);

        if (MODULOS[modulo].js) {
            contenedor.innerHTML = '';
            cargarScript(MODULOS[modulo].js)
                .then(function () {
                    if (window.Modulos && window.Modulos[modulo] && window.Modulos[modulo].render) {
                        window.Modulos[modulo].render(contenedor, parametros);
                    } else {
                        renderPlaceholder(modulo, contenedor);
                    }
                })
                .catch(function () {
                    renderPlaceholder(modulo, contenedor);
                });
        } else {
            renderPlaceholder(modulo, contenedor);
        }
    }

    function crearContenedorToasts() {
        let contenedor = document.getElementById('toast-container');
        if (!contenedor) {
            contenedor = document.createElement('div');
            contenedor.id = 'toast-container';
            document.body.appendChild(contenedor);
        }
        return contenedor;
    }

    function notificar(mensaje, tipo) {
        const contenedor = crearContenedorToasts();
        const toast = document.createElement('div');
        toast.className = 'toast ' + (tipo === 'error' ? 'toast-error' : tipo === 'info' ? 'toast-info' : 'toast-ok');
        toast.textContent = mensaje;
        contenedor.appendChild(toast);
        window.requestAnimationFrame(function () {
            toast.classList.add('toast-visible');
        });
        window.setTimeout(function () {
            toast.classList.remove('toast-visible');
            window.setTimeout(function () {
                toast.remove();
            }, 300);
        }, 3200);
    }

    function ir(modulo, parametros) {
        const resto = Array.isArray(parametros) && parametros.length
            ? '/' + parametros.map(encodeURIComponent).join('/')
            : '';
        const destino = '#/' + modulo + resto;

        if (window.location.hash === destino) {
            aplicarRuta();
        } else {
            window.location.hash = destino;
        }
    }

    function enlazarMenu() {
        document.querySelectorAll('.nav-link').forEach(function (enlace) {
            enlace.addEventListener('click', function (event) {
                event.preventDefault();
                const modulo = enlace.dataset.module;
                if (window.location.hash !== '#/' + modulo) {
                    window.location.hash = '#/' + modulo;
                } else {
                    aplicarRuta();
                }
            });
        });
    }

    function enlazarLogout() {
        const boton = document.getElementById('btn-cerrar-sesion');
        boton.addEventListener('click', async function () {
            boton.disabled = true;
            try {
                await API.post('api/auth/logout.php');
            } catch (error) {
                // se redirige igualmente a login
            }
            window.location.href = 'login.html';
        });
    }

    function enlazarResponsive() {
        const toggle = document.getElementById('btn-toggle-menu');
        const overlay = document.getElementById('sidebar-overlay');

        function cerrarMenu() {
            document.body.classList.remove('sidebar-open');
        }

        toggle.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-open');
        });

        overlay.addEventListener('click', cerrarMenu);
    }

    async function iniciar() {
        let respuesta;
        try {
            respuesta = await API.get('api/auth/check.php');
        } catch (error) {
            return;
        }

        if (!respuesta || respuesta.status !== 'success' || respuesta.authenticated !== true) {
            window.location.href = 'login.html';
            return;
        }

        usuario.nombre = respuesta.usuario.nombre;
        usuario.rol = respuesta.usuario.rol;

        const navUsuarios = document.getElementById('nav-usuarios');
        if (navUsuarios) {
            navUsuarios.hidden = usuario.rol !== 'ADMIN';
        }

        document.getElementById('topbar-nombre').textContent = usuario.nombre;
        document.getElementById('topbar-rol').textContent = 'Rol: ' + usuario.rol;
        document.getElementById('topbar-fecha').textContent = formatearFecha(new Date());

        enlazarMenu();
        enlazarLogout();
        enlazarResponsive();
        window.addEventListener('hashchange', aplicarRuta);

        if (window.location.hash === '') {
            window.location.hash = '#/dashboard';
        } else {
            aplicarRuta();
        }
    }

    return { iniciar: iniciar, usuario: usuario, notificar: notificar, ir: ir, paramPaciente: null };
})();

document.addEventListener('DOMContentLoaded', function () {
    window.App.iniciar();
});