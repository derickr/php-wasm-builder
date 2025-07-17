group "default" {
  targets = ["php-8-0", "php-8-1", "php-8-2", "php-8-3", "php-8-4", "php-8-5"]
}

target "php-8-0" {
	output = ["type=local,dest=./builds/auto/build-8.0.x"]
	tags = ["php-wasm"]
	args = {
    	PHP_VERSION = "8.0.30"
    	LIBXML_VERSION = "2.9.14"
  	}
}

target "php-8-1" {
	output = ["type=local,dest=./builds/auto/build-8.1.x"]
	tags = ["php-wasm"]
	args = {
    	PHP_VERSION = "8.1.33"
  	}
}

target "php-8-2" {
	output = ["type=local,dest=./builds/auto/build-8.2.x"]
	tags = ["php-wasm"]
	args = {
    	PHP_VERSION = "8.2.29"
  	}
}

target "php-8-3" {
	output = ["type=local,dest=./builds/auto/build-8.3.x"]
	tags = ["php-wasm"]
	args = {
    	PHP_VERSION = "8.3.23"
  	}
}

target "php-8-4" {
	output = ["type=local,dest=./builds/auto/build-8.4.x"]
	tags = ["php-wasm"]
	args = {
    	PHP_VERSION = "8.4.10"
  	}
}

target "php-8-5" {
	output = ["type=local,dest=./builds/auto/build-8.5.x"]
	tags = ["php-wasm"]
	args = {
    	PHP_VERSION = "8.5.0alpha1"
  	}
}
