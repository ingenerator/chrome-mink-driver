<?php
namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;
use WebSocket\ConnectionException;

class ChromePage extends DevToolsConnection
{
    /** @var array */
    private $pending_requests = [];
    /** @var bool */
    private $page_ready = true;
    /** @var array https://chromedevtools.github.io/devtools-protocol/tot/Network/#type-Response */
    private $response = null;
    /** @var array */
    private $console_messages = [];
    /** @var callable */
    private $javascript_dialog_handler;

    public function connect($url = null)
    {
        parent::connect();
        $this->send('Page.enable');
        $this->send('DOM.enable');
        $this->send('Network.enable');
        $this->send('Animation.enable');
        $this->send('Animation.setPlaybackRate', ['playbackRate' => 100000]);
        $this->send('Console.enable');
    }

    public function reset()
    {
        $this->response                  = null;
        $this->javascript_dialog_handler = null;
    }

    public function visit($url)
    {
        if (count($this->pending_requests) > 0) {
            $this->waitFor(function () {
                return count($this->pending_requests) == 0;
            });
        }
        $this->response = null;
        $this->page_ready = false;
        $this->send('Page.navigate', ['url' => $url]);
    }

    public function reload()
    {
        $this->page_ready = false;
        $this->send('Page.reload');
    }

    public function waitForLoad()
    {
        if (!$this->page_ready) {
            try {
                $this->waitFor(function () {
                    return $this->page_ready;
                });
            } catch (StreamReadException $exception) {
                if ($exception->isTimedOut() && false === $this->canDevToolsConnectionBeEstablished()) {
                    throw new \RuntimeException(
                        sprintf(
                            'Chrome is unreachable via "%s" and might have crashed. Please see docs/troubleshooting.md',
                            $this->getUrl()
                        )
                    );
                }

                if (!$exception->isEof() && $exception->isTimedOut()) {
                    $this->waitForLoad();
                }
            } catch (ConnectionException $exception) {
                throw new DriverException("Page not loaded");
            }
        }
    }

    public function getResponse()
    {
        $this->waitForHttpResponse();

        return $this->response;
    }

    public function getTabs()
    {
        $tabs = [];
        foreach ($this->send('Target.getTargets')['targetInfos'] as $tab) {
            if ($tab['type'] == 'page') {
                $tabs[] = $tab;
            }
        }
        return array_reverse($tabs, true);
    }

  /**
   * Get all console messages since start or last clear.
   *
   * @return array
   */
    public function getConsoleMessages()
    {
        return $this->console_messages;
    }

    /**
     * Clear the stored console messages.
     *
     * @return array
     */
    public function clearConsoleMessages()
    {
        $this->console_messages = [];
    }

    private function waitForHttpResponse()
    {
        if (null === $this->response) {
            $parameters = ['expression' => 'document.readyState == "complete"'];
            $domReady = $this->send('Runtime.evaluate', $parameters)['result']['value'];
            if (count($this->pending_requests) == 0 && $domReady) {
                $this->response = [
                    'status' => 200,
                    'headers' => [],
                ];
                return;
            }

            $this->waitFor(function () {
                return null !== $this->response && count($this->pending_requests) == 0;
            });
        }
    }

    /**
     * @param array $data
     * @return bool
     * @throws DriverException
     */
    protected function processResponse(array $data)
    {
        if (array_key_exists('method', $data)) {
            switch ($data['method']) {
                case 'Page.javascriptDialogOpening':
                    $this->processJavascriptDialog($data);
                    break;
                case 'Page.javascriptDialogClosed':
                    // Nothing specific to do here, just ignore it
                    break;
                case 'Network.requestWillBeSent':
                    if ($data['params']['type'] == 'Document') {
                        $this->pending_requests[$data['params']['requestId']] = true;
                    }
                    break;
                case 'Network.responseReceived':
                    if ($data['params']['type'] == 'Document') {
                        unset($this->pending_requests[$data['params']['requestId']]);
                        $this->response = $data['params']['response'];
                    }
                    break;
                case 'Network.loadingFailed':
                    if ($data['params']['canceled']) {
                        unset($this->pending_requests[$data['params']['requestId']]);
                    }
                    break;
                case 'Page.frameNavigated':
                case 'Page.loadEventFired':
                case 'Page.frameStartedLoading':
                    $this->page_ready = false;
                    break;
                case 'Page.navigatedWithinDocument':
                case 'Page.frameStoppedLoading':
                    $this->page_ready = true;
                    break;
                case 'Inspector.targetCrashed':
                    throw new DriverException('Browser crashed');
                    break;
                case 'Animation.animationStarted':
                    if (!empty($data['params']['source']['duration'])) {
                        usleep($data['params']['source']['duration'] * 10);
                    }
                    break;
                case 'Security.certificateError':
                    if (isset($data['params']['eventId'])) {
                        $this->send('Security.handleCertificateError', ['eventId' => $data['params']['eventId'], 'action' => 'continue']);
                        $this->page_ready = false;
                    }
                    break;
                case 'Console.messageAdded':
                    $this->console_messages[] = $data['params']['message'];
                    break;
                default:
                    break;
            }
        }

        return FALSE;
    }

    protected function processJavascriptDialog(array $data)
    {
        try {
            $handler = $this->javascript_dialog_handler
                ?: function () {
                    throw new \UnexpectedValueException(
                        'No javascript dialog handler was registered'
                    );
                };
            $outcome = $handler($data['params']);
            if ( ! isset($outcome['accept'])) {
                throw new \InvalidArgumentException(
                    'javascript dialog handlers must return an `accept` property'
                );
            }
        } catch (\Throwable $e) {
            $outcome = [
                // Default behaviour for a `beforeunload` should be to accept it so the page can
                // navigate.
                // For all others it should be to cancel (if possible) to prevent unexpected further
                // executions.
                'accept' => $data['params']['type'] === 'beforeunload' ? TRUE : FALSE,
                'error'  => $e
            ];
        }

        $this->send(
            'Page.handleJavaScriptDialog',
            [
                'accept'     => $outcome['accept'] ?? $default_accept,
                'promptText' => $outcome['promptText'] ?? ''
            ]
        );

        // Note: we don't currently wait for the dialog to be closed - this should in theory be the
        // very next operation but there's a risk that recursively waiting for it here might mean
        // we consume packets from the websocket that something above us in the stack is waiting
        // for. It should be safe to assume it will close before the browser does anything else
        // interesting, so we can treat sending close as a synchronous / successful operation...?

        if ($outcome['error'] ?? NULL) {
            throw new UnexpectedJavascriptDialogException(
                $data['params'],
                $outcome['error']
            );
        }
    }

    /**
     * Register a handler for javascript dialogs (will be reset for each scenario)
     *
     * @param callable $handler
     *
     * @see \DMore\ChromeDriver\ChromeDriver::registerJavascriptDialogHandler()
     */
    public function registerJavascriptDialogHandler(callable $handler)
    {
        $this->javascript_dialog_handler = $handler;
    }

}
