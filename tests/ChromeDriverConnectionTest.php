<?php

namespace DMore\ChromeDriverTests;

use Behat\Mink\Exception\ElementNotFoundException;
use Behat\Mink\Exception\DriverException;
use DMore\ChromeDriver\ChromeBrowser as Browser;
use DMore\ChromeDriver\ChromeDriver;
use DMore\ChromeDriver\HttpClient;
use PHPUnit\Framework\TestCase;
use DMore\ChromeDriver\StreamReadException;
use WebSocket\TimeoutException;

/**
 * Note that the majority of driver test coverage is provided via minkphp/driver-testsuite.
 *
 * Consider building on coverage there first!
 */
class ChromeDriverConnectionTest extends ChromeDriverTestBase
{
    /**
     * A confirm() will lead the browser to time out.
     *
     * @throws DriverException
     * @throws \Behat\Mink\Exception\UnsupportedDriverActionException
     */
    public function testStreamReadExceptionIfResponseBlocked()
    {
        // We don't want to wait the default 10s to time out.
        $options = [
            'socketTimeout' => 1,
        ];
        $this->driver = new ChromeDriver('http://localhost:9222', null, 'about:blank', $options);
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Client read timeout');
        $script = "confirm('Is the browser blocked? (yes, it is)');";
        $this->driver->visit('about:blank');
        $this->driver->evaluateScript($script);
        // Content read is necessary to trigger timeout.
        $this->driver->getContent();
    }

   /**
     * @throws DriverException
     */
    public function testInformativeExceptionIfChromeConnectionFailed()
    {
        $client = $this->getMockBuilder(HttpClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $client->expects($this->any())
            ->method('get')
            ->willReturn('Error Happened!');

        $this->expectException(\RuntimeException::class);
        // Test that chromium response is included in exception message.
        $this->expectExceptionMessageMatches('/Error Happened!/');

        $browser = new Browser('http://localhost:9222');
        $browser->setHttpClient($client);
        $browser->setHttpUri('http://localhost:9222');
        $browser->start();
    }
}
