document.addEventListener('DOMContentLoaded', () => {

    const menu = document.getElementById('menu-lateral');
    const abrir = document.getElementById('abrir-menu');
    const cerrar = document.getElementById('cerrar-menu');

    if (!menu || !abrir || !cerrar) return;

    function abrirMenu() {

        if (menu.open) return;

        menu.showModal();

        abrir.setAttribute('aria-expanded', 'true');

        document.body.classList.add('av-menu-open');
    }

    function cerrarMenu() {

        if (!menu.open ||
            menu.classList.contains('av-closing')) return;

        menu.classList.add('av-closing');

        setTimeout(() => {

            menu.close();
            menu.classList.remove('av-closing');

        }, 280);
    }

    abrir.addEventListener('click', abrirMenu);

    cerrar.addEventListener('click', cerrarMenu);

    menu.addEventListener('click', (event) => {

        if (event.target !== menu) return;

        const rect = menu.getBoundingClientRect();

        if (
            event.clientX < rect.left ||
            event.clientX > rect.right ||
            event.clientY < rect.top ||
            event.clientY > rect.bottom
        ) {
            cerrarMenu();
        }
    });

    menu.addEventListener('cancel', (event) => {

        event.preventDefault();
        cerrarMenu();
    });

    menu.addEventListener('close', () => {

        abrir.setAttribute('aria-expanded', 'false');

        document.body.classList.remove('av-menu-open');

        abrir.focus();
    });

});

document.addEventListener('DOMContentLoaded', () => {

    const enlace = document.getElementById('menu-alumnos');
    const menu = document.getElementById('menu-lateral');

    if (!enlace || !menu) return;

    enlace.addEventListener('click', (event) => {

        event.preventDefault();

        menu.close();

        document.getElementById('alumnos-aula')
            ?.scrollIntoView({
                behavior: window.matchMedia(
                    '(prefers-reduced-motion: reduce)'
                ).matches ? 'auto' : 'smooth',
                block: 'start'
            });

    });

});