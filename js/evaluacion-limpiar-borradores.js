'use strict';

(() => {
    const salida = new URLSearchParams(location.search).has('sesion_cerrada');
    try {
        if (salida) localStorage.setItem('us02-salida', String(Date.now()));
        for (const clave of Object.keys(sessionStorage)) {
            if (clave.startsWith('us02-editor:')) sessionStorage.removeItem(clave);
        }
        for (const clave of Object.keys(localStorage)) {
            if (clave.startsWith('us02:') || (salida && clave.startsWith('us02-cifrado:'))) {
                localStorage.removeItem(clave);
            }
        }
    } catch {
        // El editor requiere la clave de la sesión aunque el navegador impida limpiar el almacenamiento.
    }
})();

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('evaluaciones-offline.js', { updateViaCache: 'none' }).catch(() => {
        // La copia cifrada del formulario abierto funciona sin service worker.
    });
}
