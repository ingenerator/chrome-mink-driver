<?php


namespace DMore\ChromeDriverTests;

use Behat\Mink\Tests\Driver\TestCase;
use DMore\ChromeDriver\ChromeDriver;
use DMore\ChromeDriver\UnexpectedJavascriptDialogException;
use DMS\PHPUnitExtensions\ArraySubset\Assert as DMSAssert;

class JavascriptDialogHandlingTest extends TestCase
{

    /**
     * @testWith ["window.alert('I am alerting you')", "Unexpected alert browser dialog: `I am alerting you`", null]
     *           ["window.confirm('Launch missiles?')", "Unexpected confirm browser dialog: `Launch missiles?`", false]
     *           ["window.prompt('Enter launch code')", "Unexpected prompt browser dialog: `Enter launch code`", null]
     */
    public function test_it_dismisses_javascript_alert_and_throws_without_handler($js, $expect_msg, $expect_result)
    {
        $session = $this->getSession();
        $session->visit($this->pathTo('/index.html'));
        try {
            $session->executeScript('window._dialog_result = '.$js);
            $this->fail('Did not get '.UnexpectedJavascriptDialogException::class);
        } catch (UnexpectedJavascriptDialogException $e) {
            $this->assertStringStartsWith($expect_msg, $e->getMessage());
            $this->assertSame('No javascript dialog handler was registered', $e->getPrevious()->getMessage());
            // This evaluateScript also ensures that the javascript execution has resumed, odd timeout etc exceptions
            // most likely mean Chrome is still blocking.
            $this->assertSame($expect_result, $session->evaluateScript('window._dialog_result'));
        }
    }

    public function test_it_dismisses_javascript_alert_and_throws_if_handler_throws()
    {
        $session = $this->getSession();
        $session->visit($this->pathTo('/index.html'));
        $handler_exception = new \UnexpectedValueException('I wanted a prompt!');
        $this->getChromeDriver()->registerJavascriptDialogHandler(
            function ($dialog_params) use ($handler_exception) { throw $handler_exception; }
        );
        try {
            $session->executeScript(
                'window._dialog_result = window.confirm("Shall we play Global Thermonuclear War?")'
            );
            $this->fail('Did not get '.UnexpectedJavascriptDialogException::class);
        } catch (UnexpectedJavascriptDialogException $e) {
            $this->assertStringStartsWith(
                'Unexpected confirm browser dialog: `Shall we play Global Thermonuclear War?`',
                $e->getMessage()
            );
            $this->assertSame($handler_exception, $e->getPrevious(), 'Attaches previous exception');
            $this->assertSame(FALSE, $session->evaluateScript('window._dialog_result'));
        }
    }

    public function test_it_dismisses_javascript_alert_and_throws_if_handler_does_not_return_instructions()
    {
        $session = $this->getSession();
        $session->visit($this->pathTo('/index.html'));
        $this->getChromeDriver()->registerJavascriptDialogHandler(
            function ($dialog_params) { return []; }
        );
        try {
            $session->executeScript(
                'window._dialog_result = window.confirm("Shall we play Global Thermonuclear War?")'
            );
            $this->fail('Did not get '.UnexpectedJavascriptDialogException::class);
        } catch (UnexpectedJavascriptDialogException $e) {
            $this->assertStringContainsString('must return an `accept` property', $e->getMessage());
            $this->assertSame(FALSE, $session->evaluateScript('window._dialog_result'));
        }
    }

    /**
     * @testWith ["window.alert('I am alerting you')", {"accept": false}, {"type": "alert", "message": "I am alerting you"}, null]
     *           ["window.alert('Bad move')", {"accept": true}, {"type": "alert", "message": "Bad move"}, null]
     *           ["window.confirm('Thermonuclear War?')", {"accept": false}, {"type": "confirm", "message": "Thermonuclear War?"}, false]
     *           ["window.confirm('Tic Tac Toe?')", {"accept": true}, {"type": "confirm", "message": "Tic Tac Toe?"}, true]
     *           ["window.prompt('Launch codes?')", {"accept": false}, {"type": "prompt", "message": "Launch codes?"}, null]
     *           ["window.prompt('Launch codes?')", {"accept": true, "promptText": "AB9823"}, {"type": "prompt", "message": "Launch codes?"}, "AB9823"]
     */
    public function test_it_processes_expected_javascript_dialog_in_line_with_handler_instructions(
        $js,
        $handler_return,
        $expect_called,
        $expect_result
    ) {
        $session = $this->getSession();
        $session->visit($this->pathTo('/index.html'));
        $handler_calls = [];
        $this->getChromeDriver()->registerJavascriptDialogHandler(
            function ($dialog_params) use ($handler_return, &$handler_calls) {
                $handler_calls[] = $dialog_params;

                return $handler_return;
            }
        );

        // This test runs the window.whatever asynchronously so we can prove `wait` works correctly in and around
        // javascript dialogs being fired. It delays by 10ms to ensure that the wait tries to evaluate at least twice
        $session->executeScript(
            'window._dialog_called = false;'
            .'setTimeout(function () { window._dialog_called = true; window._dialog_result = '.$js.';}, 10);'
        );
        $this->assertTrue($session->wait(15, 'window._dialog_called'), 'Wait should succeed');
        $this->assertSame($expect_result, $session->evaluateScript('window._dialog_result'));
        $this->assertCount(1, $handler_calls, 'Handler should have been called exactly once');
        DMSAssert::assertArraySubset($expect_called, $handler_calls[0], 'Should have called handler with expected args');
    }

    protected function getChromeDriver(): ChromeDriver
    {
        return $this->getSession()->getDriver();
    }
}
