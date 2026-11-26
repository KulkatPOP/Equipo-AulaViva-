
"use strict";

const formulario = document.getElementById(
    "form-registro-individual"
);

const roles = formulario.querySelectorAll(
    'input[name="rol"]'
);

const camposAlumno = document.getElementById(
    "campos-alumno"
);

const camposProfesor = document.getElementById(
    "campos-profesor"
);

const camposApoderado = document.getElementById(
    "campos-apoderado"
);

const vincular = document.getElementById(
    "vincular-apoderado"
);

const datosApoderado = document.getElementById(
    "datos-apoderado"
);

const guardar = document.getElementById(
    "guardar-individual"
);

function mostrarCampos(elemento, visible) {
    elemento.hidden = !visible;
    elemento.disabled = !visible;
}

function actualizarFormulario() {

    const rol = formulario.querySelector(
        'input[name="rol"]:checked'
    ).value;

    mostrarCampos(camposAlumno, rol === "alumno");

    mostrarCampos(camposProfesor, rol === "profesor");

    mostrarCampos(camposApoderado, rol === "apoderado");

    const registroDoble =
        rol === "alumno" && vincular.checked;

    mostrarCampos(datosApoderado, registroDoble);

    if (registroDoble) {

        guardar.textContent =
            "Registrar estudiante y apoderado";

    } else if (rol === "alumno") {

        guardar.textContent = "Registrar estudiante";

    } else if (rol === "profesor") {

        guardar.textContent = "Registrar profesor";

    } else {

        guardar.textContent = "Registrar apoderado";
    }
}

roles.forEach(rol => {
    rol.addEventListener("change", actualizarFormulario);
});

vincular.addEventListener(
    "change",
    actualizarFormulario
);

formulario.addEventListener("submit", evento => {

    if (!formulario.checkValidity()) {

        evento.preventDefault();

        formulario.reportValidity();

        return;
    }

    if (!window.confirm(
        "¿Confirmas el registro de los usuarios?"
    )) {

        evento.preventDefault();

        return;
    }

    guardar.disabled = true;
    guardar.textContent = "Registrando...";

});

actualizarFormulario();
