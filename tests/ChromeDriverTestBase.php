<?php

namespace DMore\ChromeDriverTests;

use Behat\Mink\Exception\ElementNotFoundException;
use Behat\Mink\Exception\DriverException;
use DMore\ChromeDriver\ChromeBrowser as Browser;
use DMore\ChromeDriver\ChromeDriver;
use DMore\ChromeDriver\HttpClient;
use PHPUnit\Framework\TestCase;
use DMore\ChromeDriverTests\ChromeDriverTestBase;

/**
 * Note that the majority of driver test coverage is provided via minkphp/driver-testsuite.
 *
 * Consider building on coverage there first!
 */
class ChromeDriverTestBase extends TestCase
{
    /**
     * @var ChromeDriver
     */
    protected $driver;

    /**
     * {inheritDoc}
     */
    protected function setUp(): void
    {
        $this->driver = $this->getDriver();
    }

    /**
     * @return ChromeDriver
     */
    private function getDriver(): ChromeDriver
    {
        return new ChromeDriver('http://localhost:9222', null, 'about:blank');
    }
}
