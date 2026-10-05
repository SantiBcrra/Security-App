<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Cliente SMTP propio (sin PHPMailer): SSL directo (465) o STARTTLS (587/25), AUTH LOGIN,
 * timeouts cortos. Lanza \RuntimeException con la respuesta del servidor si algo falla.
 */
final class Smtp
{
    /** @var resource|null */
    private $socket = null;

    /**
     * @param array{host:string, port:int, security:'ssl'|'tls'|'none', username:string, password:string, timeout?:int} $config
     */
    public function send(array $config, MailMessage $message): void
    {
        $timeout = (int) ($config['timeout'] ?? 8);
        $remote = ($config['security'] === 'ssl' ? 'ssl://' : 'tcp://') . $config['host'] . ':' . (int) $config['port'];
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            throw new \RuntimeException("No se pudo conectar a {$config['host']}:{$config['port']} ({$errstr})");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $timeout);
        try {
            $this->expect(220);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            $this->command($ehlo, 250);
            if ($config['security'] === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('No se pudo iniciar TLS (STARTTLS).');
                }
                $this->command($ehlo, 250);
            }
            if (($config['username'] ?? '') !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($config['username']), 334);
                $this->command(base64_encode($config['password']), 235, true);
            }
            $this->command('MAIL FROM:<' . $message->fromEmail . '>', 250);
            $this->command('RCPT TO:<' . $message->to . '>', [250, 251]);
            $this->command('DATA', 354);
            $this->write(self::dotStuff($message->toMime()) . "\r\n.");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    /** Una línea que empieza con "." se duplica (si no, el servidor la toma como fin del mensaje). */
    public static function dotStuff(string $data): string
    {
        $data = preg_replace("/\r?\n/", "\r\n", $data);
        return preg_replace('/^\./m', '..', $data);
    }

    private function command(string $line, int|array $expected, bool $secret = false): void
    {
        $this->write($line);
        $this->expect($expected, $secret ? '[credencial]' : $line);
    }

    private function write(string $line): void
    {
        if (fwrite($this->socket, $line . "\r\n") === false) {
            throw new \RuntimeException('Se cortó la conexión con el servidor de correo.');
        }
    }

    private function expect(int|array $codes, string $after = ''): void
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $codes, true)) {
            $meta = stream_get_meta_data($this->socket);
            $reason = $meta['timed_out'] ? 'tiempo de espera agotado' : trim($response);
            throw new \RuntimeException('SMTP: ' . ($after !== '' ? "después de \"{$after}\": " : '') . ($reason ?: 'sin respuesta'));
        }
    }
}
