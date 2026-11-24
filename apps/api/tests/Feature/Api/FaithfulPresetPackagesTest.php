<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\PresetImporter;
use App\Http\Problems\ApiProblem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/**
 * SPEC-PRESETS-FIDELITY-001: third-party presets validate against the contracts and can be used
 * privately, while the public catalogue stays closed to packages whose rights are not approved.
 */
final class FaithfulPresetPackagesTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    /** @return iterable<string, array{0: string}> */
    public static function blockedPackages(): iterable
    {
        yield 'Ordem Paranormal' => ['ordem-paranormal-rpg/livro-basico/0.1.1'];
        yield 'Vampire V20' => ['world-of-darkness/vampire-v20/0.1.0'];
        yield 'Vampire Dark Ages V20' => ['world-of-darkness/vampire-dark-ages-v20/0.1.0'];
    }

    #[DataProvider('blockedPackages')]
    public function test_a_rights_review_package_validates_imports_privately_and_is_refused_publicly(string $directory): void
    {
        [$raw, $missing] = PresetImporter::readDirectory(__DIR__ . '/../../../../../presets/' . $directory);
        self::assertNotNull($raw, "missing {$missing}");
        $importer = app(PresetImporter::class);
        [$package, $objects] = $importer->parse((string) $raw);
        self::assertSame([], $importer->validate($package)->toArray()['issues'], 'contracts and engine accept the package');

        $master = $this->makeUser('Mestre');
        [$version] = $importer->import($master, $package, $objects);
        self::assertSame($master->getKey(), $version->definition->owner_user_id, 'available in the master\'s own library');
        self::assertSame('rights_review', $version->rights_status);

        try {
            $importer->import($this->makeUser('Equipe'), $package, $objects, canonical: true);
            self::fail('the public catalogue accepted a package under rights review');
        } catch (ApiProblem $problem) {
            self::assertSame(422, $problem->status);
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function publishablePackages(): iterable
    {
        yield 'D&D 5e SRD 5.1 (2014)' => ['dnd-5e/srd-5.1/0.1.0'];
        yield 'D&D 5e SRD 5.2.1 (2024)' => ['dnd-5e/srd-5.2.1/0.1.0'];
    }

    #[DataProvider('publishablePackages')]
    public function test_an_srd_package_validates_and_enters_the_public_catalogue_with_its_attribution(string $directory): void
    {
        [$raw, $missing] = PresetImporter::readDirectory(__DIR__ . '/../../../../../presets/' . $directory);
        self::assertNotNull($raw, "missing {$missing}");
        $importer = app(PresetImporter::class);
        [$package, $objects] = $importer->parse((string) $raw);
        self::assertSame([], $importer->validate($package)->toArray()['issues']);
        [$version] = $importer->import($this->makeUser('Equipe'), $package, $objects, canonical: true);
        self::assertNull($version->definition->owner_user_id, 'public catalogue');
        self::assertSame('approved', $version->rights_status);
        self::assertStringContainsString('Creative Commons', (string) $version->manifest['license']['attribution']);
    }
}
