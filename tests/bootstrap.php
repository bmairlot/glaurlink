<?php

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Missing tests/.env file\n");
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim($value);
}

$host     = $env['TEST_HOST'] ?? 'localhost';
$port     = (int) ($env['TEST_PORT'] ?? 3306);
$user     = $env['TEST_USER'] ?? '';
$password = $env['TEST_PASSWORD'] ?? '';
$dbName   = 'test_' . random_int(1, 1000);

$dbh = new mysqli($host, $user, $password, '', $port);
if ($dbh->connect_errno) {
    fwrite(STDERR, "Cannot connect to MySQL: {$dbh->connect_error}\n");
    exit(1);
}

$result = $dbh->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$dbName'");
if ($result && $result->num_rows > 0) {
    fwrite(STDERR, "Database '$dbName' already exists — aborting to avoid data loss.\n");
    $dbh->close();
    exit(1);
}

if (!$dbh->query("CREATE DATABASE `$dbName`")) {
    fwrite(STDERR, "Failed to create database '$dbName': {$dbh->error}\n");
    $dbh->close();
    exit(1);
}

$dbh->close();

fwrite(STDOUT, "Created test database: $dbName\n");

register_shutdown_function(function () use ($host, $user, $password, $port, $dbName) {
    $dbh = new mysqli($host, $user, $password, '', $port);
    if (!$dbh->connect_errno) {
        $dbh->query("DROP DATABASE IF EXISTS `$dbName`");
        fwrite(STDOUT, "Dropped test database: $dbName\n");
        $dbh->close();
    }
});

define('TEST_DB_HOST', $host);
define('TEST_DB_PORT', $port);
define('TEST_DB_USER', $user);
define('TEST_DB_PASSWORD', $password);
define('TEST_DB_NAME', $dbName);

require_once dirname(__DIR__) . '/vendor/autoload.php';