<?php

namespace StickySoftware\Bump;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class Identifiables
{
    public const MAX_HANDLE_LENGTH = 64;

    // A string, not ::class: spatie/laravel-prefixed-ids is optional and may not be installed.
    private const SPATIE_TRAIT = 'Spatie\PrefixedIds\Models\Concerns\HasPrefixedId';

    private static bool $reportedUnknownMode = false;

    /** @var array<class-string, true> */
    private static array $reportedFallbacks = [];

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
        $handle = self::handle($model);
        $label = method_exists($model, 'bumpLabel') ? $model->bumpLabel() : null;

        if ($label === null || $label === '') {
            return $handle;
        }

        return ['handle' => $handle, 'label' => (string) $label];
    }

    /**
     * The API rejects an over-long handle anyway; throwing here drops the event before it is sent.
     */
    public static function handle(Model $model): string
    {
        $handle = method_exists($model, 'bumpHandle')
            ? (string) $model->bumpHandle()
            : self::prefixedId($model) ?? self::keyHandle($model);

        if (mb_strlen($handle) > self::MAX_HANDLE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The Bump handle for %s is %d characters, Bump takes at most %d.',
                $model::class,
                mb_strlen($handle),
                self::MAX_HANDLE_LENGTH,
            ));
        }

        return $handle;
    }

    /**
     * Reads raw attributes, never the accessor, so a model without spatie/laravel-prefixed-ids never errors,
     * not even under Model::preventAccessingMissingAttributes().
     */
    private static function prefixedId(Model $model): ?string
    {
        if (self::mode() !== 'prefixed_id') {
            return null;
        }

        $attribute = config('prefixed-ids.prefixed_id_attribute_name');
        $attribute = is_string($attribute) && $attribute !== '' ? $attribute : 'prefixed_id';
        $attributes = $model->getAttributes();
        $usesTrait = in_array(self::SPATIE_TRAIT, class_uses_recursive($model), true);

        // A model loaded with select('id') would otherwise fall back to user_12 and split its totals from
        // the same model sent elsewhere as usr_…. Without scopes, so soft deletes and tenancy don't hide it.
        // toBase() returns the raw column: Eloquent's value() would go through the model's accessors.
        if ($usesTrait && $model->exists && ! array_key_exists($attribute, $attributes)) {
            $attributes[$attribute] = $model->newQueryWithoutScopes()->whereKey($model->getKey())->toBase()->value($attribute);

            // Kept on the model so passing it again doesn't query, and synced so save() doesn't write it back.
            $model->setRawAttributes($attributes);
            $model->syncOriginalAttribute($attribute);
        }

        $value = $attributes[$attribute] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if ($usesTrait && array_key_exists($attribute, $attributes) && ! isset(self::$reportedFallbacks[$model::class])) {
            self::$reportedFallbacks[$model::class] = true;

            Log::warning(sprintf(
                'Bump fell back to %s style handles for %s: some rows have no %s.',
                self::keyHandle($model),
                $model::class,
                $attribute,
            ));
        }

        return null;
    }

    private static function keyHandle(Model $model): string
    {
        $key = $model->getKey();

        if ($key === null || $key === '') {
            throw new InvalidArgumentException('An unsaved '.$model::class.' has no key to build a Bump handle from.');
        }

        $alias = Relation::getMorphAlias($model::class);
        $type = $alias === $model::class ? Str::snake(class_basename($model)) : $alias;

        return $type.'_'.$key;
    }

    private static function mode(): string
    {
        $mode = config('bump.handles');

        if ($mode === 'prefixed_id' || $mode === 'key') {
            return $mode;
        }

        if (! self::$reportedUnknownMode) {
            self::$reportedUnknownMode = true;

            Log::warning(sprintf('Bump doesn\'t know BUMP_HANDLES=%s and uses "key". Set it to "prefixed_id" or "key".', is_scalar($mode) ? $mode : get_debug_type($mode)));
        }

        return 'key';
    }

    /**
     * The warnings are once per process; tests need them back.
     *
     * @internal
     */
    public static function forgetReportedWarnings(): void
    {
        self::$reportedUnknownMode = false;
        self::$reportedFallbacks = [];
    }
}
