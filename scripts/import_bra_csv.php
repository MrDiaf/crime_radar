<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$filename = $argv[1] ?? null;
if ($filename === null || !is_readable($filename)) {
    fwrite(STDERR, "Usage: php scripts/import_bra_csv.php path/to/statistics.csv\n");
    exit(1);
}

$handle = fopen($filename, 'rb');
if ($handle === false) {
    throw new RuntimeException('Could not open the CSV file.');
}

$firstLine = fgets($handle);
if ($firstLine === false) {
    throw new RuntimeException('The CSV file is empty.');
}
$delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
rewind($handle);
$headers = fgetcsv($handle, 0, $delimiter);
$normalize = static function (string $value): string {
    $value = trim($value, "\xEF\xBB\xBF \t\n\r\0\x0B");
    return strtolower(str_replace(['Å', 'Ä', 'Ö', 'å', 'ä', 'ö', ' '], ['A', 'A', 'O', 'a', 'a', 'o', '_'], $value));
};
$headers = array_map($normalize, $headers ?: []);

$aliases = [
    'year' => ['year', 'ar'],
    'region' => ['region', 'lan', 'omrade'],
    'crime_type' => ['crime_type', 'brottstyp', 'brott'],
    'incident_count' => ['incident_count', 'antal', 'count'],
];
$columns = [];
foreach ($aliases as $target => $names) {
    foreach ($names as $name) {
        $index = array_search($name, $headers, true);
        if ($index !== false) {
            $columns[$target] = $index;
            break;
        }
    }
}
if (count($columns) !== 4) {
    fwrite(STDERR, "CSV headers must describe year/ar, region/lan, crime_type/brottstyp and incident_count/antal.\n");
    exit(1);
}

$db = database();
$statement = $db->prepare(
    "INSERT INTO crime_statistics(year, region, crime_type, incident_count, source)
     VALUES (:year, :region, :crime_type, :count, 'Brå')
     ON CONFLICT(year, region, crime_type, source) DO UPDATE SET
       incident_count = excluded.incident_count,
       imported_at = CURRENT_TIMESTAMP"
);
$written = 0;
$db->beginTransaction();
while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
    $year = filter_var($row[$columns['year']] ?? null, FILTER_VALIDATE_INT);
    $count = filter_var(str_replace([' ', "\xC2\xA0"], '', $row[$columns['incident_count']] ?? ''), FILTER_VALIDATE_INT);
    $region = trim($row[$columns['region']] ?? '');
    $crimeType = trim($row[$columns['crime_type']] ?? '');
    if ($year === false || $count === false || $count < 0 || $region === '' || $crimeType === '') {
        continue;
    }
    $statement->execute([':year' => $year, ':region' => $region, ':crime_type' => $crimeType, ':count' => $count]);
    $written++;
}
$db->commit();
fclose($handle);
fwrite(STDOUT, "Imported {$written} Brå statistic rows.\n");
