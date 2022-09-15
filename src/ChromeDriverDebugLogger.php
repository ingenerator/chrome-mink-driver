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

    private static ChromeDriverDebugLogger $instance;

    public static function instance(): ChromeDriverDebugLogger
    {
        if ( ! isset(static::$instance)) {
            throw new \RuntimeException('Init '.__CLASS__.' in your bootstrap-behat with a log file path to use');
        }

        return static::$instance;
    }

    public function initialise(string $log_dir)
    {
        // Use a unique file so it survives --rerun
        $now = new \DateTimeImmutable;

        static::$instance = new static(
            sprintf('%s/chromedriver-debug.%s.log.jsonl', $log_dir, $now->format('Y-m-d-H-i-s-u'))
        );
    }

    private function __construct(private string $log_file)
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

    public function logConnectionException(
        DevToolsConnection  $connection,
        ConnectionException $exception,
        string              $wait_reason
    ): void {
        $this->writeLog(
            [
                'client'  => $this->nameConnection($connection),
                'action'  => 'connectionException',
                'class'   => \get_class($exception),
                'waiting' => $wait_reason,
                'message' => $exception->getMessage(),
                'data'    => $exception->getData(),
                'trace'   => $exception->getTraceAsString(),
            ]
        );
    }

    public function logAnyException(
        string     $custom_msg,
        \Exception $exception
    ) {
        $this->writeLog(
            [
                'action'     => 'genericException',
                'class'      => \get_class($exception),
                'custom_msg' => $custom_msg,
                'message'    => $exception->getMessage(),
                'trace'      => $exception->getTraceAsString(),
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
            $this->log_file,
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
