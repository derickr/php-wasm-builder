<?php

namespace PhpWasm;

/**
 * A stack once it has been resolved (parent first, then command line
 * overrides): everything the Dockerfile needs to build it.
 */
final class Build
{
    /**
     * The targets and the name of the files they produce, as done by the
     * "resolve" stage of the Dockerfile.
     */
    public const TARGETS = [
        'web' => 'php-web',
        'node' => 'php-cli',
    ];

    /**
     * @param list<string> $extensions
     * @param string|null  $embed      absolute path of the directory to embed
     * @param list<string> $targets
     * @param string       $outputDir  absolute path of the directory the files are written to
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $php,
        public readonly array $extensions,
        public readonly ?string $embed,
        public readonly array $targets,
        public readonly ?string $memory,
        public readonly string $outputDir,
    ) {
    }

    public function withTarget(string $target): self
    {
        if (\in_array($target, $this->targets, true)) {
            return $this;
        }

        return new self($this->name, $this->php, $this->extensions, $this->embed, [...$this->targets, $target], $this->memory, $this->outputDir);
    }

    /**
     * @return string the absolute path of a file this stack produces, for example artifact('web', 'wasm')
     */
    public function artifact(string $target, string $extension): string
    {
        return $this->outputDir . '/' . self::TARGETS[$target] . '.' . $extension;
    }

    /**
     * @return list<string> the absolute paths of the files the build produces
     */
    public function artifacts(): array
    {
        $files = [];
        foreach ($this->targets as $target) {
            $files[] = $this->artifact($target, 'mjs');
            $files[] = $this->artifact($target, 'wasm');
        }

        return $files;
    }

    /**
     * The name of the target in a bake file, which only accepts [a-zA-Z0-9_-].
     */
    public function bakeName(): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '-', $this->name);
    }

    /**
     * @return array<string, mixed> the definition of the bake target that builds this stack
     */
    public function bakeTarget(string $builderRoot): array
    {
        $args = [
            'PHP_VERSION' => $this->php,
            'EXTENSIONS' => implode(' ', $this->extensions),
            'TARGETS' => implode(' ', $this->targets),
            'MEMORY' => $this->memory,
            // The name of the embedded directory in the WASM filesystem, none if nothing is embedded
            'EMBED_PATH' => null === $this->embed ? '' : basename($this->embed),
        ];

        $target = [
            'dockerfile' => $builderRoot . '/Dockerfile',
            'context' => $builderRoot,
            'args' => array_filter($args, static fn (?string $value): bool => null !== $value),
            'output' => ['type=local,dest=' . $this->outputDir],
        ];

        if (null !== $this->embed) {
            $target['contexts'] = ['embed' => $this->embed];
        }

        return $target;
    }
}
