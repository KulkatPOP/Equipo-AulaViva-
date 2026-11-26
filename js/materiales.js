document.addEventListener('DOMContentLoaded', () => {

    const modal = document.getElementById(
        'modal-eliminar-material'
    );

    const formulario = document.getElementById(
        'form-eliminar-material'
    );

    const cancelar = document.getElementById(
        'cancelar-eliminar-material'
    );

    const materialId = document.getElementById(
        'material-eliminar'
    );

    const nombreMaterial = document.getElementById(
        'nombre-material-eliminar'
    );

    if (
        !modal ||
        !formulario ||
        !cancelar ||
        !materialId ||
        !nombreMaterial
    ) {
        return;
    }

    const botonesEliminar = document.querySelectorAll(
        '.av-material-delete'
    );

    botonesEliminar.forEach(boton => {

        boton.addEventListener('click', () => {

            materialId.value = boton.dataset.material;

            nombreMaterial.textContent =
                boton.dataset.titulo;

            modal.showModal();

        });

    });

    cancelar.addEventListener('click', () => {
        modal.close();
    });

    modal.addEventListener('close', () => {

        materialId.value = '';
        nombreMaterial.textContent = '';

    });

    formulario.addEventListener('submit', evento => {

        if (!/^[1-9]\d*$/.test(materialId.value)) {

            evento.preventDefault();
            return;

        }

        const confirmar = formulario.querySelector(
            'button[type="submit"]'
        );

        confirmar.disabled = true;
        confirmar.textContent = 'Eliminando...';

    });

});