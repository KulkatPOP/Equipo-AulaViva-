<?php

require_once __DIR__ . '/materiales.php';

// Acceso al aula

function aulaEvaluaciones(PDO $pdo, int $id, bool $bloquear = false): array|false
{
    $sql = consultaAulasMateriales($_SESSION['rol'] === 'profesor') . ' AND a.id = :aula_id';

    if ($bloquear) {
        $sql .= ' FOR SHARE OF a, u, vinculo';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(parametrosAulasMateriales() + ['aula_id' => $id]);

    return $stmt->fetch();
}

// Identificador de evaluación

function idEvaluacion(mixed $id): bool
{
    return is_string($id)
        && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $id) === 1;
}

// Zona horaria

function zonaHorariaEvaluacion(): DateTimeZone
{
    return new DateTimeZone('America/Santiago');
}

// Validación de fechas

function fechaEvaluacion(mixed $valor): ?DateTimeImmutable
{
    if (!is_string($valor) || strlen($valor) !== 16) {
        return null;
    }

    $fecha = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        $valor,
        zonaHorariaEvaluacion()
    );

    if (!$fecha || $fecha->format('Y-m-d\TH:i') !== $valor) {
        return null;
    }

    return $fecha;
}

// Fecha para mostrar

function mostrarFechaEvaluacion(?string $valor): string
{
    if ($valor === null) {
        return 'Sin fecha definida';
    }

    return (new DateTimeImmutable($valor))
        ->setTimezone(zonaHorariaEvaluacion())
        ->format('d/m/Y - H:i');
}

// Fecha para el formulario

function fechaFormularioEvaluacion(?string $valor): string
{
    if ($valor === null) {
        return '';
    }

    return (new DateTimeImmutable($valor))
        ->setTimezone(zonaHorariaEvaluacion())
        ->format('Y-m-d\TH:i');
}

// Estado de disponibilidad

function estadoDisponibilidadEvaluacion(array $evaluacion): string
{
    if (empty($evaluacion['publicada_en'])) {
        return 'borrador';
    }

    $ahora = new DateTimeImmutable('now');

    if (
        !empty($evaluacion['disponible_desde'])
        && $ahora < new DateTimeImmutable($evaluacion['disponible_desde'])
    ) {
        return 'programada';
    }

    if (
        !empty($evaluacion['disponible_hasta'])
        && $ahora >= new DateTimeImmutable($evaluacion['disponible_hasta'])
    ) {
        return 'finalizada';
    }

    return 'disponible';
}

// Preguntas y alternativas

function preguntasEvaluacion(
    PDO $pdo,
    string $id,
    bool $incluirPauta
): array {
    $pauta = $incluirPauta
        ? ', p.puntaje, a.es_correcta'
        : '';

    $stmt = $pdo->prepare(
        "SELECT
            p.id,
            p.enunciado,
            p.tipo_respuesta,
            a.texto
            $pauta
         FROM preguntas p
         LEFT JOIN alternativas a ON a.pregunta_id = p.id
         WHERE p.evaluacion_id = :id
         ORDER BY p.orden, a.orden"
    );

    $stmt->execute(['id' => $id]);

    $preguntas = [];

    foreach ($stmt as $fila) {

        $preguntaId = $fila['id'];

        if (!isset($preguntas[$preguntaId])) {

            $preguntas[$preguntaId] = [
                'enunciado' => $fila['enunciado'],
                'tipo_respuesta' => $fila['tipo_respuesta'],
                'alternativas' => []
            ];

            if ($incluirPauta) {
                $preguntas[$preguntaId]['puntaje'] =
                    $fila['puntaje'];
            }
        }

        if ($fila['texto'] !== null) {

            $alternativa = [
                'texto' => $fila['texto']
            ];

            if ($incluirPauta) {
                $alternativa['es_correcta'] = in_array(
                    $fila['es_correcta'],
                    [true, 1, '1', 't', 'true'],
                    true
                );
            }

            $preguntas[$preguntaId]['alternativas'][] =
                $alternativa;
        }
    }

    return array_values($preguntas);
}

// Cabecera de evaluaciones

function cabeceraEvaluaciones(): void
{
    $inicio = $_SESSION['rol'] === 'profesor'
        ? 'mis_aulas.php'
        : 'mis_cursos.php';
    ?>
    <header>
        <a href="<?= $inicio ?>" class="logo">
            <img src="img/Logo.png" alt="">
            <span class="title-header">Aula Viva</span>
        </a>

        <span class="panel-identidad">
            <?= escaparMaterial($_SESSION['nombre'] ?? '') ?>
        </span>

        <nav aria-label="Navegación">
            <a href="logout.php" class="nav-link">
                Cerrar sesión
            </a>
        </nav>
    </header>
    <?php
}