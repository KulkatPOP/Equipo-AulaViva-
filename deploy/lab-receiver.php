<?php
// Receptor solo para el cluster efimero. No contiene datos de estudiantes.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(200);
    echo 'AulaViva lab receiver';
    exit;
}
$event = json_decode(file_get_contents('php://input'), true);
if (!is_array($event) || !isset($event['rule'])) {
    http_response_code(400);
    exit;
}
$incident = [
    'id' => bin2hex(random_bytes(8)),
    'received_at' => gmdate('c'),
    'status' => 'open',
    'assignee' => 'DevSecOps',
    'event' => $event,
];
file_put_contents('/tmp/incidents.ndjson', json_encode($incident) . PHP_EOL, FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
echo json_encode(['accepted' => true, 'incident_id' => $incident['id']]);
