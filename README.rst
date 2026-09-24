PHP WASM Builder
================

The Dockerfile in this repository builds the PHP WASM files for use in the
documentation, and the PHP Tour.

You can build them, by running the following command::

	docker buildx bake

The builds will then end up in the ``build/php.net/`` directory. The ``.wasm``
and ``.mjs`` files for the web then need to be copied to
https://github.com/php/web-php.git/js (as ``php-web.wasm`` and
``php-web.mjs``)

By default, this will build PHP 8.4.4, but you can override this by setting an
argument::

	docker buildx bake --set default.args.PHP_VERSION=8.3.16

The extensions are an argument too, so you can build PHP with other ones (see
`Supported Extensions`_)::

	docker buildx bake --set default.args.EXTENSIONS="ctype tokenizer bcmath"

Options
-------

The follow options are available for the baker:

PHP_VERSION
	Configures the PHP version to build.

EXTENSIONS
	The extensions to build, separated by spaces. It defaults to the ones that
	php.net ships: ``calendar ctype dom mbstring simplexml xml xmlreader xmlwriter``.

	The libraries that the extensions need (oniguruma for ``mbstring``, libxml2
	for the xml ones) are only built when an extension asks for them.

TARGETS
	The files to build: ``web`` for ``php-web.mjs`` and ``php-web.wasm``, and
	``node`` for ``php-cli.mjs`` and ``php-cli.wasm``. Defaults to ``web node``.

MEMORY
	The (initial and maximum) memory of the WASM module. Defaults to ``128mb``.

EMBED_PATH
	The name that the embedded directory has in the WASM filesystem. Defaults
	to ``examples``.

LIBXML_VERSION
	The LibXML version to download and build.

ONIGURUMA_VERSION
	The Oniguruma library (used for regular expressions with mbstring) version
	to download and build.

The directory to embed in the WASM filesystem is the ``embed`` named build
context. ``docker-bake.hcl`` sets it to ``examples/``; anything can be used
instead::

	docker buildx bake --set default.contexts.embed=./my-app --set default.args.EMBED_PATH=my-app

Supported Extensions
--------------------

These are the extensions that can be listed in ``EXTENSIONS``:

- bcmath
- calendar
- ctype
- dom
- filter
- libxml
- mbstring
- SimpleXML
- tokenizer
- xml
- xmlreader
- xmlwriter

They are always there, whatever ``EXTENSIONS`` says:

- Core
- date
- hash
- json
- pcre
- random
- Reflection
- SPL
- standard

To add an extension, add a line to the ``ext`` list at the top of the
``Dockerfile`` (and a stage for the library that it needs, if it needs one).

Limitations
-----------

- Fibers
    WebAssembly cannot pause and resume code the way Fibers require, so the
    build uses ``--disable-fiber-asm``.
