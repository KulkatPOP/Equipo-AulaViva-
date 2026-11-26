
"use strict";

const buscador = document.getElementById("buscar-usuario");
const filtroRol = document.getElementById("filtrar-rol");
const filas = document.querySelectorAll("[data-usuario]");
const sinUsuarios = document.getElementById("sin-usuarios");

function normalizar(texto) {
    return texto
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase()
        .trim();
}

function filtrarUsuarios() {

    const busqueda = normalizar(buscador.value);
    const rol = filtroRol.value;

    let visibles = 0;

    filas.forEach(fila => {

        const texto = normalizar(
            Array.from(fila.cells)
                .slice(0, 3)
                .map(celda => celda.textContent)
                .join(" ")
        );

        const coincideTexto = texto.includes(busqueda);
        const coincideRol = !rol || fila.dataset.rol === rol;

        const visible = coincideTexto && coincideRol;

        fila.hidden = !visible;

        if (visible) {
            visibles++;
        }
    });

    sinUsuarios.hidden = visibles !== 0;
}

if (buscador && filtroRol && sinUsuarios) {

    buscador.addEventListener("input", filtrarUsuarios);
    filtroRol.addEventListener("change", filtrarUsuarios);

    filtrarUsuarios();
}

document.querySelectorAll(".admin-form-estado").forEach(formulario => {

    formulario.addEventListener("submit", evento => {

        const accion = formulario.elements.accion.value;
        const nombre = formulario.dataset.nombre;

        if (accion === "desactivar") {

            const confirmado = window.confirm(
                `¿Deseas desactivar la cuenta de ${nombre}?\n\n` +
                "El usuario no podrá acceder a AulaIA."
            );

            if (!confirmado) {
                evento.preventDefault();
                return;
            }
        }

        formulario.querySelector("button").disabled = true;
    });
});
