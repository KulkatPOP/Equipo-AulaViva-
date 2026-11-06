<?php

$id = $_GET['id'];

// ruleid: aulaviva-php-request-to-pdo
$pdo->query('SELECT id FROM aulas WHERE id = ' . $id);

// ruleid: aulaviva-php-request-to-pdo
$pdo->prepare('SELECT id FROM aulas WHERE id = ' . $id);

// ok: aulaviva-php-request-to-pdo
$stmt = $pdo->prepare('SELECT id FROM aulas WHERE id = :id');
$stmt->execute([':id' => $id]);