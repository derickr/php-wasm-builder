<?php

namespace PhpWasm;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Helper\PathHelper;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;

use function Castor\capture;
use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\notify;
use function Castor\run;

// Castor never knows how to compile PHP: the Dockerfile does. These tasks only
// know how to name a combination (a stack, see stacks.php) and ask the
// Dockerfile for it, through `docker buildx bake`.
//
// This file works from this repository, and from any project that imports it:
//
//     import('composer://derickr/php-wasm-builder');
//
// The tasks then build the stacks of both, in the build/ directory of the
// project.

defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', false);

/**
 * Where the Dockerfile, the shim and the runner are.
 */
const BUILDER_ROOT = __DIR__;

// Composer does it when the builder is imported by another project
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, __NAMESPACE__ . '\\') && is_file($file = BUILDER_ROOT . '/.castor/' . substr($class, \strlen(__NAMESPACE__) + 1) . '.php')) {
        require $file;
    }
});

#[AsTask(name: 'build', namespace: '', description: 'Builds a stack: php.net when none is given')]
function build(
    #[AsArgument(description: 'The stack to build', autocomplete: 'PhpWasm\complete_stacks')]
    ?string $stack = null,
    #[AsOption(description: 'Build every stack, in one buildx invocation')]
    bool $all = false,
    #[AsOption(description: 'Override the PHP version, for example 8.5.11')]
    ?string $php = null,
    #[AsOption(description: 'Add an extension, can be repeated', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, autocomplete: 'PhpWasm\complete_extensions')]
    array $with = [],
    #[AsOption(description: 'Only build this target (web or node), can be repeated', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, autocomplete: ['web', 'node'])]
    array $target = [],
    #[AsOption(description: 'Override the memory of the WASM module, for example 256mb')]
    ?string $memory = null,
    #[AsOption(description: 'Override the directory to embed')]
    ?string $embed = null,
    #[AsOption(description: 'Print the bake file instead of building')]
    bool $print = false,
): int {
    $registry = registry();

    if ($all) {
        if (null !== $stack || null !== $php || [] !== $with || [] !== $target || null !== $memory || null !== $embed) {
            throw new \InvalidArgumentException('--all builds every stack as declared, it can not be combined with a stack or with overrides.');
        }

        $builds = array_map(static fn (string $name): Build => $registry->resolve($name, build_dir()), $registry->names());
        $bakeFile = build_dir() . '/bake.json';
    } else {
        $builds = [$registry->resolve($stack ?? Stacks::DEFAULT, build_dir(), php: $php, with: $with, targets: $target, memory: $memory, embed: $embed)];
        $bakeFile = $builds[0]->outputDir . '/bake.json';
    }

    if ($print) {
        io()->writeln(bake_definition($builds), OutputInterface::OUTPUT_RAW);

        return 0;
    }

    foreach ($builds as $build) {
        io()->writeln(\sprintf(' // Stack "%s": PHP %s · %s', $build->name, $build->php ?? 'default', [] === $build->extensions ? 'no extension' : implode(' ', $build->extensions)));
    }

    bake($builds, $bakeFile);

    foreach ($builds as $build) {
        // The output directory is only ever added to: forget about the targets that are not built anymore
        foreach (Build::TARGETS as $name => $file) {
            if (!\in_array($name, $build->targets, true)) {
                fs()->remove([$build->artifact($name, 'mjs'), $build->artifact($name, 'wasm')]);
            }
        }

        io()->success(array_map(static fn (string $file): string => relative($file), $build->artifacts()));
    }

    notify(1 === \count($builds) ? \sprintf('Stack "%s" is built', $builds[0]->name) : \sprintf('%d stacks are built', \count($builds)));

    return 0;
}

#[AsTask(name: 'stacks', namespace: '', description: 'Lists the stacks')]
function list_stacks(): void
{
    $rows = [];
    foreach (registry()->names() as $name) {
        $stack = registry()->describe($name);
        $rows[] = [$name, $stack['php'], $stack['extensions'], $stack['targets']];
    }

    io()->table(['Stack', 'PHP', 'Extensions', 'Targets'], $rows);
}

#[AsTask(name: 'sizes', namespace: '', description: 'Compares the size of what is built with the php.net stack')]
function sizes(): void
{
    $size = static fn (string $stack, string $file): ?int => is_file($path = build_dir() . '/' . $stack . '/' . $file) ? (int) filesize($path) : null;

    $rows = [];
    foreach (registry()->names() as $name) {
        // The web build is the one that matters, and the one everybody has
        foreach (['php-web.wasm', 'php-cli.wasm'] as $file) {
            if (null === $bytes = $size($name, $file)) {
                continue;
            }

            $reference = $size(Stacks::DEFAULT, $file);
            $rows[] = [
                $name,
                $file,
                \sprintf('%.1f MB', $bytes / 1024 / 1024),
                Stacks::DEFAULT === $name ? '-' : (null === $reference ? 'n/a' : \sprintf('%+.1f %%', ($bytes / $reference - 1) * 100)),
            ];

            break;
        }
    }

    if ([] === $rows) {
        io()->warning('Nothing is built yet, run "castor build".');

        return;
    }

    io()->table(['Stack', 'File', 'Size', 'vs ' . Stacks::DEFAULT], $rows);
}

#[AsTask(name: 'run', namespace: '', description: 'Runs a PHP file, or some code with -r, in a built stack (node target)')]
function wasm_run(
    #[AsArgument(description: 'The PHP file to run')]
    ?string $file = null,
    #[AsOption(shortcut: 'r', description: 'The PHP code to run, without <?php ?>')]
    ?string $code = null,
    #[AsOption(description: 'The stack to run', autocomplete: 'PhpWasm\complete_stacks')]
    string $stack = Stacks::DEFAULT,
): int {
    if ((null === $file) === (null === $code)) {
        throw new \InvalidArgumentException('Give the PHP file to run, or the code to run with -r.');
    }

    $arguments = null !== $code ? ['-r', $code] : [is_file($file) ? realpath($file) : throw new \InvalidArgumentException(\sprintf('The file "%s" does not exist.', $file))];

    return node(registry()->resolve($stack, build_dir()), $arguments)->getExitCode() ?? 1;
}

#[AsTask(name: 'test', namespace: '', description: 'Builds the stacks, then checks from node that each has the right PHP version and its extensions')]
function test(
    #[AsArgument(description: 'The stacks to test, all by default', autocomplete: 'PhpWasm\complete_stacks')]
    array $stacks = [],
    #[AsOption(description: 'Check what is already built')]
    bool $noBuild = false,
): int {
    $registry = registry();

    // The checks run on node, so every stack needs that target
    $builds = array_map(static fn (string $name): Build => $registry->resolve($name, build_dir())->withTarget('node'), [] !== $stacks ? $stacks : $registry->names());

    if (!$noBuild) {
        bake($builds, build_dir() . '/bake.json');
    }

    $failed = 0;
    foreach ($builds as $build) {
        $problems = check($build);

        if ([] === $problems) {
            io()->writeln(\sprintf(' <info>✔</info> %s: PHP %s, %s', $build->name, $build->php ?? 'default', [] === $build->extensions ? 'no extension' : implode(' ', $build->extensions)));
            continue;
        }

        ++$failed;
        io()->writeln(\sprintf(' <error>✘</error> %s', $build->name));
        io()->listing($problems);
    }

    if ($failed > 0) {
        io()->error(\sprintf('%d of %d stacks are broken.', $failed, \count($builds)));

        return 1;
    }

    io()->success(\sprintf('%d stacks work.', \count($builds)));

    return 0;
}

/**
 * @return list<string>
 */
function complete_stacks(): array
{
    return registry()->names();
}

/**
 * @return list<string>
 */
function complete_extensions(): array
{
    // What the Dockerfile knows: ext <name> ...
    preg_match_all('/^ext (\w+)\s/m', (string) file_get_contents(BUILDER_ROOT . '/Dockerfile'), $matches);

    return $matches[1];
}

/**
 * The stacks of this repository, and the ones of the project that imports it.
 */
function registry(): Stacks
{
    static $registry;

    return $registry ??= Stacks::load(BUILDER_ROOT, project_root());
}

/**
 * The project that runs castor, which is not always this repository.
 */
function project_root(): string
{
    return PathHelper::getRoot();
}

function build_dir(): string
{
    return project_root() . '/build';
}

function relative(string $path): string
{
    return Path::makeRelative($path, getcwd() ?: project_root());
}

/**
 * @param list<Build> $builds
 */
function bake_definition(array $builds): string
{
    $targets = [];
    foreach ($builds as $build) {
        if (isset($targets[$build->bakeName()])) {
            throw new \LogicException(\sprintf('The stack "%s" has the same name as another one, once cleaned up for buildx ("%s").', $build->name, $build->bakeName()));
        }

        $targets[$build->bakeName()] = $build->bakeTarget(BUILDER_ROOT);
    }

    return json_encode([
        'group' => ['default' => ['targets' => array_keys($targets)]],
        'target' => $targets,
    ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
}

/**
 * Builds the stacks in a single buildx invocation: it builds them in parallel,
 * and shares the layers they have in common.
 *
 * @param list<Build> $builds
 */
function bake(array $builds, string $bakeFile): void
{
    fs()->dumpFile($bakeFile, bake_definition($builds) . "\n");

    io()->writeln(' // docker buildx bake -f ' . relative($bakeFile));
    run(['docker', 'buildx', 'bake', '-f', $bakeFile]);
}

/**
 * Runs the node runner on a stack.
 *
 * @param list<string> $arguments the PHP file, or -r and some code
 */
function node(Build $build, array $arguments): \Symfony\Component\Process\Process
{
    if (!is_file($runner = $build->artifact('node', 'mjs'))) {
        throw new \RuntimeException(\sprintf('"%s" does not exist: build the stack with the node target first ("castor build %s").', relative($runner), $build->name));
    }

    // No pty: the output of PHP must not be altered, it may go through a pipe
    return run(
        ['node', BUILDER_ROOT . '/demo/run-cli.mjs', '--build', $runner, ...$arguments],
        context: context()->withPty(false)->withAllowFailure(),
    );
}

/**
 * @return list<string> what is wrong with the stack, nothing when it works
 */
function check(Build $build): array
{
    if (!is_file($runner = $build->artifact('node', 'mjs'))) {
        return [\sprintf('"%s" does not exist', relative($runner))];
    }

    $code = '$e = ' . var_export($build->extensions, true) . '; echo json_encode(["version" => PHP_VERSION, "loaded" => array_map("extension_loaded", $e)]);';

    try {
        $output = capture(
            ['node', BUILDER_ROOT . '/demo/run-cli.mjs', '--build', $runner, '-r', $code],
            context: context()->withPty(false),
        );
    } catch (\Throwable $e) {
        return ['node failed: ' . $e->getMessage()];
    }

    $result = json_decode($output, true);
    if (!\is_array($result) || !isset($result['version'], $result['loaded'])) {
        return ['unexpected output: ' . $output];
    }

    $problems = [];
    if (null !== $build->php && $build->php !== $result['version']) {
        $problems[] = \sprintf('PHP_VERSION is %s, expected %s', $result['version'], $build->php);
    }
    foreach ($build->extensions as $i => $extension) {
        if (true !== ($result['loaded'][$i] ?? null)) {
            $problems[] = \sprintf('extension_loaded("%s") is false', $extension);
        }
    }

    return $problems;
}
