<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Tests;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\GridFieldToolkit\Component\Translation\FluentStatusProvider;
use YouWillLikeIT\GridFieldToolkit\Config\GridFieldConfig_ToolkitBase;
use YouWillLikeIT\GridFieldToolkit\Contract\TranslationStatusProviderInterface;
use YouWillLikeIT\GridFieldToolkit\Tests\Fixtures\ToolkitController;
use YouWillLikeIT\GridFieldToolkit\Tests\Fixtures\ToolkitRecord;

class GroupFourTest extends InlineEditTestCase
{
    private function grid(GridFieldConfig_ToolkitBase $config): GridField
    {
        $grid = GridField::create('Records', 'Records', ToolkitRecord::get(), $config);
        new Form(new ToolkitController(), 'TestForm', FieldList::create($grid), FieldList::create());

        return $grid;
    }

    private function marker(string $html, string $feature): array
    {
        $this->assertSame(1, preg_match('/data-ywli-feature="' . $feature . '"[^>]*data-ywli-config="([^"]*)"/', $html, $m), "marker $feature");

        return json_decode(html_entity_decode($m[1]), true, flags: JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------ column manager

    public function testColumnManagerHidesStateColumnsButNeverLockedOrUnknownOnes(): void
    {
        $grid = $this->grid(GridFieldConfig_ToolkitBase::create()->withColumnManager(locked: ['Title']));
        $this->assertContains('Status', $grid->getColumns());

        $grid->getState(false)->setValue(json_encode(['YWLIColumns' => ['Hidden' => 'Title,Status,Bogus']]));
        $columns = $grid->getColumns();
        $this->assertNotContains('Status', $columns);
        $this->assertContains('Title', $columns, 'locked');
        $this->assertContains('Qty', $columns);
    }

    public function testColumnManagerDefaultHiddenAndShowAll(): void
    {
        $grid = $this->grid(GridFieldConfig_ToolkitBase::create()->withColumnManager(defaultHidden: ['Qty']));
        $this->assertNotContains('Qty', $grid->getColumns());

        $grid->getState(false)->setValue(json_encode(['YWLIColumns' => ['Hidden' => '']]));
        $this->assertContains('Qty', $grid->getColumns(), 'explicit empty list shows everything');
    }

    public function testColumnManagerListsAllColumnsInTheMarkerEvenWhenHidden(): void
    {
        $grid = $this->grid(GridFieldConfig_ToolkitBase::create()->withColumnManager(['Title'], ['Qty']));
        $html = $grid->FieldHolder();
        $config = $this->marker($html, 'column-manager');
        $byName = array_column($config['columns'], null, 'name');

        $this->assertTrue($byName['Qty']['hidden']);
        $this->assertFalse($byName['Status']['hidden']);
        $this->assertTrue($byName['Title']['locked']);
        $this->assertStringContainsString('data-ywli-columns-toggle', $html);
    }

    public function testColumnManagerWorksTogetherWithInlineEdit(): void
    {
        $grid = $this->grid(GridFieldConfig_ToolkitBase::create()
            ->withInlineEdit(new \YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\TextEditor('Title'))
            ->withColumnManager());
        $grid->getState(false)->setValue(json_encode(['YWLIColumns' => ['Hidden' => 'Qty']]));

        $html = $grid->FieldHolder();
        $this->assertStringContainsString('data-ywli-editor="text"', $html);
        $this->assertNotContains('Qty', $grid->getColumns());
    }

    // ------------------------------------------------------------ header help

    public function testHeaderHelpMarker(): void
    {
        $html = $this->grid(GridFieldConfig_ToolkitBase::create()->withHeaderHelp(['Status' => 'Life cycle <b>stage</b>']))->FieldHolder();
        $this->assertSame(['Status' => 'Life cycle <b>stage</b>'], $this->marker($html, 'header-help')['help']);
    }

    public function testHeaderHelpWithoutTextRendersNothing(): void
    {
        $this->assertStringNotContainsString('header-help', $this->grid(GridFieldConfig_ToolkitBase::create()->withHeaderHelp([]))->FieldHolder());
    }

    // ------------------------------------------------------------ translation status

    private function fakeProvider(array $locales = ['de_AT' => 'Deutsch', 'en_GB' => 'English']): TranslationStatusProviderInterface
    {
        return new class ($locales) implements TranslationStatusProviderInterface {
            public function __construct(private array $locales)
            {
            }

            public function getLocales(): array
            {
                return $this->locales;
            }

            public function getStatus(DataObject $record, string $locale): string
            {
                return match ($locale) {
                    'de_AT' => self::PUBLISHED,
                    'en_GB' => $record->Title === 'Foo' ? self::DRAFT : self::MISSING,
                    default => self::MISSING,
                };
            }
        };
    }

    public function testTranslationStatusRendersABadgePerLocale(): void
    {
        $grid = $this->grid(GridFieldConfig_ToolkitBase::create()->withTranslationStatus($this->fakeProvider()));
        $this->assertContains('ywli-translations', $grid->getColumns());

        $html = $grid->getColumnContent($this->record, 'ywli-translations');
        $this->assertStringContainsString('ywli-lang--published', $html);
        $this->assertStringContainsString('data-ywli-locale="de_AT"', $html);
        $this->assertStringContainsString('ywli-lang--draft', $html);
        $this->assertStringContainsString('>DE<', $html);
        $this->assertStringContainsString('Deutsch: translated', $html, 'screen-reader text');
    }

    public function testTranslationStatusEscapesLabels(): void
    {
        $grid = $this->grid(GridFieldConfig_ToolkitBase::create()->withTranslationStatus($this->fakeProvider(['de' => '<script>x</script>'])));
        $html = $grid->getColumnContent($this->record, 'ywli-translations');
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testTranslationStatusAddsNoColumnWithoutLocales(): void
    {
        $grid = $this->grid(GridFieldConfig_ToolkitBase::create()->withTranslationStatus($this->fakeProvider([])));
        $this->assertNotContains('ywli-translations', $grid->getColumns());
    }

    public function testFluentProviderIsInertWithoutFluent(): void
    {
        $this->assertFalse(FluentStatusProvider::isAvailable());
        $this->assertSame([], (new FluentStatusProvider())->getLocales());
        $grid = $this->grid(GridFieldConfig_ToolkitBase::create()->withTranslationStatus());
        $this->assertNotContains('ywli-translations', $grid->getColumns());
    }

    // ------------------------------------------------------------ nested relation

    public function testNestedRelationButtonShowsCountAndItemUrl(): void
    {
        $this->more();
        $this->more();
        $grid = $this->grid(GridFieldConfig_ToolkitBase::create()->withNestedRelation('Siblings', 'Siblings'));

        $html = $grid->getColumnContent($this->record, 'ywli-nested');
        $this->assertStringContainsString('ywli-nested__open', $html);
        $this->assertStringContainsString('(2)', $html);
        $this->assertStringContainsString('/item/' . $this->record->ID, $html);
        $this->assertSame('Root_Siblings', $this->marker($grid->FieldHolder(), 'nested-relation')['tab']);
    }

    public function testNestedRelationNeedsDetailFormAndAKnownRelation(): void
    {
        $config = GridFieldConfig_ToolkitBase::create()->withNestedRelation('Siblings');
        $config->removeComponentsByType(GridFieldDetailForm::class);
        $this->assertSame('', $this->grid($config)->getColumnContent($this->record, 'ywli-nested'));

        $unknown = $this->grid(GridFieldConfig_ToolkitBase::create()->withNestedRelation('Nope'));
        $this->assertSame('', $unknown->getColumnContent($this->record, 'ywli-nested'));
    }

    public function testNestedRelationRespectsCanView(): void
    {
        $grid = $this->grid(GridFieldConfig_ToolkitBase::create()->withNestedRelation('Siblings'));
        $this->logOut();
        $this->assertSame('', $grid->getColumnContent($this->record, 'ywli-nested'));
    }

    public function testCustomTabOverride(): void
    {
        $component = new \YouWillLikeIT\GridFieldToolkit\Component\Relation\GridFieldNestedRelation('Siblings', null, 'Root_Other');
        $this->assertSame('Root_Other', $component->getTab());
    }

    private function more(): void
    {
        ToolkitRecord::create(['Title' => 'X', 'Qty' => 1])->write();
    }
}
