<?php
declare(strict_types=1);

/**
 * Return the shared SQLite connection and create the schema on first use.
 */
function database(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $defaultPath = dirname(__DIR__) . '/data/crime_radar.sqlite';
    $databasePath = getenv('CRIME_RADAR_DB_PATH') ?: $defaultPath;
    $directory = dirname($databasePath);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the SQLite data directory.');
    }

    $connection = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec('PRAGMA foreign_keys = ON');
    $connection->exec('PRAGMA busy_timeout = 5000');

    $schema = file_get_contents(dirname(__DIR__) . '/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Could not read schema.sql.');
    }
    $connection->exec($schema);

    return $connection;
}
