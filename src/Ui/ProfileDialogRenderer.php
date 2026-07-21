<?php

declare(strict_types=1);

namespace Vela\Ui;

use PhpTui\Tui\Color\AnsiColor;
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
    ];

    public static function build(ProfileDialog $dlg): Widget
    {
        return match ($dlg->mode) {
            ProfileDialogMode::ListMode => self::buildList($dlg),
            ProfileDialogMode::New => self::buildForm($dlg, ' Neues Profil '),
            ProfileDialogMode::Edit => self::buildForm($dlg, ' Profil bearbeiten '),
            ProfileDialogMode::ConfirmDelete => self::buildConfirmDelete($dlg),
        };
    }

    private static function buildList(ProfileDialog $dlg): Widget
    {
        $profiles = $dlg->store->profiles;

        if ($profiles === []) {
            $body = GridWidget::default()
                ->direction(Direction::Vertical)
                ->constraints(Constraint::min(0), Constraint::length(1))
                ->widgets(
                    ParagraphWidget::fromText(Text::fromString('Keine Profile vorhanden. N = Neu anlegen'))
                        ->style(Style::default()->fg(AnsiColor::Gray)),
                    ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints(['N' => 'Neu', 'Esc' => 'Schließen']))),
                );
        } else {
            $items = [];
            foreach ($profiles as $i => $p) {
                $marker = $dlg->activeProfile === $i ? '● ' : '  ';
                $items[] = ListItem::new(Text::fromLine(Line::fromSpans(
                    new Span($marker, Style::default()->fg(AnsiColor::Green)),
                    new Span(Format::padRight($p->name, 20), Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD)),
                    new Span("  {$p->user}@{$p->host}:{$p->port}", Style::default()->fg(AnsiColor::Gray)),
                    new Span('  [' . $p->auth->value . ']', Style::default()->fg(AnsiColor::DarkGray)),
                )));
            }

            $list = ListWidget::default()
                ->items(...$items)
                ->select($dlg->listSelected)
                ->highlightStyle(Style::default()->bg(AnsiColor::Blue)->fg(AnsiColor::White)->addModifier(Modifier::BOLD))
                ->highlightSymbol('► ');

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
                    ]))),
                );
        }

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Verbindungsprofile (F9) '))
            ->borderStyle(Style::default()->fg(AnsiColor::Cyan))
            ->widget($body);

        return new CenteredBox(70, 80, $block);
    }

    private static function buildForm(ProfileDialog $dlg, string $title): Widget
    {
        $form = $dlg->form;
        $visibleFields = array_filter(
            range(0, NewProfileForm::FIELD_COUNT - 1),
            static fn (int $f): bool => $form->isFieldVisible($f),
        );

        $rowWidgets = [];
        $constraints = [];
        foreach ($visibleFields as $field) {
            $constraints[] = Constraint::length(3);
            $rowWidgets[] = $field === NewProfileForm::AUTH
                ? self::buildAuthToggleRow($form, $dlg->field === $field)
                : self::buildTextFieldRow($form, $field, $dlg->field === $field);
        }
        $constraints[] = Constraint::min(0);
        $rowWidgets[] = ParagraphWidget::fromText(Text::fromString(''));
        $constraints[] = Constraint::length(1);
        $rowWidgets[] = ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints([
            'Tab' => 'Nächstes Feld',
            'Enter' => 'Speichern',
            'Esc' => 'Abbrechen',
        ])));

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(...$constraints)
            ->widgets(...$rowWidgets);

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString($title))
            ->borderStyle(Style::default()->fg(AnsiColor::Yellow))
            ->widget($body);

        return new CenteredBox(70, 80, $block);
    }

    private static function buildTextFieldRow(NewProfileForm $form, int $field, bool $isActive): Widget
    {
        $value = $form->fieldValue($field) ?? '';
        $valueStyle = $isActive
            ? Style::default()->fg(AnsiColor::White)->addModifier(Modifier::BOLD)
            : Style::default()->fg(AnsiColor::Gray);

        $spans = [new Span($value, $valueStyle)];
        if ($isActive) {
            $spans[] = new Span('█', Style::default()->fg(AnsiColor::Cyan));
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' ' . self::FIELD_LABELS[$field] . ' '))
            ->borderStyle(Style::default()->fg($isActive ? AnsiColor::Cyan : AnsiColor::DarkGray))
            ->widget(ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(...$spans))));
    }

    private static function buildAuthToggleRow(NewProfileForm $form, bool $isActive): Widget
    {
        $keyStyle = $form->auth === AuthMethod::Key
            ? Style::default()->fg(AnsiColor::Green)->addModifier(Modifier::BOLD)
            : Style::default()->fg(AnsiColor::Gray);
        $pwStyle = $form->auth === AuthMethod::Password
            ? Style::default()->fg(AnsiColor::Green)->addModifier(Modifier::BOLD)
            : Style::default()->fg(AnsiColor::Gray);

        $spans = [
            new Span('● key   ', $keyStyle),
            new Span('● password', $pwStyle),
        ];
        if ($isActive) {
            $spans[] = new Span('  [Space]', Style::default()->fg(AnsiColor::DarkGray));
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Auth '))
            ->borderStyle(Style::default()->fg($isActive ? AnsiColor::Cyan : AnsiColor::DarkGray))
            ->widget(ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(...$spans))));
    }

    private static function buildConfirmDelete(ProfileDialog $dlg): Widget
    {
        $name = $dlg->deleteIndex !== null && isset($dlg->store->profiles[$dlg->deleteIndex])
            ? $dlg->store->profiles[$dlg->deleteIndex]->name
            : '?';

        $body = GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(0), Constraint::length(1))
            ->widgets(
                ParagraphWidget::fromText(Text::fromLine(Line::fromSpans(
                    new Span('Profil "', Style::default()->fg(AnsiColor::White)),
                    new Span($name, Style::default()->fg(AnsiColor::Yellow)->addModifier(Modifier::BOLD)),
                    new Span('" wirklich löschen?', Style::default()->fg(AnsiColor::White)),
                ))),
                ParagraphWidget::fromText(Text::fromLine(DialogChrome::hints(['Enter / Y' => 'Ja', 'Esc / N' => 'Nein']))),
            );

        $block = BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Profil löschen? '))
            ->borderStyle(Style::default()->fg(AnsiColor::Red))
            ->widget($body);

        return new CenteredBox(50, 30, $block);
    }
}
