<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Tests\Support\TestTranslatableModel;

beforeEach(function (): void {
    $this->originalOwnerMorphMap = Relation::morphMap();
});

afterEach(function (): void {
    Relation::morphMap($this->originalOwnerMorphMap, false);
});

test('translation resource keys reference shared owners while keeping authorization metadata', function (): void {
    config()->set('nvl-translatable.locales', ['en', 'bg', 'en-GB']);
    config()->set('nvl-core.owners', ['article' => TestTranslatableModel::class]);
    $registry = app(TranslationResourceRegistry::class);
    $resource = $registry->register(
        key: 'articles.editor',
        modelClass: 'article',
        label: 'Article editor',
        displayColumns: ['slug'],
        authorization: static fn (): bool => false,
    );

    expect($resource->newModel())->toBeInstanceOf(TestTranslatableModel::class)
        ->and($registry->has('article'))->toBeFalse()
        ->and($resource->displayColumns)->toBe(['slug'])
        ->and($resource->authorize(TranslationActorData::system('test'), TranslationResourceAbility::View, null))->toBeFalse();
});
