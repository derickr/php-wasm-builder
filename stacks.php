<?php

use PhpWasm\Stack;

return [
    // What php.net ships (docs + PHP Tour). `castor build` builds this one.
    Stack::named('php.net')
        ->php('8.4.26')
        ->extensions('calendar', 'ctype', 'dom', 'mbstring', 'simplexml', 'xml', 'xmlreader', 'xmlwriter')
        ->embed('examples')
        ->targets('web', 'node'),

    // The same thing on the previous and next branch. Older ones work the same way.
    Stack::named('php.net-8.3')->from('php.net')->php('8.3.35'),
    Stack::named('php.net-8.5')->from('php.net')->php('8.5.11'),

    // A playground: tools built on PHP-Parser need tokenizer, and there is
    // no reason 0.1 + 0.2 should not be 0.3 in a browser.
    Stack::named('playground')
        ->from('php.net')
        ->php('8.5.11')
        ->extensions('tokenizer', 'bcmath', 'filter')
        ->memory('256mb')
        ->embed('playground/app'),
];
