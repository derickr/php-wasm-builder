PHP WASM Builder
================

The Dockerfile in this repository builds the PHP WASM files for use in the
documentation, and the PHP Tour.

You can build them, by running the following command::

	docker buildx bake

The builds will then up in the ``build/`` directory. These two files then need
to be copied to https://github.com/php/web-php.git/js (as ``php-web.wasm`` and
``php-web.mjs``)

By default, this will build PHP 8.4.3, but you can override this by setting an
argument::

	docker buildx bake --set default.args.PHP_VERSION=8.3.16

Options
-------

The follow options are available for the baker:

PHP_VERSION
	Configures the PHP version to build.

LIBXML_VERSION
	The LibXML version to download and build.

ONIGURUMA_VERSION
	The Oniguruma library (used for regular expressions with mbstring) version
	to download and build.

ICU_VERSION
	The ICU library (used by the intl extension) version to download and build.
	This uses ICU's release tag format, for example ``74-2``.

Supported Extensions
--------------------

- Core
- calendar
- ctype
- date
- dom
- hash
- intl
- json
- libxml
- mbstring
- pcre
- random
- Reflection
- SimpleXML
- SPL
- standard
- xml
- xmlreader
