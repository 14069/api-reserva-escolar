<?php

function stripEnvWrappingQuotes(string $value): string
{
    $length = strlen($value);
    if ($length >= 2) {
        $first = $value[0];
        $last = $value[$length - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($value, 1, -1);
        }
    }

    return $value;
}

function loadEnvFile(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separatorPosition = strpos($line, '=');
        if ($separatorPosition === false) {
            continue;
        }

        $key = trim(substr($line, 0, $separatorPosition));
        $value = trim(substr($line, $separatorPosition + 1));
        $value = stripEnvWrappingQuotes($value);

        if ($key === '') {
            continue;
        }

        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function getBootstrapConfigValue(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }

    return $default;
}

function configureRuntimeTimezone(): void
{
    $timezone = trim((string) getBootstrapConfigValue(
        'RESERVA_APP_TIMEZONE',
        getBootstrapConfigValue('APP_TIMEZONE', 'America/Araguaina')
    ));

    if ($timezone === '') {
        $timezone = 'America/Araguaina';
    }

    if (@date_default_timezone_set($timezone)) {
        return;
    }

    error_log('Invalid timezone configured for Reserva Escolar API: ' . $timezone);
    date_default_timezone_set('UTC');
}

function bootstrapEnv(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $baseDir = __DIR__;
    loadEnvFile($baseDir . '/.env');
    loadEnvFile($baseDir . '/.env.local');
    configureRuntimeTimezone();

    $loaded = true;
}

bootstrapEnv();
