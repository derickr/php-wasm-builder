<?php

namespace PhpWasm;

/**
 * A named, declarative combination: a PHP version, a set of extensions, what to
 * embed and which targets to build.
 *
 * A stack can inherit from another one with from(): whatever it does not
 * declare comes from its parent, the extensions it declares are added to the
 * parent's, and anything else it declares replaces the parent's.
 */
final class Stack
{
    private ?string $parent = null;
    private ?string $php = null;
    /** @var list<string> */
    private array $extensions = [];
    private ?string $embed = null;
    /** @var list<string>|null */
    private ?array $targets = null;
    private ?string $memory = null;
    private ?string $directory = null;

    private function __construct(private readonly string $name)
    {
    }

    public static function named(string $name): self
    {
        // The name is used as a directory name, and (once cleaned up) as a bake target name
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid stack name, use letters, digits, ".", "-" and "_".', $name));
        }

        return new self($name);
    }

    public function from(string $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    public function php(string $version): self
    {
        $this->php = $version;

        return $this;
    }

    /**
     * Extensions to build, in addition to the ones of the parent stack.
     */
    public function extensions(string ...$extensions): self
    {
        $this->extensions = array_values(array_unique([...$this->extensions, ...$extensions]));

        return $this;
    }

    /**
     * The directory to embed in the WASM filesystem (at /<its name>), relative
     * to the directory of the stacks.php file that declares the stack.
     */
    public function embed(string $directory): self
    {
        $this->embed = $directory;

        return $this;
    }

    public function targets(string ...$targets): self
    {
        $this->targets = array_values(array_unique($targets));

        return $this;
    }

    public function memory(string $memory): self
    {
        $this->memory = $memory;

        return $this;
    }

    /**
     * @internal Called when the stacks.php file that declares the stack is loaded
     */
    public function definedIn(string $directory): self
    {
        $this->directory = $directory;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getParent(): ?string
    {
        return $this->parent;
    }

    public function getPhp(): ?string
    {
        return $this->php;
    }

    /**
     * @return list<string> the extensions declared by this stack only
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    public function getEmbed(): ?string
    {
        return $this->embed;
    }

    /**
     * @return list<string>|null
     */
    public function getTargets(): ?array
    {
        return $this->targets;
    }

    public function getMemory(): ?string
    {
        return $this->memory;
    }

    public function getDirectory(): ?string
    {
        return $this->directory;
    }
}
