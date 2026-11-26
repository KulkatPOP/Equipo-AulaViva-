
"use strict";

document.querySelectorAll(".admin-aula-estado").forEach(formulario => {

    formulario.addEventListener("submit", evento => {

        const accion = formulario.elements.accion.value;

        if (accion === "desactivar") {

            const confirmado = window.confirm(
                "¿Deseas desactivar esta aula?\n\n" +
                "Se conservarán sus materiales, evaluaciones y calificaciones."
            );

            if (!confirmado) {
                evento.preventDefault();
                return;
            }
        }

        formulario.querySelector("button").disabled = true;
    });
});

/* Ventanas de incorporación */

document.querySelectorAll("[data-abrir-integrantes]").forEach(boton => {

    boton.addEventListener("click", () => {

        const rol = boton.dataset.abrirIntegrantes;

        const dialogo = document.getElementById(
            `agregar-${rol}`
        );

        if (dialogo && !dialogo.open) {
            dialogo.showModal();
        }
    });
});

document.querySelectorAll(".admin-dialog").forEach(dialogo => {

    dialogo.querySelectorAll("[data-cerrar-integrantes]")
        .forEach(boton => {

            boton.addEventListener("click", () => {
                dialogo.close();
            });
        });

    dialogo.addEventListener("click", evento => {

        if (evento.target === dialogo) {

            const rect = dialogo.getBoundingClientRect();

            if (
                evento.clientX < rect.left ||
                evento.clientX > rect.right ||
                evento.clientY < rect.top ||
                evento.clientY > rect.bottom
            ) {
                dialogo.close();
            }
        }
    });

    const buscador = dialogo.querySelector(
        ".admin-buscar-integrante"
    );

    const personas = dialogo.querySelectorAll(
        "[data-persona]"
    );

    const sinResultados = dialogo.querySelector(
        "[data-sin-resultados]"
    );

    const normalizar = texto => texto
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase()
        .trim();

    if (buscador) {

        buscador.addEventListener("input", () => {

            const consulta = normalizar(buscador.value);

            let visibles = 0;

            personas.forEach(persona => {

                const informacion = persona.querySelector(
                    ".admin-persona-info"
                );

                const texto = normalizar(
                    informacion.textContent
                );

                persona.hidden = !texto.includes(consulta);

                if (!persona.hidden) {
                    visibles++;
                }
            });

            if (sinResultados) {
                sinResultados.hidden = visibles !== 0;
            }
        });
    }

    personas.forEach(formulario => {

        formulario.addEventListener("submit", () => {
            formulario.querySelector("button").disabled = true;
        });
    });
});
/* Confirmar retirada de integrantes */

document.querySelectorAll(".admin-retirar-integrante")
    .forEach(formulario => {

        formulario.addEventListener("submit", evento => {

            const nombre = formulario.dataset.nombre;

            const confirmado = window.confirm(
                `¿Deseas retirar a ${nombre} de esta aula?\n\n` +
                "Su cuenta no será eliminada."
            );

            if (!confirmado) {
                evento.preventDefault();
                return;
            }

            formulario.querySelector("button").disabled = true;
        });
    });