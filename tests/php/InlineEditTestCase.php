<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Tests;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\SecurityToken;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\DependentSelectEditor;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\NumericEditor;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\SelectEditor;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\TextEditor;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\ToggleEditor;
use YouWillLikeIT\GridFieldToolkit\Config\GridFieldConfig_ToolkitBase;
use YouWillLikeIT\GridFieldToolkit\Tests\Fixtures\ToolkitController;
use YouWillLikeIT\GridFieldToolkit\Tests\Fixtures\ToolkitRecord;

/** Shared GridField + request plumbing for the inline edit tests. Requests go through GridField::handleRequest(). */
abstract class InlineEditTestCase extends SapphireTest
{
    protected static $extra_dataobjects = [ToolkitRecord::class];

    protected static $extra_controllers = [ToolkitController::class];

    protected GridField $grid;

    protected Session $session;

    protected ToolkitRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        SecurityToken::enable();
        $this->logInWithPermission('ADMIN');

        // The test DB is shared by every test of the class: start each one from an empty table.
        foreach (ToolkitRecord::get() as $leftover) {
            $leftover->delete();
        }

        $this->record = ToolkitRecord::create([
            'Title' => 'Foo', 'IsActive' => false, 'Qty' => 3, 'Price' => 10.5, 'Status' => 'a',
            'Country' => 'AT', 'City' => 'Wien',
        ]);
        $this->record->write();

        $this->session = new Session([]);
        $this->grid = $this->makeGrid(ToolkitRecord::get());
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    protected function makeGrid(\SilverStripe\ORM\DataList $list, bool $undo = true): GridField
    {
        $config = GridFieldConfig_ToolkitBase::create(50)->withInlineEdit(
            new TextEditor('Title'),
            new ToggleEditor('IsActive'),
            new NumericEditor('Qty', min: 0, max: 99),
            new NumericEditor('Price', allowNull: true),
            new SelectEditor('Status', ['a' => 'Alpha', 'b' => 'Beta']),
            new SelectEditor('Country', ['AT' => 'Austria', 'DE' => 'Germany']),
            new DependentSelectEditor('City', 'Country', static fn (string $country): array => match ($country) {
                'AT' => ['Wien' => 'Wien', 'Graz' => 'Graz'],
                'DE' => ['Berlin' => 'Berlin'],
                default => [],
            }),
        );
        if ($undo) {
            $config->withUndo(5);
        }

        $grid = GridField::create('Records', 'Records', $list, $config);
        new Form(new ToolkitController(), 'TestForm', FieldList::create($grid), FieldList::create());

        return $grid;
    }

    protected function fresh(): ToolkitRecord
    {
        return ToolkitRecord::get()->byID($this->record->ID);
    }

    /** The etag the client would hold: rendered into the cell attributes of a freshly loaded row. */
    protected function etag(?GridField $grid = null): string
    {
        return ($grid ?? $this->grid)->getColumnAttributes($this->fresh(), 'Title')['data-ywli-etag'];
    }

    protected function patch(array $changes, ?string $etag = null, bool $csrf = true, ?GridField $grid = null, ?int $id = null): HTTPResponse
    {
        $body = json_encode(['id' => $id ?? $this->record->ID, 'etag' => $etag ?? $this->etag($grid), 'changes' => $changes]);

        return $this->send('POST', 'ywli/inline/patch', body: $body, csrf: $csrf, grid: $grid);
    }

    protected function send(string $method, string $url, array $get = [], ?string $body = null, bool $csrf = true, ?GridField $grid = null): HTTPResponse
    {
        $request = new HTTPRequest($method, $url, $get, [], $body);
        $request->setSession($this->session);
        if ($csrf) {
            $request->addHeader('X-SecurityID', SecurityToken::inst()->getValue());
        }

        $response = ($grid ?? $this->grid)->handleRequest($request);
        $this->assertInstanceOf(HTTPResponse::class, $response);

        return $response;
    }

    protected function json(HTTPResponse $response): array
    {
        $this->assertStringContainsString('application/json', (string) $response->getHeader('Content-Type'));

        return json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
