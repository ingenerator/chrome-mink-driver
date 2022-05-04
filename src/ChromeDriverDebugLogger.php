<?php

namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;
use Ingenerator\PHPUtils\DateTime\DateTimeDiff;
use Ingenerator\PHPUtils\StringEncoding\JSON;
use WebSocket\ConnectionException;

class ChromeDriverDebugLogger
{
    private $scenario_id = 0;

    private $page_ready  = NULL;

    public static function instance(): ChromeDriverDebugLogger
    {
        static $instance;
        if ( ! $instance) {
            $instance = new static;
        }

        return $instance;
    }

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

        $vars = array_merge(
            [
                '@'          => ($now)->format('H:i:s.u'),
                '+ms'        => round(DateTimeDiff::microsBetween($last_logged, $now) / 1000, 3),
                'scenario'   => $this->scenario_id,
                'page_ready' => $this->page_ready,
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

    public function logPageReadyStateChange(bool $state, string $change_trigger)
    {
        $this->writeLog(
            [
                'action'  => 'pageReadyStateChange',
                'state'   => $state,
                'trigger' => $change_trigger,
            ]
        );
        $this->page_ready = $state;
    }

    public function beginScenario(string $file, string $line)
    {
        $this->scenario_id++;
        $this->writeLog(
            [
                'action'   => 'beginScenario',
                'scenarioName' => $file.':'.$line,
            ]
        );
    }

    public function endScenario(bool $is_passed)
    {
        $this->writeLog(
            [
                'action' => 'endScenario',
                'result' => $is_passed,
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
