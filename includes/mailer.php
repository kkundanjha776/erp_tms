<?php
require_once __DIR__ . '/license_config.php';

function smtpReadResponse($socket): string {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) break;
        $response .= $line;
        if (preg_match('/^\d{3} /', $line)) break;
    }
    return $response;
}

function smtpCommand($socket, string $command, array $expected): bool {
    if ($command !== '') fwrite($socket, $command . "\r\n");
    $response = smtpReadResponse($socket);
    return in_array((int)substr($response, 0, 3), $expected, true);
}

function sendOwnerMail(string $subject, string $body): bool {
    if (SMTP_APP_PASSWORD === '') return false;
    $socket = @stream_socket_client('tcp://' . SMTP_HOST . ':' . SMTP_PORT, $errno, $error, 15);
    if (!$socket) { error_log('SMTP connection failed: ' . $error); return false; }
    stream_set_timeout($socket, 15);
    $ok = smtpCommand($socket, '', [220])
        && smtpCommand($socket, 'EHLO localhost', [250])
        && smtpCommand($socket, 'STARTTLS', [220]);
    if ($ok && !@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) $ok = false;
    $ok = $ok && smtpCommand($socket, 'EHLO localhost', [250])
        && smtpCommand($socket, 'AUTH LOGIN', [334])
        && smtpCommand($socket, base64_encode(SMTP_USERNAME), [334])
        && smtpCommand($socket, base64_encode(SMTP_APP_PASSWORD), [235])
        && smtpCommand($socket, 'MAIL FROM:<' . SYSTEM_OWNER_MAIL_FROM . '>', [250])
        && smtpCommand($socket, 'RCPT TO:<' . SYSTEM_OWNER_EMAIL . '>', [250, 251])
        && smtpCommand($socket, 'DATA', [354]);
    if ($ok) {
        $safeBody = str_replace("\n.", "\n..", str_replace("\r\n", "\n", $body));
        $message = 'From: ERP Owner Control <' . SYSTEM_OWNER_MAIL_FROM . ">\r\n"
            . 'To: <' . SYSTEM_OWNER_EMAIL . ">\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            . str_replace("\n", "\r\n", $safeBody) . "\r\n.";
        $ok = smtpCommand($socket, $message, [250]);
    }
    smtpCommand($socket, 'QUIT', [221]);
    fclose($socket);
    return $ok;
}
