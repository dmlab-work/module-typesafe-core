<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

// Router for the PHP built-in server used by CurlMultiTransportTest; runs outside Magento.
// phpcs:disable Magento2.Security.LanguageConstruct.DirectOutput

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
header('Content-Type: application/json');

if (preg_match('#^/status/(\d{3})$#', $path, $match)) {
    http_response_code((int)$match[1]);
    header('X-Loopback-Header: Mixed-Case');
    if (isset($query['retry_after'])) {
        header('Retry-After: ' . $query['retry_after']);
    }
    if (isset($query['location'])) {
        header('Location: ' . $query['location']);
    }
    echo json_encode(['status' => (int)$match[1]]);
    return true;
}

if ($path === '/echo') {
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'headers' => getallheaders(),
        'body' => file_get_contents('php://input'),
    ]);
    return true;
}

if (preg_match('#^/sleep/(\d+)$#', $path, $match)) {
    usleep((int)$match[1] * 1000);
    echo json_encode(['slept' => (int)$match[1]]);
    return true;
}

http_response_code(404);
echo json_encode(['error' => 'not found']);
return true;
