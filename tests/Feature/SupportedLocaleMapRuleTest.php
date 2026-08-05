<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;

beforeEach(function (): void {
    config(['translatable.locales' => ['en', 'bg', 'en-US']]);
});

test('supported locale map rule accepts configured locale keys', function (): void {
    $validator = Validator::make([
        'translations' => [
            'en' => ['title' => 'Hello'],
            'bg' => ['title' => 'Здравейте'],
            'en_US' => ['title' => 'Hello'],
        ],
    ], [
        'translations' => [new SupportedLocaleMapRule],
    ]);

    expect($validator->passes())->toBeTrue();
});

test('supported locale map rule rejects unsupported and malformed locale keys', function (): void {
    $unsupported = Validator::make([
        'translations' => ['fr' => ['title' => 'Bonjour']],
    ], [
        'translations' => [new SupportedLocaleMapRule],
    ]);
    $malformed = Validator::make([
        'translations' => ['' => ['title' => 'Missing locale']],
    ], [
        'translations' => [new SupportedLocaleMapRule],
    ]);

    expect($unsupported->fails())->toBeTrue()
        ->and($malformed->fails())->toBeTrue();
});

test('supported locale map rule rejects empty locale segments', function (string $locale): void {
    $validator = Validator::make([
        'translations' => [$locale => ['title' => 'Malformed']],
    ], [
        'translations' => [new SupportedLocaleMapRule],
    ]);

    expect($validator->fails())->toBeTrue();
})->with(['-en', 'en-', 'en--US', 'en__US']);

test('supported locale map rule rejects non map values without another array rule', function (): void {
    $validator = Validator::make([
        'translations' => 'en',
    ], [
        'translations' => [new SupportedLocaleMapRule],
    ]);

    expect($validator->fails())->toBeTrue();
});

test('supported locale map rule rejects keys that normalize to the same locale', function (): void {
    $validator = Validator::make([
        'translations' => [
            'en-US' => ['title' => 'First'],
            'en_US' => ['title' => 'Second'],
        ],
    ], [
        'translations' => [new SupportedLocaleMapRule],
    ]);

    expect($validator->fails())->toBeTrue();
});

test('supported locale map rule fails explicitly for an invalid locale catalog', function (): void {
    config(['translatable.locales' => ['en', 42]]);

    expect(fn () => Validator::make([
        'translations' => ['en' => ['title' => 'Hello']],
    ], [
        'translations' => [new SupportedLocaleMapRule],
    ])->passes())->toThrow(
        TranslatableException::class,
        'locale must be a string',
    );
});
