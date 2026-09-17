<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$db = database();
$startedAt = gmdate(DateTimeInterface::ATOM);
$run = $db->prepare("INSERT INTO import_runs(source, started_at, status) VALUES ('polisen.se', :started, 'running')");
$run->execute([':started' => $startedAt]);
$runId = (int) $db->lastInsertId();

try {
    $context = stream_context_create([
        'http' => [
            'timeout' => 25,
            'user_agent' => 'CrimeRadarSweden/1.0 (+educational project)',
            'ignore_errors' => true,
        ],
    ]);
    $response = file_get_contents('https://polisen.se/api/events', false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
        throw new RuntimeException('Police API request failed: ' . $statusLine);
    }

    $events = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($events)) {
        throw new RuntimeException('Police API returned an unexpected response.');
    }

    $upsert = $db->prepare(
        'INSERT INTO events(police_id, occurred_at, title, summary, event_type, location_name, latitude, longitude, source_url)
         VALUES (:police_id, :occurred_at, :title, :summary, :event_type, :location_name, :latitude, :longitude, :source_url)
         ON CONFLICT(police_id) DO UPDATE SET
            occurred_at = excluded.occurred_at,
            title = excluded.title,
            summary = excluded.summary,
            event_type = excluded.event_type,
            location_name = excluded.location_name,
            latitude = excluded.latitude,
            longitude = excluded.longitude,
            source_url = excluded.source_url,
            updated_at = CURRENT_TIMESTAMP'
    );

    $written = 0;
    $db->beginTransaction();
    foreach ($events as $event) {
        $gps = explode(',', (string) ($event['location']['gps'] ?? ''));
        if (!isset($event['id'], $event['datetime'], $event['name'], $event['type'], $event['location']['name']) || count($gps) !== 2) {
            continue;
        }

        $date = new DateTimeImmutable((string) $event['datetime']);
        $latitude = filter_var(trim($gps[0]), FILTER_VALIDATE_FLOAT);
        $longitude = filter_var(trim($gps[1]), FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $longitude === false) {
            continue;
        }

        $url = (string) ($event['url'] ?? '');
        if ($url !== '' && !str_starts_with($url, 'http')) {
            $url = 'https://polisen.se' . (str_starts_with($url, '/') ? '' : '/') . $url;
        }

        $upsert->execute([
            ':police_id' => (int) $event['id'],
            ':occurred_at' => $date->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
            ':title' => (string) $event['name'],
            ':summary' => (string) ($event['summary'] ?? ''),
            ':event_type' => (string) $event['type'],
            ':location_name' => (string) $event['location']['name'],
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':source_url' => $url,
        ]);
        $written++;
    }
    $db->commit();

    $finish = $db->prepare("UPDATE import_runs SET completed_at = :completed, status = 'success', records_seen = :seen, records_written = :written WHERE id = :id");
    $finish->execute([
        ':completed' => gmdate(DateTimeInterface::ATOM),
        ':seen' => count($events),
        ':written' => $written,
        ':id' => $runId,
    ]);
    fwrite(STDOUT, "Imported {$written} of " . count($events) . " police events.\n");
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $finish = $db->prepare("UPDATE import_runs SET completed_at = :completed, status = 'failed', message = :message WHERE id = :id");
    $finish->execute([
        ':completed' => gmdate(DateTimeInterface::ATOM),
        ':message' => mb_substr($error->getMessage(), 0, 500),
        ':id' => $runId,
    ]);
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
