/**
 * Módulo de Historias Clínicas: replica la ficha física de refracción
 * (V.L., A.D.D., V.C.) y brinda un visor interactivo de fichas guardadas.
 */
window.Modulos = window.Modulos || {};

Modulos.historias = (function () {
    let contenedor = null;
    let debouncePicker = null;
    let inicializado = false;
    let historiasActuales = [];
    let historiaEnEdicion = null;
    let fichaActual = null;

    const COLUMNAS_MEDIDAS = [
        ['ESF', 'esf'],
        ['CYL', 'cyl'],
        ['EJE', 'eje'],
        ['DIP/DNP', 'dip'],
        ['A.V.', 'av']
    ];

    const OPCIONES_MATERIAL = ['POLICARBONATO', 'CRISTAL', 'RESINA', 'ALTO INDICE', 'OTRO'];
    const OPCIONES_TIPO_LENTE = ['MONOFOCAL', 'BIFOCAL', 'MULTIFOCAL'];

    function escapar(texto) {
        const div = document.createElement('div');
        div.textContent = String(texto === null || texto === undefined ? '' : texto);
        return div.innerHTML;
    }

    function valorMedida(valor) {
        return valor !== null && valor !== undefined && String(valor).trim() !== '' ? escapar(valor) : '—';
    }

    function hoyIso() {
        const ahora = new Date();
        const mes = String(ahora.getMonth() + 1).padStart(2, '0');
        const dia = String(ahora.getDate()).padStart(2, '0');
        return ahora.getFullYear() + '-' + mes + '-' + dia;
    }

    /* ============================================================
       Código de estructura (formulario y ficha)
       ============================================================ */
    function filaMedidas(lado, seccion) {
        let celdas = '';

        COLUMNAS_MEDIDAS.forEach(function (columna) {
            celdas +=
                '<td><input name="' + columna[1] + '_' + lado + '_' + seccion +
                '" class="form-control medida-input" type="text" inputmode="decimal" autocomplete="off"></td>';
        });

        return '<tr><th scope="row">' + (lado === 'od' ? 'O.D.' : 'O.I.') + '</th>' + celdas + '</tr>';
    }

    function filaValores(lado, seccion, historia) {
        let celdas = '';

        COLUMNAS_MEDIDAS.forEach(function (columna) {
            celdas += '<td>' + valorMedida(historia[columna[1] + '_' + lado + '_' + seccion]) + '</td>';
        });

        return '<tr><th scope="row">' + (lado === 'od' ? 'O.D.' : 'O.I.') + '</th>' + celdas + '</tr>';
    }

    function tablaMedidas(seccion, historia) {
        const valores = historia || null;

        return (
            '<table class="tabla tabla-medidas' + (historia ? ' tabla-ficha' : '') + '">' +
            '<thead><tr><th></th>' +
            COLUMNAS_MEDIDAS.map(function (c) { return '<th>' + c[0] + '</th>'; }).join('') +
            '</tr></thead>' +
            '<tbody>' +
            (valores ? filaValores('od', seccion, valores) : filaMedidas('od', seccion)) +
            (valores ? filaValores('oi', seccion, valores) : filaMedidas('oi', seccion)) +
            '</tbody>' +
            '</table>'
        );
    }

    function chipsHtml(nombre, opciones) {
        let html = '<div class="chips" role="radiogroup" aria-label="' + nombre + '">';

        opciones.forEach(function (opcion) {
            html += '<button type="button" class="chip" data-value="' + opcion + '">' + opcion + '</button>';
        });

        html += '</div><input type="hidden" name="' + nombre + '" class="chips-hidden">';
        return html;
    }

    /* ============================================================
       Render principal
       ============================================================ */
    function render(container, parametros) {
        contenedor = container;
        historiaEnEdicion = null;
        fichaActual = null;

        const idCrudo = parametros && parametros[0] ? parametros[0] : null;
        const pacienteId = idCrudo !== null ? parseInt(idCrudo, 10) : NaN;
        const modoEditar = !!(parametros && parametros[1] === 'editar');
        const historiaId = modoEditar && parametros[2] ? parseInt(parametros[2], 10) : NaN;

        contenedor.innerHTML =
            '<h2 class="vista-titulo">Historia Clínica</h2>' +
            '<p class="vista-subtitulo">Ficha de refracción - Óptica Bellavista</p>' +

            /* Sección A: Datos generales */
            '<section class="panel fi-seccion">' +
            '<h3>A. Datos Generales del Paciente</h3>' +
            '<div id="fi-datos-generales">' +
            '<div id="fi-paciente-resumen" class="fi-resumen" hidden></div>' +
            '<div id="fi-picker">' +
            '<div class="buscador">' +
            '<svg class="buscador-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">' +
            '<circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>' +
            '</svg>' +
            '<input id="buscar-paciente-historia" class="form-control" type="search" ' +
            'placeholder="Buscar paciente por nombre o celular..." autocomplete="off">' +
            '</div>' +
            '<div id="fi-picker-lista" class="picker-lista"></div>' +
            '</div>' +
            '</div>' +
            '</section>' +

            '<section class="panel fi-seccion" id="fi-historial-panel" hidden>' +
            '<h3>Historial de Refracción pasado</h3>' +
            '<div id="fi-historial-lista"></div>' +
            '</section>' +

            '<form id="form-historia" novalidate hidden>' +
            '<input type="hidden" name="paciente_id" id="fi-paciente-id">' +

            '<section class="panel fi-seccion fi-fecha">' +
            '<h3>Fecha de Consulta</h3>' +
            '<input name="fecha_consulta" id="fi-fecha-consulta" class="form-control fi-fecha-input" type="date">' +
            '</section>' +

            '<section class="panel fi-seccion">' +
            '<h3>B. Visión Lejos (V.L.)</h3>' +
            tablaMedidas('vl') +
            '</section>' +

            '<section class="panel fi-seccion">' +
            '<h3>C. Adición (A.D.D.)</h3>' +
            '<input name="add_medicion" class="form-control add-input" type="text" inputmode="decimal" ' +
            'placeholder="Ej: +2.00" autocomplete="off">' +
            '</section>' +

            '<section class="panel fi-seccion">' +
            '<h3>D. Visión Cerca (V.C.)</h3>' +
            tablaMedidas('vc') +
            '</section>' +

            '<section class="panel fi-seccion">' +
            '<h3>E. Material y Tratamiento</h3>' +
            '<div class="form-group">' +
            '<label>Material</label>' +
            chipsHtml('material', OPCIONES_MATERIAL) +
            '</div>' +
            '<div class="form-group">' +
            '<label for="fi-tratamiento">Tratamiento</label>' +
            '<input id="fi-tratamiento" name="tratamiento" class="form-control" type="text" ' +
            'placeholder="Ej: Anti-reflex, Blue Block, Fotocromático" autocomplete="off">' +
            '</div>' +
            '<div class="form-group">' +
            '<label>Tipo de Lente</label>' +
            chipsHtml('tipo_lente', OPCIONES_TIPO_LENTE) +
            '</div>' +
            '</section>' +

            '<section class="panel fi-seccion">' +
            '<h3>F. Indicaciones y Próxima Cita</h3>' +
            '<div class="form-group">' +
            '<label for="fi-indicaciones">Indicaciones médicas / técnicas</label>' +
            '<textarea id="fi-indicaciones" name="indicaciones" class="form-control" rows="4" ' +
            'placeholder="Instrucciones de uso, adaptación, cuidados..."></textarea>' +
            '</div>' +
            '<div class="form-group">' +
            '<label for="fi-proxima-cita">Próxima Cita</label>' +
            '<input id="fi-proxima-cita" name="proxima_cita" class="form-control" type="date">' +
            '</div>' +
            '</section>' +

            '<div class="fi-acciones">' +
            '<button id="btn-cancelar-historia" class="btn btn-ghost" type="button">' +
            'Cancelar / Volver' +
            '</button>' +
            '<button id="btn-guardar-historia" class="btn btn-primary btn-lg" type="submit">' +
            'Guardar Historia Clínica' +
            '</button>' +
            '</div>' +
            '</form>';

        inicializar();
        enlazarChips(contenedor);

        const listaHistorial = document.getElementById('fi-historial-lista');
        if (listaHistorial) {
            listaHistorial.addEventListener('click', function (event) {
                const botonFicha = event.target.closest('[data-ver-ficha-id]');
                if (botonFicha) {
                    const id = parseInt(botonFicha.dataset.verFichaId, 10);
                    const historia = historiasActuales.find(function (h) {
                        return Number(h.id) === id;
                    });
                    if (historia) {
                        verFicha(historia);
                    }
                    return;
                }

                const botonEditar = event.target.closest('[data-editar-historia-id]');
                if (botonEditar) {
                    const id = parseInt(botonEditar.dataset.editarHistoriaId, 10);
                    const historia = historiasActuales.find(function (h) {
                        return Number(h.id) === id;
                    });
                    if (historia) {
                        window.App.ir('historias', [String(historia.paciente_id), 'editar', String(historia.id)]);
                    }
                }
            });
        }

        if (Number.isNaN(pacienteId)) {
            enlazarPicker();
        } else {
            document.getElementById('fi-picker').hidden = true;
            prellenarPaciente(pacienteId).then(function () {
                if (modoEditar && !Number.isNaN(historiaId)) {
                    cargarParaEdicion(historiaId);
                }
            });
        }

        const botonCancelar = document.getElementById('btn-cancelar-historia');
        if (botonCancelar) {
            botonCancelar.addEventListener('click', function () {
                const pacienteActual = document.getElementById('fi-paciente-id').value;
                window.App.paramPaciente = pacienteActual ? String(pacienteActual) : null;
                window.App.ir('pacientes');
            });
        }

        document.getElementById('form-historia').addEventListener('submit', guardarHistoria);
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
                if (modal && modal.id === 'modal-ficha') {
                    cerrarFicha();
                }
                return;
            }

            const botonEditarFicha = event.target.closest('#btn-editar-ficha');
            if (botonEditarFicha && fichaActual) {
                cerrarFicha();
                window.App.ir('historias', [
                    String(fichaActual.paciente_id),
                    'editar',
                    String(fichaActual.id)
                ]);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                const ficha = document.getElementById('modal-ficha');
                if (ficha && !ficha.hidden) {
                    cerrarFicha();
                }
            }
        });
    }

    /* ============================================================
       Datos generales del paciente
       ============================================================ */
    function mostrarResumenPaciente(paciente) {
        const resumen = document.getElementById('fi-paciente-resumen');
        resumen.hidden = false;
        resumen.innerHTML =
            '<span><b>Nombre:</b> ' + escapar(paciente.nombres_apellidos) + '</span>' +
            '<span><b>Edad:</b> ' + (paciente.edad === null ? '—' : escapar(paciente.edad)) + '</span>' +
            '<span><b>Celular:</b> ' + escapar(paciente.celular) + '</span>' +
            '<span><b>Ocupación:</b> ' + escapar(paciente.ocupacion) + '</span>';

        document.getElementById('fi-paciente-id').value = String(paciente.id);
        document.getElementById('form-historia').hidden = false;
    }

    function aplicarPaciente(paciente) {
        document.getElementById('fi-picker').hidden = true;
        mostrarResumenPaciente(paciente);
        cargarHistorialPaciente(paciente.id);
    }

    async function prellenarPaciente(id) {
        try {
            const respuesta = await API.get('api/pacientes.php?id=' + Math.floor(Number(id)));

            if (respuesta.status !== 'success' || !respuesta.data) {
                throw new Error(respuesta.message || 'No se pudo cargar el paciente.');
            }

            aplicarPaciente(respuesta.data.paciente);

            const fechaInput = document.getElementById('fi-fecha-consulta');
            if (fechaInput && fechaInput.value === '') {
                fechaInput.value = hoyIso();
            }
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo cargar el paciente.', 'error');
            window.App.ir('pacientes');
        }
    }

    /* ============================================================
       Historial del paciente (solo lectura con visor)
       ============================================================ */
    async function cargarHistorialPaciente(pacienteId) {
        const panel = document.getElementById('fi-historial-panel');
        const lista = document.getElementById('fi-historial-lista');

        try {
            const respuesta = await API.get('api/historias.php?paciente_id=' + pacienteId);
            const historias = respuesta.status === 'success' ? (respuesta.data || []) : [];

            panel.hidden = false;
            historiasActuales = historias;

            if (!historias.length) {
                lista.innerHTML = '<p class="sin-datos">Sin historias clínicas previas.</p>';
                return;
            }

            let html = '<div class="historia-lista">';
            historias.forEach(function (h) {
                html +=
                    '<div class="historia-item">' +
                    '<div class="historia-item-cabezal">' +
                    '<strong>Consulta: ' + escapar(h.fecha_consulta) + '</strong>' +
                    '<span>' + escapar(h.material || '') + (h.tipo_lente ? ' · ' + escapar(h.tipo_lente) : '') + '</span>' +
                    '</div>' +
                    '<div class="historia-medidas">' +
                    '<span><b>V.L. O.D.:</b> ' + valorMedida(h.esf_od_vl) + '</span>' +
                    '<span><b>V.L. O.I.:</b> ' + valorMedida(h.esf_oi_vl) + '</span>' +
                    '<span><b>A.D.D.:</b> ' + valorMedida(h.add_medicion) + '</span>' +
                    '</div>' +
                    '<div class="historia-item-acciones">' +
                    '<button type="button" class="btn btn-ghost btn-sm" data-ver-ficha-id="' + h.id + '">' +
                    '<span aria-hidden="true">👁️</span> Ver Ficha' +
                    '</button>' +
                    '<button type="button" class="btn btn-danger-outline btn-sm" data-editar-historia-id="' + h.id + '">' +
                    'Editar Historia' +
                    '</button>' +
                    '</div>' +
                    '</div>';
            });
            html += '</div>';
            lista.innerHTML = html;
        } catch (error) {
            panel.hidden = false;
            lista.innerHTML = '<p class="sin-datos">' + escapar(error.message || 'No se pudo cargar el historial.') + '</p>';
        }
    }

    /* ============================================================
       Selector de paciente (modo autocompletado)
       ============================================================ */
    async function buscarPacientes(termino) {
        const limpio = termino.trim();
        const url = 'api/pacientes.php' + (limpio === '' ? '' : '?q=' + encodeURIComponent(limpio));
        const slot = document.getElementById('fi-picker-lista');

        try {
            const respuesta = await API.get(url);
            const lista = respuesta.status === 'success' ? (respuesta.data || []) : [];

            if (!lista.length) {
                slot.innerHTML = '<p class="sin-datos">No se encontraron pacientes.</p>';
                return;
            }

            let html = '<div class="picker-resultados" role="listbox">';
            lista.forEach(function (paciente) {
                html +=
                    '<button type="button" class="picker-item" data-id="' + paciente.id + '" role="option" tabindex="0">' +
                    '<strong>' + escapar(paciente.nombres_apellidos) + '</strong>' +
                    '<span>' + escapar(paciente.celular) + ' · ' + (paciente.edad === null ? '—' : escapar(paciente.edad) + ' años') + '</span>' +
                    '</button>';
            });
            html += '</div>';
            slot.innerHTML = html;
        } catch (error) {
            slot.innerHTML = '<p class="sin-datos">' + escapar(error.message || 'Error al buscar.') + '</p>';
        }
    }

    function enlazarPicker() {
        const buscador = document.getElementById('buscar-paciente-historia');

        buscador.addEventListener('input', function () {
            window.clearTimeout(debouncePicker);
            debouncePicker = window.setTimeout(function () {
                buscarPacientes(buscador.value);
            }, 300);
        });

        const lista = document.getElementById('fi-picker-lista');
        lista.addEventListener('click', function (event) {
            const item = event.target.closest('[data-id]');
            if (!item) {
                return;
            }
            const id = parseInt(item.dataset.id, 10);
            if (!Number.isNaN(id)) {
                cargarYSeleccionar(id);
            }
        });

        lista.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                const item = event.target.closest('[data-id]');
                if (item) {
                    event.preventDefault();
                    item.click();
                }
            }
        });

        buscarPacientes('');
    }

    async function cargarYSeleccionar(id) {
        try {
            const respuesta = await API.get('api/pacientes.php?id=' + id);
            if (respuesta.status === 'success' && respuesta.data) {
                aplicarPaciente(respuesta.data.paciente);
                const fechaInput = document.getElementById('fi-fecha-consulta');
                if (fechaInput && fechaInput.value === '') {
                    fechaInput.value = hoyIso();
                }
            } else {
                throw new Error(respuesta.message || 'No se pudo cargar el paciente.');
            }
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo cargar el paciente.', 'error');
        }
    }

    /* ============================================================
       Chips (Material y Tipo de Lente)
       ============================================================ */
    function enlazarChips(raiz) {
        raiz.addEventListener('click', function (event) {
            const chip = event.target.closest('.chip');
            if (!chip) {
                return;
            }
            const grupo = chip.closest('.chips');
            grupo.querySelectorAll('.chip').forEach(function (c) {
                c.classList.toggle('active', c === chip);
            });
            const oculto = grupo.parentElement.querySelector('.chips-hidden');
            if (oculto) {
                oculto.value = chip.dataset.value;
            }
        });
    }

    /* ============================================================
       Visor de ficha de refracción (modal)
       ============================================================ */
    function crearModalFicha() {
        if (document.getElementById('modal-ficha')) {
            return;
        }

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'modal-ficha';
        overlay.hidden = true;
        overlay.innerHTML =
            '<div class="modal modal-lg" role="dialog" aria-modal="true" aria-labelledby="titulo-ficha">' +
            '<div class="modal-header">' +
            '<h2 id="titulo-ficha">Ficha de Refracción</h2>' +
            '<button type="button" class="modal-close" data-cerrar-modal aria-label="Cerrar">&times;</button>' +
            '</div>' +
            '<div class="modal-body" id="ficha-contenido"></div>' +
            '<div class="modal-actions">' +
            '<button type="button" id="btn-editar-ficha" class="btn btn-danger-outline">Editar Historia</button>' +
            '<button type="button" class="btn btn-ghost" data-cerrar-modal>Cerrar</button>' +
            '</div>' +
            '</div>';
        document.body.appendChild(overlay);
    }

    function fichaHtml(historia) {
        const material = historia.material || null;
        const tipoLente = historia.tipo_lente || null;
        const tratamiento = historia.tratamiento || null;
        const indicaciones = historia.indicaciones || null;
        const proximaCita = historia.proxima_cita || null;

        let html =
            '<table class="tabla tabla-ficha-datos"><tbody>' +
            '<tr><th>Fecha de consulta</th><td>' + valorMedida(historia.fecha_consulta) + '</td></tr>' +
            '</tbody></table>' +

            '<h4 class="ficha-titulo">V.L. - Visión Lejos</h4>' +
            tablaMedidas('vl', historia) +

            '<div class="ficha-add">' +
            '<h4 class="ficha-titulo">A.D.D. - Adición</h4>' +
            '<p class="add-valor">' + valorMedida(historia.add_medicion) + '</p>' +
            '</div>' +

            '<h4 class="ficha-titulo">V.C. - Visión Cerca</h4>' +
            tablaMedidas('vc', historia) +

            '<h4 class="ficha-titulo">Material y Tratamiento</h4>' +
            '<p class="ficha-linea">' +
            '<b>Material:</b> ' + valorMedida(material) +
            ' &nbsp;·&nbsp; <b>Tipo de Lente:</b> ' + valorMedida(tipoLente) +
            ' &nbsp;·&nbsp; <b>Tratamiento:</b> ' + valorMedida(tratamiento) +
            '</p>';

        if (indicaciones) {
            html += '<h4 class="ficha-titulo">Indicaciones</h4>' +
                '<p class="ficha-linea">' + escapar(indicaciones) + '</p>';
        }

        if (proximaCita) {
            html += '<h4 class="ficha-titulo">Próxima Cita</h4>' +
                '<p class="ficha-linea">' + valorMedida(proximaCita) + '</p>';
        }

        return html;
    }

    function cerrarFicha() {
        const ficha = document.getElementById('modal-ficha');
        if (ficha) {
            ficha.hidden = true;
            document.body.classList.remove('modal-abierto');
        }
    }

    function verFicha(historia) {
        crearModalFicha();

        fichaActual = historia;

        const overlay = document.getElementById('modal-ficha');
        document.getElementById('ficha-contenido').innerHTML = fichaHtml(historia);
        overlay.hidden = false;
        document.body.classList.add('modal-abierto');
        overlay.querySelector('.modal-close').focus();
    }

    /* ============================================================
       Edición de una historia existente
       ============================================================ */
    function activarChip(nombre, valor) {
        if (!valor) {
            return;
        }
        const oculto = document.querySelector('input[name="' + nombre + '"].chips-hidden');
        if (!oculto) {
            return;
        }
        oculto.value = valor;
        const grupo = oculto.previousElementSibling;
        if (grupo) {
            grupo.querySelectorAll('.chip').forEach(function (chip) {
                chip.classList.toggle('active', chip.dataset.value === valor);
            });
        }
    }

    function rellenarFormulario(historia) {
        const formulario = document.getElementById('form-historia');

        formulario.elements['fecha_consulta'].value = historia.fecha_consulta || '';

        const medidas = [];
        COLUMNAS_MEDIDAS.forEach(function (columna) {
            var base = columna[1];
            ['vl', 'vc'].forEach(function (seccion) {
                ['od', 'oi'].forEach(function (lado) {
                    medidas.push(base + '_' + lado + '_' + seccion);
                });
            });
        });
        medidas.push('add_medicion');
        medidas.push('tratamiento');
        medidas.push('indicaciones');

        medidas.forEach(function (nombre) {
            const campo = formulario.elements[nombre];
            if (campo) {
                const valor = historia[nombre];
                campo.value = valor === null || valor === undefined ? '' : valor;
            }
        });

        if (formulario.elements['proxima_cita']) {
            formulario.elements['proxima_cita'].value = historia.proxima_cita || '';
        }

        activarChip('material', historia.material);
        activarChip('tipo_lente', historia.tipo_lente);
    }

    async function cargarParaEdicion(id) {
        try {
            const respuesta = await API.get('api/historias.php?id=' + Math.floor(Number(id)));

            if (respuesta.status !== 'success' || !respuesta.data) {
                throw new Error(respuesta.message || 'No se pudo cargar la historia clínica.');
            }

            historiaEnEdicion = Number(respuesta.data.id);
            rellenarFormulario(respuesta.data);

            const botonGuardar = document.getElementById('btn-guardar-historia');
            if (botonGuardar) {
                botonGuardar.textContent = 'Guardar Cambios';
            }

            window.App.notificar('Modo edición: corrija los valores y guarde los cambios.', 'info');
        } catch (error) {
            window.App.notificar(error.message || 'No se pudo cargar la historia clínica.', 'error');
            window.App.ir('historias', []);
        }
    }

    /* ============================================================
       Guardado
       ============================================================ */
    async function guardarHistoria(event) {
        event.preventDefault();

        const formulario = document.getElementById('form-historia');
        const datos = {};

        new FormData(formulario).forEach(function (valor, clave) {
            datos[clave] = typeof valor === 'string' ? valor.trim() : valor;
        });

        if (!datos.paciente_id || !datos.fecha_consulta) {
            window.App.notificar('Seleccione un paciente y una fecha de consulta.', 'error');
            return;
        }

        const boton = document.getElementById('btn-guardar-historia');
        boton.disabled = true;

        const editando = historiaEnEdicion !== null;

        if (editando) {
            datos.id = historiaEnEdicion;
        }

        try {
            const respuesta = editando
                ? await API.put('api/historias.php', datos)
                : await API.post('api/historias.php', datos);
            boton.disabled = false;

            if (respuesta.status !== 'success') {
                throw new Error(respuesta.message || 'No se pudo guardar la historia clínica.');
            }

            window.App.notificar(
                editando
                    ? 'Historia clínica actualizada correctamente.'
                    : 'Historia clínica guardada correctamente.',
                'ok'
            );
            window.App.paramPaciente = String(datos.paciente_id);
            window.App.ir('pacientes');
        } catch (error) {
            boton.disabled = false;
            window.App.notificar(error.message || 'No se pudo guardar la historia clínica.', 'error');
        }
    }

    return { render: render, verFicha: verFicha };
})();