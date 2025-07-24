<?php

namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Exception\Exception as WebsocketException;

class ChromePage extends DevToolsConnection
{
    private $frames_loading = [];

    /**
     * @var array
     */
    private $pending_requests = [];

    /**
     * @var bool
     */
    private $page_ready = true;

    /**
     * @var bool
     */
    private $has_javascript_dialog = false;

    /**
     * @var array https://chromedevtools.github.io/devtools-protocol/tot/Network/#type-Response
     */
    private $response = null;

    /**
     * @var array
     */
    private $console_messages = [];

    /**
     * Connect and set up.
     *
     * @param null $url
     * @throws \Exception
     */
    public function connect($url = null): void
    {
        parent::connect();
        $this->send('Page.enable');
        $this->send('DOM.enable');
        $this->send('Network.enable');
        $this->send('Animation.enable');
        $this->send('Animation.setPlaybackRate', ['playbackRate' => 100000]);
        $this->send('Console.enable');
    }

    /**
     * Reset the latest response.
     */
    public function reset(): void
    {
        $this->response = null;
    }

    /**
     * Visit a new URL.
     *
     * @param $url
     * @throws WebsocketException
     * @throws DriverException
     */
    public function visit($url): void
    {
        if (count($this->pending_requests) > 0) {
            $this->waitFor(
                function () {
                    return count($this->pending_requests) == 0;
                }
            );
        }
        $this->response = null;
        $this->page_ready = false;
        $this->send('Page.navigate', ['url' => $url]);
    }

    /**
     * Reload the current page.
     *
     * @throws \Exception
     */
    public function reload()
    {
        $this->page_ready = false;
        $this->send('Page.reload');
    }

    /**
     * Wait for page to load.
     *
     * @throws DriverException
     */
    public function waitForLoad()
    {
        if (!$this->page_ready) {
            try {
                $this->waitFor(
                    function () {
                        return $this->page_ready;
                    }
                );
            } catch (ConnectionTimeoutException $exception) {
                throw new DriverException("Page not loaded");
            }
        }
    }

    /**
     * Get the response.
     *
     * @return array|null
     * @throws WebsocketException
     * @throws DriverException
     */
    public function getResponse()
    {
        $this->waitForHttpResponse();
        return $this->response;
    }

    /**
     * @return boolean
     */
    public function hasJavascriptDialog(): bool
    {
        return $this->has_javascript_dialog;
    }

    /**
     * Get the browser's tabs.
     *
     * @return array
     * @throws \Exception
     */
    public function getTabs(): array
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
    public function getConsoleMessages(): array
    {
        return $this->console_messages;
    }

    /**
     * Clear the stored console messages.
     */
    public function clearConsoleMessages()
    {
        $this->console_messages = [];
    }

    /**
     * Wait for an HTTP response.
     *
     * @throws WebsocketException
     * @throws DriverException
     */
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

            $this->waitFor(
                function () {
                    return null !== $this->response && count($this->pending_requests) == 0;
                }
            );
        }
    }

    /**
     * {inheritDoc}
     */
    protected function processResponse(array $data): bool
    {
        if (array_key_exists('method', $data)) {
            switch ($data['method']) {
                case 'Page.javascriptDialogOpening':
                    $this->has_javascript_dialog = true;
                    return true;
                case 'Page.javascriptDialogClosed':
                    $this->has_javascript_dialog = false;
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
                    $this->page_ready = false;
                    break;

                case 'Page.frameStartedLoading':
                    // @todo There are still potential races if a child frame starts loading during pageload and after
                    //       we've received the event that the page is ready, so it can transition from "not ready" to
                    //       "ready" in unexpected sequence. It might be better to ignore child frame load entirely...
                    $this->frames_loading[$data['params']['frameId']] = true;
                    $this->page_ready = false;
                    break;

                case 'Page.frameStoppedLoading':
                    // not sure why we get frameDetached sometimes, but we do, and they mean the frame never fires frameStoppedLoading
                case 'Page.frameDetached':
                    unset($this->frames_loading[$data['params']['frameId']]);
                    if ($this->frames_loading === []) {
                        $this->page_ready = true;
                    }
                    break;

                case 'Page.navigatedWithinDocument':
                    $this->page_ready = true;
                    break;
                case 'Inspector.targetCrashed':
                    throw new DriverException('Browser crashed');
                case 'Animation.animationStarted':
                    if (!empty($data['params']['source']['duration'])) {
                        usleep($data['params']['source']['duration'] * 10);
                    }
                    break;
                case 'Security.certificateError':
                    if (isset($data['params']['eventId'])) {
                        $this->send(
                            'Security.handleCertificateError',
                            ['eventId' => $data['params']['eventId'], 'action' => 'continue']
                        );
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

        return false;
    }
}
