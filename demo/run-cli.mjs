import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

// Usage: node run-cli.mjs [--build <php-cli.mjs>] (<file.php> | -r <code>)
//
// --build  the php-cli.mjs of the stack to run, the php.net one by default
// -r       PHP code to run, without <?php ?>
var build = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../build/php.net/php-cli.mjs');
var code = null;
var file = null;

var args = process.argv.slice(2);
while (args.length) {
	var arg = args.shift();

	if (arg === '--build') {
		build = path.resolve(args.shift());
	} else if (arg === '-r') {
		code = args.shift();
	} else {
		file = arg;
	}
}

if ((file === null) === (code === null)) {
	console.error('Usage: node run-cli.mjs [--build <php-cli.mjs>] (<file.php> | -r <code>)');
	process.exit(1);
}

var { default: phpBinary } = await import(pathToFileURL(build));

var bufferA = [];

var loadPhp = async function () {
	const { ccall } = await phpBinary({
		print(data) {
			if (!data) {
				return;
			}

			if (bufferA.length) {
				bufferA.push("\n");
			}
			bufferA.push(data);
		},
	});

	// The code of a file starts in HTML mode, the one of -r in PHP mode
	var version = ccall("phpw_run", null, ["string"], [code !== null ? code : "?>" + fs.readFileSync(file)]);

	// print() drops the line breaks, and the last one with them
	process.stdout.write(bufferA.join("") + (bufferA.length ? "\n" : ""));
};

var php = loadPhp();
