'use strict';

const cacheAnterior = 'aulaviva-us02-v1';

self.addEventListener('install', evento => evento.waitUntil(self.skipWaiting()));
self.addEventListener('activate', evento => {
    evento.waitUntil(caches.delete(cacheAnterior).then(() => self.clients.claim()));
});

self.addEventListener('fetch', evento => {
    const solicitud = evento.request;
    const editor = new URL('evaluacion_editar.php', self.registration.scope);
    if (solicitud.mode !== 'navigate' || solicitud.method !== 'GET') return;
    const url = new URL(solicitud.url);
    if (url.origin !== editor.origin || url.pathname !== editor.pathname) return;

    evento.respondWith(fetch(solicitud).catch(() => new Response(
        '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' +
        '<title>Editor sin conexión | Aula Viva</title><main><h1>Editor sin conexión</h1>' +
        '<p>Reconecta y vuelve a cargar esta página para verificar tu sesión y recuperar el borrador.</p></main></html>',
        { headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'Cache-Control': 'no-store',
            'Content-Security-Policy': "default-src 'none'; base-uri 'none'; form-action 'none'"
        } }
    )));
});
