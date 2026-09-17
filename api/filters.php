<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/http.php';

try {
    $db = database();
    $types = $db->query('SELECT DISTINCT event_type FROM events ORDER BY event_type')->fetchAll(PDO::FETCH_COLUMN);
    $locations = $db->query('SELECT DISTINCT location_name FROM events ORDER BY location_name')->fetchAll(PDO::FETCH_COLUMN);
    $total = (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $lastUpdated = $db->query("SELECT MAX(completed_at) FROM import_runs WHERE source = 'polisen.se' AND status = 'success'")->fetchColumn();

    json_response([
        'types' => $types,
        'locations' => $locations,
        'total_events' => $total,
        'last_updated' => $lastUpdated ?: null,
    ]);
} catch (Throwable $error) {
    error_log($error->__toString());
    json_response(['error' => 'Filter metadata is temporarily unavailable.'], 500);
}
