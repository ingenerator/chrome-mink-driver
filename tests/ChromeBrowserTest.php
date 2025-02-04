<?php

namespace DMore\ChromeDriverTests;

use DMore\ChromeDriver\ChromeBrowser;
use DMore\ChromeDriver\HttpClient;
use PHPUnit\Framework\TestCase;

class ChromeBrowserTest extends TestCase
{
    public function testInformativeExceptionIfChromeConnectionFailed()
    {
        $client = $this->getMockBuilder(HttpClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $client->method('get')
            ->willReturn('Error Happened!');

        $this->expectException(\RuntimeException::class);
        // Test that chromium response is included in exception message.
        $this->expectExceptionMessageMatches('/Error Happened!/');

        $browser = new ChromeBrowser('https://bad-default-url');
        $browser->setHttpClient($client);
        $browser->setHttpUri(ChromeDriverConfig::getInstance()->getChromeUrl());
        $browser->start();
    }
}
