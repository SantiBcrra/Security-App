<?php
declare(strict_types=1);

namespace App\Core;

/** Email MIME (texto + HTML, UTF-8). Arma encabezados y cuerpo listos para SMTP o para guardar como .eml. */
final class MailMessage
{
    public function __construct(
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
    ) {
    }

    public static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    /** Mensaje completo (encabezados + cuerpo) con CRLF. */
    public function toMime(): string
    {
        $boundary = 'b' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($this->fromEmail, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . self::encodeHeader($this->fromName) . ' <' . $this->fromEmail . '>',
            'To: <' . $this->to . '>',
            'Subject: ' . self::encodeHeader($this->subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'X-Mailer: SecurityApp',
        ];
        $part = fn (string $type, string $content) => "Content-Type: {$type}; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($content), 76, "\r\n"));
        if ($this->html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($this->text), 76, "\r\n")) . "\r\n";
        }
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        return implode("\r\n", $headers) . "\r\n\r\n"
            . "--{$boundary}\r\n" . $part('text/plain', $this->text) . "\r\n"
            . "--{$boundary}\r\n" . $part('text/html', $this->html) . "\r\n"
            . "--{$boundary}--\r\n";
    }
}
