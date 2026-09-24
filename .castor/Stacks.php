<?php

namespace PhpWasm;

/**
 * The stacks that are declared in the stacks.php files, and what it takes to
 * turn one of them into something the Dockerfile can build.
 */
final class Stacks
{
    /**
     * The stack that is built when none is given: what php.net ships.
     */
    public const DEFAULT = 'php.net';

    /**
     * @param array<string, Stack> $stacks
     */
    private function __construct(
        private readonly string $builderRoot,
        private readonly array $stacks,
    ) {
    }

    /**
     * Loads the stacks.php of the builder first, then the ones of the other
     * directories (the project that imports the builder): they can inherit from
     * the stacks of the builder, or replace them.
     */
    public static function load(string $builderRoot, string ...$directories): self
    {
        $stacks = [];
        // The builder is also the project when it runs from this repository
        foreach (array_unique(array_map(static fn (string $directory): string => realpath($directory) ?: $directory, [$builderRoot, ...$directories])) as $directory) {
            if (!is_file($file = $directory . '/stacks.php')) {
                continue;
            }

            foreach (self::require($file) as $stack) {
                if (!$stack instanceof Stack) {
                    throw new \UnexpectedValueException(\sprintf('"%s" must return a list of "%s".', $file, Stack::class));
                }

                $stacks[$stack->getName()] = $stack->definedIn($directory);
            }
        }

        return new self($builderRoot, $stacks);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->stacks);
    }

    public function get(string $name): Stack
    {
        return $this->stacks[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown stack "%s", the stacks are: %s.', $name, implode(', ', $this->names())));
    }

    /**
     * Resolves a stack: its parents first, then the overrides.
     *
     * @param list<string> $with    extensions to add
     * @param list<string> $targets the targets to build, instead of the ones of the stack
     */
    public function resolve(
        string $name,
        string $buildDir,
        ?string $php = null,
        array $with = [],
        array $targets = [],
        ?string $memory = null,
        ?string $embed = null,
    ): Build {
        $merged = $this->merge($name);

        $php ??= $merged['php'] ?? $this->dockerfileDefault('PHP_VERSION');
        $extensions = array_values(array_unique([...$merged['extensions'], ...$with]));
        $targets = [] !== $targets ? array_values(array_unique($targets)) : $merged['targets'] ?? array_keys(Build::TARGETS);
        $memory ??= $merged['memory'];

        if (null !== $embed) {
            // It comes from the command line: relative to where the command is run
            $embed = self::absolute($embed, getcwd() ?: '.');
        } else {
            $embed = $merged['embed'];
        }
        if (null !== $embed && !is_dir($embed)) {
            throw new \InvalidArgumentException(\sprintf('Cannot embed "%s" in the stack "%s": it is not a directory.', $embed, $name));
        }

        foreach ($targets as $target) {
            if (!isset(Build::TARGETS[$target])) {
                throw new \InvalidArgumentException(\sprintf('Unknown target "%s", the targets are: %s.', $target, implode(', ', array_keys(Build::TARGETS))));
            }
        }

        return new Build($name, $php, $extensions, $embed, $targets, $memory, $buildDir . '/' . $name);
    }

    /**
     * @return array{php: string, extensions: string, targets: string}
     */
    public function describe(string $name): array
    {
        $stack = $this->get($name);
        $merged = $this->merge($name);

        $own = implode(' ', $stack->getExtensions());
        if (null === $stack->getParent()) {
            $extensions = \strlen($own) > 44 ? strtok(wordwrap($own, 44, "\n", false), "\n") . ' …' : ($own ?: '-');
        } else {
            $extensions = $stack->getParent() . ('' !== $own ? ' + ' . $own : '');
        }
        if (null !== $stack->getMemory()) {
            $extensions .= ', ' . $stack->getMemory();
        }

        return [
            'php' => $merged['php'] ?? $this->dockerfileDefault('PHP_VERSION') ?? '-',
            'extensions' => $extensions,
            'targets' => implode(' ', $merged['targets'] ?? array_keys(Build::TARGETS)),
        ];
    }

    /**
     * @return array{php: ?string, extensions: list<string>, embed: ?string, targets: ?list<string>, memory: ?string}
     */
    private function merge(string $name): array
    {
        $merged = ['php' => null, 'extensions' => [], 'embed' => null, 'targets' => null, 'memory' => null];

        foreach ($this->chain($name) as $stack) {
            $merged['php'] = $stack->getPhp() ?? $merged['php'];
            $merged['extensions'] = array_values(array_unique([...$merged['extensions'], ...$stack->getExtensions()]));
            $merged['targets'] = $stack->getTargets() ?? $merged['targets'];
            $merged['memory'] = $stack->getMemory() ?? $merged['memory'];
            if (null !== $stack->getEmbed()) {
                // Relative to the stacks.php that declares the stack
                $merged['embed'] = self::absolute($stack->getEmbed(), $stack->getDirectory() ?? '.');
            }
        }

        return $merged;
    }

    /**
     * @return list<Stack> the stack and its parents, the farthest one first
     */
    private function chain(string $name): array
    {
        $chain = [];
        $seen = [];

        for ($current = $name; null !== $current; $current = $stack->getParent()) {
            if (isset($seen[$current])) {
                throw new \LogicException(\sprintf('The stacks inherit from each other in a loop: %s.', implode(' -> ', [...array_keys($seen), $current])));
            }
            $seen[$current] = true;

            $stack = $this->get($current);
            array_unshift($chain, $stack);
        }

        return $chain;
    }

    /**
     * The default of a build argument, as declared by the Dockerfile.
     */
    private function dockerfileDefault(string $argument): ?string
    {
        $dockerfile = (string) file_get_contents($this->builderRoot . '/Dockerfile');

        return preg_match('/^ARG ' . preg_quote($argument, '/') . '=(\S+)/m', $dockerfile, $matches) ? trim($matches[1], '"') : null;
    }

    private static function absolute(string $path, string $relativeTo): string
    {
        $path = str_starts_with($path, '/') ? $path : $relativeTo . '/' . $path;

        return realpath($path) ?: $path;
    }

    /**
     * Isolates the stacks.php file: it only sees its own variables.
     *
     * @return iterable<mixed>
     */
    private static function require(string $file): iterable
    {
        return require $file;
    }
}
