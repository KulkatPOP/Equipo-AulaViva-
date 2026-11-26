
"use strict";

const zona = document.getElementById("zona-excel");
const archivo = document.getElementById("archivo-excel");
const nombre = document.getElementById("nombre-archivo");
const validar = document.getElementById("validar-excel");
const error = document.getElementById("error-excel");
const formulario = document.getElementById("form-importar-excel");

const LIMITE_ARCHIVO = 5 * 1024 * 1024;

function mostrarError(mensaje) {
    error.textContent = mensaje;
    error.hidden = false;

    archivo.value = "";
    validar.disabled = true;

    zona.classList.remove("archivo-listo");

    nombre.textContent = "Ningún archivo seleccionado";
}

function revisarArchivo(fichero) {
    error.hidden = true;
    error.textContent = "";

    if (!fichero) {
        validar.disabled = true;

        zona.classList.remove("archivo-listo");

        nombre.textContent = "Ningún archivo seleccionado";

        return false;
    }

    if (!fichero.name.toLowerCase().endsWith(".xlsx")) {
        mostrarError(
            "Selecciona un archivo Excel con formato .xlsx."
        );

        return false;
    }

    if (fichero.size === 0) {
        mostrarError("El archivo está vacío.");

        return false;
    }

    if (fichero.size > LIMITE_ARCHIVO) {
        mostrarError(
            "El archivo no puede superar los 5 MB."
        );

        return false;
    }

    nombre.textContent = fichero.name;

    zona.classList.add("archivo-listo");

    validar.disabled = false;

    return true;
}

archivo.addEventListener("change", () => {
    revisarArchivo(archivo.files[0]);
});

zona.addEventListener("dragover", evento => {
    evento.preventDefault();

    zona.classList.add("arrastrando");
});

zona.addEventListener("dragleave", () => {
    zona.classList.remove("arrastrando");
});

zona.addEventListener("drop", evento => {
    evento.preventDefault();

    zona.classList.remove("arrastrando");

    const archivos = evento.dataTransfer.files;

    if (archivos.length !== 1) {
        mostrarError(
            "Debes seleccionar un solo archivo Excel."
        );

        return;
    }

    archivo.files = archivos;

    revisarArchivo(archivo.files[0]);
});

/* Enviar archivo a PHP */

formulario.addEventListener("submit", evento => {
    if (!revisarArchivo(archivo.files[0])) {
        evento.preventDefault();
        return;
    }

    validar.disabled = true;
    validar.textContent = "Validando archivo...";
});

/* Mostrar automáticamente el resultado */

const resultado = document.getElementById(
    "resultado-importacion"
);

if (resultado) {
    resultado.scrollIntoView({
        behavior: "smooth",
        block: "start"
    });
}
