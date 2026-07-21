<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Extension\Core\Widget\CompositeWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use Vela\ActivePanel;
use Vela\App;

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
        $frame = self::buildFrame($app, $viewport);

        $layers = [$frame];
        if ($app->profileDialog !== null) {
            $layers[] = ProfileDialogRenderer::build($app->profileDialog);
        }
        if ($app->passwordDialog !== null) {
            $layers[] = PasswordDialogRenderer::build($app->passwordDialog);
        }
        if ($app->renameDialog !== null) {
            $layers[] = RenameDialogRenderer::build($app->renameDialog);
        }
        if ($app->mkdirDialog !== null) {
            $layers[] = MkdirDialogRenderer::build($app->mkdirDialog);
        }
        if ($app->deleteDialog !== null) {
            $layers[] = DeleteDialogRenderer::build($app->deleteDialog);
        }
        if ($app->shellDialog !== null) {
            $layers[] = ShellDialogRenderer::build($app->shellDialog, $app->left->path);
        }
        if ($app->permissionDialog !== null) {
            $layers[] = PermissionDialogRenderer::build($app->permissionDialog);
        }
        if ($app->hostKeyDialog !== null) {
            $layers[] = HostKeyDialogRenderer::build($app->hostKeyDialog);
        }
        if ($app->helpVisible) {
            $layers[] = HelpDialogRenderer::build();
        }

        return count($layers) === 1 ? $frame : CompositeWidget::fromWidgets(...$layers);
    }

    private static function buildFrame(App $app, Area $viewport): Widget
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

        $leftBlock = PanelRenderer::renderPanel($cols->get(0), $app->left, $app->active === ActivePanel::Left, 'Local');
        $rightBlock = PanelRenderer::renderPanel($cols->get(1), $app->right, $app->active === ActivePanel::Right, $rightLabel, $connected);

        $panelsGrid = GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(Constraint::percentage(50), Constraint::percentage(50))
            ->widgets($leftBlock, $rightBlock);

        $statusArea = $app->activeTransfer !== null
            ? TransferBarRenderer::build(
                $rows->get(1),
                $app->activeTransfer,
                $app->activeTransferVerb,
                $app->activeTransferVerb === 'Download' ? AnsiColor::Magenta : AnsiColor::Green,
            )
            : self::buildHintArea($rows->get(1), $app);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(0), Constraint::length(2))
            ->widgets($panelsGrid, $statusArea);
    }

    private static function buildHintArea(Area $area, App $app): Widget
    {
        $rows = Layout::default()
            ->direction(Direction::Vertical)
            ->constraints([Constraint::length(1), Constraint::length(1)])
            ->split($area);

        $hint = ParagraphWidget::fromText(Text::fromString(self::hintLine($app)))
            ->style(Style::default()->fg(AnsiColor::DarkGray));
        $status = ParagraphWidget::fromText(Text::fromString(
            $app->statusMessage !== null ? ' ' . $app->statusMessage : ''
        ))->style(Style::default()->fg(AnsiColor::Yellow));

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::length(1), Constraint::length(1))
            ->widgets($hint, $status);
    }

    private static function hintLine(App $app): string
    {
        $connected = $app->isConnected();
        $upload = $connected ? ' | F5 hochladen' : '';
        $download = $connected ? ' | F6 herunterladen' : '';

        return ' Tab wechseln | ↑↓ bewegen | Enter öffnen | Backspace hoch | Leertaste markieren | * alle markieren'
            . $upload . $download . ' | q beenden';
    }
}
