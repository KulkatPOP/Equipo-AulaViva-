'use strict';

// Confirmar eliminación

const modalEliminarEvaluacion = document.querySelector(
    '#modal-eliminar-evaluacion'
);

if (modalEliminarEvaluacion) {

    const formularioEliminar = document.querySelector(
        '#form-eliminar-evaluacion'
    );

    const nombreEvaluacion = document.querySelector(
        '#nombre-evaluacion-eliminar'
    );

    const idEvaluacionEliminar = document.querySelector(
        '#evaluacion-eliminar'
    );

    const cancelarEliminar = document.querySelector(
        '#cancelar-eliminar-evaluacion'
    );

    const confirmarEliminar = formularioEliminar.querySelector(
        'button[type="submit"]'
    );

    let eliminando = false;
    let botonAnterior = null;

    // Abrir ventana

    document.querySelectorAll('.av-evaluation-delete')
        .forEach(boton => {

            boton.addEventListener('click', () => {

                botonAnterior = boton;

                idEvaluacionEliminar.value =
                    boton.dataset.evaluacion;

                nombreEvaluacion.textContent =
                    boton.dataset.titulo;

                modalEliminarEvaluacion.showModal();

            });

        });

    // Cancelar eliminación

    cancelarEliminar.addEventListener('click', () => {

        if (!eliminando) {
            modalEliminarEvaluacion.close();
        }

    });

    modalEliminarEvaluacion.addEventListener('cancel', evento => {

        if (eliminando) {
            evento.preventDefault();
        }

    });

    // Limpiar ventana

    modalEliminarEvaluacion.addEventListener('close', () => {

        idEvaluacionEliminar.value = '';
        nombreEvaluacion.textContent = '';

        botonAnterior?.focus();

    });

    // Enviar solicitud

    formularioEliminar.addEventListener('submit', evento => {

        if (eliminando || !idEvaluacionEliminar.value) {
            evento.preventDefault();
            return;
        }

        eliminando = true;

        confirmarEliminar.disabled = true;
        cancelarEliminar.disabled = true;

        confirmarEliminar.textContent = 'Eliminando...';

    });

    // Restaurar controles al regresar

    window.addEventListener('pageshow', () => {

        eliminando = false;

        confirmarEliminar.disabled = false;
        cancelarEliminar.disabled = false;

        confirmarEliminar.textContent = 'Eliminar evaluación';

        if (modalEliminarEvaluacion.open) {
            modalEliminarEvaluacion.close();
        }

    });

}