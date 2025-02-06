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

Supported Extensions
--------------------

- Core
- calendar
- ctype
- date
- dom
- hash
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
