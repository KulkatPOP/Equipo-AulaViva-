'use strict';

// Tutor IA

const tutorForm = document.querySelector('#tutor-form');

if (tutorForm) {

    const campo = tutorForm.elements.pregunta;
    const aula = tutorForm.elements.aula_id;

    const boton = document.querySelector('#tutor-enviar');
    const aviso = document.querySelector('#tutor-estado');
    const historial = document.querySelector('#tutor-historial');
    const respuestas = document.querySelector('#tutor-respuestas');
    const bienvenida = document.querySelector('#tutor-bienvenida');
    const chat = document.querySelector('#tutor-chat');
    const aulaActual = document.querySelector('#tutor-aula-actual');
    const nueva = document.querySelector('#tutor-nueva');

    const asignaturas = [
        ...document.querySelectorAll('.tutor-asignatura')
    ];

    const mensajes = {

        INSUFFICIENT_EVIDENCE:
            'No encontré información suficiente en los materiales disponibles para responder esta pregunta.',

        GENERATION_UNAVAILABLE:
            'El Tutor IA no está disponible en este momento.',

        GENERATION_TIMEOUT:
            'El Tutor IA tardó demasiado en responder. Inténtalo nuevamente.',

        VALIDATION_FAILED:
            'No fue posible validar la respuesta. Intenta nuevamente.',

        NETWORK_ERROR:
            'No se pudo conectar con el Tutor IA. Comprueba tu conexión e inténtalo nuevamente.'

    };

    let consultando = false;

    function cambiarEstado(estado, texto = '') {

        tutorForm.dataset.state = estado;

        aviso.textContent = texto;
    }

    function actualizarBoton() {

        boton.disabled =
            consultando || !campo.value.trim();
    }

    function ajustarCampo() {

        campo.style.height = 'auto';

        campo.style.height =
            Math.min(campo.scrollHeight, 180) + 'px';
    }

    function desplazarChat() {

        chat.scrollTop = chat.scrollHeight;
    }

    function agregarElemento(
        padre,
        etiqueta,
        texto = '',
        clase = ''
    ) {

        const elemento = document.createElement(etiqueta);

        elemento.textContent = texto;

        if (clase) {
            elemento.className = clase;
        }

        padre.append(elemento);

        return elemento;
    }

    function reiniciarConversacion() {

        if (consultando) return;

        respuestas.replaceChildren();

        historial.hidden = true;

        bienvenida.hidden = false;

        campo.value = '';

        cambiarEstado('IDLE');

        ajustarCampo();

        actualizarBoton();

        chat.scrollTop = 0;

        campo.focus();
    }

    nueva.addEventListener(
        'click',
        reiniciarConversacion
    );

    // Seleccionar asignatura

    for (const asignatura of asignaturas) {

        asignatura.addEventListener('click', () => {

            if (consultando) return;

            const nuevoId = asignatura.dataset.aula;

            if (aula.value !== nuevoId) {

                aula.value = nuevoId;

                aulaActual.textContent =
                    asignatura.dataset.nombre;

                for (const elemento of asignaturas) {

                    const activa = elemento === asignatura;

                    elemento.classList.toggle(
                        'activa',
                        activa
                    );

                    elemento.setAttribute(
                        'aria-pressed',
                        String(activa)
                    );
                }

                reiniciarConversacion();
            }

            campo.focus();
        });
    }

    // Mostrar pregunta y estado de carga

    function crearTurno(pregunta, nombreAula) {

        bienvenida.hidden = true;

        historial.hidden = false;

        const turno = document.createElement('li');

        turno.className = 'tutor-turno';

        turno.dataset.state = 'LOADING';

        agregarElemento(
            turno,
            'div',
            pregunta,
            'tutor-mensaje-usuario'
        );

        const respuesta = agregarElemento(
            turno,
            'div',
            '',
            'tutor-mensaje-ia'
        );

        agregarElemento(
            respuesta,
            'p',
            nombreAula,
            'tutor-turno-aula'
        );

        agregarElemento(
            respuesta,
            'h3',
            'Tutor IA'
        );

        const carga = agregarElemento(
            respuesta,
            'div',
            '',
            'tutor-cargando'
        );

        carga.setAttribute('aria-hidden', 'true');

        for (let i = 0; i < 3; i++) {

            agregarElemento(carga, 'span');
        }

        respuestas.append(turno);

        desplazarChat();

        return {
            turno,
            respuesta,
            carga
        };
    }

    // Mostrar respuesta definitiva

    function mostrarResultado(turno, resultado) {

        turno.carga.remove();

        turno.turno.dataset.state = resultado.estado;

        agregarElemento(
            turno.respuesta,
            'p',
            resultado.texto,
            'tutor-respuesta'
        );

        if (resultado.citas?.length) {

            agregarElemento(
                turno.respuesta,
                'h4',
                'Fuentes consultadas'
            );

            const fuentes = agregarElemento(
                turno.respuesta,
                'ul',
                '',
                'tutor-fuentes'
            );

            for (const cita of resultado.citas) {

                agregarElemento(
                    fuentes,
                    'li',
                    'Fragmento ' + cita.fragment_id +
                    ' · Página ' + cita.page
                );
            }
        }

        desplazarChat();
    }

    // Validar respuesta del servidor

    function interpretar(datos, respuestaOk) {

        if (
            datos?.status === 'ANSWERED' &&
            respuestaOk &&
            typeof datos.answer === 'string' &&
            datos.answer.trim() &&
            Array.isArray(datos.citations) &&
            datos.citations.length > 0 &&
            datos.citations.length <= 3 &&
            datos.citations.every(cita =>
                Number.isSafeInteger(cita?.fragment_id) &&
                cita.fragment_id > 0 &&
                Number.isSafeInteger(cita.page) &&
                cita.page > 0
            )
        ) {

            const texto = datos.answer.replace(
                /\s*\[F\d+,\s*p\.\s*\d+\]/g,
                ''
            ).trim();

            if (texto) {

                return {
                    estado: 'ANSWERED',
                    texto,
                    citas: datos.citations
                };
            }
        }

        const estado =
            Object.hasOwn(mensajes, datos?.status) &&
            datos.status !== 'NETWORK_ERROR'

                ? datos.status

                : 'VALIDATION_FAILED';

        return {
            estado,
            texto: mensajes[estado]
        };
    }

    // Campo de escritura

    campo.addEventListener('input', () => {

        ajustarCampo();

        actualizarBoton();
    });

    campo.addEventListener('keydown', evento => {

        if (
            evento.key === 'Enter' &&
            !evento.shiftKey &&
            !evento.isComposing
        ) {

            evento.preventDefault();

            if (!boton.disabled) {

                tutorForm.requestSubmit();
            }
        }
    });

    // Enviar consulta

    tutorForm.addEventListener('submit', async evento => {

        evento.preventDefault();

        if (consultando) return;

        const pregunta = campo.value.trim();

        const aulaId = Number(aula.value);

        if (
            !pregunta ||
            pregunta.length > 1500 ||
            !Number.isSafeInteger(aulaId) ||
            aulaId < 1
        ) {

            campo.focus();

            return;
        }

        const nombreAula = aulaActual.textContent.trim();

        consultando = true;

        campo.value = '';

        campo.readOnly = true;

        nueva.disabled = true;

        for (const asignatura of asignaturas) {

            asignatura.disabled = true;
        }

        ajustarCampo();

        actualizarBoton();

        cambiarEstado(
            'LOADING',
            'Consultando el Tutor IA...'
        );

        const turno = crearTurno(
            pregunta,
            nombreAula
        );

        try {

            let resultado;

            try {

                const respuesta = await fetch(
                    tutorForm.action,
                    {
                        method: 'POST',

                        credentials: 'same-origin',

                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json'
                        },

                        body: JSON.stringify({
                            pregunta,
                            aula_id: aulaId
                        })
                    }
                );

                const datos = await respuesta.json()
                    .catch(() => null);

                resultado = interpretar(
                    datos,
                    respuesta.ok
                );

            } catch {

                resultado = {
                    estado: 'NETWORK_ERROR',
                    texto: mensajes.NETWORK_ERROR
                };
            }

            mostrarResultado(
                turno,
                resultado
            );

            cambiarEstado(
                resultado.estado,
                resultado.texto
            );

            if (
                resultado.estado === 'NETWORK_ERROR' ||
                resultado.estado === 'GENERATION_TIMEOUT' ||
                resultado.estado === 'GENERATION_UNAVAILABLE' ||
                resultado.estado === 'VALIDATION_FAILED'
            ) {

                campo.value = pregunta;
            }

        } finally {

            consultando = false;

            campo.readOnly = false;

            nueva.disabled = false;

            for (const asignatura of asignaturas) {

                asignatura.disabled = false;
            }

            ajustarCampo();

            actualizarBoton();

            if (
                document.activeElement === document.body ||
                document.activeElement === boton
            ) {

                campo.focus();
            }
        }
    });

    ajustarCampo();

    actualizarBoton();
}