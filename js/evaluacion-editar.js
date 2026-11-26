'use strict';

// Elementos del formulario

const formulario = document.querySelector('#form-evaluacion');
const campos = document.querySelector('#campos-evaluacion');
const titulo = document.querySelector('#titulo-evaluacion');
const desde = document.querySelector('#disponible-desde');
const hasta = document.querySelector('#disponible-hasta');
const fechaDesde = document.querySelector('#fecha-desde');
const horaDesde = document.querySelector('#hora-desde');
const fechaHasta = document.querySelector('#fecha-hasta');
const horaHasta = document.querySelector('#hora-hasta');
const lista = document.querySelector('#preguntas-evaluacion');
const estado = document.querySelector('#estado-evaluacion');
const ruta = new URL(location.href);

let borrador = JSON.parse(formulario.dataset.borrador);

let clave = 'us02-cifrado:' + formulario.dataset.clave
    + ':' + (borrador.id || 'nuevo');

const claveCifrado = crypto.subtle?.importKey(
    'raw',
    Uint8Array.from(
        atob(formulario.dataset.cifrado),
        c => c.charCodeAt(0)
    ),
    'AES-GCM',
    false,
    ['encrypt', 'decrypt']
);

delete formulario.dataset.cifrado;

// Cifrado de borradores

function parametrosCifrado(iv) {
    return {
        name: 'AES-GCM',
        iv,
        additionalData: new TextEncoder().encode(clave)
    };
}

async function leerLocal() {
    try {
        const copia = JSON.parse(localStorage.getItem(clave));

        if (!copia || !claveCifrado) {
            return null;
        }

        const iv = Uint8Array.from(copia.iv);

        const datos = Uint8Array.from(
            atob(copia.datos),
            c => c.charCodeAt(0)
        );

        const texto = await crypto.subtle.decrypt(
            parametrosCifrado(iv),
            await claveCifrado,
            datos
        );

        const local = JSON.parse(
            new TextDecoder().decode(texto)
        );

        return borrador.id && borrador.id !== local.id
            ? null
            : local;

    } catch {
        return null;
    }
}

// Lectura del formulario

// Creación de botones

function boton(texto, accion, clase = 'av-editor-add-option') {
    const elemento = document.createElement('button');

    elemento.type = 'button';
    elemento.className = clase;
    elemento.textContent = texto;

    elemento.addEventListener('click', accion);

    return elemento;
}

// Botón de eliminación

function botonEliminar(texto, accion) {
    const elemento = document.createElement('button');

    elemento.type = 'button';
    elemento.className = 'av-editor-delete';
    elemento.title = texto;
    elemento.setAttribute('aria-label', texto);

    elemento.innerHTML = `
        <svg viewBox="0 0 24 24"
             width="20"
             height="20"
             fill="none"
             stroke="currentColor"
             stroke-width="1.7"
             stroke-linecap="round"
             stroke-linejoin="round"
             aria-hidden="true">
            <path d="M3 6h18M8 6V4h8v2"/>
            <path d="M5 6l1 14h12l1-14"/>
            <path d="M10 10v6M14 10v6"/>
        </svg>
    `;

    elemento.addEventListener('click', accion);

    return elemento;
}

// Creación de campos

function campo(padre, texto, tipo, valor) {
    const etiqueta = document.createElement('label');

    etiqueta.className = 'av-editor-field';

    const nombre = document.createElement('span');

    nombre.textContent = texto;

    const input = document.createElement('input');

    input.type = tipo;

    if (tipo === 'checkbox') {
        input.checked = valor === true;
    } else {
        input.value = valor ?? '';
    }

    etiqueta.append(nombre, input);
    padre.append(etiqueta);

    return input;
}

// Numeración de alternativas

function actualizarAlternativas(padre) {
    const filas = Array.from(padre.children);

    filas.forEach((fila, indice) => {
        const letra = indice < 26
            ? String.fromCharCode(65 + indice)
            : String(indice + 1);

        fila.querySelector('.av-editor-option-letter')
            .textContent = letra + '.';

        const input = fila.querySelector('input[type="text"]');

        input.placeholder = 'Alternativa ' + letra;

        input.setAttribute(
            'aria-label',
            'Alternativa ' + letra
        );

        fila.querySelector('.av-editor-checkbox')
            .setAttribute(
                'aria-label',
                'Marcar alternativa ' + letra + ' como correcta'
            );

        fila.querySelector('.av-editor-delete').disabled =
            filas.length <= 2;
    });
}

function actualizarCorrectas(padre) {
    padre.querySelectorAll('.av-editor-option')
        .forEach(fila => {
            const correcta = fila.querySelector(
                '.av-editor-checkbox'
            );

            fila.classList.toggle(
                'av-editor-option-correct',
                correcta.checked
            );
        });
}

// Alternativas

function agregarAlternativa(
    padre,
    alternativa = {
        texto: '',
        es_correcta: false
    }
) {
    const fila = document.createElement('div');
    fila.className = 'panel-alternativa av-editor-option';

    const correcta = document.createElement('input');

    correcta.type = padre.dataset.tipo === 'multiple'
        ? 'checkbox'
        : 'radio';

    correcta.name = padre.dataset.grupo;
    correcta.className = 'av-editor-checkbox';
    correcta.checked = alternativa.es_correcta === true;

    const letra = document.createElement('span');
    letra.className = 'av-editor-option-letter';

    const texto = document.createElement('input');

    texto.type = 'text';
    texto.className = 'av-editor-option-input';
    texto.value = alternativa.texto ?? '';

    correcta.addEventListener('change', () => {
        actualizarCorrectas(padre);
    });

    const eliminar = botonEliminar(
        'Eliminar alternativa',
        () => {
            if (padre.children.length <= 2) {
                return;
            }

            fila.remove();
            actualizarAlternativas(padre);
            actualizarCorrectas(padre);
        }
    );

    fila.append(correcta, letra, texto, eliminar);
    padre.append(fila);

    actualizarAlternativas(padre);
    actualizarCorrectas(padre);
}

// Contador y puntaje total

function actualizarPreguntas() {
    const preguntas = Array.from(lista.children);

    let total = 0;

    preguntas.forEach((pregunta, indice) => {
        const nombre = pregunta.querySelector(
            '.av-editor-question-title'
        );

        nombre.textContent = 'Pregunta ' + (indice + 1);

        const puntaje = pregunta.querySelector(
            'input[type="number"]'
        );

        const valor = Number(puntaje.value);

        if (Number.isFinite(valor) && valor >= 0) {
            total += valor;
        }
    });

    document.querySelector('#contador-preguntas')
        .textContent = preguntas.length
        + (preguntas.length === 1
            ? ' pregunta'
            : ' preguntas');

    document.querySelector('#puntaje-total')
        .textContent = total.toLocaleString('es-CL', {
            maximumFractionDigits: 10
        }) + ' puntos';
}

// Preguntas

function agregarPregunta(
    pregunta = {
        enunciado: '',
        puntaje: '10',
        alternativas: [
            { texto: '', es_correcta: false },
            { texto: '', es_correcta: false },
            { texto: '', es_correcta: false },
            { texto: '', es_correcta: false }
        ]
    }
) {
    const bloque = document.createElement('article');
    bloque.className = 'av-editor-card av-editor-question';

    // Encabezado

    const encabezado = document.createElement('div');
    encabezado.className = 'av-editor-question-header';

    const nombre = document.createElement('h3');
    nombre.className = 'av-editor-question-title';

    const eliminar = botonEliminar(
        'Eliminar pregunta',
        () => {
            bloque.remove();
            actualizarPreguntas();
        }
    );

    encabezado.append(nombre, eliminar);
    bloque.append(encabezado);

    // Enunciado

    const etiqueta = document.createElement('label');
    etiqueta.className = 'av-editor-field';

    const textoEtiqueta = document.createElement('span');
    textoEtiqueta.textContent = 'Enunciado de la pregunta';

    const enunciado = document.createElement('textarea');

    enunciado.className = 'av-editor-enunciado';
    enunciado.rows = 3;
    enunciado.placeholder = 'Escribe la pregunta...';
    enunciado.value = pregunta.enunciado ?? '';

    etiqueta.append(textoEtiqueta, enunciado);
    bloque.append(etiqueta);

    // Configuración de la pregunta

    const configuracion = document.createElement('div');
    configuracion.className = 'av-editor-question-config';

    const puntaje = campo(
        configuracion,
        'Puntaje',
        'number',
        pregunta.puntaje
    );

    puntaje.step = 'any';
    puntaje.min = '0';

    puntaje.addEventListener(
        'input',
        actualizarPreguntas
    );

    // Tipo de respuesta

    const etiquetaTipo = document.createElement('label');
    etiquetaTipo.className = 'av-editor-field';

    const nombreTipo = document.createElement('span');
    nombreTipo.textContent = 'Respuestas correctas';

    const tipo = document.createElement('select');
    tipo.className = 'av-editor-answer-type';

    tipo.add(new Option('Una respuesta correcta', '1'));
    tipo.add(new Option('Dos o más respuestas correctas', 'multiple'));

    const cantidadCorrectas = pregunta.alternativas.filter(
        alternativa => alternativa.es_correcta === true
    ).length;

    tipo.value = pregunta.tipo_respuesta === 'multiple'
        || cantidadCorrectas > 1
        ? 'multiple'
        : '1';

    etiquetaTipo.append(nombreTipo, tipo);
    configuracion.append(etiquetaTipo);
    bloque.append(configuracion);

    // Alternativas

    const tituloAlternativas = document.createElement('h4');
    tituloAlternativas.className = 'av-editor-options-title';
    tituloAlternativas.textContent = 'Alternativas';

    const ayuda = document.createElement('p');
    ayuda.className = 'av-editor-help';

    const alternativas = document.createElement('div');

    alternativas.className = 'av-editor-options';
    alternativas.dataset.tipo = tipo.value;
    alternativas.dataset.grupo = 'correcta-' + crypto.randomUUID();

    function actualizarTipo() {
        alternativas.dataset.tipo = tipo.value;

        const multiple = tipo.value === 'multiple';

        ayuda.textContent = multiple
            ? 'Marca al menos dos respuestas correctas.'
            : 'Marca una sola respuesta correcta.';

        const opciones = [
            ...alternativas.querySelectorAll('.av-editor-checkbox')
        ];

        const seleccionadas = opciones.filter(
            opcion => opcion.checked
        );

        opciones.forEach(opcion => {
            opcion.checked = false;
            opcion.type = multiple ? 'checkbox' : 'radio';
        });

        const conservar = multiple
            ? seleccionadas
            : seleccionadas.slice(0, 1);

        conservar.forEach(opcion => {
            opcion.checked = true;
        });

        actualizarCorrectas(alternativas);
    }

    bloque.append(
        tituloAlternativas,
        ayuda,
        alternativas
    );

    pregunta.alternativas.forEach(alternativa => {
        agregarAlternativa(alternativas, alternativa);
    });

    while (alternativas.children.length < 2) {
        agregarAlternativa(alternativas);
    }

    tipo.addEventListener('change', actualizarTipo);

    actualizarTipo();

    bloque.append(
        boton(
            '+ Agregar alternativa',
            () => agregarAlternativa(alternativas)
        )
    );

    lista.append(bloque);
    actualizarPreguntas();
}
// Disponibilidad de la evaluación

function sincronizarFechas() {
    desde.value = fechaDesde.value && horaDesde.value
        ? `${fechaDesde.value}T${horaDesde.value}`
        : '';

    hasta.value = fechaHasta.value && horaHasta.value
        ? `${fechaHasta.value}T${horaHasta.value}`
        : '';
}

function cargarFechas() {
    const [inicioFecha = '', inicioHora = ''] =
        (desde.value || '').split('T');

    const [terminoFecha = '', terminoHora = ''] =
        (hasta.value || '').split('T');

    fechaDesde.value = inicioFecha;
    horaDesde.value = inicioHora.slice(0, 5);

    fechaHasta.value = terminoFecha;
    horaHasta.value = terminoHora.slice(0, 5);
}
// Lectura del formulario

function leerFormulario() {
    sincronizarFechas();

    return {
        id: borrador.id,
        titulo: titulo.value,
        disponible_desde: desde.value,
        disponible_hasta: hasta.value,

        preguntas: Array.from(
            lista.children,
            pregunta => ({
                enunciado: pregunta.querySelector(
                    '.av-editor-enunciado'
                ).value,

                puntaje: pregunta.querySelector(
                    'input[type="number"]'
                ).value || '0',

                tipo_respuesta: pregunta.querySelector(
                    '.av-editor-answer-type'
                ).value,

                alternativas: Array.from(
                    pregunta.querySelectorAll('.panel-alternativa'),
                    alternativa => ({
                        texto: alternativa.querySelector(
                            'input[type="text"]'
                        ).value,

                        es_correcta: alternativa.querySelector(
                            '.av-editor-checkbox'
                        ).checked
                    })
                )
            })
        )
    };
}
// Validación de fechas

// Validación de fechas

function validarFechas(datos, publicar) {
    const camposFecha = [
        fechaDesde,
        horaDesde,
        fechaHasta,
        horaHasta
    ];

    const completos = camposFecha.every(
        campo => campo.value !== ''
    );

    const vacios = camposFecha.every(
        campo => campo.value === ''
    );

    if (!publicar && vacios) {
        return true;
    }

    if (!completos) {
        estado.textContent =
            'Completa las fechas y horas de inicio y término.';

        camposFecha.find(campo => !campo.value)?.focus();

        return false;
    }

    if (datos.disponible_hasta <= datos.disponible_desde) {
        estado.textContent =
            'La fecha y hora de término deben ser posteriores al inicio.';

        fechaHasta.focus();

        return false;
    }

    return true;
}

// Guardado local cifrado

async function guardarLocal(datos, desconectado) {
    try {
        if (!claveCifrado) {
            throw new Error('Cifrado no disponible');
        }

        const iv = crypto.getRandomValues(
            new Uint8Array(12)
        );

        const texto = new TextEncoder().encode(
            JSON.stringify(datos)
        );

        const cifrado = await crypto.subtle.encrypt(
            parametrosCifrado(iv),
            await claveCifrado,
            texto
        );

        const contenido = Array.from(
            new Uint8Array(cifrado),
            byte => String.fromCharCode(byte)
        ).join('');

        localStorage.setItem(
            clave,
            JSON.stringify({
                iv: Array.from(iv),
                datos: btoa(contenido)
            })
        );

        estado.textContent = desconectado
            ? 'Sin conexión. Guardado en este dispositivo. Se intentará guardar al reconectar.'
            : 'Guardado en este dispositivo.';

    } catch {
        estado.textContent =
            'No se pudo guardar en este dispositivo. Mantén abierto el formulario.';
    }
}

// Guardado en el servidor

function validarTiposRespuesta(datos) {
    for (const [indice, pregunta] of datos.preguntas.entries()) {

        const correctas = pregunta.alternativas.filter(
            alternativa => alternativa.es_correcta
        ).length;

        if (
            pregunta.tipo_respuesta === '1'
            && correctas !== 1
        ) {
            estado.textContent =
                `La pregunta ${indice + 1} debe tener una respuesta correcta.`;

            return false;
        }

        if (
            pregunta.tipo_respuesta === 'multiple'
            && correctas < 2
        ) {
            estado.textContent =
                `La pregunta ${indice + 1} debe tener al menos dos respuestas correctas.`;

            return false;
        }
    }

    return true;
}


let guardando = false;

async function guardar(publicar) {
    if (guardando) {
        return;
    }

    const datos = leerFormulario();

    if (!validarFechas(datos, publicar)) {
        return;
    }
if (publicar && !validarTiposRespuesta(datos)) {
    return;
}
    guardando = true;
    campos.disabled = true;

    try {
        if (!publicar) {
            await guardarLocal(datos, !navigator.onLine);
        }

        if (!navigator.onLine) {
            if (publicar) {
                await guardarLocal(datos, true);

                estado.textContent =
                    'Reconecta para publicar la evaluación.';
            }

            return;
        }

        let respuesta;

        try {
            respuesta = await fetch(ruta, {
                method: 'POST',

                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': formulario.dataset.csrf
                },

                body: JSON.stringify({
                    ...datos,
                    publicar
                }),

                redirect: 'error'
            });

        } catch {
            await guardarLocal(datos, true);
            return;
        }

        const resultado = await respuesta.json();

        if (!respuesta.ok) {
            estado.textContent =
                resultado.error || 'No se pudo guardar la evaluación.';

            return;
        }

        try {
            localStorage.removeItem(clave);
        } catch {
            // El servidor ya confirmó la operación.
        }

        if (publicar) {
            location.assign(
                'evaluaciones.php?aula_id='
                + ruta.searchParams.get('aula_id')
                + '&id='
                + encodeURIComponent(resultado.id)
            );

            return;
        }

        borrador = datos;

        ruta.searchParams.set('id', resultado.id);

        history.replaceState(null, '', ruta);

        clave = 'us02-cifrado:'
            + formulario.dataset.clave
            + ':'
            + resultado.id;

        estado.textContent = 'Borrador guardado correctamente.';

    } catch {
        estado.textContent =
            'No se pudo confirmar el guardado. Vuelve a abrir el formulario si la sesión caducó.';

    } finally {
        guardando = false;
        campos.disabled = false;
    }
}

// Apertura del editor

async function abrirEditor() {
    const local = await leerLocal();

    if (local) {
        borrador = local;
    }

    if (!borrador.id) {
        borrador.id = crypto.randomUUID();
    }

    titulo.value = borrador.titulo;

    desde.value = borrador.disponible_desde ?? '';
    hasta.value = borrador.disponible_hasta ?? '';
    cargarFechas();

    if (borrador.preguntas.length > 0) {
    borrador.preguntas.forEach(agregarPregunta);
    } else if (!local && !ruta.searchParams.has('id')) {
        agregarPregunta();
    }

actualizarPreguntas();

    formulario.hidden = false;

    document.querySelector('#agregar-pregunta')
        .addEventListener('click', () => {
            agregarPregunta();
        });

    formulario.addEventListener('submit', evento => {
        evento.preventDefault();

        void guardar(
            evento.submitter?.value === 'publicar'
        );
    });

    window.addEventListener('online', async () => {
        if (await leerLocal()) {
            void guardar(false);
        }
    });

    if (local) {
        estado.textContent = 'Guardado en este dispositivo.';

        if (navigator.onLine) {
            void guardar(false);
        }
    }
}

// Protección de información al salir

window.addEventListener('pagehide', () => {
    formulario.hidden = true;

    titulo.value = '';
    desde.value = '';
    hasta.value = '';
    fechaDesde.value = '';
    horaDesde.value = '';
    fechaHasta.value = '';
    horaHasta.value = '';

    lista.replaceChildren();
});

window.addEventListener('pageshow', evento => {
    if (evento.persisted) {
        location.reload();
    }
});

window.addEventListener('storage', evento => {
    if (evento.key === 'us02-salida') {
        location.reload();
    }
});

void abrirEditor();
