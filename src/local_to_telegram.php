<?php
/**
 * Filippo Finke
 */

define("MADELINE_BRANCH", "master");

require __DIR__ . '/../vendor/autoload.php';

if (count($argv) != 3) {
    echo "ℹ️ Usage: php ".$argv[0].".php HOST:PORT @channel" . PHP_EOL;
    exit;
}
$host = $argv[1];
$channel = $argv[2];

$sockets = [];

$settings = [];
$settings['logger']['logger_level'] = \danog\MadelineProto\Logger::ERROR;
$MadelineProto = new \danog\MadelineProto\API('local_to_telegram.madeline', $settings);
$MadelineProto->async(true);
$MadelineProto->setCallback(function ($update) use (&$sockets, $MadelineProto) {
    if ($update["_"] === "updateNewChannelMessage" &&
    !$update["message"]["out"]) {
        $message = str_replace("socket:", "", $update["message"]["message"]);
        if (isset($sockets[$message])) {
            $socket = $sockets[$message];
            try {
                [$to_ip, $to_port] = \explode(':', $socket->getRemoteAddress());
                $file = yield $MadelineProto->download_to_dir($update, 'storage/');
                $content = file_get_contents($file);
                unlink($file);
                printf("📡 ID:%d TELEGRAM -> %s:%d [%d bytes]".PHP_EOL, $message, $to_ip, $to_port, strlen($content));
                yield $socket->write($content);
            } catch (\Amp\ByteStream\ClosedException $e) {
                printf("❌ %s:%d Socket error: ".$e->getMessage().PHP_EOL, $to_port, $to_port);
                $socket->close();
            } catch(\danog\MadelineProto\Exception $e) {
                echo "❌ RIP".PHP_EOL;
                $socket->close();
            }
        }
    }
});
$MadelineProto->loop(function () use ($host, &$sockets, $MadelineProto, $channel) {
    $forwarder = function ($from, $id) use ($sockets, $MadelineProto, $channel) {
        [$from_ip, $from_port] = \explode(':', $from->getRemoteAddress());
        try {
            while (($read = yield $from->read()) !== null) {
                printf("📡 ID:%d %s:%d -> TELEGRAM [%d bytes]".PHP_EOL, $id, $from_ip, $from_port, strlen($read));
                $filename = uniqid().".txt";
                file_put_contents('storage/'.$filename, $read);
                yield $MadelineProto->messages->sendMedia([
                    'peer' => $channel,
                    'media' => [
                        '_' => 'inputMediaUploadedDocument',
                        'file' => 'storage/'.$filename
                    ],
                    'message' => 'socket:'.$id
                ]);
                unlink('storage/'.$filename);
            }
        } catch (\Amp\ByteStream\ClosedException $e) {
        }
        printf("❌ %s:%d Socket closed!".PHP_EOL, $from_ip, $from_port);
        $from->close();
    };

    $createServer = function () use ($host, &$sockets, $forwarder) {
        $server = \Amp\Socket\listen($host);

        echo '⌛ Listening for new connections on ' . $server->getAddress() . ' ...' . PHP_EOL;
        while ($socket = yield $server->accept()) {
            echo '💻 Now forwarding from ' . $server->getAddress() . ' to telegram for ' . $socket->getRemoteAddress() . PHP_EOL;
            $sockets[] = $socket;
            \Amp\asyncCall($forwarder, $socket, count($sockets) - 1);
        }
    };
    yield $MadelineProto->start();
    $me = yield $MadelineProto->get_self();
    printf("🤖 Logged as @%s [%d]".PHP_EOL, $me["username"], $me["id"]);
    \Amp\asyncCall($createServer);
});
$MadelineProto->loop();
