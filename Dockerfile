FROM debian:bookworm AS bookworm
ARG PHP_VERSION=8.4.4
ARG ONIGURUMA_VERSION=6.9.10
ARG LIBXML_VERSION=2.13.5
ARG ICU_VERSION=74-2
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
	git clone https://github.com/php/php-src.git php-src --branch php-$PHP_VERSION --single-branch --depth 1 && \
	cd php-src && \
	./buildconf --force

# Setting ENV vars
ENV PATH=/local/src/emsdk:/local/src/emsdk/upstream/emscripten:/usr/local/bin:/usr/bin
ENV EMSDK=/local/src/emsdk
# emsdk's bundled node version changes between releases
ENV EMSDK_NODE=/usr/local/bin/node
RUN ln -sf $(ls -d /local/src/emsdk/node/*/bin/node) /usr/local/bin/node

# Create install directory
RUN mkdir -p /local/install

# Compile mbstring regex library, and set its env vars
RUN git clone https://github.com/kkos/oniguruma --branch v$ONIGURUMA_VERSION  --single-branch --depth 1 && \
	cd oniguruma && \
	autoreconf -vfi && \
	CFLAGS="-Os -g0" emconfigure ./configure --prefix=/local/install --disable-shared && \
	emmake make && \
	emmake make install
ENV ONIG_LIBS="-L/local/install"
ENV ONIG_CFLAGS="-I/local/install/include"

# Compile libxml and related extensions, and set its env vars
RUN git clone https://gitlab.gnome.org/GNOME/libxml2.git libxml2 --branch v$LIBXML_VERSION  --single-branch --depth 1 && \
	cd libxml2 && \
	CFLAGS="-Os -g0" emconfigure ./autogen.sh --prefix=/local/install --enable-static --disable-shared --with-python=no --with-threads=no && \
	emmake make -j`nproc` && \
	emmake make install
ENV LIBXML_LIBS="-L/local/install"
ENV LIBXML_CFLAGS="-I/local/install/include/libxml2"

# phase 1: build .dat files on host
RUN git clone https://github.com/unicode-org/icu.git icu --branch release-$ICU_VERSION  --single-branch --depth 1 && \
	mkdir -p /local/src/icu-host && \
	cd /local/src/icu-host && \
	/local/src/icu/icu4c/source/runConfigureICU Linux --enable-static --disable-shared && \
	make -j`nproc`

# phase 2: build for wasm32
# emscriptens mmap is unaligned, force stdio read instead
RUN mkdir -p /local/src/icu-build && \
	cd /local/src/icu-build && \
	CFLAGS="-fPIC -Os -g0" CXXFLAGS="-fPIC -Os -g0" CPPFLAGS="-DU_HAVE_MMAP=0" emconfigure /local/src/icu/icu4c/source/configure --prefix=/local/install --enable-static --disable-shared --disable-extras --disable-tests --disable-samples --with-cross-build=/local/src/icu-host --with-data-packaging=archive && \
	emmake make -j`nproc` && \
	emmake make install
ENV ICU_LIBS="-L/local/install/lib -licui18n -licuio -licuuc -licudata"
ENV ICU_CFLAGS="-I/local/install/include"

# Configure PHP
RUN cd php-src && \
	emconfigure ./configure --host=$(emcc -dumpmachine) --enable-embed=static \
	--disable-all --without-pcre-jit --disable-fiber-asm --disable-cgi --disable-cli --disable-phpdbg \
	--with-libxml --enable-simplexml --enable-xml --enable-xmlreader --enable-xmlwriter --enable-dom \
	--enable-mbstring \
	--enable-intl=shared \
	--enable-calendar --enable-ctype

# Compile WASM shim
RUN \
	emcc -Os -g0 -I php-src/. -I php-src/Zend -I php-src/main -I php-src/TSRM -c phpw.c -o phpw.o

# Compile PHP
RUN \
	cd php-src && \
	emmake make -j`nproc` EXTRA_CFLAGS="-Os -g0" EXTRA_CXXFLAGS="-Os -g0"

# rebuild intl.so as -fPIC SIDE_MODULE
RUN cd php-src && \
	find ext/intl \( -name "*.o" -o -name "*.lo" \) -delete && \
	emmake make -j`nproc` EXTRA_CFLAGS="-fPIC -Os -g0" EXTRA_CXXFLAGS="-fPIC -Os -g0"

COPY examples examples

RUN mkdir -p /build && \
	cd /local/src && \
	emcc -O2 -g0 -s SIDE_MODULE=1 \
	$(find php-src/ext/intl -name "*.o") \
	/local/install/lib/libicui18n.a \
	/local/install/lib/libicuio.a \
	/local/install/lib/libicuuc.a \
	/local/install/lib/libicudata.a \
	-o /build/intl.so && \
	cp $(ls /local/install/share/icu/*/icudt*.dat) /build/

# Collect the symbols intl.so imports from the base, so MAIN_MODULE=2's dead-code elimination keeps them.
# -Wno-undefined lets emcc ignore the symbols intl defines itself
RUN /local/src/emsdk/upstream/bin/wasm-dis /build/intl.so 2>/dev/null \
	| grep -oE '\(import "(env|GOT\.func|GOT\.mem)" "[^"]+"' \
	| sed -E 's/.*"([^"]+)"$/\1/' | sort -u \
	| grep -vE '^(memory|__indirect_function_table|__stack_pointer|__memory_base|__table_base)$' \
	| sed 's/^/_/' > /local/src/exports.txt && \
	printf '%s\n' _phpw _phpw_flush _phpw_exec _phpw_run _chdir _setenv _php_embed_init _php_embed_shutdown _zend_eval_string >> /local/src/exports.txt && \
	sort -u -o /local/src/exports.txt /local/src/exports.txt

# Create PHP-WASM
RUN mkdir -p /build && \
	emcc -o /build/php-web.mjs \
	-O2 -g0 -s MAIN_MODULE=2 -Wno-undefined \
	-s EXPORTED_FUNCTIONS=@/local/src/exports.txt \
	-s EXPORTED_RUNTIME_METHODS='["ccall", "UTF8ToString", "lengthBytesUTF8", "FS", "ENV"]' \
	-s ENVIRONMENT=web \
	-s MAXIMUM_MEMORY=128mb -s INITIAL_MEMORY=128mb -s ALLOW_MEMORY_GROWTH=0 \
	-s ASSERTIONS=0 -s ERROR_ON_UNDEFINED_SYMBOLS=0 -s MODULARIZE=1 -s INVOKE_RUN=0 -s LZ4=1 -s EXPORT_ES6=1 \
	-s EXPORT_NAME=createPhpModule \
	--embed-file examples \
	phpw.o php-src/.libs/libphp.a \
	/local/install/lib/libxml2.a \
	/local/install/lib/libonig.a

RUN mkdir -p /build && \
	emcc -o /build/php-cli.mjs \
	-O2 -g0 -s MAIN_MODULE=2 -Wno-undefined \
	-s EXPORTED_FUNCTIONS=@/local/src/exports.txt \
	-s EXPORTED_RUNTIME_METHODS='["ccall", "UTF8ToString", "lengthBytesUTF8", "FS", "ENV"]' \
	-s ENVIRONMENT=node \
	-s MAXIMUM_MEMORY=128mb -s INITIAL_MEMORY=128mb -s ALLOW_MEMORY_GROWTH=0 \
	-s ASSERTIONS=0 -s ERROR_ON_UNDEFINED_SYMBOLS=0 -s MODULARIZE=1 -s INVOKE_RUN=0 -s LZ4=1 -s EXPORT_ES6=1 \
	-s EXPORT_NAME=createPhpModule \
	--embed-file examples \
	phpw.o php-src/.libs/libphp.a \
	/local/install/lib/libxml2.a \
	/local/install/lib/libonig.a

# Save file
FROM scratch
COPY --from=bookworm /build/ .
