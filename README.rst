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

Stacks, and castor
------------------

A *stack* is a PHP version, a set of extensions, what to embed and which
targets to build, with a name. The stacks are declared in ``stacks.php``, and
`castor <https://castor.jolicode.com>`_ builds them with one command; castor
only runs ``docker buildx bake`` for you, the Dockerfile is what knows how to
compile PHP. Nobody needs castor to use this repository, and no PHP is needed
on the host: only Docker (and node, to run what is built).

.. code-block:: php

	<?php

	use PhpWasm\Stack;

	return [
	    Stack::named('php.net')
	        ->php('8.4.26')
	        ->extensions('calendar', 'ctype', 'dom', 'mbstring', 'simplexml', 'xml', 'xmlreader', 'xmlwriter')
	        ->embed('examples')
	        ->targets('web', 'node'),

	    // Inherits everything from php.net, and adds two extensions
	    Stack::named('php.net-bcmath')->from('php.net')->extensions('bcmath', 'tokenizer'),
	];

A stack that inherits from another one with ``from()`` gets whatever it does
not declare from it. The extensions it declares are added to the ones of its
parent, the rest replaces what the parent says. ``embed()`` is relative to the
directory of the ``stacks.php`` file.

Everything is built in ``build/<stack>/``::

	castor stacks                         # lists the stacks
	castor build                          # builds the php.net stack
	castor build playground               # builds another one
	castor build --all                    # builds all of them, in one buildx invocation
	castor test                           # builds them all, and checks them
	castor sizes                          # compares the size of the .wasm files with php.net's
	castor run demo/phpinfo.php           # runs a file, with the php.net stack
	castor run -r 'echo PHP_VERSION;' --stack playground

Anything in a stack can be overridden from the command line, so there is no
need to declare a stack to try something::

	castor build --php 8.3.35                      # the php.net stack, on PHP 8.3
	castor build playground --target node          # only the node target
	castor build --with tokenizer --with bcmath    # php.net, and two more extensions
	castor build --memory 256mb --embed my-app     # more memory, another directory to embed
	castor build --print                           # only shows the bake file

``castor test`` is what tells that a stack works: once built, it runs each
stack in node, and checks that ``PHP_VERSION`` is the one that was asked for,
and that ``extension_loaded()`` is true for every extension that was asked for.

Using it from your own project
------------------------------

The Dockerfile knows which emsdk works, how to cross-compile oniguruma and
libxml2, which ``./configure`` flags PHP needs to link and which ``emcc``
flags make it load. Any PHP project can reuse that, instead of copying the
Dockerfile, by importing this repository as a castor package::

	castor composer require derickr/php-wasm-builder

.. code-block:: php

	<?php
	// castor.php

	use function Castor\import;

	defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', false);

	import('composer://derickr/php-wasm-builder');

Then declare your stacks, with the same format, in a ``stacks.php`` file next
to ``castor.php``. The stacks of this repository are there to inherit from:

.. code-block:: php

	<?php
	// stacks.php

	use PhpWasm\Stack;

	return [
	    Stack::named('my-app')
	        ->from('php.net')
	        ->php('8.5.11')
	        ->extensions('tokenizer', 'bcmath')
	        ->embed('dist'),    // relative to your project
	];

``castor build my-app`` then builds it in the ``build/my-app/`` directory of
your project. Use ``mount('composer://derickr/php-wasm-builder', 'wasm')``
instead of ``import()`` to get the tasks under a ``wasm:`` prefix
(``castor wasm:build``), if ``build``, ``run`` or ``test`` are already tasks of
your project.
