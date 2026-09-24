# Builds PHP as WebAssembly (see README.rst for the build arguments).
#
# Stages:
#
#   resolve     turns EXTENSIONS and TARGETS into a build plan (/plan). This is
#               where the extension -> ./configure flags / libraries map lives.
#   base        the toolchain: build tools and the emscripten SDK
#   php-src     PHP's sources, only depends on PHP_VERSION
#   oniguruma   third-party libraries, each one only built when the plan says
#   libxml2     an extension needs it (the others do nothing and stay tiny)
#   php         configures and compiles PHP
#   link        compiles the shim and links the .mjs/.wasm files
#
# Every stage only reads the parts of the plan it needs, and the plan is made
# of small files: changing EXTENSIONS, TARGETS, ... only rebuilds what actually
# depends on what changed.

FROM debian:bookworm AS resolve
ARG EXTENSIONS="calendar ctype dom mbstring simplexml xml xmlreader xmlwriter"
ARG TARGETS="web node"
COPY <<'EOF' /resolve.sh
set -eu
mkdir -p /plan

EXTENSIONS=$(echo "$EXTENSIONS" | tr ',\n' '  ')
TARGETS=$(echo "$TARGETS" | tr ',\n' '  ')

flags=""
libs=""
known=""

has() {
	case " $1 " in *" $2 "*) return 0 ;; esac
	return 1
}

# ext <name> <library or -> [<./configure flag>]
#
# The order of the lines is the order of the flags on the ./configure command
# line, which phpinfo() shows: it is kept as it always was, so that the default
# extensions still give the exact same command line.
ext() {
	known="$known $1"
	has "$EXTENSIONS" "$1" || return 0
	if [ "$2" != "-" ] && ! has "$libs" "$2"; then
		libs="$libs $2"
	fi
	if [ -n "${3:-}" ]; then
		flags="$flags $3"
	fi
}

ext libxml    libxml2
ext simplexml libxml2 --enable-simplexml
ext xml       libxml2 --enable-xml
ext xmlreader libxml2 --enable-xmlreader
ext xmlwriter libxml2 --enable-xmlwriter
ext dom       libxml2 --enable-dom
ext mbstring  oniguruma --enable-mbstring
ext calendar  - --enable-calendar
ext ctype     - --enable-ctype
ext bcmath    - --enable-bcmath
ext filter    - --enable-filter
ext tokenizer - --enable-tokenizer

for extension in $EXTENSIONS; do
	has "$known" "$extension" || { echo "Unknown extension '$extension', supported extensions:$known" >&2; exit 1; }
done

link=""
libxml2=0
oniguruma=0
if has "$libs" libxml2; then
	libxml2=1
	flags="--with-libxml$flags"
	link="$link /local/install/lib/libxml2.a"
fi
if has "$libs" oniguruma; then
	oniguruma=1
	link="$link /local/install/lib/libonig.a"
fi

# targets: <output file name> <emscripten environment>
: > /plan/targets
for target in $TARGETS; do
	case $target in
		web)  echo "php-web web" >> /plan/targets ;;
		node) echo "php-cli node" >> /plan/targets ;;
		*)    echo "Unknown target '$target', supported targets: web node" >&2; exit 1 ;;
	esac
done

echo "$flags" > /plan/configure
echo "$link" > /plan/link
echo "$libxml2" > /plan/libxml2
echo "$oniguruma" > /plan/oniguruma

echo "./configure:$flags"
echo "libraries:$libs"
echo "targets: $TARGETS"
EOF
RUN sh /resolve.sh

FROM debian:bookworm AS base
WORKDIR /local/src

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

# Setting ENV vars
ENV PATH=/local/src/emsdk:/local/src/emsdk/upstream/emscripten:/usr/local/bin:/usr/bin
ENV EMSDK=/local/src/emsdk
ENV EMSDK_NODE=/local/src/emsdk/node/20.18.0_64bit/bin/node

# Create install directory
RUN mkdir -p /local/install

# Download PHP and Set-Up Configure
FROM base AS php-src
ARG PHP_VERSION=8.4.4
RUN \
	git clone https://github.com/php/php-src.git php-src --branch php-$PHP_VERSION --single-branch --depth 1 && \
	cd php-src && \
	./buildconf --force

# Compile mbstring regex library
FROM base AS oniguruma
ARG ONIGURUMA_VERSION=6.9.10
COPY --from=resolve /plan/oniguruma /plan/oniguruma
RUN <<EOF
set -eu
[ "$(cat /plan/oniguruma)" = 1 ] || exit 0
git clone https://github.com/kkos/oniguruma --branch v$ONIGURUMA_VERSION --single-branch --depth 1
cd oniguruma
autoreconf -vfi
emconfigure ./configure --prefix=/local/install --disable-shared
emmake make
emmake make install
EOF

# Compile libxml and related extensions
FROM base AS libxml2
ARG LIBXML_VERSION=2.13.5
COPY --from=resolve /plan/libxml2 /plan/libxml2
RUN <<EOF
set -eu
[ "$(cat /plan/libxml2)" = 1 ] || exit 0
git clone https://gitlab.gnome.org/GNOME/libxml2.git libxml2 --branch v$LIBXML_VERSION --single-branch --depth 1
cd libxml2
emconfigure ./autogen.sh --prefix=/local/install --enable-static --disable-shared --with-python=no --with-threads=no
emmake make -j`nproc`
emmake make install
EOF

# Configure and compile PHP
FROM php-src AS php
COPY --from=oniguruma /local/install /local/install
COPY --from=libxml2 /local/install /local/install
COPY --from=resolve /plan/configure /plan/configure

ENV ONIG_LIBS="-L/local/install"
ENV ONIG_CFLAGS="-I/local/install/include"
ENV LIBXML_LIBS="-L/local/install"
ENV LIBXML_CFLAGS="-I/local/install/include/libxml2"

RUN cd php-src && \
	emconfigure ./configure --host=$(emcc -dumpmachine) --enable-embed=static \
	--disable-all --without-pcre-jit --disable-fiber-asm --disable-cgi --disable-cli --disable-phpdbg \
	$(cat /plan/configure)

RUN \
	cd php-src && \
	emmake make -j`nproc`

# The directory to embed in the WASM filesystem is the named build context
# "embed" (empty unless given, see docker-bake.hcl), it shows up as /$EMBED_PATH
FROM scratch AS embed

# Compile the WASM shim, and link everything
FROM php AS link
ARG EMBED_PATH=examples
ARG MEMORY=128mb

# Compile WASM shim
COPY phpw.c /local/src/phpw.c
RUN \
	emcc -O2 -I php-src/. -I php-src/Zend -I php-src/main -I php-src/TSRM -c phpw.c -o phpw.o

COPY --from=embed / /local/src/$EMBED_PATH
COPY --from=resolve /plan/link /plan/link
COPY --from=resolve /plan/targets /plan/targets

# Create PHP-WASM
RUN <<EOF
set -eu
mkdir -p /build
[ -z "$EMBED_PATH" ] || mkdir -p "$EMBED_PATH"
while read -r output environment; do
	emcc -o /build/$output.mjs \
	-O2 --llvm-lto 2 \
	-s EXPORTED_FUNCTIONS='["_phpw", "_phpw_flush", "_phpw_exec", "_phpw_run", "_chdir", "_setenv", "_php_embed_init", "_php_embed_shutdown", "_zend_eval_string"]' \
	-s EXPORTED_RUNTIME_METHODS='["ccall", "UTF8ToString", "lengthBytesUTF8", "FS"]' \
	-s ENVIRONMENT=$environment \
	-s MAXIMUM_MEMORY=$MEMORY -s INITIAL_MEMORY=$MEMORY -s ALLOW_MEMORY_GROWTH=0 \
	-s ASSERTIONS=0 -s ERROR_ON_UNDEFINED_SYMBOLS=0 -s MODULARIZE=1 -s INVOKE_RUN=0 -s LZ4=1 -s EXPORT_ES6=1 \
	-s EXPORT_NAME=createPhpModule \
	${EMBED_PATH:+--embed-file "$EMBED_PATH"} \
	phpw.o php-src/.libs/libphp.a \
	$(cat /plan/link) \
	php-src/.libs/libphp.a < /dev/null
done < /plan/targets
EOF

# Save file
FROM scratch
COPY --from=link /build/ .
