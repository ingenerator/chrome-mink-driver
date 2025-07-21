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
        return $_SERVER['CHROME_URL']
            ?? throw new \RuntimeException('The CHROME_URL server/env variable must be set');
    }

    /**
     * {@inheritdoc}
     */
    public function createDriver(): ChromeDriver
    {
        $webFixturesHost = $_SERVER['WEB_FIXTURES_HOST']
            ?? throw new \RuntimeException('The WEB_FIXTURES_HOST server/env variable must be set');

        return new ChromeDriver($this->getChromeUrl(), null, $webFixturesHost, ['socketTimeout' => 1]);
    }

    /**
     * {@inheritdoc}
     */
    protected function supportsCss(): bool
    {
        return true;
    }
}
