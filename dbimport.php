<?php

use PhpPgAdmin\Database\Import\StreamChunkProcessor;

// dbimport.php
// API endpoint for chunked file upload and import processing
// This is an API endpoint that expects JSON requests and returns JSON responses.
// Authentication is checked via session (user must be logged in via main app).

// Check for server parameter
if (!isset($_REQUEST['server'])) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'server parameter required']);
    exit;
}

require_once __DIR__ . '/libraries/bootstrap.php';

// Main action dispatcher (streaming-only)

$action = $_REQUEST['action'] ?? 'process_chunk';

switch ($action) {
    case 'process_chunk':
        //usleep(1000000); // 0.1s
        $processor = StreamChunkProcessor::fromGlobals();
        $response = $processor->handle();
        http_response_code($processor->getHttpStatus());
        echo json_encode($response);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
