<?php

declare(strict_types=1);

// A minimal host entry point proving process isolation and shared durable state.
$database = new PDO('sqlite:'.__DIR__.'/operations.sqlite');
$options = [];

foreach (array_slice($argv, 3) as $argument) {
    [$name, $value] = array_pad(explode('=', $argument, 2), 2, true);
    $options[$name] = $value;
}

if (($argv[1] ?? null) !== 'module:install' || ! isset($options['--operation'])) {
    exit(1);
}

$start = $database->prepare("UPDATE module_installations SET status = 'preparing' WHERE id = ? AND key = ? AND status = 'queued' AND started_at IS NOT NULL");
$start->execute([$options['--operation'], $argv[2]]);

if ($start->rowCount() !== 1) {
    exit(1);
}

$install = $database->prepare("UPDATE module_installations SET status = 'installing' WHERE id = ? AND status = 'preparing'");
$install->execute([$options['--operation']]);
$record = $database->prepare('INSERT INTO child_observations (arguments, process_id) VALUES (?, ?)');
$record->execute([json_encode($argv, JSON_THROW_ON_ERROR), getmypid()]);

// Deliberately sensitive output must never be copied into operation storage.
fwrite(STDERR, 'password=private-token');
