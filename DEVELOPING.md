# Developing

## Test coverage

The base test coverage is that from upstream `mink/driver-testsuite`, and validates that core DriverInterface
functionality is correct per those expectations. Additional driver-specific test coverage is supplied via the
`tests/` directory.

Tests are executed on each merge request via Gitlab CI. New functionality or bugfixes should seek to expand
test or at least maintain existing coverage.

## Checking Chrome is available

To manually test connectivity, you can use `curl` or similar and request the `json/version` endpoint:

```
curl http://localhost:9222/json/version
```

## Running tests

Execution of tests in CI is best documented by the implementation.

To run tests locally, use the `behat-chrome/docker-chrome-headless` image:

Simplest is to run `make test`, which expands to:

```
hostos$ docker run --rm -it -v $(pwd):/code -e DOCROOT=/code/vendor/mink/driver-testsuite/web-fixtures registry.gitlab.com/behat-chrome/docker-chrome-headless bash
docker$ vendor/bin/phpunit
```

## Running tests with XDebug

If developing or debugging, it may help to set a couple environment variables in the docker environment. This can be done by adding to the `docker_run=` line in `Makefile`:

```
docker_run=docker run --rm -ti -v .:/code -e PHP_IDE_CONFIG="serverName=appserver" -e XDEBUG_CONFIG="idekey=youridekey client_host=192.168.1.2" -e DOCROOT=/code/vendor/mink/driver-testsuite/web-fixtures registry.gitlab.com/behat-chrome/docker-chrome-headless
```

Use the IP of your host OS (or the debugger) and adjust to suit.

## Chromium as a service

Chrome has a restriction that clients sending a Host header other than `localhost` are rejected.

This can be bypassed to access Chrome when run as a service, eg if you want to provide Chrome service to a Gitlab CI job.

This requires resolving the IP of the Chrome service. If your Chrome service is on host `chromium` then you can obtain this IP using eg `getent hosts chromium | head -n 1 | cut -d ' ' -f 1`.

Use the IP address to connect to any service not at localhost by modifying the `api_url` option when instantiating `DMore\ChromeDriver\ChromeDriver` (refer to [Usage](https://gitlab.com/behat-chrome/chrome-mink-driver#usage)).

The below example reproduces the check for connectivity above, using the IP address resolved for "chromium" as the endpoint.

```
IPADDR=$( getent hosts chromium | head -n 1 | cut -d ' ' -f 1 )
curl http://localhost:9222/json/version --resolve localhost:9222:$IPADDR
```

[behat-chrome/chrome-mink-driver#132](https://gitlab.com/behat-chrome/chrome-mink-driver/-/issues/132) will address this via a configuration option; the above details are here to support debugging connectivity.

## Changelog

All notable changes to this project will be documented in [CHANGELOG.md](https://gitlab.com/behat-chrome/chrome-mink-driver/-/blob/main/CHANGELOG.md).

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Merge requests

Merge requests should include an entry under the "Unreleased" section of [CHANGELOG.md](https://gitlab.com/behat-chrome/chrome-mink-driver/-/blob/main/CHANGELOG.md) which describes briefly the proposed change, with includes references to the related issue or MR.

## Code style & linting

Proposed changes should adhere to the project's existing coding standards, and this is validated in CI. If a code style check fails, please amend your MR accordingly.

PHP code should adhere to [PSR12](https://www.php-fig.org/psr/psr-12/).

`composer.json` should be kept normalized using `composer normalize`.

## Releasing

Releases are numbered according to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Release co-ordination issues (["Release" label](https://gitlab.com/behat-chrome/chrome-mink-driver/-/issues/?state=all&label_name%5B%5D=Release)) are used to plan and communicate upcoming releases.

When it's time to release:

1. Ensure a header for the release is added to CHANGELOG.md, retaining the "Unreleased" header at top.
2. A new release should be created using [Gitlab's Release UI](https://gitlab.com/behat-chrome/chrome-mink-driver/-/releases)
  - The release title and tag is the version only.
  - The release notes are the CHANGELOG entries for the version.
3. Packagist will detect the new release and [make it available](https://packagist.org/packages/dmore/chrome-mink-driver).
