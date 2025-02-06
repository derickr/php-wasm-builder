FROM debian:bookworm as bookworm
ARG PHP_VERSION=8.4.3
WORKDIR /local/src

# Copy SHIM source to /local/src
COPY phpw.c /local/src/phpw.c

# Apt-Install
RUN apt-get update && \
	apt-get --no-install-recommends -y install \
	build-essential \
	automake \
	autoconf \
	libtool \
	pkg-config \
	bison \
	flex \
	make \
	re2c \
	git \
	pv \
	ca-certificates \
	python3

# Install emscripten sdk
RUN \
	git clone  https://github.com/emscripten-core/emsdk.git && \
	cd emsdk && \
	./emsdk install latest && \
	./emsdk activate latest

# Download PHP and Set-Up Configure
RUN \
	git clone https://github.com/php/php-src.git php-src --branch PHP-$PHP_VERSION --single-branch --depth 1 && \
	cd php-src && \
	./buildconf --force

# Setting ENV vars
ENV PATH=/local/src/emsdk:/local/src/emsdk/upstream/emscripten:/usr/local/bin:/usr/bin
ENV EMSDK=/local/src/emsdk
ENV EMSDK_NODE=/local/src/emsdk/node/20.18.0_64bit/bin/node

# Configure PHP
RUN cd php-src && \
	emconfigure ./configure --enable-embed=static \
	--disable-all --without-pcre-jit --disable-fiber-asm --disable-cgi --disable-cli --disable-phpdbg \
#	--with-libxml --enable-simplexml --enable-xml --enable-xmlreader --enable-dom \
#	--enable-mbstring \
	--enable-calendar --enable-ctype

# Compile WASM shim
RUN \
	emcc -O2 -I php-src/. -I php-src/Zend -I php-src/main -I php-src/TSRM -c phpw.c -o phpw.o

# Compile PHP
RUN \
	cd php-src && \
	emmake make -j`nproc`

# Create PHP-WASM
RUN mkdir /build && \
	emcc -o /build/php-$PHP_VERSION-web.mjs \
	-O2 --llvm-lto 2 \
	-s EXPORTED_FUNCTIONS='["_phpw", "_phpw_flush", "_phpw_exec", "_phpw_run", "_chdir", "_setenv", "_php_embed_init", "_php_embed_shutdown", "_zend_eval_string"]' \
	-s EXPORTED_RUNTIME_METHODS='["ccall", "UTF8ToString", "lengthBytesUTF8", "FS"]' \
	-s ENVIRONMENT=web \
	-s MAXIMUM_MEMORY=128mb -s INITIAL_MEMORY=128mb -s ALLOW_MEMORY_GROWTH=0 \
	-s ASSERTIONS=0 -s ERROR_ON_UNDEFINED_SYMBOLS=0 -s MODULARIZE=1 -s INVOKE_RUN=0 -s LZ4=1 -s EXPORT_ES6=1 \
	-s EXPORT_NAME=createPhpModule \
	phpw.o php-src/.libs/libphp.a \
#	../install/lib/libxml2.a ../install/lib/libonig.a \
	php-src/.libs/libphp.a

# Save file
FROM scratch
COPY --from=bookworm /build/ .
