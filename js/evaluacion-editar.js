'use strict';

const formulario = document.querySelector('#form-evaluacion');
const campos = document.querySelector('#campos-evaluacion');
const titulo = document.querySelector('#titulo-evaluacion');
const lista = document.querySelector('#preguntas-evaluacion');
const estado = document.querySelector('#estado-evaluacion');
const sinServidor = formulario.dataset.offline === 'true';
const ruta = new URL(location.href);
const sesion = 'us02-editor:' + ruta.search;
let borrador;
let clave;

function leerLocal() {
    try {
        return JSON.parse(localStorage.getItem(clave));
    } catch {
        return null;
    }
}

if (sinServidor) {
    try {
        clave = sessionStorage.getItem(sesion);
        borrador = clave && leerLocal();
    } catch {
        borrador = null;
    }
    window.addEventListener('online', () => location.reload());
    if (!borrador) {
        estado.textContent = 'Sin conexión. Reconecta para abrir el formulario.';
    }
} else {
    borrador = JSON.parse(formulario.dataset.borrador);
    clave = formulario.dataset.clave + ':' + (borrador.id || 'nuevo');
    try {
        sessionStorage.setItem(sesion, clave);
    } catch {
        // El guardado en el servidor sigue disponible.
    }
    const local = leerLocal();
    if (local) {
        borrador = local;
    } else if (!borrador.id) {
        borrador.id = crypto.randomUUID();
    }
}

function boton(texto, accion) {
    const elemento = document.createElement('button');
    elemento.type = 'button';
    elemento.className = 'panel-boton';
    elemento.textContent = texto;
    elemento.addEventListener('click', accion);
    return elemento;
}

function campo(padre, texto, tipo, valor) {
    const etiqueta = document.createElement('label');
    etiqueta.append(texto + ' ');
    const input = document.createElement('input');
    input.type = tipo;
    if (tipo === 'checkbox') {
        input.checked = valor === true;
    } else {
        input.value = valor;
    }
    etiqueta.append(input);
    padre.append(etiqueta);
    return input;
}

function agregarAlternativa(padre, alternativa = { texto: '', es_correcta: false }) {
    const fila = document.createElement('div');
    fila.className = 'panel-alternativa';
    campo(fila, 'Alternativa', 'text', alternativa.texto);
    campo(fila, 'Correcta', 'checkbox', alternativa.es_correcta);
    fila.append(boton('Quitar alternativa', () => fila.remove()));
    padre.append(fila);
}

function agregarPregunta(pregunta = { enunciado: '', puntaje: '0', alternativas: [] }) {
    const bloque = document.createElement('fieldset');
    bloque.className = 'panel-pregunta';
    const leyenda = document.createElement('legend');
    leyenda.textContent = 'Pregunta';
    bloque.append(leyenda);
    campo(bloque, 'Enunciado', 'text', pregunta.enunciado);
    campo(bloque, 'Puntaje', 'number', pregunta.puntaje).step = 'any';
    const alternativas = document.createElement('div');
    alternativas.className = 'panel-alternativas';
    bloque.append(alternativas);
    const opciones = pregunta.alternativas;
    opciones.forEach(alternativa => agregarAlternativa(alternativas, alternativa));
    bloque.append(boton('Agregar alternativa', () => agregarAlternativa(alternativas)));
    bloque.append(boton('Quitar pregunta', () => bloque.remove()));
    lista.append(bloque);
}

function leerFormulario() {
    return {
        id: borrador.id,
        titulo: titulo.value,
        preguntas: Array.from(lista.children, pregunta => ({
            enunciado: pregunta.querySelector('input[type=text]').value,
            puntaje: pregunta.querySelector('input[type=number]').value || '0',
            alternativas: Array.from(pregunta.querySelectorAll('.panel-alternativa'), alternativa => ({
                texto: alternativa.querySelector('input[type=text]').value,
                es_correcta: alternativa.querySelector('input[type=checkbox]').checked
            }))
        }))
    };
}

function guardarLocal(datos, desconectado) {
    try {
        localStorage.setItem(clave, JSON.stringify(datos));
        estado.textContent = desconectado
            ? 'Sin conexión. Guardado en este dispositivo. Se intentará guardar al reconectar.'
            : 'Guardado en este dispositivo';
        return true;
    } catch {
        estado.textContent = 'No se pudo guardar en este dispositivo. Mantén abierto el formulario.';
        return false;
    }
}

let guardando = false;
async function guardar(publicar) {
    if (guardando) return;
    const datos = leerFormulario();
    if (!publicar) guardarLocal(datos, sinServidor || !navigator.onLine);
    if (sinServidor || !navigator.onLine) {
        if (publicar) estado.textContent = 'Reconecta para publicar la evaluación.';
        return;
    }

    guardando = true;
    campos.disabled = true;
    try {
        let respuesta;
        try {
            respuesta = await fetch(ruta, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': formulario.dataset.csrf },
                body: JSON.stringify({ ...datos, publicar }),
                redirect: 'error'
            });
        } catch {
            guardarLocal(datos, true);
            return;
        }
        const resultado = await respuesta.json();
        if (!respuesta.ok) {
            estado.textContent = resultado.error;
            return;
        }
        try {
            localStorage.removeItem(clave);
        } catch {
            // El servidor ya confirmó la operación.
        }
        if (publicar) {
            location.assign('evaluaciones.php?aula_id=' + ruta.searchParams.get('aula_id') + '&id=' + resultado.id);
            return;
        }
        ruta.searchParams.set('id', resultado.id);
        history.replaceState(null, '', ruta);
        clave = formulario.dataset.clave + ':' + resultado.id;
        try {
            sessionStorage.setItem('us02-editor:' + ruta.search, clave);
        } catch {
            // La URL conserva el acceso al borrador del servidor.
        }
        estado.textContent = 'Guardado';
    } catch {
        estado.textContent = 'No se pudo confirmar el guardado. Vuelve a abrir el formulario si la sesión caducó.';
    } finally {
        guardando = false;
        campos.disabled = false;
    }
}

if (borrador) {
    titulo.value = borrador.titulo;
    borrador.preguntas.forEach(agregarPregunta);
    formulario.hidden = false;
    document.querySelector('#agregar-pregunta').addEventListener('click', () => agregarPregunta());
    formulario.addEventListener('submit', evento => {
        evento.preventDefault();
        void guardar(evento.submitter?.value === 'publicar');
    });
    if (!sinServidor) {
        window.addEventListener('online', () => {
            if (leerLocal()) void guardar(false);
        });
        if (leerLocal()) {
            estado.textContent = 'Guardado en este dispositivo';
            if (navigator.onLine) void guardar(false);
        }
    } else {
        estado.textContent = 'Sin conexión. Guardado en este dispositivo.';
    }
}

if (!sinServidor && 'serviceWorker' in navigator) {
    navigator.serviceWorker.register('evaluaciones-offline.js').catch(() => {
        // El almacenamiento local sigue funcionando en el formulario abierto.
    });
}
