const buscadorAlumnos = document.getElementById('buscar-alumno');
const avisoBusquedaAlumnos = document.getElementById('aviso-busqueda-alumnos');

function normalizarBusquedaAlumno(texto) {
    return texto.trim().replace(/\s+/g, ' ').toLocaleLowerCase('es');
}

const tarjetasBusquedaAlumnos = Array.from(document.querySelectorAll('#listado-alumnos > li'), tarjeta => ({
    tarjeta,
    nombre: normalizarBusquedaAlumno(tarjeta.querySelector('h3').textContent),
    usuario: normalizarBusquedaAlumno(tarjeta.querySelector('[data-usuario-alumno]').textContent),
}));

function filtrarAlumnos() {
    const busqueda = normalizarBusquedaAlumno(buscadorAlumnos.value);
    let hayCoincidencias = false;

    for (const { tarjeta, nombre, usuario } of tarjetasBusquedaAlumnos) {
        const coincide = nombre.includes(busqueda) || usuario.includes(busqueda);
        tarjeta.hidden = !coincide;
        hayCoincidencias ||= coincide;
    }

    const mensaje = hayCoincidencias ? '' : 'No se encontraron alumnos.';
    if (avisoBusquedaAlumnos.textContent !== mensaje) {
        avisoBusquedaAlumnos.textContent = mensaje;
    }
}

buscadorAlumnos.addEventListener('input', filtrarAlumnos);
buscadorAlumnos.closest('.panel-buscar-alumno').hidden = false;
filtrarAlumnos();
