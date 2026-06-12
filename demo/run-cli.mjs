import fs from 'node:fs';

//import phpBinary from 'file:///home/derick/dev/php/wasm-test/php-node.mjs';
import phpBinary from '../build/php-cli.mjs';

var bufferA = [];

var loadPhp = async function () {
	const { ccall, FS } = await phpBinary({
		print(data) {
			if (!data) {
				return;
			}

			if (bufferA.length) {
				bufferA.push("\n");
			}
			bufferA.push(data);
		},
		preRun(module) {
			module.ENV.PHPRC = '/php.ini';
		},
	});

	FS.mkdir('/icu');
	FS.writeFile('/icu/icudt74l.dat', fs.readFileSync(new URL('../build/icudt74l.dat', import.meta.url)));

	FS.writeFile('/intl.so', fs.readFileSync(new URL('../build/intl.so', import.meta.url)));
	FS.writeFile('/php.ini', 'extension_dir=/\nextension=intl\n');

	var version = ccall("phpw_run", null, ["string"], ["?>" + fs.readFileSync(process.argv[2])]);

	process.stdout.write(bufferA.join(""));
};

var php = loadPhp();
