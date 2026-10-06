<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Tests;

use SilverStripe\ORM\FieldType\DBDatetime;
use YouWillLikeIT\GridFieldToolkit\Service\UndoStore;

class GridFieldUndoManagerTest extends InlineEditTestCase
{
    private function undoUrl(array $patchResponse): string
    {
        $this->assertIsArray($patchResponse['undo'], json_encode($patchResponse));
        $this->assertSame(5, $patchResponse['undo']['ttl']);
        $this->assertMatchesRegularExpression('#/field/Records/ywli/undo/[a-f0-9]{32}$#', $patchResponse['undo']['url']);

        // Dispatch through the same GridField router, relative to the GridField.
        return 'ywli/undo/' . $patchResponse['undo']['token'];
    }

    public function testUndoRestoresThePreviousValuesAndConsumesTheToken(): void
    {
        $patch = $this->json($this->patch(['Title' => 'Changed', 'Qty' => 9]));
        $url = $this->undoUrl($patch);

        $undo = $this->send('POST', $url);
        $data = $this->json($undo);

        $this->assertSame(200, $undo->getStatusCode(), json_encode($data));
        $this->assertSame('Foo', $this->fresh()->Title);
        $this->assertSame(3, (int) $this->fresh()->Qty);
        $this->assertStringContainsString('Foo', $data['cells']['Title']);
        $this->assertSame($this->etag(), $data['etag']);

        $again = $this->send('POST', $url);
        $this->assertSame(410, $again->getStatusCode(), 'single use');
        $this->assertSame('undo_expired', $this->json($again)['error']);
    }

    public function testUndoAlsoRestoresAChildThatWasClearedByTheParentChange(): void
    {
        $patch = $this->json($this->patch(['Country' => 'DE']));
        $this->assertSame('', (string) $this->fresh()->City);

        $this->assertSame(200, $this->send('POST', $this->undoUrl($patch))->getStatusCode());

        $this->assertSame('AT', $this->fresh()->Country);
        $this->assertSame('Wien', $this->fresh()->City);
    }

    public function testUndoIsRefusedWith409WhenTheRecordChangedInTheMeantime(): void
    {
        $patch = $this->json($this->patch(['Title' => 'Mine']));
        $this->patch(['Title' => 'Someone else'], $patch['etag']);

        $response = $this->send('POST', $this->undoUrl($patch));
        $data = $this->json($response);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('conflict', $data['error']);
        $this->assertSame('Someone else', $this->fresh()->Title);
        $this->assertStringContainsString('Someone else', $data['cells']['Title']);
    }

    public function testUndoExpiresServerSideRegardlessOfTheClientCountdown(): void
    {
        DBDatetime::set_mock_now('2026-10-04 10:00:00');
        $patch = $this->json($this->patch(['Title' => 'Changed']));
        $url = $this->undoUrl($patch);

        DBDatetime::set_mock_now('2026-10-04 10:00:' . (5 + UndoStore::GRACE_SECONDS + 1));
        $response = $this->send('POST', $url);

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame('Changed', $this->fresh()->Title);
    }

    public function testUndoStillWorksInsideTheGracePeriod(): void
    {
        DBDatetime::set_mock_now('2026-10-04 10:00:00');
        $url = $this->undoUrl($this->json($this->patch(['Title' => 'Changed'])));

        DBDatetime::set_mock_now('2026-10-04 10:00:07'); // past the 5s UI countdown, inside ttl + grace

        $this->assertSame(200, $this->send('POST', $url)->getStatusCode());
        $this->assertSame('Foo', $this->fresh()->Title);
    }

    public function testUnknownMalformedAndForeignTokensAreGone(): void
    {
        $this->assertSame(410, $this->send('POST', 'ywli/undo/' . str_repeat('a', 32))->getStatusCode());
        $this->assertSame(410, $this->send('POST', 'ywli/undo/not-a-token')->getStatusCode());

        $url = $this->undoUrl($this->json($this->patch(['Title' => 'Changed'])));
        $other = $this->createMemberWithPermission('CMS_ACCESS_LeftAndMain');
        $this->logInAs($other);
        $this->assertSame(410, $this->send('POST', $url)->getStatusCode());
        $this->assertSame('Changed', $this->fresh()->Title);
    }

    public function testUndoRequiresTheCsrfToken(): void
    {
        $url = $this->undoUrl($this->json($this->patch(['Title' => 'Changed'])));

        $this->assertSame(403, $this->send('POST', $url, csrf: false)->getStatusCode());
        $this->assertSame('Changed', $this->fresh()->Title);
    }

    public function testNoUndoPayloadWhenUndoIsNotEnabled(): void
    {
        $grid = $this->makeGrid(\YouWillLikeIT\GridFieldToolkit\Tests\Fixtures\ToolkitRecord::get(), undo: false);

        $data = $this->json($this->patch(['Title' => 'Changed'], grid: $grid));

        $this->assertTrue($data['ok']);
        $this->assertNull($data['undo']);
    }

    public function testStoreIsCappedAndPrunesExpiredEntries(): void
    {
        DBDatetime::set_mock_now('2026-10-04 10:00:00');
        $session = $this->session;
        $store = new UndoStore($session);
        $make = fn (int $i) => new \YouWillLikeIT\GridFieldToolkit\Service\UndoMemento(1, 'Records', 'X', $i, ['a' => 1], 'e', $store->expiryFor(5));

        $first = $store->remember($make(0));
        for ($i = 1; $i <= UndoStore::MAX_ENTRIES; $i++) {
            $store->remember($make($i));
        }
        $this->assertNull($store->take($first, 1, 'Records'), 'oldest entry evicted beyond the cap');
        $this->assertCount(UndoStore::MAX_ENTRIES, $session->get(UndoStore::SESSION_KEY));

        DBDatetime::set_mock_now('2026-10-04 10:05:00');
        $store->remember($make(99));
        $this->assertCount(1, $session->get(UndoStore::SESSION_KEY), 'expired entries pruned');
    }
}
