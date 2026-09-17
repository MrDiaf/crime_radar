<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/http.php';

try {
    $db = database();
    $where = [];
    $params = [];

    $type = query_string('type');
    $location = query_string('location');
    $search = query_string('search');
    $from = query_date('from');
    $to = query_date('to', true);

    if ($type !== null) {
        $where[] = 'event_type = :type';
        $params[':type'] = $type;
    }
    if ($location !== null) {
        $where[] = 'location_name = :location';
        $params[':location'] = $location;
    }
    if ($search !== null) {
        $where[] = '(title LIKE :search ESCAPE \'!\' OR summary LIKE :search ESCAPE \'!\')';
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
        $params[':search'] = '%' . $escaped . '%';
    }
    if ($from !== null) {
        $where[] = 'occurred_at >= :from';
        $params[':from'] = $from;
    }
    if ($to !== null) {
        $where[] = 'occurred_at <= :to';
        $params[':to'] = $to;
    }

    $requestedLimit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
    $limit = max(1, min($requestedLimit ?: 500, 1000));
    $sql = 'SELECT police_id AS id, occurred_at, title, summary, event_type, location_name,
                   latitude, longitude, source_url
            FROM events';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY occurred_at DESC LIMIT :limit';

    $statement = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $statement->bindValue($key, $value, PDO::PARAM_STR);
    }
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    $events = $statement->fetchAll();
    foreach ($events as &$event) {
        $event['id'] = (int) $event['id'];
        $event['latitude'] = (float) $event['latitude'];
        $event['longitude'] = (float) $event['longitude'];
    }

    json_response(['data' => $events, 'count' => count($events), 'limit' => $limit]);
} catch (Throwable $error) {
    error_log($error->__toString());
    json_response(['error' => 'The event archive is temporarily unavailable.'], 500);
}
