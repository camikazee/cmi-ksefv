<?php

// Loopback-only subprocess fixture. No log of request headers or API keys.
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!$server) exit(2);
$address = stream_socket_get_name($server, false);
fwrite(STDOUT, substr($address, strrpos($address, ':') + 1)."\n");
fflush(STDOUT);
$client = stream_socket_accept($server, 5);
if (!$client) exit(3);
fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n{\"outcome\":");
fflush($client);
for ($part = 0; $part < 80; ++$part) {
    usleep(50_000);
    if (false === @fwrite($client, ' ')) break;
    fflush($client);
}
@fwrite($client, '"processing","processingStatus":100}');
fclose($client);
fclose($server);
