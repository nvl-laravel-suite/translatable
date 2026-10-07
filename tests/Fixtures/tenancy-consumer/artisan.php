<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translatable\Tests\Fixtures\TenancyConsumerServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;

$vendor = getenv('NVL_TEST_VENDOR_DIR');
if (! is_string($vendor) || $vendor === '' || ! is_file($vendor.'/autoload.php')) {
    throw new RuntimeException('The worker fixture requires the isolated consumer vendor directory.');
}
require $vendor.'/autoload.php';
putenv('COMPOSER_VENDOR_DIR='.$vendor);

$app = Application::configure(basePath: __DIR__)
    ->withProviders([
        LocaleServiceProvider::class, SupportServiceProvider::class,
        DataServiceProvider::class,
        TenancyServiceProvider::class,
        TranslatableServiceProvider::class,
        TenancyConsumerServiceProvider::class,
    ])
    ->withExceptions()
    ->withMiddleware()
    ->create();

exit($app->handleCommand(new ArgvInput));
