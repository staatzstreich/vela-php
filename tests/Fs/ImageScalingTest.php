<?php

declare(strict_types=1);

namespace Vela\Tests\Fs;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vela\Fs\ImageScaling;

final class ImageScalingTest extends TestCase
{
    #[Test]
    public function anImageAlreadyWithinBoundsIsReturnedUnchanged(): void
    {
        self::assertSame(['width' => 100, 'height' => 50], ImageScaling::fit(100, 50, 160, 100));
    }

    #[Test]
    public function anImageExactlyAtTheBoundsIsReturnedUnchanged(): void
    {
        self::assertSame(['width' => 160, 'height' => 100], ImageScaling::fit(160, 100, 160, 100));
    }

    #[Test]
    public function aWideLandscapeImageIsScaledDownByItsWidth(): void
    {
        // 4000x2000 is width-constrained against 160x100 (0.04 vs 0.05 ratio)
        self::assertSame(['width' => 160, 'height' => 80], ImageScaling::fit(4000, 2000, 160, 100));
    }

    #[Test]
    public function aTallPortraitImageIsScaledDownByItsHeight(): void
    {
        // 2000x4000 is height-constrained against 160x100 (0.08 vs 0.025 ratio)
        self::assertSame(['width' => 50, 'height' => 100], ImageScaling::fit(2000, 4000, 160, 100));
    }

    #[Test]
    public function aSquareImageStaysSquareWhenScaledDown(): void
    {
        self::assertSame(['width' => 100, 'height' => 100], ImageScaling::fit(1000, 1000, 100, 100));
    }

    #[Test]
    public function neverUpscalesASmallImage(): void
    {
        self::assertSame(['width' => 10, 'height' => 5], ImageScaling::fit(10, 5, 160, 100));
    }

    #[Test]
    public function aZeroWidthSourceDoesNotDivideByZero(): void
    {
        self::assertSame(['width' => 1, 'height' => 50], ImageScaling::fit(0, 50, 160, 100));
    }

    #[Test]
    public function aZeroHeightSourceDoesNotDivideByZero(): void
    {
        self::assertSame(['width' => 50, 'height' => 1], ImageScaling::fit(50, 0, 160, 100));
    }
}
