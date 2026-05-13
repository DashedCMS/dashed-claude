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
        if (method_exists(cms(), 'registerIntegration')) {
            cms()->registerIntegration([
                'slug' => 'anthropic',
                'label' => 'Anthropic Claude',
                'icon' => 'heroicon-o-sparkles',
                'category' => 'ai',
                'settings_page' => \Dashed\DashedAi\Filament\Pages\Settings\AiSettingsPage::class,
                'health_check' => fn (?string $siteId = null) => \Dashed\DashedCore\Integrations\IntegrationHealth::fromSettings(['claude_api_key'], $siteId, 'API key ontbreekt'),
                'package' => 'dashed-claude',
            ]);
        }

        app(AiManager::class)->register(new ClaudeProvider());
    }
}
