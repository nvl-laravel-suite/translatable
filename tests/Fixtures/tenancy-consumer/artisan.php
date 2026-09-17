<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Nvl\Translatable\Tests\Fixtures\TenancyConsumerServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;

$root = getenv('NVL_TEST_SUITE_ROOT');
$root = is_string($root) && $root !== '' ? $root : dirname(__DIR__, 6);
require $root.'/vendor/autoload.php';
putenv('COMPOSER_VENDOR_DIR='.$root.'/vendor');

$app = Application::configure(basePath: __DIR__)
    ->withProviders([
        SupportServiceProvider::class,
        DataServiceProvider::class,
        TenancyServiceProvider::class,
        TranslatableServiceProvider::class,
        TenancyConsumerServiceProvider::class,
    ])
    ->withExceptions()
    ->withMiddleware()
    ->create();

exit($app->handleCommand(new ArgvInput));
