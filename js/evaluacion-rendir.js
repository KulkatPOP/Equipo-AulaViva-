'use strict';

const panel = document.querySelector('#rendicion');
const inicio = document.querySelector('#iniciar');
const formulario = document.querySelector('#respuestas');
const campos = document.querySelector('#preguntas-rendicion');
const envio = document.querySelector('#enviar-rendicion');
const dialogo = document.querySelector('#confirmar-rendicion');
const aviso = document.querySelector('#estado-rendicion');
const reloj = document.querySelector('#tiempo-rendicion');
const progreso = document.querySelector('#progreso-rendicion');
const ayuda = document.querySelector('#ayuda-rendicion');
const avisoAyuda = document.querySelector('#estado-ayuda');
const preguntas = [...campos.querySelectorAll('.panel-pregunta')];
const opciones = [...campos.querySelectorAll('input')];

let entrega = panel.dataset.entrega;
let revision = 0;
let recibidas = [];
let limite = 0;
let abierto = false;
let ocupado = false;
let cerrado = false;
let pendiente = false;
let enviarPendiente = false;
let solicitud = null;
let retenerResultado = false;
let almacenamientoDisponible = true;

function leerLocal() {
    try {
        return JSON.parse(localStorage.getItem(panel.dataset.clave));
    } catch {
        return null;
    }
}

function selecciones() {
    return opciones.filter(opcion => opcion.checked).map(opcion => opcion.value);
}

function guardarLocal() {
    try {
        localStorage.setItem(panel.dataset.clave, JSON.stringify({
            entrega, revision, selecciones: selecciones(), pendiente, enviarPendiente, solicitud
        }));
        almacenamientoDisponible = true;
    } catch {
        almacenamientoDisponible = false;
    }
}

function marcar(ids) {
    const conjunto = new Set(ids);
    opciones.forEach(opcion => { opcion.checked = conjunto.has(opcion.value); });
}

function contar(ids) {
    const conjunto = new Set(ids);
    return preguntas.filter(pregunta => [...pregunta.querySelectorAll('input')].some(input => conjunto.has(input.value))).length;
}

function presentarProgreso() {
    const local = contar(selecciones());
    progreso.textContent = 'Has respondido ' + local + ' de ' + preguntas.length + ' preguntas. Recibidas por el servidor: ' + contar(recibidas) + '.';
}

async function solicitar(accion, datos = {}) {
    const respuesta = await fetch(location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': panel.dataset.csrf },
        body: JSON.stringify({ accion, entrega, ...datos }),
        redirect: 'error'
    });
    const resultado = await respuesta.json();
    return { ...resultado, codigo: respuesta.status };
}

function irAlResultado() {
    location.assign('evaluacion_resultado.php?id=' + encodeURIComponent(entrega));
}

function aplicarEstado(datos, restaurar) {
    entrega = datos.id;
    const local = restaurar ? leerLocal() : null;
    if (local?.entrega === entrega) solicitud = local.solicitud;
    ayuda.hidden = false;
    if (datos.estado === 'calificada') {
        const mostrarAviso = pendiente || enviarPendiente || local?.pendiente || local?.enviarPendiente;
        cerrado = true;
        retenerResultado ||= Boolean(mostrarAviso || solicitud);
        abierto = false;
        campos.disabled = true;
        envio.disabled = true;
        if (dialogo.open) dialogo.close();
        if (mostrarAviso) aviso.textContent = 'Entrega confirmada. Las respuestas que solo quedaron en este dispositivo al vencer no se calificaron.';
        pendiente = false;
        enviarPendiente = false;
        recibidas = datos.selecciones;
        marcar(recibidas);
        if (!retenerResultado) {
            localStorageSafeRemove();
            irAlResultado();
            return;
        }
        aviso.textContent ||= 'Entrega confirmada.';
        if (!document.querySelector('#ir-resultado')) {
            const enlace = document.createElement('a');
            enlace.id = 'ir-resultado';
            enlace.className = 'panel-boton';
            enlace.textContent = 'Ver resultado';
            enlace.href = 'evaluacion_resultado.php?id=' + encodeURIComponent(entrega);
            aviso.after(enlace);
        }
        guardarLocal();
        return;
    }

    abierto = true;
    limite = performance.now() + Number(datos.restante_ms);
    inicio.hidden = true;
    formulario.hidden = false;
    recibidas = datos.selecciones;
    revision = Number(datos.revision_respuestas);
    if (restaurar && local?.entrega === entrega && local.pendiente) {
        if (local.revision === revision) {
            marcar(local.selecciones);
            pendiente = true;
            enviarPendiente = local.enviarPendiente;
        } else {
            marcar(recibidas);
            aviso.textContent = 'Se recuperaron las respuestas más recientes del servidor. Los cambios de una revisión anterior no se enviaron.';
        }
    } else {
        marcar(recibidas);
    }
    presentarProgreso();
    actualizarReloj();
}

function localStorageSafeRemove() {
    try {
        localStorage.removeItem(panel.dataset.clave);
    } catch {
        // El resultado confirmado sigue disponible en el servidor.
    }
}

async function sincronizar() {
    if (ocupado || !navigator.onLine || !entrega) return;
    ocupado = true;
    try {
        if (!cerrado && (pendiente || performance.now() >= limite)) {
            const vencida = performance.now() >= limite;
            const accion = vencida ? 'estado' : enviarPendiente ? 'entregar' : 'guardar';
            const datos = await solicitar(accion, { revision, selecciones: selecciones() });
            if (datos.codigo === 409) {
                pendiente = enviarPendiente = false;
                aplicarEstado(datos, false);
                aviso.textContent = datos.error + ' Se cargaron las respuestas confirmadas por el servidor.';
                guardarLocal();
            } else if (datos.codigo !== 200) {
                aviso.textContent = datos.error;
            } else if (datos.estado === 'calificada') {
                const locales = selecciones();
                const coinciden = locales.length === datos.selecciones.length
                    && locales.every(id => datos.selecciones.includes(id));
                if (coinciden) pendiente = enviarPendiente = false;
                aplicarEstado(datos, false);
            } else if (accion !== 'estado') {
                recibidas = datos.selecciones;
                revision = Number(datos.revision_respuestas);
                pendiente = enviarPendiente = false;
                guardarLocal();
                presentarProgreso();
                aviso.textContent = 'Sincronizado';
            } else {
                aplicarEstado(datos, false);
            }
        }
        if (solicitud) {
            const datos = await solicitar('ayuda', solicitud);
            if (datos.ayuda_recibida) {
                solicitud = null;
                guardarLocal();
                avisoAyuda.textContent = 'Solicitud recibida por el servidor.';
            } else {
                avisoAyuda.textContent = datos.error;
            }
        }
    } catch {
        aviso.textContent = pendiente && almacenamientoDisponible
            ? 'Guardado en este dispositivo. Se reintentará al reconectar.'
            : 'No hay confirmación del servidor. Se reintentará la conexión.';
    } finally {
        ocupado = false;
        actualizarReloj();
    }
}

function actualizarReloj() {
    if (!abierto) return;
    const segundos = Math.max(0, Math.ceil((limite - performance.now()) / 1000));
    reloj.textContent = String(Math.floor(segundos / 60)).padStart(2, '0') + ':' + String(segundos % 60).padStart(2, '0');
    campos.disabled = ocupado || !segundos || enviarPendiente;
    envio.disabled = ocupado || !segundos || enviarPendiente;
    if (!segundos && dialogo.open) dialogo.close();
    if (!segundos && !navigator.onLine) {
        aviso.textContent = 'Tiempo agotado. Solo se calificarán las respuestas recibidas por el servidor antes del vencimiento.';
    }
}

async function abrir(accion) {
    inicio.disabled = true;
    try {
        const datos = await solicitar(accion);
        if (datos.codigo !== 200) {
            aviso.textContent = datos.error;
            return;
        }
        aplicarEstado(datos, true);
        if (pendiente || solicitud) void sincronizar();
    } catch {
        aviso.textContent = 'Se necesita conexión para consultar el intento.';
    } finally {
        inicio.disabled = false;
    }
}

inicio.addEventListener('click', () => void abrir('iniciar'));
formulario.addEventListener('change', () => {
    pendiente = true;
    guardarLocal();
    presentarProgreso();
    aviso.textContent = almacenamientoDisponible ? 'Guardado en este dispositivo' : 'El dispositivo no pudo guardar una copia.';
    void sincronizar();
});

function enviar() {
    if (!abierto || ocupado || performance.now() >= limite) return;
    pendiente = enviarPendiente = true;
    guardarLocal();
    aviso.textContent = almacenamientoDisponible ? 'Guardado en este dispositivo. Entrega pendiente de confirmación.' : 'Entrega pendiente de confirmación.';
    actualizarReloj();
    void sincronizar();
}

formulario.addEventListener('submit', evento => {
    evento.preventDefault();
    if (contar(selecciones()) < preguntas.length) {
        dialogo.returnValue = '';
        dialogo.showModal();
    } else {
        enviar();
    }
});
dialogo.addEventListener('close', () => {
    if (dialogo.returnValue === 'enviar') enviar();
});

document.querySelector('#solicitud-rendicion').addEventListener('submit', evento => {
    evento.preventDefault();
    if (!solicitud) {
        solicitud = { solicitud: crypto.randomUUID(), mensaje: document.querySelector('#mensaje-rendicion').value };
    }
    guardarLocal();
    avisoAyuda.textContent = almacenamientoDisponible ? 'Solicitud pendiente de envío.' : 'Mantén abierta esta pestaña para enviar la solicitud.';
    void sincronizar();
});

window.addEventListener('online', () => void sincronizar());
setInterval(() => {
    actualizarReloj();
    if (pendiente || solicitud || (abierto && performance.now() >= limite)) void sincronizar();
}, 1000);

inicio.hidden = Boolean(entrega);
if (entrega) void abrir('estado');
