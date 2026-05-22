<?php

declare(strict_types=1);

use App\Config;
use App\Database;
use App\Repositories\AdminRepository;

require __DIR__ . '/../../vendor/autoload.php';

define('ABS_PATH', dirname(__DIR__, 2));

$config = Config::load();
$db = Database::connect($config['db']);
$repo = new AdminRepository($db);

$username = getenv('ADMIN_USERNAME') ?: '';
$email = getenv('ADMIN_EMAIL') ?: '';
$password = getenv('ADMIN_PASSWORD') ?: '';

if ($username === '' || $email === '' || $password === '') {
    fwrite(STDERR, "Missing required env vars.\n");
    fwrite(STDERR, "Usage: ADMIN_USERNAME=... ADMIN_EMAIL=... ADMIN_PASSWORD=... php public_html/scripts/seed-admin.php\n");
    exit(1);
}

$existing = $repo->findByUsername($username);
if ($existing) {
    fwrite(STDOUT, "Admin user already exists: {$username}\n");
    exit(0);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$repo->create($username, $email, $passwordHash);

fwrite(STDOUT, "Created admin user: {$username}\n");
