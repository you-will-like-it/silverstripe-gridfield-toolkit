<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Tests;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use YouWillLikeIT\GridFieldToolkit\Component\Board\GridFieldKanbanView;
use YouWillLikeIT\GridFieldToolkit\Component\Layout\GridFieldAccordion;
use YouWillLikeIT\GridFieldToolkit\Component\Layout\GridFieldFullscreenToggle;
use YouWillLikeIT\GridFieldToolkit\Component\Layout\GridFieldMasterDetail;
use YouWillLikeIT\GridFieldToolkit\Config\GridFieldConfig_ToolkitBase;
use YouWillLikeIT\GridFieldToolkit\Tests\Fixtures\ToolkitController;
use YouWillLikeIT\GridFieldToolkit\Tests\Fixtures\ToolkitRecord;

class LayoutComponentsTest extends InlineEditTestCase
{
    private function layoutGrid(int $perPage = 50): GridField
    {
        $config = GridFieldConfig_ToolkitBase::create($perPage)
            ->withFullscreen()
            ->withAccordion(static fn (ToolkitRecord $r): array => ['Title' => $r->Title, 'Raw' => '<b>x</b>'])
            ->withMasterDetail(70)
            ->withKanban('Status', 'Title', ['Qty'], ['a' => 'Alpha', 'b' => 'Beta'], limit: 2);
        $grid = GridField::create('Records', 'Records', ToolkitRecord::get(), $config);
        new Form(new ToolkitController(), 'TestForm', FieldList::create($grid), FieldList::create());

        return $grid;
    }

    private function more(string $title, string $status): ToolkitRecord
    {
        $r = ToolkitRecord::create(['Title' => $title, 'Status' => $status, 'Qty' => 1]);
        $r->write();

        return $r;
    }

    // ------------------------------------------------------------ config

    public function testNothingIsAddedUnlessRequested(): void
    {
        $config = GridFieldConfig_ToolkitBase::create();
        foreach ([GridFieldFullscreenToggle::class, GridFieldAccordion::class, GridFieldMasterDetail::class, GridFieldKanbanView::class] as $class) {
            $this->assertNull($config->getComponentByType($class));
        }
    }

    public function testWithMethodsReplaceInsteadOfDuplicate(): void
    {
        $config = GridFieldConfig_ToolkitBase::create()->withFullscreen()->withFullscreen()->withMasterDetail(10)->withMasterDetail(90);
        $this->assertCount(1, $config->getComponentsByType(GridFieldFullscreenToggle::class));
        $this->assertSame(80, $config->getComponentByType(GridFieldMasterDetail::class)->getWidthPercent());
    }

    public function testMasterDetailWidthIsClamped(): void
    {
        $this->assertSame(30, (new GridFieldMasterDetail(1))->getWidthPercent());
        $this->assertSame(80, (new GridFieldMasterDetail(500))->getWidthPercent());
    }

    // ------------------------------------------------------------ fragments + columns

    public function testMarkersAreRenderedForEveryFeature(): void
    {
        $html = $this->layoutGrid()->FieldHolder();
        foreach (['fullscreen', 'accordion', 'master-detail', 'kanban'] as $feature) {
            $this->assertStringContainsString('data-ywli-feature="' . $feature . '"', $html);
        }
        $this->assertStringContainsString('data-ywli-fullscreen-toggle', $html);
        $this->assertStringContainsString('data-ywli-kanban-toggle', $html);
    }

    public function testAccordionColumnComesFirstAndRendersAToggle(): void
    {
        $grid = $this->layoutGrid();
        $columns = $grid->getColumns();
        $this->assertSame('ywli-accordion', $columns[0]);
        $this->assertSame(1, count(array_keys($columns, 'ywli-accordion', true)));
        $this->assertStringContainsString(
            'data-ywli-accordion-id="' . $this->record->ID . '"',
            $grid->getColumnContent($this->record, 'ywli-accordion')
        );
    }

    // ------------------------------------------------------------ accordion endpoint

    public function testAccordionArrayIsEscaped(): void
    {
        $body = $this->json($this->send('GET', 'ywli/accordion/' . $this->record->ID, grid: $this->layoutGrid()));
        $this->assertTrue($body['ok']);
        $this->assertStringContainsString('<dt>Title</dt><dd>Foo</dd>', $body['html']);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $body['html']);
        $this->assertStringNotContainsString('<b>x</b>', $body['html']);
    }

    public function testAccordionStringIsPassedThroughAsTrustedHtml(): void
    {
        $config = GridFieldConfig_ToolkitBase::create()->withAccordion(static fn (): string => '<p>hi</p>');
        $grid = GridField::create('Records', 'Records', ToolkitRecord::get(), $config);
        new Form(new ToolkitController(), 'TestForm', FieldList::create($grid), FieldList::create());

        $this->assertSame('<p>hi</p>', $this->json($this->send('GET', 'ywli/accordion/' . $this->record->ID, grid: $grid))['html']);
    }

    public function testAccordionRejectsUnknownAndForeignRecords(): void
    {
        $grid = $this->layoutGrid();
        $this->assertSame(404, $this->send('GET', 'ywli/accordion/99999', grid: $grid)->getStatusCode());

        $filtered = GridField::create('Records', 'Records', ToolkitRecord::get()->filter('Title', 'nope'), GridFieldConfig_ToolkitBase::create()->withAccordion(static fn (): string => 'secret'));
        new Form(new ToolkitController(), 'TestForm', FieldList::create($filtered), FieldList::create());
        $response = $this->send('GET', 'ywli/accordion/' . $this->record->ID, grid: $filtered);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('secret', $response->getBody());
    }

    public function testAccordionRespectsCanView(): void
    {
        $this->logOut();
        $response = $this->send('GET', 'ywli/accordion/' . $this->record->ID, grid: $this->layoutGrid());
        $this->assertSame(403, $response->getStatusCode());
    }

    // ------------------------------------------------------------ kanban data

    public function testKanbanDataListsLanesCardsAndCapsAtLimit(): void
    {
        $this->more('Two', 'b');
        $this->more('Three', 'b');

        $data = $this->json($this->send('GET', 'ywli/kanban/data', grid: $this->layoutGrid(perPage: 1)));
        $this->assertSame(['a', 'b'], array_column($data['columns'], 'value'));
        $this->assertCount(2, $data['cards'], 'limit applies');
        $this->assertSame(3, $data['total'], 'pagination (1 per page) is ignored');
        $this->assertTrue($data['truncated']);
        $this->assertSame('Foo', $data['cards'][0]['title']);
        $this->assertSame([['label' => 'Qty', 'value' => '3']], array_map(
            static fn (array $f): array => ['label' => $f['label'], 'value' => $f['value']],
            $data['cards'][0]['fields']
        ));
        $this->assertTrue($data['cards'][0]['canEdit']);
    }

    public function testKanbanUnmappedValuesGoToALockedOtherLane(): void
    {
        $this->more('Odd', 'c');
        $config = GridFieldConfig_ToolkitBase::create()->withKanban('Status', columns: ['a' => 'Alpha']);
        $grid = GridField::create('Records', 'Records', ToolkitRecord::get(), $config);
        new Form(new ToolkitController(), 'TestForm', FieldList::create($grid), FieldList::create());

        $data = $this->json($this->send('GET', 'ywli/kanban/data', grid: $grid));
        $other = end($data['columns']);
        $this->assertSame(['value' => '', 'label' => 'Other', 'locked' => true], $other);
        $this->assertContains('', array_column($data['cards'], 'group'));
    }

    public function testKanbanDefaultsToEnumValues(): void
    {
        $config = GridFieldConfig_ToolkitBase::create()->withKanban('Status');
        $grid = GridField::create('Records', 'Records', ToolkitRecord::get(), $config);
        new Form(new ToolkitController(), 'TestForm', FieldList::create($grid), FieldList::create());

        $this->assertSame(['a', 'b', 'c'], array_column($this->json($this->send('GET', 'ywli/kanban/data', grid: $grid))['columns'], 'value'));
    }

    public function testKanbanDataNeedsViewPermission(): void
    {
        $this->logOut();
        $this->assertSame(403, $this->send('GET', 'ywli/kanban/data', grid: $this->layoutGrid())->getStatusCode());
    }

    // ------------------------------------------------------------ kanban move

    private function move(array $body, bool $csrf = true): \SilverStripe\Control\HTTPResponse
    {
        return $this->send('POST', 'ywli/kanban/move', body: json_encode($body), csrf: $csrf, grid: $this->layoutGrid());
    }

    public function testMoveChangesTheGroupField(): void
    {
        $response = $this->move(['id' => $this->record->ID, 'to' => 'b', 'from' => 'a']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('b', $this->json($response)['group']);
        $this->assertSame('b', $this->fresh()->Status);
    }

    public function testMoveRequiresCsrf(): void
    {
        $this->assertSame(403, $this->move(['id' => $this->record->ID, 'to' => 'b', 'from' => 'a'], csrf: false)->getStatusCode());
        $this->assertSame('a', $this->fresh()->Status);
    }

    public function testMoveToUnknownLaneIsRejected(): void
    {
        $this->assertSame(422, $this->move(['id' => $this->record->ID, 'to' => 'c', 'from' => 'a'])->getStatusCode());
        $this->assertSame(422, $this->move(['id' => $this->record->ID, 'to' => '', 'from' => 'a'])->getStatusCode());
        $this->assertSame('a', $this->fresh()->Status);
    }

    public function testStaleFromIs409AndNothingChanges(): void
    {
        $response = $this->move(['id' => $this->record->ID, 'to' => 'b', 'from' => 'b']);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('a', $this->json($response)['group']);
        $this->assertSame('a', $this->fresh()->Status);
    }

    public function testMoveRejectsMalformedAndUnknownRecords(): void
    {
        $this->assertSame(400, $this->move(['id' => 'x', 'to' => 'b', 'from' => 'a'])->getStatusCode());
        $this->assertSame(400, $this->move(['id' => $this->record->ID, 'to' => 1, 'from' => 'a'])->getStatusCode());
        $this->assertSame(404, $this->move(['id' => 99999, 'to' => 'b', 'from' => 'a'])->getStatusCode());
        $this->assertSame(400, $this->send('POST', 'ywli/kanban/move', body: '{nope', grid: $this->layoutGrid())->getStatusCode());
    }

    public function testMoveNeedsEditPermission(): void
    {
        $this->logOut();
        $this->assertContains($this->move(['id' => $this->record->ID, 'to' => 'b', 'from' => 'a'])->getStatusCode(), [403]);
        $this->assertSame('a', $this->fresh()->Status);
    }

    public function testMoveToSameLaneIsANoOp(): void
    {
        $this->assertSame(200, $this->move(['id' => $this->record->ID, 'to' => 'a', 'from' => 'a'])->getStatusCode());
    }
}
