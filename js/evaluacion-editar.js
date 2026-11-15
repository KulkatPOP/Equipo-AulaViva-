'use strict';

const formulario = document.querySelector('#form-evaluacion');
const campos = document.querySelector('#campos-evaluacion');
const titulo = document.querySelector('#titulo-evaluacion');
const lista = document.querySelector('#preguntas-evaluacion');
const estado = document.querySelector('#estado-evaluacion');
const ruta = new URL(location.href);
let borrador = JSON.parse(formulario.dataset.borrador);
let clave = 'us02-cifrado:' + formulario.dataset.clave + ':' + (borrador.id || 'nuevo');
const claveCifrado = crypto.subtle?.importKey(
    'raw', Uint8Array.from(atob(formulario.dataset.cifrado), c => c.charCodeAt(0)),
    'AES-GCM', false, ['encrypt', 'decrypt']
);
delete formulario.dataset.cifrado;

function parametrosCifrado(iv) {
    return { name: 'AES-GCM', iv, additionalData: new TextEncoder().encode(clave) };
}

async function leerLocal() {
    try {
        const copia = JSON.parse(localStorage.getItem(clave));
        if (!copia || !claveCifrado) return null;
        const iv = Uint8Array.from(copia.iv);
        const datos = Uint8Array.from(atob(copia.datos), c => c.charCodeAt(0));
        const texto = await crypto.subtle.decrypt(parametrosCifrado(iv), await claveCifrado, datos);
        const local = JSON.parse(new TextDecoder().decode(texto));
        return borrador.id && borrador.id !== local.id ? null : local;
    } catch {
        return null;
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

async function guardarLocal(datos, desconectado) {
    try {
        if (!claveCifrado) throw new Error('Cifrado no disponible');
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const texto = new TextEncoder().encode(JSON.stringify(datos));
        const cifrado = await crypto.subtle.encrypt(parametrosCifrado(iv), await claveCifrado, texto);
        const contenido = Array.from(new Uint8Array(cifrado), byte => String.fromCharCode(byte)).join('');
        localStorage.setItem(clave, JSON.stringify({ iv: Array.from(iv), datos: btoa(contenido) }));
        estado.textContent = desconectado
            ? 'Sin conexión. Guardado en este dispositivo. Se intentará guardar al reconectar.'
            : 'Guardado en este dispositivo';
    } catch {
        estado.textContent = 'No se pudo guardar en este dispositivo. Mantén abierto el formulario.';
    }
}

let guardando = false;
async function guardar(publicar) {
    if (guardando) return;
    guardando = true;
    campos.disabled = true;
    const datos = leerFormulario();
    try {
        if (!publicar) await guardarLocal(datos, !navigator.onLine);
        if (!navigator.onLine) {
            if (publicar) estado.textContent = 'Reconecta para publicar la evaluación.';
            return;
        }

        let respuesta;
        try {
            respuesta = await fetch(ruta, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': formulario.dataset.csrf },
                body: JSON.stringify({ ...datos, publicar }),
                redirect: 'error'
            });
        } catch {
            await guardarLocal(datos, true);
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
        clave = 'us02-cifrado:' + formulario.dataset.clave + ':' + resultado.id;
        estado.textContent = 'Guardado';
    } catch {
        estado.textContent = 'No se pudo confirmar el guardado. Vuelve a abrir el formulario si la sesión caducó.';
    } finally {
        guardando = false;
        campos.disabled = false;
    }
}

async function abrirEditor() {
    const local = await leerLocal();
    if (local) borrador = local;
    if (!borrador.id) borrador.id = crypto.randomUUID();
    titulo.value = borrador.titulo;
    borrador.preguntas.forEach(agregarPregunta);
    formulario.hidden = false;
    document.querySelector('#agregar-pregunta').addEventListener('click', () => agregarPregunta());
    formulario.addEventListener('submit', evento => {
        evento.preventDefault();
        void guardar(evento.submitter?.value === 'publicar');
    });
    window.addEventListener('online', async () => {
        if (await leerLocal()) void guardar(false);
    });
    if (local) {
        estado.textContent = 'Guardado en este dispositivo';
        if (navigator.onLine) void guardar(false);
    }
}

window.addEventListener('pagehide', () => {
    formulario.hidden = true;
    titulo.value = '';
    lista.replaceChildren();
});
window.addEventListener('pageshow', evento => {
    if (evento.persisted) location.reload();
});
window.addEventListener('storage', evento => {
    if (evento.key === 'us02-salida') location.reload();
});
void abrirEditor();
