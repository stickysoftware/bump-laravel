<?php

namespace StickySoftware\Bump\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'make:bump-backfill')]
final class BackfillMakeCommand extends GeneratorCommand
{
    protected $signature = 'make:bump-backfill
                            {name : The name of the backfill class}
                            {--f|force : Create the class even if the backfill already exists}';

    protected $description = 'Create a new Bump backfill class';

    protected $type = 'Backfill';

    protected function getStub(): string
    {
        $published = $this->laravel->basePath('stubs/bump-backfill.stub');

        return file_exists($published) ? $published : __DIR__.'/../../stubs/bump-backfill.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Bump';
    }

    protected function buildClass($name): string
    {
        return str_replace('{{ prefix }}', $this->prefix($name), parent::buildClass($name));
    }

    /**
     * OrdersBackfill becomes "orders".
     */
    private function prefix(string $name): string
    {
        $class = class_basename($name);

        if ($class !== 'Backfill' && str_ends_with($class, 'Backfill')) {
            $class = Str::beforeLast($class, 'Backfill');
        }

        return Str::kebab($class);
    }
}
