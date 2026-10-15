<?php

namespace StickySoftware\Bump;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class Identifiables
{
    /**
     * Models become handles. Strings, integers and maps pass through untouched, so every shape the API
     * accepts still works.
     */
    public static function normalize(mixed $identifiable): mixed
    {
        if ($identifiable instanceof Model) {
            return self::fromModel($identifiable);
        }

        if (is_array($identifiable) && array_is_list($identifiable)) {
            return array_map(
                fn (mixed $entry) => $entry instanceof Model ? self::fromModel($entry) : $entry,
                $identifiable,
            );
        }

        return $identifiable;
    }

    /**
     * Labels are opt-in: a model only sends one when it defines bumpLabel().
     *
     * @return string|array{handle: string, label: string}
     */
    public static function fromModel(Model $model): string|array
    {
        $handle = method_exists($model, 'bumpHandle') ? (string) $model->bumpHandle() : self::handle($model);
        $label = method_exists($model, 'bumpLabel') ? $model->bumpLabel() : null;

        if ($label === null || $label === '') {
            return $handle;
        }

        return ['handle' => $handle, 'label' => (string) $label];
    }

    public static function handle(Model $model): string
    {
        $key = $model->getKey();

        if ($key === null || $key === '') {
            throw new InvalidArgumentException('An unsaved '.$model::class.' has no key to build a Bump handle from.');
        }

        $alias = Relation::getMorphAlias($model::class);
        $type = $alias === $model::class ? Str::snake(class_basename($model)) : $alias;

        return $type.'_'.$key;
    }
}
