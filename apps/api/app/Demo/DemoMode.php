<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * Local demonstration mode. It bypasses real authentication, so it is only ever active when the
 * flag is set AND the application runs in a non-production environment (local or testing).
 * Any other environment answers 404 even with the flag set.
 */
final class DemoMode
{
    public const MASTER_EMAIL = 'mestre@demo.novtt.invalid';
    public const PLAYER_EMAIL = 'jogador@demo.novtt.invalid';
    public const CAMPAIGN_NAME = 'Campanha de demonstração';
    public const CHARACTER_NAME = 'Aria';
    public const PRESET_DIRECTORY = 'presets/fixtures/demo-basic/1.0.0';

    public static function enabled(): bool
    {
        return config('novtt.demo_mode') === true && app()->environment(['local', 'testing']);
    }
}
