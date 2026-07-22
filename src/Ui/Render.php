<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\CompositeWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use Vela\ActivePanel;
use Vela\App;
use Vela\Theme\Theme;

/**
 * Top-level frame composition. Mirrors vela's src/ui/mod.rs render(): main
 * frame, then each open dialog layered on top in the same fixed order Rust
 * uses (which doubles as z-order — see App::handleKey()'s priority chain
 * for why more than one can be open at once, e.g. password + host-key),
 * with the help overlay always on top of everything.
 */
final class Render
{
    public static function build(App $app, Area $viewport): Widget
    {
        $theme = $app->themeChoice->resolve();
        $frame = self::buildFrame($app, $viewport, $theme);

        $layers = [$frame];
        if ($app->profileDialog !== null) {
            $layers[] = ProfileDialogRenderer::build($app->profileDialog, $theme);
        }
        if ($app->passwordDialog !== null) {
            $layers[] = PasswordDialogRenderer::build($app->passwordDialog, $theme);
        }
        if ($app->renameDialog !== null) {
            $layers[] = RenameDialogRenderer::build($app->renameDialog, $theme);
        }
        if ($app->mkdirDialog !== null) {
            $layers[] = MkdirDialogRenderer::build($app->mkdirDialog, $theme);
        }
        if ($app->deleteDialog !== null) {
            $layers[] = DeleteDialogRenderer::build($app->deleteDialog, $theme);
        }
        if ($app->shellDialog !== null) {
            $layers[] = ShellDialogRenderer::build($app->shellDialog, $app->left->path, $theme);
        }
        if ($app->permissionDialog !== null) {
            $layers[] = PermissionDialogRenderer::build($app->permissionDialog, $theme);
        }
        if ($app->hostKeyDialog !== null) {
            $layers[] = HostKeyDialogRenderer::build($app->hostKeyDialog, $theme);
        }
        if ($app->helpVisible) {
            $layers[] = HelpDialogRenderer::build($theme);
        }

        return count($layers) === 1 ? $frame : CompositeWidget::fromWidgets(...$layers);
    }

    private static function buildFrame(App $app, Area $viewport, Theme $theme): Widget
    {
        // Split eagerly (in addition to the GridWidget below, which does the
        // same solve again at render time) purely so panel content sizing
        // (name truncation/padding) can use the real column widths — mirrors
        // vela's own Layout::split() + Block::inner() combo in ui/mod.rs +
        // ui/panels.rs.
        $rows = Layout::default()
            ->direction(Direction::Vertical)
            ->constraints([Constraint::min(0), Constraint::length(2)])
            ->split($viewport);

        $cols = Layout::default()
            ->direction(Direction::Horizontal)
            ->constraints([Constraint::percentage(50), Constraint::percentage(50)])
            ->split($rows->get(0));

        $connected = $app->isConnected();
        $rightLabel = $connected && $app->sftp !== null
            ? "Remote [{$app->sftp->user}@{$app->sftp->host}]"
            : 'Remote [nicht verbunden]';

        // Purely visual — which physical side shows local vs. remote. The
        // data model (app->left is always local) never changes.
        [$localArea, $remoteArea] = $app->panelsSwapped ? [$cols->get(1), $cols->get(0)] : [$cols->get(0), $cols->get(1)];

        $localBlock = PanelRenderer::renderPanel($localArea, $app->left, $app->active === ActivePanel::Left, 'Local', $theme);
        $remoteBlock = PanelRenderer::renderPanel($remoteArea, $app->right, $app->active === ActivePanel::Right, $rightLabel, $theme, $connected);

        [$physicalLeft, $physicalRight] = $app->panelsSwapped ? [$remoteBlock, $localBlock] : [$localBlock, $remoteBlock];

        $panelsGrid = GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(Constraint::percentage(50), Constraint::percentage(50))
            ->widgets($physicalLeft, $physicalRight);

        $statusArea = $app->activeTransfer !== null
            ? TransferBarRenderer::build($rows->get(1), $app->activeTransfer, $app->activeTransferVerb, $theme)
            : self::buildHintArea($rows->get(1), $app, $theme);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(0), Constraint::length(2))
            ->widgets($panelsGrid, $statusArea);
    }

    /**
     * Mirrors statusbar.rs render_hint_bar(): row 0 is the function-key
     * badge row, row 1 the status message. Deviates from vela's Rust
     * original (which always shows F5/F6) by hiding Upload/Download too
     * while disconnected — same reasoning as the already-conditional F3
     * Disconnect: showing a hint for an action that's a silent no-op right
     * now (the right panel has nothing to transfer to/from until connected)
     * is worse than not showing it.
     */
    private static function buildHintArea(Area $area, App $app, Theme $theme): Widget
    {
        $connected = $app->isConnected();

        $hints = [
            ['F1', 'Help', false],
            ['F2', 'Rename', false],
            ['F4', 'Edit', false],
        ];
        if ($connected) {
            $hints[] = ['F5', 'Upload', false];
            $hints[] = ['F6', 'Download', false];
        }
        $hints[] = ['F7', 'MkDir', false];
        $hints[] = ['F8', 'Delete', false];
        $hints[] = ['F9', 'Profile', false];
        $hints[] = ['!', 'Shell', false];
        $hints[] = ['^U', 'Swap', false];
        if ($connected) {
            $hints[] = ['F3', 'Disconnect', true];
        }
        $hints[] = ['F10', 'Quit', false];

        $spans = [];
        foreach ($hints as [$key, $label, $danger]) {
            $spans[] = new Span(
                " {$key} ",
                Style::default()
                    ->bg($danger ? $theme->hintBadgeDangerBg : $theme->hintBadgeBg)
                    ->fg($theme->hintBadgeFg)
                    ->addModifier(Modifier::BOLD),
            );
            $spans[] = new Span("{$label} ", Style::default()->fg($theme->hintLabel));
        }

        $hint = ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(...$spans)))
            ->style(Style::default()->bg($theme->hintBarBg));
        $status = ParagraphWidget::fromText(Text::fromString(
            $app->statusMessage !== null ? ' ' . $app->statusMessage : ''
        ))->style(Style::default()->fg($theme->statusMessage)->bg($theme->hintBarBg));

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::length(1))
            ->widgets($hint, $status);
    }
}
