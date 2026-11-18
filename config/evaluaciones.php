<?php

require_once __DIR__ . '/materiales.php';

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

function idEvaluacion(mixed $id): bool
{
    return is_string($id) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $id) === 1;
}

function preguntasEvaluacion(PDO $pdo, string $id, bool $incluirPauta): array
{
    $pauta = $incluirPauta ? ', p.puntaje, a.es_correcta' : '';
    $stmt = $pdo->prepare(
        "SELECT p.id, p.enunciado, a.texto $pauta
         FROM preguntas p LEFT JOIN alternativas a ON a.pregunta_id = p.id
         WHERE p.evaluacion_id = :id ORDER BY p.orden, a.orden"
    );
    $stmt->execute(['id' => $id]);
    $preguntas = [];
    foreach ($stmt as $fila) {
        $pregunta = &$preguntas[$fila['id']];
        if ($pregunta === null) {
            $pregunta = ['enunciado' => $fila['enunciado'], 'alternativas' => []];
            if ($incluirPauta) {
                $pregunta['puntaje'] = $fila['puntaje'];
            }
        }
        if ($fila['texto'] !== null) {
            $alternativa = ['texto' => $fila['texto']];
            if ($incluirPauta) {
                $alternativa['es_correcta'] = $fila['es_correcta'];
            }
            $pregunta['alternativas'][] = $alternativa;
        }
        unset($pregunta);
    }
    return array_values($preguntas);
}

function cabeceraEvaluaciones(): void
{
    $inicio = $_SESSION['rol'] === 'profesor' ? 'mis_aulas.php' : 'mis_cursos.php';
    ?>
    <header>
        <a href="<?= $inicio ?>" class="logo">
            <img src="img/Logo.png" alt=""><span class="title-header">Aula Viva</span>
        </a>
        <span class="panel-identidad"><?= escaparMaterial($_SESSION['nombre'] ?? '') ?></span>
        <nav aria-label="Navegación"><a href="logout.php" class="nav-link">Cerrar sesión</a></nav>
    </header>
    <?php
}
