<?php
/**
 * sync.php  –  can be called directly (AJAX or browser) or included.
 * Returns a JSON response when called via HTTP.
 */
require_once __DIR__ . '/db.php';

$result = sync_json_files();

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'added'   => $result['added'],
    'skipped' => $result['skipped'],
    'message' => count($result['added']) > 0
        ? count($result['added']) . ' new file(s) added to the database.'
        : 'Database is up to date.',
]);
