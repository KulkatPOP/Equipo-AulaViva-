const formularioInvitacion = document.getElementById('form-invitar-alumno');
const botonInvitacion = formularioInvitacion.querySelector('button');
const mensajeInvitacion = document.getElementById('mensaje-invitacion');
const resultadoInvitacion = document.getElementById('resultado-invitacion');
const enlaceInvitacion = document.getElementById('enlace-invitacion');
const botonCopiarInvitacion = document.getElementById('copiar-invitacion');
const mensajeCopiaInvitacion = document.getElementById('mensaje-copia-invitacion');
let solicitudInvitacion;
let enviandoInvitacion = false;

function mostrarMensajeInvitacion(mensaje, estado = '') {
    mensajeInvitacion.textContent = mensaje;
    mensajeInvitacion.classList.toggle('panel-resultado-exito', estado === 'exito');
    mensajeInvitacion.classList.toggle('panel-resultado-error', estado === 'error');
}

formularioInvitacion.addEventListener('submit', async evento => {
    evento.preventDefault();
    if (enviandoInvitacion) return;

    const campos = new FormData(formularioInvitacion);
    const email = campos.get('email').trim();
    const rut = campos.get('rut').trim();
    const username = campos.get('username').trim();

    resultadoInvitacion.hidden = true;
    enlaceInvitacion.value = '';
    mensajeCopiaInvitacion.textContent = '';

    if (!email || !rut || !username) {
        mostrarMensajeInvitacion('Completa el correo, el RUT y el nombre de usuario.', 'error');
        return;
    }
    if (/\s|[\u0000-\u001f\u007f]/u.test(username)) {
        mostrarMensajeInvitacion('Escribe un nombre de usuario sin espacios ni caracteres de control.', 'error');
        return;
    }

    const cuerpo = JSON.stringify({ email, rut, username, rol: 'alumno' });
    enviandoInvitacion = true;
    botonInvitacion.disabled = true;
    mostrarMensajeInvitacion('Creando invitación…');

    try {
        if (solicitudInvitacion?.cuerpo !== cuerpo) {
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            const clave = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
            solicitudInvitacion = { cuerpo, clave };
        }

        const respuesta = await fetch(formularioInvitacion.action, {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'error',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'Idempotency-Key': solicitudInvitacion.clave,
            },
            body: cuerpo,
        });

        if (respuesta.status === 401) {
            mostrarMensajeInvitacion('Tu sesión expiró. Vuelve a iniciar sesión.', 'error');
            return;
        }
        if (respuesta.status === 403) {
            mostrarMensajeInvitacion('No tienes permiso para crear invitaciones.', 'error');
            return;
        }

        const contenido = await respuesta.json();
        if (respuesta.status === 201) {
            const ruta = contenido.data?.url_activacion;
            if (typeof ruta !== 'string' || !/^\/activar_cuenta\.php\?token=[0-9a-f]{64}$/.test(ruta)) {
                mostrarMensajeInvitacion('La invitación fue creada, pero la API no devolvió un enlace válido.', 'error');
                return;
            }
            enlaceInvitacion.value = new URL(ruta, window.location.origin).href;
            resultadoInvitacion.hidden = false;
            mostrarMensajeInvitacion('Invitación creada correctamente.', 'exito');
        } else if (respuesta.status === 200 && contenido.meta?.idempotent_replay === true) {
            const usuario = contenido.data?.username;
            const detalle = typeof usuario === 'string' ? ' para ' + usuario : '';
            mostrarMensajeInvitacion('La invitación' + detalle + ' ya fue creada. La API no vuelve a entregar el enlace de activación.');
        } else if (respuesta.status === 409) {
            const duplicada = contenido.type === '/errors/invitacion-duplicada';
            mostrarMensajeInvitacion(duplicada
                ? 'Ya existe una invitación activa para ese correo.'
                : 'La solicitud entra en conflicto con una invitación existente.', 'error');
        } else if (respuesta.status === 400) {
            mostrarMensajeInvitacion(contenido.type === '/errors/email-invalido'
                ? 'Escribe un correo electrónico válido.'
                : 'Revisa el correo, el RUT y el nombre de usuario.', 'error');
        } else {
            mostrarMensajeInvitacion('No se pudo crear la invitación. Inténtalo nuevamente.', 'error');
        }
    } catch {
        mostrarMensajeInvitacion('No se pudo completar la solicitud. Inténtalo nuevamente.', 'error');
    } finally {
        enviandoInvitacion = false;
        botonInvitacion.disabled = false;
    }
});

botonCopiarInvitacion.addEventListener('click', async () => {
    if (!enlaceInvitacion.value) return;

    try {
        await navigator.clipboard.writeText(enlaceInvitacion.value);
        mensajeCopiaInvitacion.textContent = 'Enlace copiado.';
    } catch {
        enlaceInvitacion.focus();
        enlaceInvitacion.select();
        mensajeCopiaInvitacion.textContent = 'El enlace está seleccionado. Usa el comando Copiar.';
    }
});

formularioInvitacion.hidden = false;
