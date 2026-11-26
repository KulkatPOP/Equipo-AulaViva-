document.addEventListener('DOMContentLoaded', () => {

    const buscador = document.getElementById('buscar-estudiante');
    const filas = document.querySelectorAll('.av-notes-row');
    const sinResultados = document.getElementById('notas-sin-resultados');

    const formulario = document.getElementById('form-guardar-notas');
    const formularioAgregar = document.getElementById('form-agregar-nota');
    const formularioSync = document.querySelector('.av-notes-sync');

    const formularioEliminar = document.getElementById('form-eliminar-nota');
    const columnaEliminar = document.getElementById('columna-eliminar');
    const botonesEliminar = document.querySelectorAll('.av-note-delete');

    const botonGuardar = document.getElementById('guardar-notas');
    const notasJson = document.getElementById('notas-json');
    const campos = document.querySelectorAll('.av-note-input');

    let enviando = false;

    /* BUSCADOR */

    function normalizar(texto) {

        return texto
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();

    }

    if (buscador) {

        buscador.addEventListener('input', () => {

            const consulta = normalizar(buscador.value);

            let visibles = 0;

            filas.forEach(fila => {

                const estudiante = fila.querySelector('.av-notes-student');

                const coincide = normalizar(
                    estudiante.textContent
                ).includes(consulta);

                fila.hidden = !coincide;

                if (coincide) {
                    visibles++;
                }

            });

            if (sinResultados) {
                sinResultados.hidden =
                    visibles !== 0 || filas.length === 0;
            }

        });

    }

    /* VALIDAR NOTAS */

    function notaValida(valor) {

        return /^(?:[1-6](?:[.,][0-9])?|7(?:[.,]0)?)$/
            .test(valor);

    }

    function notaModificada(campo) {

        return campo.value !== campo.defaultValue;

    }

    function validarCampo(campo) {

    const valor = campo.value.trim();

    let mensaje = '';

    if (valor === '' && campo.defaultValue !== '') {

        mensaje = 'No se puede eliminar una nota guardada.';

    } else if (valor !== '' && !notaValida(valor)) {

        mensaje = 'Ingresa una nota entre 1,0 y 7,0.';

    }

    campo.setCustomValidity(mensaje);

    campo.classList.toggle(
        'av-note-invalid',
        mensaje !== ''
    );

    campo.classList.toggle(
        'av-note-reprobada',
        notaValida(valor) &&
        Number(valor.replace(',', '.')) < 4.0
    );

    return mensaje === '';
}

    function hayCambios() {

        return Array.from(campos).some(notaModificada);

    }

    function actualizarBoton() {

        if (botonGuardar) {
            botonGuardar.disabled = !hayCambios();
        }

    }

    campos.forEach(campo => {

        campo.addEventListener('input', () => {

            validarCampo(campo);

            campo.classList.toggle(
                'av-note-modified',
                notaModificada(campo)
            );

            actualizarBoton();

        });

        campo.addEventListener('blur', () => {

            const valor = campo.value.trim();

            if (notaValida(valor)) {

                campo.value = Number(
                    valor.replace(',', '.')
                ).toFixed(1).replace('.', ',');

            }

            validarCampo(campo);

            campo.classList.toggle(
                'av-note-modified',
                notaModificada(campo)
            );

            actualizarBoton();

        });

    });

    /* GUARDAR CAMBIOS */

    if (formulario) {

        formulario.noValidate = true;

        formulario.addEventListener('submit', evento => {

            const cambios = [];

            for (const campo of campos) {

                if (!validarCampo(campo)) {

                    evento.preventDefault();

                    campo.closest('tr').hidden = false;

                    campo.reportValidity();

                    return;

                }

                if (
                    notaModificada(campo) &&
                    campo.value.trim() !== ''
                ) {

                    cambios.push({

                        estudiante: Number(
                            campo.dataset.estudiante
                        ),

                        columna: Number(
                            campo.dataset.columna
                        ),

                        valor: campo.value.trim()

                    });

                }

            }

            if (cambios.length === 0) {

                evento.preventDefault();

                return;

            }

            notasJson.value = JSON.stringify(cambios);

            enviando = true;

            botonGuardar.disabled = true;
            botonGuardar.textContent = 'Guardando...';

        });

    }

    /* ELIMINAR COLUMNA */

    botonesEliminar.forEach(boton => {

        boton.addEventListener('click', () => {

            if (!formularioEliminar || !columnaEliminar) {
                return;
            }

            if (hayCambios()) {

                window.alert(
                    'Guarda los cambios pendientes antes de eliminar una nota.'
                );

                return;

            }

            const confirmar = window.confirm(
                `¿Deseas eliminar ${boton.dataset.titulo}?`
            );

            if (!confirmar) {
                return;
            }

            columnaEliminar.value = boton.dataset.columna;

            enviando = true;

            formularioEliminar.requestSubmit();

        });

    });

    /* EVITAR PERDER CAMBIOS */

    [formularioAgregar, formularioSync].forEach(form => {

        if (!form) {
            return;
        }

        form.addEventListener('submit', evento => {

            if (hayCambios()) {

                const continuar = window.confirm(
                    'Tienes notas sin guardar. ¿Deseas continuar y descartar los cambios?'
                );

                if (!continuar) {

                    evento.preventDefault();

                    return;

                }

            }

            enviando = true;

        });

    });

    window.addEventListener('beforeunload', evento => {

        if (!enviando && hayCambios()) {

            evento.preventDefault();
            evento.returnValue = '';

        }

    });

    actualizarBoton();

});