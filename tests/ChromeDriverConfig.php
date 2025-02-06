<?php

namespace DMore\ChromeDriverTests;

use Behat\Mink\Tests\Driver\AbstractConfig;
use DMore\ChromeDriver\ChromeDriver;

class ChromeDriverConfig extends AbstractConfig
{
    public static function getInstance(): ChromeDriverConfig
    {
        static $instance;

        return $instance ?? ($instance = new self());
    }

    public function getChromeUrl(): string
    {
        return getenv('CHROME_URL')
            ?: throw new \RuntimeException('The CHROME_URL environment variable must be set');
    }

    public function getWebFixturesUrl()
    {
        return getenv('WEB_FIXTURES_HOST')
            ?: throw new \RuntimeException('The WEB_FIXTURES_HOST environment variable must be set');
    }

    /**
     * {@inheritdoc}
     */
    public function createDriver(): ChromeDriver
    {
        return new ChromeDriver($this->getChromeUrl(), null, $this->getWebFixturesUrl(), ['socketTimeout' => 1]);
    }

    /**
     * {@inheritdoc}
     */
    protected function supportsCss(): bool
    {
        return true;
    }
}
