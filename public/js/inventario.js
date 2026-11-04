/**
 * Módulo de Inventario de Monturas: buscador instantáneo, tabla,
 * registro/edición y ajuste de stock sin recargar la página.
 */
window.Modulos = window.Modulos || {};

Modulos.inventario = (function () {
    let contenedor = null;
    let debounceTimer = null;
    let busquedaActual = '';
    let monturas = [];
    let monturaEnEdicion = null;
    let modoAjusteStock = false;
    let guardando = false;
    let inicializado = false;

    const GENEROS = ['MASCULINO', 'FEMENINO', 'UNISEX', 'NIÑO'];
    const TIPOS_MONTURA = ['SOL', '5EN1', 'AMARILLO', 'LECTURA', 'SEGURIDAD', 'RECETADO', 'DESCANSO'];

    function escapar(texto) {
        const div = document.createElement('div');
        div.textContent = String(texto === null || texto === undefined ? '' : texto);
        return div.innerHTML;
    }

    function capitalizar(texto) {
        if (!texto) {
            return '—';
        }
        return texto.charAt(0) + texto.slice(1).toLowerCase();
    }

    /* ============================================================
       Render principal
       ============================================================ */
    function render(container) {
        contenedor = container;
        monturaEnEdicion = null;
        modoAjusteStock = false;

        contenedor.innerHTML =
            '<div class="modulo-cabecera">' +
                '<h2 class="vista-titulo">Inventario de Monturas</h2>' +
                '<button id="btn-nueva-montura" class="btn btn-primary" type="button">' +
                    '<span aria-hidden="true">+</span> Nueva Montura' +
                '</button>' +
            '</div>' +

            '<div class="buscador">' +
                '<svg class="buscador-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">' +
                    '<circle cx="11" cy="11" r="7"></circle>' +
                    '<path d="m20 20-3.5-3.5"></path>' +
                '</svg>' +
                '<input id="buscar-monturas" class="form-control" type="search" ' +
                    'placeholder="Buscar por código, marca o descripción..." autocomplete="off">' +
            '</div>' +

            '<div class="panel">' +
                '<div id="tabla-monturas-slot">Cargando monturas...</div>' +
            '</div>';

        crearModales();
        inicializar();

        document.getElementById('btn-nueva-montura').addEventListener('click', abrirNuevaMontura);

        const buscador = document.getElementById('buscar-monturas');
        buscador.addEventListener('input', function () {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(function () {
                busquedaActual = buscador.value;
                cargarMonturas(busquedaActual);
            }, 300);
        });

        document.getElementById('tabla-monturas-slot').addEventListener('click', function (event) {
            const boton = event.target.closest('[data-accion]');
            if (!boton) {
                return;
            }
            const montura = monturas.find(function (m) {
                return Number(m.id) === Number(boton.dataset.id);
            });
            if (!montura) {
                return;
            }
            if (boton.dataset.accion === 'editar') {
                abrirEditarMontura(montura);
            } else if (boton.dataset.accion === 'ajustar') {
                abrirAjustarStock(montura);
            }
        });

        cargarMonturas(busquedaActual);
    }

    function inicializar() {
        if (inicializado) {
            return;
        }
        inicializado = true;

        document.addEventListener('click', function (event) {
            const botonCierre = event.target.closest('[data-cerrar-modal]');
            if (botonCierre) {
                const modal = botonCierre.closest('.modal-overlay');
                if (modal) {
                    cerrarModal(modal);
                }
                return;
            }

            if (event.target.classList && event.target.classList.contains('modal-overlay') && !event.target.hidden) {
                cerrarModal(event.target);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                const abierto = document.querySelector('.modal-overlay:not([hidden])');
                if (abierto) {
                    cerrarModal(abierto);
                }
            }
        });

        document.getElementById('form-montura').addEventListener('submit', guardarMontura);
    }

    /* ============================================================
       Carga y búsqueda
       ============================================================ */
    async function cargarMonturas(termino) {
        const limpio = termino.trim();
        const url = limpio === ''
            ? 'api/inventario.php?todos=1'
            : 'api/inventario.php?q=' + encodeURIComponent(limpio);

        try {
            const respuesta = await API.get(url);
            if (respuesta.status === 'success') {
                monturas = respuesta.data || [];
                renderTabla(monturas);
                return;
            }
            renderTabla([]);
        } catch (error) {
            renderTablaPendiente(error.message);
        }
    }

    function renderTabla(lista) {
        const slot = document.getElementById('tabla-monturas-slot');

        if (!lista.length) {
            slot.innerHTML = '<p class="sin-datos">No se encontraron monturas.</p>';
            return;
        }

        let html =
            '<table class="tabla">' +
                '<thead><tr>' +
                    '<th>Código</th>' +
                    '<th>Marca</th>' +
                    '<th>Descripción del Material</th>' +
                    '<th>Género</th>' +
                    '<th>Tipo de Montura</th>' +
                    '<th>Stock</th>' +
                    '<th>Acciones</th>' +
                '</tr></thead><tbody>';

        lista.forEach(function (m) {
            const stock = Number(m.stock);
            const stockHtml = stock <= 0
                ? '<span class="badge badge-stock-cero">Sin stock</span>'
                : '<span class="badge badge-stock">' + stock + '</span>';

            html +=
                '<tr>' +
                    '<td><strong>' + escapar(m.codigo_montura) + '</strong></td>' +
                    '<td>' + escapar(m.marca) + '</td>' +
                    '<td>' + escapar(m.descripcion_material) + '</td>' +
                    '<td>' + capitalizar(m.genero) + '</td>' +
                    '<td>' + capitalizar(m.tipo_montura) + '</td>' +
                    '<td>' + stockHtml + '</td>' +
                    '<td class="celda-acciones">' +
                        '<button type="button" class="btn btn-ghost btn-sm" data-accion="editar" data-id="' + m.id + '">Editar</button>' +
                        '<button type="button" class="btn btn-ghost btn-sm" data-accion="ajustar" data-id="' + m.id + '">Ajustar Stock</button>' +
                    '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        slot.innerHTML = html;
    }

    function renderTablaPendiente(mensaje) {
        const slot = document.getElementById('tabla-monturas-slot');
        slot.innerHTML = '<p class="sin-datos">' + escapar(mensaje || 'Error al cargar monturas.') + '</p>';
    }

    /* ============================================================
       Modal de registro / edición / ajuste de stock
       ============================================================ */
    function crearModales() {
        if (document.getElementById('modal-montura')) {
            return;
        }

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'modal-montura';
        overlay.hidden = true;
        overlay.innerHTML =
            '<div class="modal modal-lg" role="dialog" aria-modal="true" aria-labelledby="titulo-modal-montura">' +
                '<div class="modal-header">' +
                    '<h2 id="titulo-modal-montura">Nueva Montura</h2>' +
                    '<button type="button" class="modal-close" data-cerrar-modal aria-label="Cerrar">&times;</button>' +
                '</div>' +
                '<form id="form-montura" novalidate>' +
                    '<div class="modal-body">' +
                        '<div class="form-group">' +
                            '<label for="mm-codigo">Código de Montura *</label>' +
                            '<input id="mm-codigo" name="codigo_montura" class="form-control" required>' +
                        '</div>' +
                        '<div class="form-row">' +
                            '<div class="form-group">' +
                                '<label for="mm-marca">Marca *</label>' +
                                '<input id="mm-marca" name="marca" class="form-control" required>' +
                            '</div>' +
                            '<div class="form-group">' +
                                '<label for="mm-stock">Stock *</label>' +
                                '<input id="mm-stock" name="stock" class="form-control" type="number" min="0" step="1" inputmode="numeric" required>' +
                            '</div>' +
                        '</div>' +
                        '<div class="form-group">' +
                            '<label for="mm-descripcion">Descripción del Material *</label>' +
                            '<input id="mm-descripcion" name="descripcion_material" class="form-control" required>' +
                        '</div>' +
                        '<div class="form-row">' +
                            '<div class="form-group">' +
                                '<label for="mm-genero">Género *</label>' +
                                '<select id="mm-genero" name="genero" class="form-control" required>' +
                                    opcionesSelect(GENEROS) +
                                '</select>' +
                            '</div>' +
                            '<div class="form-group">' +
                                '<label for="mm-tipo">Tipo de Montura *</label>' +
                                '<select id="mm-tipo" name="tipo_montura" class="form-control" required>' +
                                    opcionesSelect(TIPOS_MONTURA) +
                                '</select>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="modal-actions">' +
                        '<button type="button" class="btn btn-ghost" data-cerrar-modal>Cancelar</button>' +
                        '<button type="submit" id="btn-guardar-montura" class="btn btn-primary">Guardar Montura</button>' +
                    '</div>' +
                '</form>' +
            '</div>';
        document.body.appendChild(overlay);
    }

    function opcionesSelect(opciones) {
        return '<option value="">— Seleccionar —</option>' +
            opciones.map(function (opcion) {
                return '<option value="' + opcion + '">' + capitalizar(opcion) + '</option>';
            }).join('');
    }

    function abrirModalMontura(titulo, enfoque) {
        document.getElementById('titulo-modal-montura').textContent = titulo;
        abrirModal('modal-montura', enfoque);
    }

    function abrirNuevaMontura() {
        ocultarEdicion();

        const formulario = document.getElementById('form-montura');
        formulario.reset();
        monturaEnEdicion = null;
        modoAjusteStock = false;
        document.getElementById('btn-guardar-montura').textContent = 'Guardar Montura';
        abrirModalMontura('Nueva Montura', 'mm-codigo');
    }

    function abrirEditarMontura(montura) {
        monturaEnEdicion = Number(montura.id);
        modoAjusteStock = false;
        rellenarFormulario(montura);
        document.getElementById('btn-guardar-montura').textContent = 'Guardar Cambios';
        abrirModalMontura('Editar Montura', 'mm-codigo');
    }

    function abrirAjustarStock(montura) {
        monturaEnEdicion = Number(montura.id);
        modoAjusteStock = true;
        rellenarFormulario(montura);
        document.getElementById('btn-guardar-montura').textContent = 'Guardar Stock';
        abrirModalMontura('Ajustar Stock', 'mm-stock');
    }

    function rellenarFormulario(montura) {
        const formulario = document.getElementById('form-montura');

        formulario.elements['codigo_montura'].value = montura.codigo_montura || '';
        formulario.elements['marca'].value = montura.marca || '';
        formulario.elements['descripcion_material'].value = montura.descripcion_material || '';
        formulario.elements['genero'].value = montura.genero || '';
        formulario.elements['tipo_montura'].value = montura.tipo_montura || 'RECETADO';
        formulario.elements['stock'].value = montura.stock;
    }

    function ocultarEdicion() {
        monturaEnEdicion = null;
        modoAjusteStock = false;
    }

    /* ============================================================
       Guardado (una sola ejecución por envío)
       ============================================================ */
    async function guardarMontura(event) {
        event.preventDefault();

        if (guardando) {
            return;
        }

        const formulario = event.target;
        const boton = document.getElementById('btn-guardar-montura');

        const datos = {};

        new FormData(formulario).forEach(function (valor, clave) {
            datos[clave] = typeof valor === 'string' ? valor.trim() : valor;
        });

        if (monturaEnEdicion !== null) {
            datos.id = monturaEnEdicion;
        }

        guardando = true;
        boton.disabled = true;

        try {
            const respuesta = await API.post('api/inventario.php', datos);

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo guardar la montura.');
            }

            const mensaje = modoAjusteStock
                ? 'Stock de la montura actualizado correctamente.'
                : (monturaEnEdicion !== null
                    ? 'Montura actualizada correctamente.'
                    : 'Montura registrada correctamente.');

            window.App.notificar(mensaje, 'ok');
            cerrarModal(document.getElementById('modal-montura'));
            cargarMonturas(busquedaActual);
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo guardar la montura.', 'error');
        } finally {
            guardando = false;
            boton.disabled = false;
        }
    }

    /* ============================================================
       Modales base
       ============================================================ */
    function abrirModal(id, inputEnfoque) {
        const modal = document.getElementById(id);
        if (!modal) {
            return;
        }
        modal.hidden = false;
        document.body.classList.add('modal-abierto');
        if (inputEnfoque) {
            const campo = document.getElementById(inputEnfoque);
            if (campo) {
                campo.focus();
            }
        }
    }

    function cerrarModal(modal) {
        modal.hidden = true;
        document.body.classList.remove('modal-abierto');
    }

    return { render: render };
})();