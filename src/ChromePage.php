<?php
namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;

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
            }, 'before-visit');
        }
        $this->response = null;
        $this->setPageReady(FALSE, __METHOD__);
        $this->send('Page.navigate', ['url' => $url]);
    }

    public function reload()
    {
        $this->setPageReady(FALSE, __METHOD__);
        $this->send('Page.reload');
    }

    public function waitForLoad()
    {
        if (!$this->page_ready) {
            try {
                // @todo: need a better way of setting the timeout expiry based on the start of pageload rather than specific call
                $timeout = new \DateTimeImmutable('+30 seconds');
                $this->waitFor(function () {
                    return $this->page_ready;
                },
                    'wait-for-load',
                    $timeout
                );
            } catch (DriverException $exception) {
                // This could then be decorated with more detail on what we're waiting for
                throw new DriverException("Page not loaded: ".$exception->getMessage(), 0, $exception);
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
                return NULL !== $this->response && count($this->pending_requests) == 0;
            }, 'wait-for-http-response');
        }
    }

    /**
     * @param array $data
     * @return void
     * @throws DriverException
     */
    protected function processResponse(array $data): void
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
                    $this->setPageReady(FALSE, $data['method']);
                break;
                case 'Page.navigatedWithinDocument':
                case 'Page.loadEventFired':
                case 'Page.frameStoppedLoading':
                    $this->setPageReady(TRUE, $data['method']);
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
                        $this->setPageReady(FALSE, $data['method']);
                    }
                    break;
                case 'Console.messageAdded':
                    $this->console_messages[] = $data['params']['message'];
                    break;
                default:
                    break;
            }
        }
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

    private function setPageReady(bool $state, string $change_trigger): void
    {
        $this->logger->logPageReadyStateChange($state, $change_trigger);
        $this->page_ready = $state;
    }

    public function waitUntilFullyLoaded(): void
    {
        // $this->wait($this->domWaitTimeout, 'document.readyState == "complete"');
        // $this->page->waitForLoad();

        // The old waitForDom was waiting for document.readyState == 'complete'
        // But this in turn called evaluateScript (in a loop) with a bunch of sleeping
        // And runSript calls $page->waitForLoad() as well so it was doing waitForLoad -> runScript -> waitForLoad

        // If we make it that the page only goes ready on the `frameStoppedLoading` event, that follows the `load`
        // event so by definition document.readyState === complete by that point and there is no need to check it again
        // and we may just be able to do waitForLoad and leave it at that.

        $this->waitForLoad();
    }

    public function expectNewPageload():void
    {
        // @todo How can we tell the page that an operation will load a new page?
        // Whether for us calling ->visit, or for us doing a window.history.back() or whatever over the devtools
        // protocol. We can't really just set page_ready = FALSE as there will be a race condition there as that might
        // already have happened before control returns from our command.

        // *MAYBE* can we call this *before* the navigating action, and set some sort of navigation count value so we
        // can assert that the page has moved on from that?

        // *ALTHOUGH* window.history.back() will not always trigger a pageload if they're using PushState API in which
        // case we can't forcibly wait for a pageload to start, so it's a bit like click().

        // For now give it 300ms, that should be long enough for us to get our normal websocket 'page load' events and
        // this may yet prove to be the best solution - just wait long enough that we should expect it to have started
        // reacting to our command.

        // Potentially another approach instead of sleep() is actually to read the websocket with waitFor until:
        // - a navigation has started
        // - enough time has passed that we know we're not getting one.
        //
        // This still means non-navigating click/back/whatever have to wait a bit to see what they'll do, but probably
        // not that long and the majority of clicks *are* navigating clicks so at least those would be faster.
        \usleep(300_000);
    }

}
