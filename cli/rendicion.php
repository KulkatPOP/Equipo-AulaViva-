<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../config/conexion.php';
require __DIR__ . '/../config/rendicion.php';

function cerrarVencidas(PDO $pdo): int
{
    $cerradas = 0;
    while ($cerradas < 100) {
        $pdo->beginTransaction();
        $entrega = $pdo->query("
            SELECT * FROM entregas WHERE estado = 'en_progreso' AND fecha_vencimiento <= clock_timestamp()
            ORDER BY fecha_vencimiento LIMIT 1 FOR UPDATE SKIP LOCKED
        ")->fetch();
        if ($entrega) {
            cerrarRendicion($pdo, $entrega);
        }
        $pdo->commit();
        if (!$entrega) {
            break;
        }
        $cerradas++;
    }
    return $cerradas;
}

function publicarOutboxRendicion(PDO $pdo, Redis $redis): void
{
    for ($i = 0; $i < 100; $i++) {
        $pdo->beginTransaction();
        $evento = $pdo->query('
            SELECT * FROM outbox_events WHERE publicado_en IS NULL ORDER BY ocurrido_en, id LIMIT 1 FOR UPDATE
        ')->fetch();
        if (!$evento) {
            $pdo->commit();
            return;
        }
        $datos = [
            'event_id' => $evento['id'],
            'event_type' => $evento['tipo'],
            'event_version' => '1.0',
            'occurred_at' => $evento['ocurrido_en'],
            'aggregate_id' => $evento['entrega_id'],
            'trace_id' => $evento['entrega_id'],
            'data' => json_decode($evento['datos'], true, 512, JSON_THROW_ON_ERROR),
        ];
        if (!$redis->xAdd(getenv('REDIS_STREAM') ?: 'aulaviva:dominio', '*', ['evento' => json_encode($datos, JSON_THROW_ON_ERROR)])) {
            throw new RuntimeException('Redis no confirmó el evento.');
        }
        rendicionPDO($pdo, 'UPDATE outbox_events SET publicado_en = clock_timestamp() WHERE id = ?', [$evento['id']]);
        $pdo->commit();
    }
}

$continuo = in_array('--continuo', $argv, true);
do {
    try {
        cerrarVencidas($pdo);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, 'No se pudieron cerrar los intentos vencidos.' . PHP_EOL);
        if (!$continuo) exit(1);
    }
    $host = getenv('REDIS_HOST');
    if ($host) {
        try {
            $redis = new Redis();
            if (!$redis->connect($host, (int) (getenv('REDIS_PORT') ?: 6379), 2)
                || (($clave = getenv('REDIS_PASSWORD')) && !$redis->auth($clave))) {
                throw new RuntimeException('Redis no disponible.');
            }
            publicarOutboxRendicion($pdo, $redis);
            $redis->close();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fwrite(STDERR, 'Los eventos pendientes se conservan en Outbox.' . PHP_EOL);
            if (!$continuo) exit(1);
        }
    }
    if ($continuo) sleep(2);
} while ($continuo);
