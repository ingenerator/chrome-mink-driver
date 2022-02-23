<?php

namespace DMore\ChromeDriverTests;

use Behat\Mink\Tests\Driver\TestCase as DriverTestCase;

class ChromeDriverTest extends DriverTestCase
{

    /**
     * Check that the timestamp on a click event is a valid / realistic value
     *
     * Vuejs and potentially other javascript frameworks use the event.timeStamp to detect (and ignore) events that
     * were fired before the event listener was attached. For example, if an event causes a new parent element to be
     * rendered, the event may then bubble to that parent even though the parent was not present in the document when
     * the event was first dispatched.
     *
     * Therefore it's important that event timestamps mirror Chrome's native behaviour and carry the correct
     * high-performance timestamp for the interaction rather than a simulated value.
     *
     * @return void
     */
    public function testClickEventTimestamps()
    {
        $this->getSession()->visit($this->pathTo('/index.html'));
        $link = $this->getAssertSession()->elementExists('css', 'a[href="some_url"]');

        $this->getSession()->executeScript(
            <<<JS
            (function () {
              window._test = {
                listenerAttached: performance.now(),
                clickedAt: null                
              }
              
              document.querySelector('a[href="some_url"]').addEventListener('click', function (e) {
                  e.preventDefault();
                  window._test.clickedAt = e.timeStamp;
                });
            })();
JS
        );

        $link->click();
        $result = $this->getSession()->evaluateScript('window._test');
        $this->assertGreaterThan(
            $result['listenerAttached'],
            $result['clickedAt'],
            'Click event timestamp should be after event listener was attached'
        );
    }

}
