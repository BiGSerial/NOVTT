<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use Novtt\Engine\Presets\SheetLayout;
use Novtt\Engine\Presets\SheetProjector;
use Novtt\Engine\Sheets\JsonPointer;
use PHPUnit\Framework\TestCase;

final class SheetProjectorTest extends TestCase
{
    private function projector(): SheetProjector
    {
        $ui = json_decode((string) file_get_contents(__DIR__ . '/../../../../presets/fixtures/demo-basic/1.0.0/sheet.ui.json'), true, 64, JSON_THROW_ON_ERROR);

        return new SheetProjector(SheetLayout::fromArray($ui));
    }

    /** @return array<string, mixed> */
    private static function sheet(): array
    {
        return [
            'identity' => ['name' => 'Ana', 'concept' => 'Batedora'],
            'attributes' => ['might' => 3, 'agility' => 2],
            'merits' => [['id' => 'm1', 'name' => 'Ágil', 'cost' => 2, 'smuggled' => 'x']],
            'secrets' => ['hook' => 'Deve dinheiro ao conde'],
            'master' => ['notes' => 'Vai trair o grupo'],
            'undeclared' => ['anything' => 'leak?'],
            '_ledger' => [['budget' => 'xp']],
        ];
    }

    public function test_players_never_receive_hidden_master_only_or_undeclared_data(): void
    {
        $projected = $this->projector()->forPlayer(self::sheet());

        $this->assertSame([
            'identity' => ['name' => 'Ana', 'concept' => 'Batedora'],
            'attributes' => ['might' => 3, 'agility' => 2],
            'merits' => [['id' => 'm1', 'name' => 'Ágil', 'cost' => 2]],
        ], $projected);
        $json = json_encode($projected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['conde', 'trair', 'leak', 'smuggled', '_ledger'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_masters_read_declared_fields_but_undeclared_data_is_sanitized(): void
    {
        $projected = $this->projector()->forMaster(self::sheet());

        $this->assertSame('Deve dinheiro ao conde', $projected['secrets']['hook']);
        $this->assertSame('Vai trair o grupo', $projected['master']['notes']);
        $this->assertArrayNotHasKey('undeclared', $projected);
        $this->assertArrayNotHasKey('_ledger', $projected);
    }

    public function test_path_readability_for_players(): void
    {
        $p = $this->projector();
        $this->assertTrue($p->playerCanRead(JsonPointer::parse('/attributes/might')));
        $this->assertFalse($p->playerCanRead(JsonPointer::parse('/secrets/hook')));
        $this->assertFalse($p->playerCanRead(JsonPointer::parse('/master/notes')));
        $this->assertTrue($p->playerCanRead(JsonPointer::parse('/merits')));
        $this->assertTrue($p->playerCanRead(JsonPointer::parse('/merits/m1/cost')));
        $this->assertFalse($p->playerCanRead(JsonPointer::parse('/merits/m1/smuggled')));
        $this->assertFalse($p->playerCanRead(JsonPointer::parse('/undeclared/anything')));
    }
}
