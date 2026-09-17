<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/http.php';

try {
    $db = database();
    $from = query_date('from');
    $to = query_date('to', true);
    $where = [];
    $params = [];

    if ($from !== null) {
        $where[] = 'occurred_at >= :from';
        $params[':from'] = $from;
    }
    if ($to !== null) {
        $where[] = 'occurred_at <= :to';
        $params[':to'] = $to;
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $runQuery = static function (PDO $db, string $sql, array $params): array {
        $statement = $db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    };

    $totals = $runQuery($db, 'SELECT COUNT(*) AS events, COUNT(DISTINCT event_type) AS types, COUNT(DISTINCT location_name) AS locations FROM events' . $whereSql, $params)[0];
    $byType = $runQuery($db, 'SELECT event_type AS label, COUNT(*) AS value FROM events' . $whereSql . ' GROUP BY event_type ORDER BY value DESC LIMIT 10', $params);
    $byLocation = $runQuery($db, 'SELECT location_name AS label, COUNT(*) AS value FROM events' . $whereSql . ' GROUP BY location_name ORDER BY value DESC LIMIT 10', $params);
    $byDay = $runQuery($db, "SELECT substr(occurred_at, 1, 10) AS label, COUNT(*) AS value FROM events{$whereSql} GROUP BY substr(occurred_at, 1, 10) ORDER BY label DESC LIMIT 30", $params);
    $byDay = array_reverse($byDay);
    $historical = $db->query('SELECT year, SUM(incident_count) AS value FROM crime_statistics GROUP BY year ORDER BY year')->fetchAll();

    foreach (['byType', 'byLocation', 'byDay', 'historical'] as $seriesName) {
        foreach ($$seriesName as &$row) {
            $row['value'] = (int) $row['value'];
        }
        unset($row);
    }

    json_response([
        'totals' => array_map('intval', $totals),
        'by_type' => $byType,
        'by_location' => $byLocation,
        'by_day' => $byDay,
        'historical' => $historical,
    ]);
} catch (Throwable $error) {
    error_log($error->__toString());
    json_response(['error' => 'Statistics are temporarily unavailable.'], 500);
}
