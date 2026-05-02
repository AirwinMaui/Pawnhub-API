<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/vendor/autoload.php';

function envValue(string $key, ?string $default = null): ?string
{
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return trim($value);
}

function sendMail(string $toEmail, string $toName, string $subject, string $html): bool
{
    $smtpHost = envValue('SMTP_HOST');
    $smtpPort = (int)(envValue('SMTP_PORT', '587'));
    $smtpUsername = envValue('SMTP_USERNAME');
    $smtpPassword = envValue('SMTP_PASSWORD');
    $smtpEncryption = strtolower(envValue('SMTP_ENCRYPTION', 'tls'));
    $fromEmail = envValue('SMTP_FROM_EMAIL', $smtpUsername);
    $fromName = envValue('SMTP_FROM_NAME', 'Pawnhub');

    error_log('MAILER: Starting sendMail()');
    error_log('MAILER: SMTP_HOST=' . ($smtpHost ?: 'MISSING'));
    error_log('MAILER: SMTP_PORT=' . $smtpPort);
    error_log('MAILER: SMTP_USERNAME=' . ($smtpUsername ?: 'MISSING'));
    error_log('MAILER: SMTP_FROM_EMAIL=' . ($fromEmail ?: 'MISSING'));
    error_log('MAILER: SMTP_FROM_NAME=' . ($fromName ?: 'MISSING'));
    error_log('MAILER: SMTP_ENCRYPTION=' . $smtpEncryption);
    error_log('MAILER: To=' . $toEmail);

    if (!$smtpHost || !$smtpUsername || !$smtpPassword || !$fromEmail) {
        error_log('MAILER ERROR: SMTP configuration is incomplete.');
        return false;
    }

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('MAILER ERROR: Invalid recipient email: ' . $toEmail);
        return false;
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('MAILER ERROR: Invalid from email: ' . $fromEmail);
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();

        /*
          Set this to SMTP::DEBUG_SERVER only while debugging.
          It writes SMTP conversation details to Azure logs.
        */
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->Debugoutput = function ($str, $level) {
            error_log("SMTP DEBUG {$level}: {$str}");
        };

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

        /*
          Gmail usually needs TLS on port 587:
          SMTP_HOST=smtp.gmail.com
          SMTP_PORT=587
          SMTP_ENCRYPTION=tls
        */

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName ?: 'Customer');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = strip_tags(
            str_replace(['<br>', '<br/>', '<br />'], "\n", $html)
        );

        $result = $mail->send();

        error_log('MAILER: PHPMailer send result: ' . ($result ? 'success' : 'failed'));

        return $result;
    } catch (Exception $e) {
        error_log('MAILER ERROR: PHPMailer exception: ' . $e->getMessage());
        error_log('MAILER ERROR: PHPMailer error info: ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        error_log('MAILER ERROR: Throwable: ' . $e->getMessage());
        return false;
    }
}