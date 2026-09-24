<?php

// A tiny application, embedded in the "playground" stack (see stacks.php): it
// is available in the WASM filesystem as /app.

echo 'PHP ', PHP_VERSION, "\n";
echo '0.1 + 0.2 = ', bcadd('0.1', '0.2', 1), "\n";
echo 'tokens: ', count(token_get_all('<?php echo 1 + 2;')), "\n";
echo 'valid e-mail: ', var_export((bool) filter_var('derick@php.net', FILTER_VALIDATE_EMAIL), true), "\n";
