<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\DependentSelectEditor;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\TextEditor;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\ToggleEditor;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\GridFieldInlineEdit;
use YouWillLikeIT\GridFieldToolkit\Config\GridFieldConfig_ToolkitBase;
use YouWillLikeIT\GridFieldToolkit\Tests\Fixtures\ToolkitRecord;

class GridFieldInlineEditTest extends InlineEditTestCase
{
    // ------------------------------------------------------------------ config / rendering

    public function testBaseConfigIsOptIn(): void
    {
        $config = GridFieldConfig_ToolkitBase::create();
        $this->assertNull($config->getComponentByType(GridFieldInlineEdit::class));
    }

    public function testWithInlineEditReplacesDataColumnsInPlaceKeepingItsConfiguration(): void
    {
        $config = GridFieldConfig_ToolkitBase::create();
        $stock = $config->getComponentByType(GridFieldDataColumns::class);
        $stock->setDisplayFields(['Title' => 'Name']);
        $position = array_search($stock, $config->getComponents()->toArray(), true);

        $config->withInlineEdit(new TextEditor('Title'))->withInlineEdit(new ToggleEditor('IsActive'));

        $columns = $config->getComponentsByType(GridFieldDataColumns::class);
        $this->assertCount(1, $columns, 'exactly one column provider, otherwise cells render twice');
        $inline = $columns->first();
        $this->assertInstanceOf(GridFieldInlineEdit::class, $inline);
        $this->assertSame($position, array_search($inline, $config->getComponents()->toArray(), true));
        $this->assertSame(['Title' => 'Name'], $inline->getDisplayFields($this->grid));
        $this->assertNotNull($inline->getEditor('Title'));
        $this->assertNotNull($inline->getEditor('IsActive'), 'repeated calls add to the same component');
    }

    public function testEditableCellsRenderOnceWithAddressableAttributes(): void
    {
        $record = $this->fresh();

        $toggle = $this->grid->getColumnContent($record, 'IsActive');
        $this->assertSame(1, substr_count($toggle, 'role="switch"'));
        $this->assertStringContainsString('aria-checked="false"', $toggle);

        $text = $this->grid->getColumnContent($record, 'Title');
        $this->assertStringContainsString('data-ywli-value="Foo"', $text);
        $this->assertSame(1, substr_count($text, 'class="ywli-value"'));

        $attributes = $this->grid->getColumnAttributes($record, 'Title');
        $this->assertSame('Title', $attributes['data-ywli-column']);
        $this->assertSame('text', $attributes['data-ywli-editor']);
        $this->assertSame((string) $record->ID, $attributes['data-ywli-id']);
        $this->assertSame('20', $attributes['data-ywli-maxlength'], 'derived from Varchar(20)');
        $this->assertStringContainsString('ywli-editable ywli-editable--text', $attributes['class']);
        $this->assertStringContainsString('col-Title', $attributes['class'], 'stock class survives');
    }

    public function testSelectShowsTheOptionLabelAndNumericAdvertisesItsStep(): void
    {
        $record = $this->fresh();
        $this->assertStringContainsString('>Alpha</span>', $this->grid->getColumnContent($record, 'Status'));
        $this->assertSame('1', $this->grid->getColumnAttributes($record, 'Qty')['data-ywli-step']);
        $this->assertSame('0.01', $this->grid->getColumnAttributes($record, 'Price')['data-ywli-step']);
    }

    public function testDependentSelectShowsItsLabelWhileKeepingTheStoredValue(): void
    {
        $record = $this->fresh();
        $record->setField('City', '7');
        $editor = new DependentSelectEditor('City', 'Country', static fn (string $country): array => ['7' => 'Graz']);

        $html = $editor->renderCell($this->grid, $record, '7');

        $this->assertStringContainsString('data-ywli-value="7"', $html);
        $this->assertStringContainsString('>Graz</span>', $html);
        $this->assertSame('7', $editor->readValue($record));
    }

    public function testDependentSelectShowsEmptyLabelWhenNoValueIsSelected(): void
    {
        $record = $this->fresh();
        $record->setField('City', '');
        $editor = new DependentSelectEditor(
            'City',
            'Country',
            static fn (string $country): array => ['7' => 'Graz'],
            emptyLabel: 'Choose a city',
        );

        $html = $editor->renderCell($this->grid, $record, '');

        $this->assertStringContainsString('data-ywli-value=""', $html);
        $this->assertStringContainsString('>Choose a city</span>', $html);
        $this->assertSame('', $editor->readValue($record));
    }

    public function testReadOnlyUserGetsPlainCells(): void
    {
        $this->logOut();
        $record = $this->fresh();
        $this->assertStringNotContainsString('role="switch"', $this->grid->getColumnContent($record, 'IsActive'));
        $this->assertArrayNotHasKey('data-ywli-editor', $this->grid->getColumnAttributes($record, 'Title'));
    }

    public function testMountMarkerCarriesClientConfig(): void
    {
        $html = $this->grid->FieldHolder();
        $this->assertStringContainsString('class="ywli-marker"', $html);
        $this->assertSame(1, substr_count($html, 'data-ywli-feature="inline-edit"'));
        preg_match('/data-ywli-config="([^"]+)"/', $html, $m);
        $config = json_decode(html_entity_decode($m[1]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringEndsWith('/field/Records/ywli/inline/patch', $config['patchUrl']);
        $this->assertSame('toggle', $config['editors']['IsActive']['type']);
        $this->assertTrue($config['editors']['City']['dynamic']);
        $this->assertSame('Country', $config['editors']['City']['dependsOn']);
        $this->assertSame('b', $config['editors']['Status']['options'][1]['value']);
    }

    // ------------------------------------------------------------------ patch: happy path + concurrency

    public function testTogglePatchPersistsAndReturnsRefreshedRow(): void
    {
        $response = $this->patch(['IsActive' => true]);
        $data = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($data['ok']);
        $this->assertSame(['IsActive' => true], $data['changes']);
        $this->assertSame(['IsActive' => false], $data['previous']);
        $this->assertStringContainsString('aria-checked="true"', $data['cells']['IsActive']);
        $this->assertTrue((bool) $this->fresh()->IsActive);
        $this->assertSame($this->etag(), $data['etag'], 'returned etag equals the etag of a freshly loaded row');
    }

    public function testConsecutivePatchesWithTheReturnedEtagNeverConflict(): void
    {
        $first = $this->json($this->patch(['Title' => 'One']));
        $second = $this->json($this->patch(['Title' => 'Two'], $first['etag']));
        $third = $this->json($this->patch(['Qty' => 7], $second['etag']));

        $this->assertTrue($third['ok'], json_encode($third));
        $this->assertSame('Two', $this->fresh()->Title);
        $this->assertSame(7, (int) $this->fresh()->Qty);
    }

    public function testStaleEtagIsRejectedWith409AndCarriesTheCurrentRow(): void
    {
        $stale = $this->etag();
        $this->patch(['Title' => 'Other user']);

        $response = $this->patch(['Title' => 'Mine'], $stale);
        $data = $this->json($response);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('conflict', $data['error']);
        $this->assertSame($this->etag(), $data['etag']);
        $this->assertSame($this->record->ID, $data['id']);
        $this->assertStringContainsString('Other user', $data['cells']['Title']);
        $this->assertSame('Other user', $this->fresh()->Title);
    }

    public function testUnchangedValueWritesNothingAndOffersNoUndo(): void
    {
        $data = $this->json($this->patch(['Title' => 'Foo']));

        $this->assertTrue($data['ok']);
        $this->assertSame([], $data['changes']);
        $this->assertNull($data['undo']);
    }

    // ------------------------------------------------------------------ patch: rejections

    public function testMissingCsrfTokenIsRejected(): void
    {
        $response = $this->patch(['Title' => 'X'], csrf: false);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('csrf', $this->json($response)['error']);
        $this->assertSame('Foo', $this->fresh()->Title);
    }

    public function testNonEditableColumnAndMalformedBodyAreRejected(): void
    {
        $this->assertSame(422, $this->patch(['ID' => 99])->getStatusCode());
        $this->assertSame(422, $this->patch(['LastEdited' => 'x'])->getStatusCode());

        $bad = $this->send('POST', 'ywli/inline/patch', body: '{nope');
        $this->assertSame(400, $bad->getStatusCode());
        $empty = $this->send('POST', 'ywli/inline/patch', body: json_encode(['id' => 1, 'etag' => 'x', 'changes' => []]));
        $this->assertSame(400, $empty->getStatusCode());
    }

    public function testRecordOutsideTheGridFieldListIsNotFound(): void
    {
        $other = ToolkitRecord::create(['Title' => 'Other']);
        $other->write();
        $scoped = $this->makeGrid(ToolkitRecord::get()->filter('Title', 'Foo'));

        $response = $this->patch(['Title' => 'Hacked'], 'whatever', grid: $scoped, id: (int) $other->ID);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Other', ToolkitRecord::get()->byID($other->ID)->Title);
    }

    public function testUserWithoutEditPermissionIsForbidden(): void
    {
        $etag = $this->etag();
        $this->logOut();

        $response = $this->patch(['Title' => 'X'], $etag);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('forbidden', $this->json($response)['error']);
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAre422AndNothingIsWritten(array $changes, string $messagePart): void
    {
        $response = $this->patch($changes);
        $data = $this->json($response);

        $this->assertSame(422, $response->getStatusCode(), json_encode($data));
        $this->assertStringContainsString($messagePart, $data['message']);
        $this->assertSame('Foo', $this->fresh()->Title);
        $this->assertSame(3, (int) $this->fresh()->Qty);
    }

    public static function invalidValues(): array
    {
        return [
            'text too long' => [['Title' => str_repeat('x', 21)], '20'],
            'text with newline' => [['Title' => "a\nb"], 'Line breaks'],
            'text not scalar' => [['Title' => ['a']], 'Invalid text'],
            'number above max' => [['Qty' => 100], 'Maximum value is 99'],
            'number below min' => [['Qty' => -1], 'Minimum value is 0'],
            'number fraction on Int' => [['Qty' => '2.5'], 'whole number'],
            'number not numeric' => [['Qty' => 'abc'], 'valid number'],
            'number empty but required' => [['Qty' => ''], 'required'],
            'select unknown option' => [['Status' => 'zzz'], 'Invalid choice'],
            'toggle garbage' => [['IsActive' => 'maybe'], 'toggle'],
        ];
    }

    public function testValidChangesAreAtomicWhenAnotherColumnInTheSamePatchIsInvalid(): void
    {
        $response = $this->patch(['Title' => 'Changed', 'Qty' => 1000]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Foo', $this->fresh()->Title);
    }

    // ------------------------------------------------------------------ editors

    public function testTextIsTrimmedAndDecimalIsRoundedToItsScale(): void
    {
        $data = $this->json($this->patch(['Title' => '  Padded  ', 'Price' => 12.345]));

        $this->assertTrue($data['ok'], json_encode($data));
        $this->assertSame('Padded', $this->fresh()->Title);
        $this->assertEquals(12.35, (float) $this->fresh()->Price);
        $this->assertSame(12.35, $data['changes']['Price']);
    }

    // ------------------------------------------------------------------ dependent selects / options endpoint

    public function testParentChangeClearsAChildThatNoLongerFits(): void
    {
        $data = $this->json($this->patch(['Country' => 'DE']));

        $this->assertTrue($data['ok'], json_encode($data));
        $this->assertSame(['Country' => 'DE', 'City' => ''], $data['changes']);
        $this->assertSame(['Country' => 'AT', 'City' => 'Wien'], $data['previous']);
        $this->assertSame('', (string) $this->fresh()->City);
    }

    public function testParentChangeKeepsAChildThatStillFits(): void
    {
        $this->json($this->patch(['Country' => 'DE', 'City' => 'Berlin']));
        $data = $this->json($this->patch(['Country' => 'DE']));

        $this->assertSame([], $data['changes']);
        $this->assertSame('Berlin', $this->fresh()->City);
    }

    public function testParentAndChildInOnePatchAreValidatedTogether(): void
    {
        $data = $this->json($this->patch(['Country' => 'DE', 'City' => 'Berlin']));

        $this->assertTrue($data['ok'], json_encode($data));
        $this->assertSame('Berlin', $this->fresh()->City);
    }

    public function testKeyOrderInTheRequestBodyDoesNotMatter(): void
    {
        $body = json_encode([
            'id' => $this->record->ID,
            'etag' => $this->etag(),
            'changes' => ['City' => 'Berlin', 'Country' => 'DE'], // child listed first
        ]);

        $data = $this->json($this->send('POST', 'ywli/inline/patch', body: $body));

        $this->assertTrue($data['ok'], json_encode($data));
        $this->assertSame('Berlin', $this->fresh()->City);
        $this->assertSame('DE', $this->fresh()->Country);
    }

    public function testExplicitChildNotValidForTheCurrentParentIsRejected(): void
    {
        $response = $this->patch(['City' => 'Berlin']); // record is in AT

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Wien', $this->fresh()->City);
    }

    public function testOptionsEndpointListsChoicesForTheRecordsCurrentParent(): void
    {
        $response = $this->send('GET', 'ywli/inline/options/City', ['id' => $this->record->ID], csrf: false);
        $data = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [['value' => '', 'label' => ''], ['value' => 'Wien', 'label' => 'Wien'], ['value' => 'Graz', 'label' => 'Graz']],
            $data['options']
        );
    }

    public function testOptionsEndpointRejectsColumnsWithoutOptionsAndUnknownRecords(): void
    {
        $this->assertSame(422, $this->send('GET', 'ywli/inline/options/Title', ['id' => $this->record->ID])->getStatusCode());
        $this->assertSame(404, $this->send('GET', 'ywli/inline/options/City', ['id' => 99999])->getStatusCode());
        $this->assertSame(400, $this->send('GET', 'ywli/inline/options/City')->getStatusCode());
    }
}
