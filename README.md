# Chrome Mink Driver

Mink driver for controlling Chrome without the overhead of Selenium.

It communicates directly with chrome over HTTP and WebSockets, which allows it to work at least twice as fast as Chrome with Selenium.  For Chrome 59+ it supports headless mode, eliminating the need to install a display server, and the overhead that comes with it. This driver is tested and benchmarked against a behat suite of 1800 scenarios and 19000 steps. It can successfully run it in less than 18 minutes with Chrome 60 headless. The same suite running against Chrome 58 with xvfb and Selenium takes ~60 minutes.

## Installation:

```bash
composer require dmore/chrome-mink-driver
```

## Requirements:

* Google Chrome or Chromium running with remote debugging.

Example:

```bash
google-chrome-stable --remote-debugging-address=0.0.0.0 --remote-debugging-port=9222
```

or headless (v59+):

```bash
google-chrome-unstable --disable-gpu --headless --remote-debugging-address=0.0.0.0 --remote-debugging-port=9222
```

The [Docker image](https://gitlab.com/behat-chrome/docker-chrome-headless) includes recent Chrome Stable, Chrome Beta and Chromium.

It is recommended to start Chrome with the `--disable-extensions` flag.

See https://gitlab.com/DMore/behat-chrome-skeleton for a fully working example.

## Contributing

Contributions are welcome! You're encouraged to fork and submit improvements. Please make sure linting and tests pass when submitting MRs.

## Tests

To run tests locally using the docker image:

```bash
cd chrome-mink-driver
docker run --rm -it -v $(pwd):/code -e DOCROOT=/code/vendor/mink/driver-testsuite/web-fixtures registry.gitlab.com/behat-chrome/docker-chrome-headless bash
```

Then in the shell:

```bash
composer install
vendor/bin/phpunit
```

This will execute both [tests specific to this driver](https://gitlab.com/behat-chrome/chrome-mink-driver/-/tree/main/tests) and the more comprehensive test suite from [mink/driver-testsuite](https://github.com/minkphp/driver-testsuite/), which is the common testsuite to ensure consistency across Mink driver implementations.

## Usage:

```php
use Behat\Mink\Mink;
use Behat\Mink\Session;
use DMore\ChromeDriver\ChromeDriver;

$mink = new Mink(array(
    'browser' => new Session(new ChromeDriver('http://localhost:9222', null, 'http://www.google.com'))
));
```

## Configuration

| Option           | Value                    | Description                               |
|------------------|--------------------------|-------------------------------------------|
| socketTimeout    | int, default: 10         | Connection timeout in seconds             |
| domWaitTimeout   | int, default: 3000       | DOM ready waiting timeout in milliseconds |
| downloadBehavior | allow, default, deny     | Chrome switch to permit downloads. (v62+) |
| downloadPath     | e.g. /tmp/ (the default) | Where to download files to, if permitted. |

Pass configuration values as the third parameter to `new ChromeDriver()`.

## Rendering PDF and Screenshots

Despite the Mink functionality the driver supports printing PDF pages or capturing a screenshot.

```php
use Behat\Mink\Mink;
use Behat\Mink\Session;
use DMore\ChromeDriver\ChromeDriver;
$mink = new Mink(array(
    'browser' => new Session(new ChromeDriver('http://localhost:9222', null, 'http://www.google.com'))
));
$mink->setDefaultSessionName('browser');
$mink->getSession()->visit('https://gitlab.com/behat-chrome/chrome-mink-driver/blob/master/README.md');
$driver = $mink->getSession()->getDriver();
$driver->printToPdf('/tmp/readme.pdf');
```

The available options are documented here: https://chromedevtools.github.io/devtools-protocol/tot/Page/#method-printToPDF

You can capture a screenshot with the `captueScreenshot` method. Options are documented here.

## Behat

See [the behat extension](https://gitlab.com/behat-chrome/behat-chrome-extension) if you want to use this driver with behat.
