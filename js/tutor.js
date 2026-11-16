'use strict';

const tutorForm = document.querySelector('#tutor-form');

if (tutorForm) {
    const campo = tutorForm.elements.pregunta;
    const aula = tutorForm.elements.aula_id;
    const boton = document.querySelector('#tutor-enviar');
    const aviso = document.querySelector('#tutor-estado');
    const historial = document.querySelector('#tutor-historial');
    const respuestas = document.querySelector('#tutor-respuestas');
    const mensajes = {
        INSUFFICIENT_EVIDENCE: 'No encontré información suficiente en los materiales disponibles para responder esta pregunta.',
        GENERATION_UNAVAILABLE: 'El Tutor IA no está disponible en este momento.',
        GENERATION_TIMEOUT: 'El Tutor IA no está disponible en este momento. Inténtalo más tarde',
        VALIDATION_FAILED: 'No fue posible validar la respuesta. Intenta nuevamente.',
        NETWORK_ERROR: 'No se pudo conectar con el Tutor IA. Comprueba tu conexión e inténtalo nuevamente.'
    };

    let consultando = false;

    function cambiarEstado(estado, texto = '') {
        tutorForm.dataset.state = estado;
        aviso.textContent = texto;
    }

    function actualizarBoton() {
        boton.disabled = consultando || !campo.value.trim();
    }

    function agregarElemento(padre, etiqueta, texto, clase = '') {
        const elemento = document.createElement(etiqueta);
        elemento.textContent = texto;
        if (clase) elemento.className = clase;
        padre.append(elemento);
        return elemento;
    }

    function mostrar(pregunta, nombreAula, estado, respuesta, citas = []) {
        const turno = document.createElement('li');
        turno.className = 'curso tutor-turno';
        turno.dataset.state = estado;
        if (nombreAula) agregarElemento(turno, 'p', nombreAula, 'tutor-turno-aula');
        agregarElemento(turno, 'h3', 'Tu pregunta');
        agregarElemento(turno, 'p', pregunta);
        agregarElemento(turno, 'h3', 'Tutor IA');
        agregarElemento(turno, 'p', respuesta, 'tutor-respuesta');
        if (citas.length) {
            agregarElemento(turno, 'h4', 'Fuentes');
            const fuentes = agregarElemento(turno, 'ul', '', 'tutor-fuentes');
            for (const cita of citas) {
                agregarElemento(fuentes, 'li', '[F' + cita.fragment_id + ', p. ' + cita.page + ']');
            }
        }
        historial.hidden = false;
        respuestas.append(turno);
    }

    function interpretar(datos, respuestaOk) {
        if (datos?.status === 'ANSWERED' && respuestaOk && typeof datos.answer === 'string' && datos.answer.trim()
            && Array.isArray(datos.citations) && datos.citations.length > 0 && datos.citations.length <= 3
            && datos.citations.every(cita => Number.isSafeInteger(cita?.fragment_id) && cita.fragment_id > 0
                && Number.isSafeInteger(cita.page) && cita.page > 0)) {
            // Las etiquetas en línea son locales a la consulta. La lista usa los IDs validados que devuelve el servidor.
            const texto = datos.answer.replace(/\s*\[F\d+,\s*p\.\s*\d+\]/g, '').trim();
            if (texto) return { estado: 'ANSWERED', texto, citas: datos.citations };
        }
        const estado = Object.hasOwn(mensajes, datos?.status) && datos.status !== 'NETWORK_ERROR'
            ? datos.status : 'VALIDATION_FAILED';
        return { estado, texto: mensajes[estado] };
    }

    campo.addEventListener('input', actualizarBoton);
    tutorForm.addEventListener('submit', async evento => {
        evento.preventDefault();
        if (consultando) return;
        const pregunta = campo.value.trim();
        const aulaId = Number(aula.value);
        if (!pregunta || !Number.isSafeInteger(aulaId) || aulaId < 1) {
            campo.focus();
            return;
        }

        const nombreAula = aula.selectedOptions?.[0]?.textContent ?? '';
        consultando = true;
        campo.readOnly = true;
        aula.disabled = true;
        actualizarBoton();
        cambiarEstado('LOADING', 'Consultando el Tutor IA...');

        try {
            let respuesta;
            try {
                respuesta = await fetch(tutorForm.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ pregunta, aula_id: aulaId })
                });
            } catch {
                cambiarEstado('NETWORK_ERROR', mensajes.NETWORK_ERROR);
                mostrar(pregunta, nombreAula, 'NETWORK_ERROR', mensajes.NETWORK_ERROR);
                return;
            }
            const datos = await respuesta.json().catch(() => null);
            const resultado = interpretar(datos, respuesta.ok);
            cambiarEstado(resultado.estado, resultado.texto);
            mostrar(pregunta, nombreAula, resultado.estado, resultado.texto, resultado.citas);
            if (resultado.estado === 'ANSWERED') campo.value = '';
        } finally {
            consultando = false;
            campo.readOnly = false;
            aula.disabled = false;
            actualizarBoton();
            if (document.activeElement === document.body || document.activeElement === boton) campo.focus();
        }
    });
    actualizarBoton();
}
