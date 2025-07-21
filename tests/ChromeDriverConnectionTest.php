<?php

namespace DMore\ChromeDriverTests;

use DMore\ChromeDriver\ChromeDriver;
use WebSocket\Exception\ConnectionTimeoutException;

/**
 * Note that the majority of driver test coverage is provided via minkphp/driver-testsuite.
 *
 * Consider building on coverage there first!
 */
class ChromeDriverConnectionTest extends ChromeDriverTestBase
{
    /**
     * Unable to connect to nonsense ChromeDriver URL.
     */
    public function testRuntimeExceptionIfNotConnected()
    {
        $nonWorkingUrl = 'http://localhost:12345';
        $this->driver = new ChromeDriver($nonWorkingUrl, null, 'about:blank', []);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not fetch version information from http://localhost:12345/json/version.');
        $this->driver->visit('about:blank');
        // Content read is necessary to trigger timeout.
        $this->driver->getContent();
    }

    /**
     * JS confirm() will lead the browser to time out.
     */
    public function testTimeoutExceptionIfResponseBlocked()
    {
        // We don't want to wait the default 10s to time out.
        $options = ['socketTimeout' => 1];
        $chromeUrl = ChromeDriverConfig::getInstance()->getChromeUrl();
        $this->driver = new ChromeDriver($chromeUrl, null, 'about:blank', $options);
        $script = "confirm('Is the browser blocked? (yes, it is)');";
        $this->driver->visit('about:blank');
        $this->driver->evaluateScript($script);

        // Content read is necessary to trigger timeout.
        $this->expectException(ConnectionTimeoutException::class);
        $this->driver->getContent();
    }
}
