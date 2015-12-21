# PHP Configuration Tool #

The PHP Configuration Tool was built as a simple script to make it easier to
configure multiple PHP installations on a Windows machine quickly. The purpose
is to allow defining a simple configuration file that is used to configure
multiple different php.ini files in different PHP installation paths.

## Installation ##

To install this library globally via [Composer](https://getcomposer.org), simply
run the following command

    > composer --global require riimu/php-configure:1.*

## Configuration ##

See the included `configure.sample.json` file for a sample configuration. The
included `php-configure` command takes a single parameter, which indicates the
configuration json file. For example:

    > php-configure configure.json

The configuration may include the following settings:

 * **paths** defines the paths to PHP installations. The paths are iterated
   using `glob()` which allows the user of path wildcards.
 * **base** defines the base configuration files that are searched for in the
   installation directory, if the php.ini does not exist
 * **settings** defines the configured PHP settings.
 * **extensions** defines the PHP extensions to enable

The configuration script will only change the setting values if they are
different and only enable the extensions if they are not enabled in the php.ini
file. Note that if the setting or extension does not exist in the configuration
file in either uncommented or commented form, the settings are not configured
and the extensions are not enabled.

## Credits ##

This project is copyright 2015 to Riikka Kalliomäki.

See LICENSE for license and copying information.
