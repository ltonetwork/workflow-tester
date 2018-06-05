# Test Live Contracts with Gherkin BDD tests

![screenshot-calendar google com-2018 06 01-18-46-57](https://user-images.githubusercontent.com/100821/40949026-296e4118-686c-11e8-9bc3-4847d17257c6.png)

## Pre-requirements

Make sure you have an `lto-node` [docker environment](https://hub.docker.com/u/legalthings/) up and running in `test` mode  
using docker-compose.

* Linux or MacOS
* [PHP >= 7.1](http://www.php.net/)
* [composer](https://getcomposer.org/)

Other requirement are configured through composer.

_Using the [base58 php extension](https://github.com/legalthings/base58-php-ext) will make the tester a lot faster._

## Configuration

Edit `lctest.behat.yml` to configure how to connect to the LTO webserver.

## Installation

In the terminal

    git clone https://github.com/legalthings/livecontracts-tester.git
    cd livecontracts-tester
    composer install

Add the `bin` dir to your `$PATH` to run the command

    $PATH="$PATH:/path/to/livecontracts-tester/bin"

## Running

    cd my-workflows
    lctest
    
Run `lctest --help` for more options.

    
