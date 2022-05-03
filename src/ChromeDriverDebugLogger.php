<?php

namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;
use Ingenerator\PHPUtils\DateTime\DateTimeDiff;
use Ingenerator\PHPUtils\StringEncoding\JSON;
use WebSocket\ConnectionException;

class ChromeDriverDebugLogger
{

    public function __construct()
    {

    }

    public function logCommandSent(DevToolsConnection $connection, array $payload): void
    {
        $this->writeLog(
            [
                'client'  => $this->nameConnection($connection),
                'action'  => 'send',
                'payload' => $payload,
            ]
        );
    }

    public function logChromeResponse(DevToolsConnection $connection, array $response, string $wait_reason): void
    {
        $this->writeLog(
            [
                'client'   => $this->nameConnection($connection),
                'action'   => 'receive',
                'waiting'  => $wait_reason,
                'response' => $response,
            ]
        );
    }

    public function logNullResponse(DevToolsConnection $connection, string $wait_reason): void
    {
        $this->writeLog(
            [
                'client'  => $this->nameConnection($connection),
                'action'  => 'receiveEmpty',
                'waiting' => $wait_reason,
            ]
        );
    }

    public function logConnectionException(
        DevToolsConnection  $connection,
        ConnectionException $exception,
        string              $wait_reason
    ): void {
        $this->writeLog(
            [
                'client'  => $this->nameConnection($connection),
                'action'  => 'connectionException',
                'waiting' => $wait_reason,
                'message' => $exception->getMessage(),
                'data'    => $exception->getData(),
                'trace'   => $exception->getTraceAsString(),
            ]
        );
    }

    private function writeLog(array $vars)
    {
        static $last_logged;
        if ($last_logged === NULL) {
            $last_logged = new \DateTimeImmutable;
        }
        $now = new \DateTimeImmutable;

        $vars        = array_merge(
            [
                '@'   => ($now)->format('H:i:s.u'),
                '+ms' => round(DateTimeDiff::microsBetween($last_logged, $now) / 1000, 3),
            ],
            $vars
        );

        $last_logged = $now;

        \file_put_contents(
            PROJECT_BASE_DIR.'/build/logs/chromedriver-debug.log.jsonl',
            JSON::encode($vars, FALSE)."\n",
            FILE_APPEND
        );
    }

    public function logDriverException(DriverException $exception, string $context): void
    {
        $this->writeLog(
            [
                'action'  => 'driverException',
                'context' => $context,
                'message' => $exception->getMessage(),
                'code'    => $exception->getCode(),
                'trace'   => $exception->getTraceAsString(),
            ]
        );
    }

    private function nameConnection(DevToolsConnection $connection)
    {
        if ($connection instanceof ChromePage) {
            return 'page:'.\spl_object_id($connection);
        } elseif ($connection instanceof ChromeBrowser) {
            return 'browser:'.\spl_object_id($connection);
        } else {
            return \get_class($connection).':'.\spl_object_id($connection);
        }
    }
}
