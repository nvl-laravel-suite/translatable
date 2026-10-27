<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translatable\Contracts\TranslationResourceAuthorizer;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Services\TranslationResourceAuthorization;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;
use Nvl\Translatable\TranslationResourceDefinition;

beforeEach(function (): void {
    config(['nvl-translatable.locales' => ['en', 'bg', 'en-GB']]);
    Schema::create('resource_translation_actor_test_users', static function (Blueprint $table): void {
        $table->id();
        $table->boolean('can_translate')->default(false);
    });
    Schema::create('test_translatable_models_i18n', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('test_translatable_model_id');
        $table->string('locale');
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->timestamps();
    });
});

it('passes canonical persisted actor facts to resource and host translation policies', function (bool $definitionPolicy): void {
    $actor = ResourceTranslationActorTestUser::query()->create(['can_translate' => false]);
    $actor->forceFill(['can_translate' => true]);
    $decision = static fn (Authenticatable|SystemActor $actor): bool => $actor instanceof Model && (bool) $actor->getAttribute('can_translate');
    app()->instance(TranslationResourceAuthorizer::class, new class implements TranslationResourceAuthorizer
    {
        public function allows(Authenticatable|SystemActor $actor, TranslationResourceAbility $ability, TranslationResourceDefinition $resource, ?Model $record = null): bool
        {
            return $actor instanceof Model && (bool) $actor->getAttribute('can_translate');
        }
    });
    $resource = new TranslationResourceDefinition('tests.actor-freshness', 'Actor test', TestTranslatableModel::class, authorization: $definitionPolicy ? $decision : null);
    expect(fn () => app(TranslationResourceAuthorization::class)->authorize($actor, TranslationResourceAbility::View, $resource))->toThrow(TranslationResourceException::class);
    $actor->newQuery()->whereKey($actor->getKey())->update(['can_translate' => true]);
    app(TranslationResourceAuthorization::class)->authorize($actor, TranslationResourceAbility::View, $resource);
    $actor->newQuery()->whereKey($actor->getKey())->delete();
    expect(fn () => app(TranslationResourceAuthorization::class)->authorize($actor, TranslationResourceAbility::View, $resource))->toThrow(TranslationResourceException::class);
})->with(['resource closure' => true, 'host port' => false]);

it('retains valid non Eloquent resource actor contracts without claiming external persistence', function (): void {
    $actor = new GenericUser(['id' => 'directory-user']);
    $seen = null;
    $resource = new TranslationResourceDefinition('tests.external-actor', 'Actor test', TestTranslatableModel::class, authorization: static function (Authenticatable|SystemActor $candidate) use (&$seen): bool {
        $seen = $candidate;

        return $candidate instanceof GenericUser && $candidate->getAuthIdentifier() === 'directory-user';
    });
    app(TranslationResourceAuthorization::class)->authorize($actor, TranslationResourceAbility::View, $resource);
    expect($seen)->toBe($actor);
});

/** Persisted actor from the owning application's identity schema. */
final class ResourceTranslationActorTestUser extends User
{
    protected $table = 'resource_translation_actor_test_users';

    protected $guarded = [];

    public $timestamps = false;
}
