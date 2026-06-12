import fs from 'node:fs';

//import phpBinary from 'file:///home/derick/dev/php/wasm-test/php-node.mjs';
import phpBinary from '../build/php-cli.mjs';

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

	var version = ccall("phpw_run", null, ["string"], ["?>" + fs.readFileSync(process.argv[2])]);

	process.stdout.write(bufferA.join(""));
};

var php = loadPhp();
