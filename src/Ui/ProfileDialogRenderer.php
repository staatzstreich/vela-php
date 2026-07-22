<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Modifier;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use Vela\Config\AuthMethod;
use Vela\Dialog\NewProfileForm;
use Vela\Dialog\ProfileDialog;
use Vela\Dialog\ProfileDialogMode;
use Vela\Theme\Theme;

/** Mirrors vela's src/ui/dialogs.rs render_profile_dialog() + render_profile_form() + render_confirm_delete(). */
final class ProfileDialogRenderer
{
    private const FIELD_LABELS = [
        NewProfileForm::NAME => 'Name',
        NewProfileForm::HOST => 'Host',
        NewProfileForm::PORT => 'Port',
        NewProfileForm::USER => 'User',
        NewProfileForm::KEY_PATH => 'Key-Datei',
        NewProfileForm::REMOTE_PATH => 'Remote-Pfad (optional)',
        NewProfileForm::LOCAL_PATH => 'Lokaler Startpfad (optional)',
        NewProfileForm::PASSWORD => 'Passwort',
    ];

    public static function build(ProfileDialog $dlg, Theme $theme): Widget
    {
        return match ($dlg->mode) {
            ProfileDialogMode::ListMode => self::buildList($dlg, $theme),
            ProfileDialogMode::New => self::buildForm($dlg, ' Neues Profil ', $theme),
            ProfileDialogMode::Edit => self::buildForm($dlg, ' Profil bearbeiten ', $theme),
            ProfileDialogMode::ConfirmDelete => self::buildConfirmDelete($dlg, $theme),
        };
    }

    private static function buildList(ProfileDialog $dlg, Theme $theme): Widget
    {
        $profiles = $dlg->store->profiles;

        if ($profiles === []) {
            $body = GridWidget::default()
                ->direction(Direction::Vertical)
                ->constraints(Constraint::min(0), Constraint::length(1))
                ->widgets(
                    ParagraphWidget::fromText(Text::fromString('Keine Profile vorhanden. N = Neu anlegen'))
                        ->style(Style::default()->fg($theme->textMuted)),
                    ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints(['N' => 'Neu', 'Esc' => 'Schließen'], $theme))),
                );
        } else {
            $items = [];
            foreach ($profiles as $i => $p) {
                $marker = $dlg->activeProfile === $i ? '● ' : '  ';
                $items[] = ListItem::new(Text::fromLine(Line::fromSpans(
                    new Span($marker, Style::default()->fg($theme->profileActive)),
                    new Span(Format::padRight($p->name, 20), Style::default()->fg($theme->textPrimary)->addModifier(Modifier::BOLD)),
                    new Span("  {$p->user}@{$p->host}:{$p->port}", Style::default()->fg($theme->textSecondary)),
                    new Span('  [' . $p->auth->value . ']', Style::default()->fg($theme->textMuted)),
                )));
            }

            $list = ListWidget::default()
                ->items(...$items)
                ->select($dlg->listSelected)
                ->highlightStyle(Style::default()->bg($theme->highlightPrimaryBg)->fg($theme->highlightPrimaryFg)->addModifier(Modifier::BOLD))
                ->highlightSymbol(Theme::HIGHLIGHT_SYMBOL);

            $body = GridWidget::default()
                ->direction(Direction::Vertical)
                ->constraints(Constraint::min(0), Constraint::length(1))
                ->widgets(
                    $list,
                    ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints([
                        'Enter' => 'Auswählen',
                        'N' => 'Neu',
                        'E / F2' => 'Bearbeiten',
                        'D' => 'Löschen',
                        'Esc' => 'Schließen',
                    ], $theme))),
                );
        }

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Verbindungsprofile (F9) '))
            ->borderStyle(Style::default()->fg($theme->dialogActiveBorder))
            ->widget($body);

        return new CenteredBox(70, 80, $block);
    }

    private static function buildForm(ProfileDialog $dlg, string $title, Theme $theme): Widget
    {
        $form = $dlg->form;
        $visibleFields = array_filter(
            range(0, NewProfileForm::FIELD_COUNT - 1),
            $form->isFieldVisible(...),
        );

        $rowWidgets = [];
        $constraints = [];
        foreach ($visibleFields as $field) {
            $constraints[] = Constraint::length(3);
            $rowWidgets[] = match ($field) {
                NewProfileForm::AUTH => self::buildAuthToggleRow($form, $dlg->field === $field, $theme),
                NewProfileForm::SAVE_PASSWORD => self::buildSavePasswordToggleRow($form, $dlg->field === $field, $theme),
                default => self::buildTextFieldRow($form, $field, $dlg->field === $field, $theme),
            };
        }
        $constraints[] = Constraint::min(0);
        $rowWidgets[] = ParagraphWidget::fromText(Text::fromString(''));
        $constraints[] = Constraint::length(1);
        $rowWidgets[] = ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints([
            'Tab' => 'Nächstes Feld',
            'Enter' => 'Speichern',
            'Esc' => 'Abbrechen',
        ], $theme)));

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(...$rowWidgets);

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString($title))
            ->borderStyle(Style::default()->fg($theme->dialogWarningBorder))
            ->widget($body);

        return new CenteredBox(70, 80, $block);
    }

    private static function buildTextFieldRow(NewProfileForm $form, int $field, bool $isActive, Theme $theme): Widget
    {
        $value = $form->fieldValue($field) ?? '';
        if ($field === NewProfileForm::PASSWORD) {
            $value = str_repeat('●', mb_strlen($value));
        }
        $valueStyle = $isActive
            ? Style::default()->fg($theme->textActive)->addModifier(Modifier::BOLD)
            : Style::default()->fg($theme->textInactive);

        $spans = [new Span($value, $valueStyle)];
        if ($isActive) {
            $spans[] = new Span('█', Style::default()->fg($theme->cursorBg));
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' ' . self::FIELD_LABELS[$field] . ' '))
            ->borderStyle(Style::default()->fg($isActive ? $theme->dialogActiveBorder : $theme->dialogInactiveBorder))
            ->widget(ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(...$spans))));
    }

    private static function buildAuthToggleRow(NewProfileForm $form, bool $isActive, Theme $theme): Widget
    {
        $keyStyle = $form->auth === AuthMethod::Key
            ? Style::default()->fg($theme->toggleOn)->addModifier(Modifier::BOLD)
            : Style::default()->fg($theme->toggleOff);
        $pwStyle = $form->auth === AuthMethod::Password
            ? Style::default()->fg($theme->toggleOn)->addModifier(Modifier::BOLD)
            : Style::default()->fg($theme->toggleOff);

        $spans = [
            new Span('● key   ', $keyStyle),
            new Span('● password', $pwStyle),
        ];
        if ($isActive) {
            $spans[] = new Span('  [Space]', Style::default()->fg($theme->textMuted));
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Auth '))
            ->borderStyle(Style::default()->fg($isActive ? $theme->dialogActiveBorder : $theme->dialogInactiveBorder))
            ->widget(ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(...$spans))));
    }

    private static function buildSavePasswordToggleRow(NewProfileForm $form, bool $isActive, Theme $theme): Widget
    {
        $yesStyle = $form->savePassword
            ? Style::default()->fg($theme->toggleOn)->addModifier(Modifier::BOLD)
            : Style::default()->fg($theme->toggleOff);
        $noStyle = $form->savePassword
            ? Style::default()->fg($theme->toggleOff)
            : Style::default()->fg($theme->toggleOn)->addModifier(Modifier::BOLD);

        $spans = [
            new Span('● Ja   ', $yesStyle),
            new Span('● Nein', $noStyle),
        ];
        if ($isActive) {
            $spans[] = new Span('  [Space]', Style::default()->fg($theme->textMuted));
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Passwort im Keychain speichern '))
            ->borderStyle(Style::default()->fg($isActive ? $theme->dialogActiveBorder : $theme->dialogInactiveBorder))
            ->widget(ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(...$spans))));
    }

    private static function buildConfirmDelete(ProfileDialog $dlg, Theme $theme): Widget
    {
        $name = $dlg->deleteIndex !== null && isset($dlg->store->profiles[$dlg->deleteIndex])
            ? $dlg->store->profiles[$dlg->deleteIndex]->name
            : '?';

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(0), Constraint::length(1))
            ->widgets(
                ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(
                    new Span('Profil "', Style::default()->fg($theme->textPrimary)),
                    new Span($name, Style::default()->fg($theme->textWarning)->addModifier(Modifier::BOLD)),
                    new Span('" wirklich löschen?', Style::default()->fg($theme->textPrimary)),
                ))),
                ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints(['Enter / Y' => 'Ja', 'Esc / N' => 'Nein'], $theme))),
            );

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Profil löschen? '))
            ->borderStyle(Style::default()->fg($theme->dialogErrorBorder))
            ->widget($body);

        return new CenteredBox(50, 30, $block);
    }
}
