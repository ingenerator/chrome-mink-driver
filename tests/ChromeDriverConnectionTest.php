<?php

namespace DMore\ChromeDriverTests;

use Behat\Mink\Exception\ElementNotFoundException;
use Behat\Mink\Exception\DriverException;
use DMore\ChromeDriver\ChromeBrowser as Browser;
use DMore\ChromeDriver\ChromeDriver;
use DMore\ChromeDriver\HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Note that the majority of driver test coverage is provided via minkphp/driver-testsuite.
 *
 * Consider building on coverage there first!
 */
class ChromeDriverConnectionTest extends ChromeDriverTestBase
{
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
