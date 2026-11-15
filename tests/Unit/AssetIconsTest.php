<?php

namespace Tests\Unit;

use App\Support\AssetIcons;
use PHPUnit\Framework\TestCase;

class AssetIconsTest extends TestCase
{
    public function test_icon_by_name(): void
    {
        $this->assertSame('chair', AssetIcons::guess('Стул для пациента'));
        $this->assertSame('bed', AssetIcons::guess('Кресло-кровать для диализа'));
        $this->assertSame('heart', AssetIcons::guess('Прикроватный монитор'));
        $this->assertSame('table', AssetIcons::guess('Стол прикроватный'));
        $this->assertSame('table', AssetIcons::guess('Cтол с тумбой 1200х700'), 'латинская C');
        $this->assertSame('fridge', AssetIcons::guess('Холодильник ХФ 400-2 POZIS'));
        $this->assertSame('cabinet', AssetIcons::guess('Шкафы и стеллажи'));
        $this->assertNull(AssetIcons::guess('Прочее медицинское оборудование'));
    }
}
