<?php
declare(strict_types=1);

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function query_string(string $name, int $maxLength = 120): ?string
{
    if (!isset($_GET[$name]) || !is_string($_GET[$name])) {
        return null;
    }

    $value = trim($_GET[$name]);
    if ($value === '') {
        return null;
    }

    return mb_substr($value, 0, $maxLength);
}

function query_date(string $name, bool $endOfDay = false): ?string
{
    $value = query_string($name, 10);
    if ($value === null) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Stockholm'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        json_response(['error' => "Invalid {$name}; expected YYYY-MM-DD."], 422);
    }

    if ($endOfDay) {
        $date = $date->setTime(23, 59, 59);
    }

    return $date->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
}
