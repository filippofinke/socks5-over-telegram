<?php
/**
 * Class that allows you to forward tcp packets from an host to another.
 * 
 * Filippo Finke
 */

require __DIR__ . '/../vendor/autoload.php';

use Amp\Loop;
use Amp\Socket\ResourceSocket;
use Amp\Socket\Server;
use function Amp\asyncCoroutine;
use function Amp\Socket\connect;

if(count($argv) != 3) {
    echo "ℹ️ Usage: php ".$argv[0].".php HOST:PORT FORWARD_TO:PORT" . PHP_EOL;
    exit;
}
$host = $argv[1];
$forward_to = $argv[2];

Loop::run(function () use ($host, $forward_to) {

    $forwarder = asyncCoroutine(function ($from, $to) {
        [$from_ip, $from_port] = \explode(':', $from->getRemoteAddress());
        [$to_ip, $to_port] = \explode(':', $to->getRemoteAddress());
        while (($read = yield $from->read()) !== null) {
            printf("📡 %s:%d -> %s:%d [%d bytes]".PHP_EOL, $from_ip, $from_port, $to_ip, $to_port, strlen($read));
            $to->write($read);
        }
        $from->close();
    });

    $server = Server::listen($host);

    echo '⌛ Listening for new connections on ' . $server->getAddress() . ' ...' . PHP_EOL;

    while ($socket = yield $server->accept()) {
        $socks = yield connect($forward_to);
        echo '💻 Now forwarding from ' . $server->getAddress() . ' to ' . $socks->getRemoteAddress() . ' for ' . $socket->getRemoteAddress() . PHP_EOL;
        $forwarder($socket, $socks);
        $forwarder($socks, $socket);
    }
});
