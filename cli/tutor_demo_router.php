<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$ruta = rawurldecode(explode('?', $_SERVER['REQUEST_URI'], 2)[0]);
if ($ruta === '/Aula viva/login.php') {
    header('Location: /login.php', true, 302);
    return true;
}

if (preg_match('~(?:^|[/\\\\])(?:\.[^/\\\\]*|cli|config|database|docs|migrations|node_modules|packages|test)(?:[/\\\\]|$)~i', $ruta)) {
    http_response_code(404);
    return true;
}

return false;
