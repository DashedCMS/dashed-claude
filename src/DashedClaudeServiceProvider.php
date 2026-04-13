<?php

namespace Dashed\DashedClaude;

use Dashed\DashedAi\AiManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DashedClaudeServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-claude';

    public function configurePackage(Package $package): void
    {
        $package->name(self::$name);
    }

    public function bootingPackage(): void
    {
        app(AiManager::class)->register(new ClaudeProvider());
    }
}
