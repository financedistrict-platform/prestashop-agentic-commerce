<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    case '/p':
        header('Location: /p/', true, 301);

        return;
    case '/p/':
        header('Content-Type: application/json');
        echo json_encode(['ucp' => ['version' => '2026-01-23']]);

        return;
    case '/two-hops':
        header('Location: /p', true, 308);

        return;
    case '/big':
        header('Location: /big/', true, 301);

        return;
    case '/big/':
        echo str_repeat(' ', 131073) . '{}';

        return;
    case '/away':
        header('Location: http://localhost:1/p', true, 302);

        return;
    default:
        http_response_code(404);
}
