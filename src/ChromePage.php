<?php
namespace DMore\ChromeDriver;

use Behat\Mink\Exception\DriverException;

class ChromePage extends DevToolsConnection
{
    /** @var bool */
    private $page_ready = true;
    /** @var array https://chromedevtools.github.io/devtools-protocol/tot/Network/#type-Response */
    private $response = null;
    /** @var array */
    private $console_messages = [];
    /** @var callable */
    private $javascript_dialog_handler;

    private bool $is_recovering_from_crash = false;

    private ?\DateTimeImmutable $page_ready_timeout = NULL;

    private bool $has_navigated_since_reset = FALSE;

    public function __construct(
        private string $window_id,
        $url,
        $socket_timeout = NULL
    ) {
        parent::__construct($url, $socket_timeout);
    }

    public function connect($url = null)
    {
        parent::connect();
        $this->send('Page.enable');
        $this->send('DOM.enable');
        // Is it any more stable if we opt out of Network events - there are loads of them and right around the times
        // we see deadlocks and the page crashing : could that be because we're not properly async and not reading the
        // socket fast enough for Chrome to keep up?
        // $this->send('Network.enable');

        // Opt out of Animation.enable, it floods with notifications and I don't think we actually need them
        // $this->send('Animation.enable');
        $this->send('Animation.setPlaybackRate', ['playbackRate' => 100000]);
        $this->send('Console.enable');
    }

    public function reset()
    {
        $this->response                  = NULL;
        $this->javascript_dialog_handler = NULL;
        if ( ! $this->has_navigated_since_reset) {
            // We only need to go back to about:blank if the scenario has ever done anything. If this was a non-JS
            // scenario it will have navigated other browsers.
            return;
        }
        try {
            $this->visit('about:blank', new \DateTimeImmutable('5 seconds'));
            $this->waitForLoad();
        } finally {
            $this->has_navigated_since_reset = FALSE;
        }
    }

    public function visit($url, ?\DateTimeImmutable $timeout = NULL)
    {
        $this->setPageReady(FALSE, __METHOD__);
        // Page.navigate does not return until after the initial network request so it needs to be able to run for at least as
        // long as you expect that to take.
        $this->send('Page.navigate', ['url' => $url], $timeout ?? new \DateTimeImmutable('+30 seconds'));
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
                $this->waitFor(function () {
                    return $this->page_ready;
                },
                    'wait-for-load',
                    $this->page_ready_timeout
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
        // Can't throw UnsupportedDriverActionException because that needs a reference to the driver instance
        throw new \BadMethodCallException(
        // Because there's a bajillion of them and I've started to wonder if not keeping up with reading that
        // socket might help.
            'Getting page response is disabled to allow us to opt out from Network events'
        );

        // Do we actually need to do anything cleverer than waiting for tha page to load...???
        $this->waitFor(
            fn() => $this->response !== NULL,
            'wait-http-response',
            new \DateTimeImmutable('+30 seconds')
        );
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
                    // Think we don't need to worry about this and can just rely on `->response` being set alongside page_ready?
                    break;
                case 'Network.responseReceived':
                    if (
                        ($data['params']['type'] == 'Document')
                        &&
                        ($data['params']['frameId'] === $this->window_id)
                    ) {
                        $this->response = $data['params']['response'];
                    }
                    break;
                case 'Network.loadingFailed':
                    // No longer need to track pending requests, I think?
                    break;
                case 'Page.frameNavigated':
                    // Ignore, it duplicates frameStartedLoading so far as I can see!
                    break;
                case 'Page.frameStartedLoading':
                    // Page.frameStartedLoading comes *after* the main document has completed if calling the Page.navigate
                    // devtools method, or *before* if clicking / script-initiated nav in the UI.
                    // So I'm not convinced we should use this at all
                    // Really for the quickest solution should we just set it page_ready=false on net.requestWillBeSent with type of document?
                    if ($data['params']['frameId'] === $this->window_id) {
                        $this->setPageReady(FALSE, $data['method']);
                    }
                    break;
                case 'Page.navigatedWithinDocument':
                    // On a same-page navigation in at least some instances - e.g. in the flickr photoset page where it
                    // uses history.replaceState - you get:
                    // - potentially some animationy stuff
                    // - Page.frameStartedLoading
                    // - Page.navigatedWithinDocument
                    // - Page.frameStoppedLoading

                    // But no Page.loadEventFired.
                    // At the moment I'm marking page_ready = False on Page.frameStartedLoading, so need to clear it
                    // here. If I used Page.frameStoppedLoading instead of Page.loadEventFired this would maybe not
                    // be necessary.
                    // This is another example where we want click-> to wait a bit to see if something happens, but
                    // it can't just wait for readyState to change because that may not change (it doesn't in this case).
                    if ($data['params']['frameId'] === $this->window_id) {
                        $this->setPageReady(TRUE, $data['method']);
                    }
                    break;

                case 'Page.loadEventFired':
                    // I am tempted to *only* look at loadEventFired to know when the page is fully loaded and ready to
                    // use.
                    // @todo: should we also wait for Page.domContentEventFired? In some cases that goes *after* Page.loadEventFired
                    $this->setPageReady(TRUE, $data['method']);
                    break;
                case 'Page.frameStoppedLoading':
                    // This fires after Page.loadEventFired sometimes, but not always (I think).
                    // The big case where it fires *without* Page.loadEventFired is if you set window.location.href to a URL that is
                    // served as a download, in that case it seems we get:
                    // - Page.frameScheduledNavigation
                    // - Page.frameRequestedNavigation
                    // - Page.frameStartedLoading
                    // - page.frameClearedScheduledNavigation
                    // - Page.frameStoppedLoading - BUT no Page.loadEventFired because there was no pageload
                    if ($data['params']['frameId'] === $this->window_id) {
                        $this->setPageReady(TRUE, $data['method']);
                    }
                    break;
                case 'Inspector.targetCrashed':
                    // Chrome has actually crashed (or been OOM killed or whatever). In this case it looks like:
                    // - attempting to send Runtime.evaluate or similar will succeed on the send, but will not actually
                    //   return a response. So you get timeouts on any waitFor after the send
                    // - then, when you eventually run Page.navigate (for cleanup, or a next scenario, that starts the
                    //   page reloading. At that point any queued commands from *before* the navigation will return
                    //   with {"error": {"code": -32000, "message": "Target crashed"}}.
                    // - of course that reply now comes in while we are waiting for Page.navigate so it looks like that
                    //   is what failed, so the scenario moves on to more stuff. If there were multiple Runtime.evaluate
                    //   queued into the crashed target, then they will be dequeued and fail subsequent commands in
                    //   sequence.
                    // - So we actually need to make sure we handle the `targetCrashed` properly *first*, so that we
                    //   can get the page back interactive again before any more commands run.
                    // - I *think* we can do that by navigating to about:blank and then waiting for
                    //   Inspector.targetReloadedAfterCrash which follows the navigation attempt - though it seems like
                    //   there could be edge cases in this scenario....
                    // See http://www.edbookfest.test/chrome-logs.php?log=1e5d0d6b-f86a-46b4-8f09-5495280d021e-chromedriver-debug.log.jsonl&p=119
                    $this->is_recovering_from_crash = TRUE;
                    try {
                        // @TODO this is not fully robust because:
                        // -> if the crash happened while waiting on a command, then we get the `Target Crashed` here
                        //    in this method.
                        //    http://www.edbookfest.test/chrome-logs.php?log=70e99413-78a7-4b4f-a82a-d46c47b16796-chromedriver-debug.log.jsonl&p=20
                        // -> that then prevents us waiting for Inspector.targetReloadedAfterCrash here
                        // -> so we probably need to either expect those exceptions, or do this lower-level and send the
                        //    command then wait separately. OR could we do something hacky to just throw away replies
                        //    to anything before the current command ID????
                        // Don't use the normal visit() here, we need to keep the scope for unexpected calls tiny
                        $this->send('Page.navigate', ['url' => 'about:blank']);
                        $this->waitFor(
                            // We'll be ready to move on when about:blank has loaded *and* the Inspector.targetReloadedAfterCrash has fired
                            fn() => $this->page_ready && ! $this->is_recovering_from_crash,
                            'recover-from-crash',
                            new \DateTimeImmutable('+30 seconds')
                        );
                    } catch (\Exception $e) {
                        throw new DriverException(
                            sprintf('Browser crashed, and failed to recover: [%s] %s', \get_class($e), $e->getMessage())
                        );
                    }

                    // Whatever happens though we still need to fail *this* step, as we are now on about:blank
                    throw new DriverException('Browser crashed');
                case 'Inspector.targetReloadedAfterCrash':
                    // A crash was handled, the waiter can stop waiting now
                    $this->is_recovering_from_crash = FALSE;
                    break;
//              NO LONGER GETS ANIMATION EVENTS
//              - This was implemented in https://gitlab.com/behat-chrome/chrome-mink-driver/-/commit/b0239834f6ccf8b288903de8af80b64f5a322c6c
//                and "fixed" in https://gitlab.com/behat-chrome/chrome-mink-driver/-/commit/0b8113f4f98a246634d8608736f82245ab4e6e3c
//                but in practice the fix actually disabled it, since the key is in fact
//                $data['response']['params']['animation']['source']['duration']
//                so this has never actually been sleeping and my hunch is it doesn't need to because
//                the driver already sets a massive animationPlaybackRate which should make it virtually impossible
//                to interact with an element during the animation.
//                Removing this functionality means we can avoid listening for Animation events which are
//                another big flood aroudn the time of click / pageload etc and might be another cause for
//                Chrome & the socket struggling to keep up.
//                If it ever goes back in then need to review the units of the animation duration and whether
//                multiplying by 10 is vaguely useful in terms of actual execution speed at the forced rate.
//
//                case 'Animation.animationStarted':
//                    if (!empty($data['params']['source']['duration'])) {
//                        usleep($data['params']['source']['duration'] * 10);
//                    }
//                    break;
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
        $this->has_navigated_since_reset = TRUE;
        if (!$state) {
            // Clear the response ahead of loading a new doc
            $this->response = null;
            // Set a timeout from when the page went "not ready" so it's not affected by multiple calls to waitForLoad
            // e.g. after an exception during loading the page.
            // @todo: pageload expiry time should be configurable
            $this->page_ready_timeout = new \DateTimeImmutable('+30 seconds');
        } else {
            // Clear the timeout for the next load
            $this->page_ready_timeout = NULL;
        }
        $this->logger->logPageReadyStateChange($state, $change_trigger);
        $this->page_ready = $state;
    }

    public function waitForPossibleNavigation():void
    {
        // For example, on a `click` one of a few things might happen:
        // - it might be a normal link / form button and trigger an immediate nav. Usually in that case we will have a
        //   frameStartedLoading before we've even finished reading the reply to Input.dispatchMouseEvent, but not
        //   always.
        // - it might be a link that triggers an only-just-deferred JS action - for example if it's a link with
        //   an analytics click handler on it that calls e.preventDefault before doing something then setting
        //   document.location.href shortly after
        // - it might be a button that triggers some JS validation or more complex behaviour before again using
        //   document.location.href to navigate.
        //
        // The old driver implementation therefore had an explicit 50ms sleep at the end of the `click` method. However
        // this means we are not reading from the socket right when it's likely to be busy, which might be causing
        // chrome instability? And means that in the majority of simple navigation cases, we have to wait 50ms before
        // we start processing the pageload our side, which feels like it's not ideal.
        //
        // Instead, send a simple command on the socket for a while with very short sleeps, only until the page starts
        // loading.
        //
        // To be honest, it feels like really it would make more sense that click always returns immediately, and it's
        // the assertions where you then wait for the page to load (including the potential it might not even have
        // started yet) but that is very hard to make work in php/synchronous code and the mink interfaces.

        $max_wait_micros = 50_000;
        $wait_until      = \microtime(TRUE) + ($max_wait_micros / 1_000_000);

        while ($this->page_ready) {
            // Only need to do this while the page is *ready* - because we are trying to wait until it goes *not ready*

            if (\microtime(TRUE) >= $wait_until) {
                // We got to the end of the waiting time and the page still isn't navigating, assume therefore the click
                // triggered some on-page action (modal / UI component / whatever) and return
                return;
            }

            // This would ideally be a ping but I can't send pings just now because the driver doesn't surface the pong
            // I'm assuming Chrome will find this easy to answer even if everything else is broken
            $this->send('Browser.getVersion');

            if ($this->page_ready) {
                // Wait for a tiny bit just so we're not totally hammering Chrome with this, but only if we are going
                // to loop.
                \usleep(5_000);
            }
        }

        // If we get here then the page started loading, so wait for it to complete loading
        $this->waitForLoad();
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

        // BUT what we do have to do is make sure we have read any pending messages off the websocket. We can't go
        // straight to `waitForLoad` without reading messages off the queue otherwise we will not yet know that the
        // page is loading. Sending Browser.getVersion shouldn't tax Chrome and I've seen Runtime.evaluate fail on
        // a crashed target, I'm hoping this can't throw in the same way. We can't just read the socket because if
        // there's nothing to report that will just give us a timedout read on whatever the socket timeout is.

        // This would ideally be a ping, but I can't send pings just now because the driver doesn't surface the pong
        // so our wait can't ever respond to it.
        $this->send('Browser.getVersion');

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
