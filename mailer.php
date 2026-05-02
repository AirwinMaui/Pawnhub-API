<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/vendor/autoload.php';

function sendMail(string $toEmail, string $toName, string $subject, string $html): bool
{
    $smtpHost = getenv('SMTP_HOST');
    $smtpPort = (int)(getenv('SMTP_PORT') ?: 587);
    $smtpUsername = getenv('SMTP_USERNAME');
    $smtpPassword = getenv('SMTP_PASSWORD');
    $smtpEncryption = strtolower(getenv('SMTP_ENCRYPTION') ?: 'tls');
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: $smtpUsername;
    $fromName = getenv('SMTP_FROM_NAME') ?: 'Pawnhub';

    if (!$smtpHost || !$smtpUsername || !$smtpPassword || !$fromEmail) {
        error_log('SMTP configuration is incomplete.');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->Port = $smtpPort;
        $mail->CharSet = 'UTF-8';

        if ($smtpEncryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName ?: 'Customer');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = strip_tags(
            str_replace(['<br>', '<br/>', '<br />'], "\n", $html)
        );

        return $mail->send();
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $mail->ErrorInfo);
        return false;
    }
}