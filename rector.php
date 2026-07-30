<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Foreach_\UnusedForeachValueToArrayKeysRector;
use Rector\CodeQuality\Rector\FuncCall\UnwrapSprintfOneArgumentRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;
use Rector\Php53\Rector\Ternary\TernaryToElvisRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

// Mirrors php-tui/php-tui's own rector.php (vendor/php-tui/php-tui/rector.php)
// as closely as this project's layout allows — same sets, same PHP-version
// target (this project's composer.json also requires ^8.1). Two deviations
// from php-tui's skip list, decided deliberately rather than copied blindly:
// EncapsedStringsToSprintfRector and LocallyCalledStaticMethodToNonStaticRector
// are both skipped here (the latter isn't even in php-tui's own skip list, but
// fights this codebase's established "no $this needed -> static" convention).
return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->parallel();
    $rectorConfig->importNames();
    $rectorConfig->importShortClasses();

    $rectorConfig->paths([
        __DIR__ . '/bin',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);

    $rectorConfig->skip([
        TernaryToElvisRector::class,
        ExplicitBoolCompareRector::class,
        ClosureToArrowFunctionRector::class,
        UnusedForeachValueToArrayKeysRector::class,
        FlipTypeControlToUseExclusiveTypeRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
    ]);

    $rectorConfig->rules([
        UnwrapSprintfOneArgumentRector::class,
    ]);

    $rectorConfig->sets([
        SetList::CODE_QUALITY,
        SetList::TYPE_DECLARATION,
        LevelSetList::UP_TO_PHP_81,
    ]);
};
