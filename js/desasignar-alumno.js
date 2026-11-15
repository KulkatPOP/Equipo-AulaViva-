const dialogoDesasignacion = document.getElementById('confirmar-desasignacion');
let botonDesasignacion;

for (const boton of document.querySelectorAll('[data-desasignar]')) {
    boton.addEventListener('click', () => {
        botonDesasignacion = boton;
        document.getElementById('id-desasignacion').value = boton.dataset.desasignar;
        document.getElementById('alumno-desasignacion').textContent =
            boton.closest('li').querySelector('h3').textContent.trim();
        dialogoDesasignacion.showModal();
    });
}

dialogoDesasignacion.addEventListener('close', () => botonDesasignacion?.focus());
