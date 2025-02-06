/**
 * Copyright (c) 2025 The PHP Foundation
 * Copyright (c) 2023-2024 Antoine Bluchet
 *
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */
#include "sapi/embed/php_embed.h"
#include <emscripten.h>
#include <stdlib.h>

#include "zend_globals_macros.h"
#include "zend_exceptions.h"
#include "zend_closures.h"

int main() {
	return 0;
}

void phpw_flush()
{
	fprintf(stdout, "\n");
	fprintf(stderr, "\n");
}

char *EMSCRIPTEN_KEEPALIVE phpw_exec(char *code)
{
	setenv("USE_ZEND_ALLOC", "0", 1);
	php_embed_init(0, NULL);
	char *retVal = NULL;

	zend_try
	{
		zval ret_zv;

		zend_eval_string(code, &ret_zv, "expression");
		convert_to_string(&ret_zv);

		retVal = Z_STRVAL(ret_zv);
	} zend_catch {
	} zend_end_try();

	phpw_flush();
	php_embed_shutdown();

	return retVal;
}

void EMSCRIPTEN_KEEPALIVE phpw_run(char *code)
{
	setenv("USE_ZEND_ALLOC", "0", 1);
	php_embed_init(0, NULL);
	PG(during_request_startup) = 0;

	zend_try
	{
		zend_eval_string(code, NULL, "script");
		if (EG(exception)) {
			zend_exception_error(EG(exception), E_ERROR);
		}
	} zend_catch {
		/* int exit_status = EG(exit_status); */
	} zend_end_try();

	phpw_flush();
	php_embed_shutdown();
}

int EMBED_SHUTDOWN = 1;

void phpw(char *file)
{
	setenv("USE_ZEND_ALLOC", "0", 1);
	if (EMBED_SHUTDOWN == 0) {
		php_embed_shutdown();
	}

	php_embed_init(0, NULL);
	EMBED_SHUTDOWN = 0;
	zend_first_try {
		zend_file_handle file_handle;
		zend_stream_init_filename(&file_handle, file);
		// file_handle.primary_script = 1;

		if (!php_execute_script(&file_handle)) {
			php_printf("Failed to execute PHP script.\n");
		}

		zend_destroy_file_handle(&file_handle);
	} zend_catch {
		/* int exit_status = EG(exit_status); */
	} zend_end_try();

	phpw_flush();
	php_embed_shutdown();
	EMBED_SHUTDOWN = 1;
}
