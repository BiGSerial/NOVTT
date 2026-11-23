<?php

declare(strict_types=1);

return [
    // Versioned JSON contracts shipped with the repository (contracts/).
    'contracts_path' => env('NOVTT_CONTRACTS_PATH', base_path('../../contracts')),

    // Engine version recorded on imported preset versions.
    'engine_version' => '0.1.0',

    // Import limits (SPEC-PRESETS-001: bounded size and complexity).
    'preset_import_max_bytes' => 512 * 1024,
];
