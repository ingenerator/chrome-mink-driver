<?php
namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;
use WebSocket\Client as WebsocketClient;
use WebSocket\ConnectionException;
use WebSocket\TimeoutException;

abstract class DevToolsConnection
{
    /** @var WebsocketClient */
    private $client;
    /** @var int */
    private $command_id = 1;
    /** @var string */
    private $url;
    /** @var int|null */
    private $socket_timeout;

    protected ChromeDriverDebugLogger $logger;

    public function __construct($url, $socket_timeout = null)
    {
        $this->url = $url;
        $this->socket_timeout = $socket_timeout;
        $this->logger = ChromeDriverDebugLogger::instance();
    }

    public function canDevToolsConnectionBeEstablished()
    {
        // It would need instead to get & call the HTTP API url, but it's actually pointless to do that anyway
        // because realistically in almost every case the connection has only timed out because Chrome has nothing
        // to say and connecting to an HTTP API endpoint doesn't really tell us anything anyway.
        throw new \BadMethodCallException(
            __METHOD__.
            ' has been removed because it always returned false due to adding a path to the end of the websocket URL'
        );
    }

    protected function getUrl()
    {
        return $this->url;
    }

    public function connect($url = null)
    {
        $url = $url == null ? $this->url : $url;
        $options = ['fragment_size' => 2000000]; # Chrome closes the connection if a message is sent in fragments
        if (is_numeric($this->socket_timeout) && $this->socket_timeout > 0) {
            $options['timeout'] = (int) $this->socket_timeout;
        }
        $this->client = new ConnLoggingWebsocketClient($url, $options);
    }

    public function close()
    {
        $this->client->close();
    }

    /**
     * @param string $command
     * @param array $parameters
     * @return null|string|string[][]
     * @throws \Exception
     */
    public function send($command, array $parameters = [], ?\DateTimeImmutable $timeout = NULL)
    {
        $payload['id'] = $this->command_id++;
        $payload['method'] = $command;
        if (!empty($parameters)) {
            $payload['params'] = $parameters;
        }

        $this->logger->logCommandSent($this, $payload);
        $this->client->send(json_encode($payload));

        $data = $this->waitFor(function ($data) use ($payload) {
            return array_key_exists('id', $data) && $data['id'] == $payload['id'];
        },
            'send-'.$payload['id'],
            $timeout
        );

        if (isset($data['result'])) {
            return $data['result'];
        }

        return ['result' => ['type' => 'undefined']];
    }

    protected function waitFor(callable $is_ready, string $debug_reason, ?\DateTimeImmutable $timeout = NULL)
    {
        // @todo: What's the best way to set overall timeouts for operations?
        // Or do we just solidly say that realistically any single step should never be more than 90 seconds say?
        $timeout ??= new \DateTimeImmutable('+90 seconds');
        while (TRUE) {
            if ($timeout && (new \DateTimeImmutable > $timeout)) {
                // Sometimes the socket itself doesn't time out (e.g. if a page is sending AJAX requests
                // on a timer of some kind but the `load` event has never fired). I'm still not clear on
                // underlying cause of us never getting to page load status, but we definitely don't want this
                // to loop infinitely until build timeout.
                throw new DriverException(
                    'Timed out waiting for Chrome state - websocket is healthy'
                );
            }

            try {
                $response = $this->client->receive();
            } catch (TimeoutException $exception) {
                $this->logger->logConnectionException($this, $exception, $debug_reason);

                // A stream read timeout generally just happens when Chrome has nothing to report for the duration
                // of the stream timeout. This may mean that:
                // a) the page is idle & waiting on user action / a timer to trigger some action
                // b) a server-side pageload has taken unusually long (there are no websocket packets while the main
                //    document request is pending, so if the server-side pageload takes longer than the socket timeout
                //    then you will always get a read timeout.
                // c) a page is loading but e.g. a child request is hanging so again there is no activity to report
                //    and no events to fire.
                if ($timeout && ($timeout > new \DateTimeImmutable)) {
                    // We are still within the application level timeout that the caller provided. Just retry reading
                    continue;
                }

                // If the caller did not provide a timeout, then we should not retry and should just throw.
                throw new DriverException(
                    sprintf(
                        'Timed out reading from Chrome: %s %s',
                        $exception->getMessage(),
                        \json_encode($exception->getData())
                    ),
                    0,
                    $exception
                );
            } catch (ConnectionException $exception) {
                $this->logger->logConnectionException($this, $exception, $debug_reason);

                // These are not just a simple timeout, they represent a more failure error on the socket, treat them
                // as outright failures and just rethrow.
                throw new DriverException(
                    sprintf(
                        'Error reading from Chrome: %s (%s)',
                        $exception->getMessage(),
                        \json_encode($exception->getData())
                    ),
                    0,
                    $exception
                );
            }

            if (is_null($response)) {
                // I can't see any valid case where we actually can get an explict `null` value from the websocket
                // other than the socket being closed by Chrome. In any event if it does happen it surely shouldn't
                // cause us to quit out of the entire thing we're waiting for without processing further events, that
                // seems entirely wrong - potentially this should have been a `continue`. There's no description
                // of why this was here in code or commit messages, it has been there ever since the initial commit of
                // the project.
                throw new DriverException(
                    'Received unexpected NULL payload from Chrome websocket'
                );
            }
            $data = json_decode($response, true);

            $this->logger->logChromeResponse($this, $data, $debug_reason);

            if (array_key_exists('error', $data)) {
                $message = isset($data['error']['data']) ? $data['error']['message'] . '. ' . $data['error']['data'] : $data['error']['message'];
                throw new DriverException($message , $data['error']['code']);
            }

            $this->processResponse($data);

            if ($is_ready($data)) {
                return $data;
            }
        }
    }

    /**
     * @param array $data
     * @return void
     */
    abstract protected function processResponse(array $data):void;
}

class ConnLoggingWebsocketClient extends WebsocketClient
{
    private $conn_count = 0;

    public function connect(): void
    {
        $this->conn_count++;
        if ($this->conn_count > 1) {
            ChromeDriverDebugLogger::instance()->logAnyException(
                'Chrome socket connection reopened (#'.$this->conn_count.')',
                new \RuntimeException('Socket re-connected?')
            );
        }
        parent::connect();
    }

}
