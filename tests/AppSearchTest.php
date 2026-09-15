<?php

declare(strict_types=1);

namespace Vela\Tests;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PHPUnit\Framework\Attributes\Test;
use Vela\ActivePanel;
use Vela\App;

/**
 * Covers the vim-style '/' incremental search: App::openSearch()/searchPush()/
 * searchBackspace()/confirmSearch()/cancelSearch()/searchNext()/searchPrev(),
 * wired through App::handleKey(). Every App instance here is disconnected, so
 * the active panel is always the local left panel unless a test switches it.
 */
final class AppSearchTest extends AppTestCase
{
    #[Test]
    public function slashOpensSearchAnchoredAtTheCurrentSelection(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $app->left->selected = 1;

        $app->handleKey(CharKeyEvent::new('/'));

        self::assertNotNull($app->search);
        self::assertSame('', $app->search->query);
        self::assertSame(1, $app->search->origin);
    }

    #[Test]
    public function typingJumpsToTheNearestMatchFromTheOrigin(): void
    {
        file_put_contents("{$this->scratchLeft}/alpha.txt", 'x');
        file_put_contents("{$this->scratchLeft}/beta.txt", 'x');
        $app = $this->makeApp();
        $app->left->selected = 0;
        $betaIndex = self::mustIndexOf($app->left->entries, 'beta.txt');

        $app->handleKey(CharKeyEvent::new('/'));
        $app->handleKey(CharKeyEvent::new('b'));
        $app->handleKey(CharKeyEvent::new('e'));

        self::assertNotNull($app->search);
        self::assertSame('be', $app->search->query);
        self::assertSame($betaIndex, $app->left->selected);
    }

    #[Test]
    public function backspaceShrinksTheQueryAndRejumps(): void
    {
        file_put_contents("{$this->scratchLeft}/alpha.txt", 'x');
        file_put_contents("{$this->scratchLeft}/beta.txt", 'x');
        $app = $this->makeApp();
        $alphaIndex = self::mustIndexOf($app->left->entries, 'alpha.txt');
        $app->left->selected = $alphaIndex;

        $app->handleKey(CharKeyEvent::new('/'));
        $app->handleKey(CharKeyEvent::new('b'));
        $app->handleKey(CodedKeyEvent::new(KeyCode::Backspace));

        self::assertNotNull($app->search);
        self::assertSame('', $app->search->query);
        self::assertSame($alphaIndex, $app->left->selected);
    }

    #[Test]
    public function enterConfirmsTheSearchAndRemembersItForNAndShiftN(): void
    {
        file_put_contents("{$this->scratchLeft}/alpha.txt", 'x');
        file_put_contents("{$this->scratchLeft}/beta.txt", 'x');
        $app = $this->makeApp();

        $app->handleKey(CharKeyEvent::new('/'));
        $app->handleKey(CharKeyEvent::new('b'));
        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));

        self::assertNull($app->search);
        self::assertSame('b', $app->lastSearch);
    }

    #[Test]
    public function escCancelsAndRevertsTheSelectionToTheOrigin(): void
    {
        file_put_contents("{$this->scratchLeft}/alpha.txt", 'x');
        file_put_contents("{$this->scratchLeft}/beta.txt", 'x');
        $app = $this->makeApp();
        $app->left->selected = 0;

        $app->handleKey(CharKeyEvent::new('/'));
        $app->handleKey(CharKeyEvent::new('b'));
        $app->handleKey(CodedKeyEvent::new(KeyCode::Esc));

        self::assertNull($app->search);
        self::assertSame(0, $app->left->selected);
    }

    #[Test]
    public function nRepeatsTheLastSearchForwardWrappingAround(): void
    {
        file_put_contents("{$this->scratchLeft}/foo1.txt", 'x');
        file_put_contents("{$this->scratchLeft}/foo2.txt", 'x');
        $app = $this->makeApp();
        $foo1 = self::mustIndexOf($app->left->entries, 'foo1.txt');
        $foo2 = self::mustIndexOf($app->left->entries, 'foo2.txt');

        $app->handleKey(CharKeyEvent::new('/'));
        $app->handleKey(CharKeyEvent::new('f'));
        $app->handleKey(CharKeyEvent::new('o'));
        $app->handleKey(CharKeyEvent::new('o'));
        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));
        self::assertSame($foo1, $app->left->selected);

        $app->handleKey(CharKeyEvent::new('n'));
        self::assertSame($foo2, $app->left->selected);

        $app->handleKey(CharKeyEvent::new('n'));
        self::assertSame($foo1, $app->left->selected);
    }

    #[Test]
    public function shiftNRepeatsTheLastSearchBackward(): void
    {
        file_put_contents("{$this->scratchLeft}/foo1.txt", 'x');
        file_put_contents("{$this->scratchLeft}/foo2.txt", 'x');
        $app = $this->makeApp();
        $foo1 = self::mustIndexOf($app->left->entries, 'foo1.txt');
        $foo2 = self::mustIndexOf($app->left->entries, 'foo2.txt');

        $app->handleKey(CharKeyEvent::new('/'));
        $app->handleKey(CharKeyEvent::new('f'));
        $app->handleKey(CharKeyEvent::new('o'));
        $app->handleKey(CharKeyEvent::new('o'));
        $app->handleKey(CodedKeyEvent::new(KeyCode::Enter));
        self::assertSame($foo1, $app->left->selected);

        $app->handleKey(CharKeyEvent::new('N'));
        self::assertSame($foo2, $app->left->selected);
    }

    #[Test]
    public function nBeforeAnySearchIsANoOp(): void
    {
        file_put_contents("{$this->scratchLeft}/a.txt", 'x');
        $app = $this->makeApp();
        $before = $app->left->selected;

        $app->handleKey(CharKeyEvent::new('n'));

        self::assertNull($app->lastSearch);
        self::assertSame($before, $app->left->selected);
    }

    #[Test]
    public function searchOperatesOnWhicheverPanelIsActive(): void
    {
        file_put_contents("{$this->scratchRight}/remote-ish.txt", 'x');
        $app = $this->makeApp();
        $app->active = ActivePanel::Right;

        $app->handleKey(CharKeyEvent::new('/'));

        self::assertNotNull($app->search);
        self::assertSame($app->right->selected, $app->search->origin);
    }
}
