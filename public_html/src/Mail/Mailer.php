<?php

declare(strict_types=1);

namespace App\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

final class Mailer
{
    public function __construct(private array $config)
    {
    }

    /**
     * @param array<int, array{cid: string, data: string, filename: string, mime: string}> $embeddedImages
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        array $embeddedImages = [],
    ): void
    {
        $smtp = $this->config['smtp'] ?? [];

        $mail = new PHPMailer(true);
        // Ensure UTF-8 headers (subjects, names) don't get mojibake like "â€”".
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isSMTP();
        $mail->Host = (string) ($smtp['host'] ?? '');
        $mail->Port = (int) ($smtp['port'] ?? 587);
        $mail->SMTPAuth = true;
        $mail->Username = (string) ($smtp['username'] ?? '');
        $mail->Password = (string) ($smtp['password'] ?? '');

        $encryption = $smtp['encryption'] ?? 'tls';
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $fromEmail = (string) ($smtp['from_email'] ?? 'no-reply@example.com');
        $fromName = (string) ($smtp['from_name'] ?? 'Simple Event Checkout');

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        if ($textBody) {
            $mail->AltBody = $textBody;
        }

        foreach ($embeddedImages as $img) {
            $cid = (string) ($img['cid'] ?? '');
            $data = (string) ($img['data'] ?? '');
            if ($cid === '' || $data === '') {
                continue;
            }
            $mail->addStringEmbeddedImage(
                $data,
                $cid,
                (string) ($img['filename'] ?? 'image.png'),
                'base64',
                (string) ($img['mime'] ?? 'image/png')
            );
        }

        $mail->send();
    }
}
