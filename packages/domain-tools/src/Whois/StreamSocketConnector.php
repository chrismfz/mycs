<?php

namespace Nixpal\DomainTools\Whois;

/** Port-43 whois over a plain TCP stream, bounded in time and size. */
final class StreamSocketConnector implements SocketConnector
{
    public function query($host, $query, $timeout = 8, $max = 65536)
    {
        $socket = @stream_socket_client('tcp://' . $host . ':43', $errno, $error, $timeout);
        if (!$socket) {
            return null;
        }
        stream_set_timeout($socket, (int) $timeout);
        fwrite($socket, $query . "\r\n");
        $answer = '';
        $started = time();
        while (!feof($socket) && strlen($answer) < $max && time() - $started <= $timeout) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || ($chunk === '' && stream_get_meta_data($socket)['timed_out'])) {
                break;
            }
            $answer .= $chunk;
        }
        fclose($socket);

        return substr($answer, 0, $max);
    }
}
