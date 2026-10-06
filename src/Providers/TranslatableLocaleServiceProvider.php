<?php

declare(strict_types=1);

namespace Nvl\Translatable\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Locales\ApplicationLocaleCatalog;
use Nvl\Translatable\Services\LocaleRegistry;
use Nvl\Translatable\Services\LocaleRegistryCatalog;

/** Selects Translatable's catalog while preserving explicit host locale implementations. */
final class TranslatableLocaleServiceProvider extends ServiceProvider
{
    /** Register the configured catalog independently of provider discovery order. */
    public function register(): void
    {
        $this->app->singleton(LocaleRegistry::class);
        $this->app->extend(LocaleCatalog::class, static function (LocaleCatalog $catalog, Application $app): LocaleCatalog {
            return $catalog instanceof ApplicationLocaleCatalog
                ? $app->make(LocaleRegistryCatalog::class)
                : $catalog;
        });
    }
}
