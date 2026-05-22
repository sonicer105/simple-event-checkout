<?php

declare(strict_types=1);

use App\Config;
use App\Mail\Mailer;

require __DIR__ . '/../../vendor/autoload.php';

define('ABS_PATH', dirname(__DIR__, 2));

$config = Config::load();

$toEmail = getenv('TO_EMAIL') ?: '';
$toName = getenv('TO_NAME') ?: 'Test Recipient';

if ($toEmail === '') {
    fwrite(STDERR, "Missing TO_EMAIL env var.\n");
    fwrite(STDERR, "Usage: TO_EMAIL=you@example.com [TO_NAME='Your Name'] php public_html/scripts/test-smtp.php\n");
    exit(1);
}

$smtp = $config['smtp'] ?? [];
$host = (string) ($smtp['host'] ?? '');
$port = (int) ($smtp['port'] ?? 0);
$encryption = (string) ($smtp['encryption'] ?? '');
$fromEmail = (string) ($smtp['from_email'] ?? '');
$fromName = (string) ($smtp['from_name'] ?? '');
$username = (string) ($smtp['username'] ?? '');

fwrite(STDOUT, "SMTP test configuration:\n");
fwrite(STDOUT, "- host: {$host}\n");
fwrite(STDOUT, "- port: {$port}\n");
fwrite(STDOUT, "- encryption: {$encryption}\n");
fwrite(STDOUT, "- from: {$fromName} <{$fromEmail}>\n");
fwrite(STDOUT, "- username: " . ($username !== '' ? '(set)' : '(empty)') . "\n");

$subject = getenv('SUBJECT') ?: 'SMTP Test - Simple Event Checkout';
$now = date('Y-m-d H:i:s');
$html = "<p>SMTP test succeeded.</p><p>Sent at <strong>{$now}</strong>.</p>";
$text = "SMTP test succeeded.\nSent at {$now}.\n";

$mailer = new Mailer($config);
$mailer->send($toEmail, $toName, $subject, $html, $text);

fwrite(STDOUT, "Sent SMTP test email to {$toName} <{$toEmail}>.\n");
