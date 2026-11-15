'use strict';

const cacheNombre = 'aulaviva-us02-v1';
const editor = new URL('evaluacion_editar.php', self.registration.scope);
const script = new URL('js/evaluacion-editar.js', editor);
const estilos = new URL('css/styles.css', editor);

self.addEventListener('install', evento => {
    evento.waitUntil(caches.open(cacheNombre).then(cache => cache.addAll([script, estilos])).then(() => self.skipWaiting()));
});
self.addEventListener('activate', evento => evento.waitUntil(self.clients.claim()));

self.addEventListener('fetch', evento => {
    const solicitud = evento.request;
    if (solicitud.method !== 'GET') return;
    const url = new URL(solicitud.url);
    if (solicitud.mode === 'navigate' && url.origin === editor.origin && url.pathname === editor.pathname) {
        // La respuesta autenticada nunca se almacena en la caché del navegador.
        evento.respondWith(fetch(solicitud).catch(() => new Response(
            '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' +
            '<title>Borrador sin conexión | Aula Viva</title><link rel="stylesheet" href="css/styles.css">' +
            '<body class="panel-docente"><main class="panel-contenido"><h1>Borrador sin conexión</h1>' +
            '<p id="estado-evaluacion" role="status"></p><form id="form-evaluacion" class="curso panel-editor-evaluacion" data-offline="true" hidden>' +
            '<fieldset id="campos-evaluacion"><label>Título <input id="titulo-evaluacion"></label><div id="preguntas-evaluacion"></div>' +
            '<button class="panel-boton" id="agregar-pregunta" type="button">Agregar pregunta</button> ' +
            '<button class="panel-boton" type="submit" value="guardar">Guardar borrador</button></fieldset></form></main>' +
            '<script src="js/evaluacion-editar.js" defer></script></body></html>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8', 'Content-Security-Policy': "default-src 'none'; script-src 'self'; style-src 'self'; base-uri 'none'; form-action 'none'" } }
        )));
    } else if (url.href === script.href || url.href === estilos.href) {
        evento.respondWith(fetch(solicitud).catch(async () => (await caches.match(solicitud)) || Response.error()));
    }
});
