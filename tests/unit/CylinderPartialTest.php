<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class CylinderPartialTest extends CIUnitTestCase
{
    public function testRendersWellFormedSvg(): void
    {
        $svg = view('partials/cylinder_svg', ['fill' => 50]);

        $this->assertStringContainsString('<svg viewBox="0 0 120 290"', $svg);
        $this->assertStringContainsString('PERFECT', $svg);
        $this->assertStringContainsString('</svg>', $svg);

        $doc = new DOMDocument();
        $this->assertTrue(@$doc->loadXML($svg), 'Partial must be valid XML so browsers render it.');
    }

    public function testEveryInstanceUsesItsOwnGradientIds(): void
    {
        $ids = [];
        foreach ([78, 92, 35] as $fill) {
            preg_match_all('/id="(cyl[0-9a-f]+[a-z])"/', view('partials/cylinder_svg', ['fill' => $fill]), $m);
            $ids = array_merge($ids, $m[1]);
        }

        $this->assertCount(12, $ids, 'Three cylinders with four ids each.');
        $this->assertSame($ids, array_values(array_unique($ids)), 'Duplicate ids make SVG gradients break when cylinders share a page.');
    }

    public function testFuelLevelFollowsFillPercentage(): void
    {
        $levelOf = static function (float $fill): float {
            preg_match('/class="lv" x="0" y="([0-9.]+)"/', view('partials/cylinder_svg', ['fill' => $fill]), $m);

            return (float) $m[1];
        };

        $this->assertGreaterThan($levelOf(90), $levelOf(20), 'Lower fill means the gas line sits lower (larger y).');
        $this->assertSame(96.0, $levelOf(100));
        $this->assertSame(262.0, $levelOf(0));
    }

    public function testFillIsClampedToValidRange(): void
    {
        $level = static function (float $fill): string {
            preg_match('/class="lv" x="0" y="([0-9.]+)"/', view('partials/cylinder_svg', ['fill' => $fill]), $m);

            return $m[1];
        };

        $this->assertSame($level(100), $level(250));
        $this->assertSame($level(0), $level(-40));
    }
}
