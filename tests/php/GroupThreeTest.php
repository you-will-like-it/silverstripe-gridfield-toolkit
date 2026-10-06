<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Tests;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\GridFieldToolkit\Component\Bulk\Action\CallbackBulkAction;
use YouWillLikeIT\GridFieldToolkit\Component\Bulk\Action\DeleteBulkAction;
use YouWillLikeIT\GridFieldToolkit\Component\Bulk\Action\SetFieldBulkAction;
use YouWillLikeIT\GridFieldToolkit\Component\Bulk\GridFieldStashBulk;
use YouWillLikeIT\GridFieldToolkit\Component\Link\GridFieldLinkedFilter;
use YouWillLikeIT\GridFieldToolkit\Component\State\GridFieldViewStatePersister;
use YouWillLikeIT\GridFieldToolkit\Config\GridFieldConfig_ToolkitBase;
use YouWillLikeIT\GridFieldToolkit\Tests\Fixtures\ToolkitController;
use YouWillLikeIT\GridFieldToolkit\Tests\Fixtures\ToolkitRecord;

class GroupThreeTest extends InlineEditTestCase
{
    private function grid(string $name, GridFieldConfig_ToolkitBase $config, ?\SilverStripe\ORM\DataList $list = null): GridField
    {
        return GridField::create($name, $name, $list ?? ToolkitRecord::get(), $config);
    }

    /** @param GridField ...$grids */
    private function form(GridField ...$grids): void
    {
        new Form(new ToolkitController(), 'TestForm', FieldList::create($grids), FieldList::create());
    }

    private function more(string $title, array $extra = []): ToolkitRecord
    {
        $r = ToolkitRecord::create(array_merge(['Title' => $title, 'Qty' => 1], $extra));
        $r->write();

        return $r;
    }

    // ------------------------------------------------------------ stash bulk

    private function stashGrid(): GridField
    {
        $grid = $this->grid('Records', GridFieldConfig_ToolkitBase::create()->withStashBulk(
            new DeleteBulkAction(),
            new SetFieldBulkAction('archive', 'Archive', 'Status', 'c'),
            new CallbackBulkAction('boom', 'Boom', static function (DataObject $r): void {
                if ($r->Title === 'Bad') {
                    throw new \RuntimeException('secret internals');
                }
            }),
        ));
        $this->form($grid);

        return $grid;
    }

    private function runBulk(GridField $grid, string $action, mixed $ids, bool $csrf = true): \SilverStripe\Control\HTTPResponse
    {
        return $this->send('POST', 'ywli/stash/run/' . $action, body: json_encode(['ids' => $ids]), csrf: $csrf, grid: $grid);
    }

    public function testStashColumnAndMarker(): void
    {
        $grid = $this->stashGrid();
        $this->assertSame('ywli-stash', $grid->getColumns()[0]);
        $this->assertStringContainsString('data-ywli-stash-id="' . $this->record->ID . '"', $grid->getColumnContent($this->record, 'ywli-stash'));
        $this->assertStringContainsString('data-ywli-feature="stash"', $grid->FieldHolder());
    }

    public function testStashSetFieldRunsOnEverySelectedRecord(): void
    {
        $b = $this->more('B');
        $data = $this->json($this->runBulk($this->stashGrid(), 'archive', [$this->record->ID, $b->ID]));
        $this->assertSame(2, $data['processed']);
        $this->assertSame([], $data['failed']);
        $this->assertSame('c', $this->fresh()->Status);
        $this->assertSame('c', ToolkitRecord::get()->byID($b->ID)->Status);
    }

    public function testStashDeleteRespectsListAndPermissions(): void
    {
        $b = $this->more('B');
        $this->assertSame(1, $this->json($this->runBulk($this->stashGrid(), 'delete', [$b->ID]))['processed']);
        $this->assertNull(ToolkitRecord::get()->byID($b->ID));
        $this->assertNotNull($this->fresh());
    }

    public function testStashIgnoresRecordsOutsideTheGridList(): void
    {
        $grid = $this->grid('Records', GridFieldConfig_ToolkitBase::create()->withStashBulk(new DeleteBulkAction()), ToolkitRecord::get()->filter('Title', 'nope'));
        $this->form($grid);

        $data = $this->json($this->runBulk($grid, 'delete', [$this->record->ID]));
        $this->assertSame(0, $data['processed']);
        $this->assertSame($this->record->ID, $data['failed'][0]['id']);
        $this->assertNotNull($this->fresh());
    }

    public function testStashReportsPartialFailuresWithoutLeakingExceptions(): void
    {
        $bad = $this->more('Bad');
        $response = $this->runBulk($this->stashGrid(), 'boom', [$this->record->ID, $bad->ID, 99999]);
        $data = $this->json($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $data['processed']);
        $this->assertCount(2, $data['failed']);
        $this->assertStringNotContainsString('secret', $response->getBody());
    }

    public function testStashValidation(): void
    {
        $grid = $this->stashGrid();
        $this->assertSame(403, $this->runBulk($grid, 'archive', [1], csrf: false)->getStatusCode());
        $this->assertSame(404, $this->runBulk($grid, 'nope', [1])->getStatusCode());
        $this->assertSame(400, $this->runBulk($grid, 'archive', [])->getStatusCode());
        $this->assertSame(400, $this->runBulk($grid, 'archive', ['x'])->getStatusCode());
        $this->assertSame(400, $this->runBulk($grid, 'archive', ['a' => 1])->getStatusCode());

        $small = $this->grid('Records', GridFieldConfig_ToolkitBase::create()->withStashBulkLimit(2, new DeleteBulkAction()));
        $this->form($small);
        $this->assertSame(413, $this->runBulk($small, 'delete', [1, 2, 3])->getStatusCode());
    }

    public function testStashDeniedForUsersWithoutPermission(): void
    {
        $grid = $this->stashGrid();
        $this->logOut();
        $data = $this->json($this->runBulk($grid, 'archive', [$this->record->ID]));
        $this->assertSame(0, $data['processed']);
        $this->assertSame('a', $this->fresh()->Status);
    }

    public function testBulkActionNamesAreValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CallbackBulkAction('Bad Name!', 'x', static fn () => null);
    }

    // ------------------------------------------------------------ linked filter

    private function linkedGrid(bool $emptyUntilSelected): GridField
    {
        $grid = $this->grid('Slave', GridFieldConfig_ToolkitBase::create()->withLinkedFilter('Qty', $emptyUntilSelected));
        $this->form($grid);

        return $grid;
    }

    public function testLinkedFilterIsEmptyUntilSelected(): void
    {
        $this->assertSame(0, $this->linkedGrid(true)->getManipulatedList()->count());
        $this->assertSame(1, $this->linkedGrid(false)->getManipulatedList()->count());
    }

    public function testLinkedFilterUsesTheMasterIdFromGridState(): void
    {
        $this->more('Q7', ['Qty' => 7]);
        $grid = $this->linkedGrid(true);
        $grid->getState(false)->setValue(json_encode(['YWLILinkedFilter' => ['MasterID' => 7]]));

        $this->assertSame(['Q7'], $grid->getManipulatedList()->column('Title'));
    }

    public function testLinkedFilterCastsTheMasterIdToInt(): void
    {
        $grid = $this->linkedGrid(true);
        $grid->getState(false)->setValue(json_encode(['YWLILinkedFilter' => ['MasterID' => "3 OR 1=1"]]));

        $this->assertSame(['Foo'], $grid->getManipulatedList()->column('Title'), 'Qty = 3, not an injection');
    }

    // ------------------------------------------------------------ transfer

    private function transferGrids(array $modes = ['move', 'copy']): array
    {
        $source = $this->grid('Source', GridFieldConfig_ToolkitBase::create()->withTransferSource());
        $target = $this->grid('Target', GridFieldConfig_ToolkitBase::create()->withTransferTarget(
            ['Source'],
            static function (DataObject $record, GridField $target, string $mode): void {
                $record->Country = $mode === 'copy' ? 'XX' : 'DE';
            },
            $modes,
        ), ToolkitRecord::get()->filter('Country', 'DE'));
        $this->form($source, $target);

        return [$source, $target];
    }

    private function receive(GridField $target, array $body, bool $csrf = true): \SilverStripe\Control\HTTPResponse
    {
        return $this->send('POST', 'ywli/transfer/receive', body: json_encode($body), csrf: $csrf, grid: $target);
    }

    public function testTransferMoveMutatesAndWritesTheRecord(): void
    {
        [, $target] = $this->transferGrids();
        $data = $this->json($this->receive($target, ['source' => 'Source', 'ids' => [$this->record->ID], 'mode' => 'move']));
        $this->assertSame(1, $data['processed']);
        $this->assertSame('DE', $this->fresh()->Country);
        $this->assertSame(1, ToolkitRecord::get()->count());
    }

    public function testTransferCopyLeavesTheOriginalAlone(): void
    {
        [, $target] = $this->transferGrids();
        $this->assertSame(1, $this->json($this->receive($target, ['source' => 'Source', 'ids' => [$this->record->ID], 'mode' => 'copy']))['processed']);
        $this->assertSame('AT', $this->fresh()->Country);
        $this->assertSame(2, ToolkitRecord::get()->count());
        $this->assertSame(1, ToolkitRecord::get()->filter('Country', 'XX')->count());
    }

    public function testTransferRejectsBadInput(): void
    {
        [, $target] = $this->transferGrids(['move']);
        $ids = [$this->record->ID];
        $this->assertSame(403, $this->receive($target, ['source' => 'Source', 'ids' => $ids, 'mode' => 'move'], csrf: false)->getStatusCode());
        $this->assertSame(422, $this->receive($target, ['source' => 'Source', 'ids' => $ids, 'mode' => 'copy'])->getStatusCode(), 'mode not enabled');
        $this->assertSame(422, $this->receive($target, ['source' => 'Other', 'ids' => $ids, 'mode' => 'move'])->getStatusCode(), 'source not accepted');
        $this->assertSame(400, $this->receive($target, ['source' => 'Source', 'ids' => [], 'mode' => 'move'])->getStatusCode());
        $this->assertSame('AT', $this->fresh()->Country);
    }

    public function testTransferRequiresASourceGridWithTheSourceComponent(): void
    {
        $plain = $this->grid('Source', GridFieldConfig_ToolkitBase::create());
        $target = $this->grid('Target', GridFieldConfig_ToolkitBase::create()->withTransferTarget(['Source'], static fn () => null));
        $this->form($plain, $target);

        $this->assertSame(422, $this->receive($target, ['source' => 'Source', 'ids' => [$this->record->ID], 'mode' => 'move'])->getStatusCode());
    }

    public function testTransferChecksPermissionsPerRecord(): void
    {
        [, $target] = $this->transferGrids();
        $this->logOut();
        $data = $this->json($this->receive($target, ['source' => 'Source', 'ids' => [$this->record->ID], 'mode' => 'move']));
        $this->assertSame(0, $data['processed']);
        $this->assertSame('AT', $this->fresh()->Country);
    }

    // ------------------------------------------------------------ view state persister

    private function persisted(string $stateJson = ''): GridField
    {
        $grid = $this->grid('Records', GridFieldConfig_ToolkitBase::create(10)->withViewStatePersister());
        $this->form($grid);
        $request = new HTTPRequest('GET', '/');
        $request->setSession($this->session);
        $grid->setRequest($request);
        if ($stateJson !== '') {
            $request->setRouteParams([]);
            $grid->getState(false)->setValue($stateJson);
            $request = new HTTPRequest('POST', '/', [], ['Records' => ['GridState' => $stateJson]]);
            $request->setSession($this->session);
            $grid->setRequest($request);
        }

        return $grid;
    }

    public function testPersisterIsTheFirstComponent(): void
    {
        $config = GridFieldConfig_ToolkitBase::create()->withViewStatePersister();
        $this->assertInstanceOf(GridFieldViewStatePersister::class, $config->getComponents()->first());
        $config->withViewStatePersister();
        $this->assertCount(1, $config->getComponentsByType(GridFieldViewStatePersister::class));
    }

    public function testPersisterRestoresStateOnAFreshRender(): void
    {
        $state = json_encode(['GridFieldSortableHeader' => ['SortColumn' => 'Title', 'SortDirection' => 'desc']]);
        $this->persisted($state)->getManipulatedList();

        $fresh = $this->persisted();
        $fresh->getManipulatedList();
        $this->assertSame('Title', (string) $fresh->State->GridFieldSortableHeader->SortColumn);
        $this->assertSame('desc', (string) $fresh->State->GridFieldSortableHeader->SortDirection);
    }

    public function testExplicitStateBeatsTheSavedOne(): void
    {
        $this->persisted(json_encode(['GridFieldSortableHeader' => ['SortColumn' => 'Title', 'SortDirection' => 'desc']]))->getManipulatedList();

        $explicit = $this->persisted(json_encode(['GridFieldPaginator' => ['currentPage' => 1]]));
        $explicit->getManipulatedList();
        $this->assertEmpty((string) $explicit->State->GridFieldSortableHeader->SortColumn, 'explicit request state is not overlaid with the saved sort');
    }

    public function testPersisterDoesNothingForGuests(): void
    {
        $this->logOut();
        $grid = $this->persisted();
        $grid->getManipulatedList();
        $this->assertNull($this->session->get('ywli.viewstate'));
    }
}
