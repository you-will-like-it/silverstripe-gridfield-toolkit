<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Transfer;

use Closure;
use JsonException;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_URLHandler;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use Throwable;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\GridFieldInlineEdit;
use YouWillLikeIT\GridFieldToolkit\Support\ClientMarker;

/**
 * Drop zone: records dragged from an accepted GridFieldTransferSource grid are moved or copied into this grid.
 *
 * What "into this grid" means is the developer's call, given as a closure that only *mutates* the record:
 *
 *   new GridFieldTransferTarget(['Inbox'], function (DataObject $record, GridField $target, string $mode): void {
 *       $record->FolderID = $target->getForm()->getRecord()->ID;
 *   });
 *
 * move: closure receives the persisted record, the toolkit writes it afterwards (needs canEdit on the record).
 * copy: closure receives an unsaved duplicate (`duplicate(false)`), the toolkit writes it (needs canCreate).
 * Each record is its own transaction; failures are reported per record.
 *
 * Endpoint: POST {target}/ywli/transfer/receive  {source, ids, mode}  ->  {ok, processed, failed: [{id, message}]}
 */
class GridFieldTransferTarget extends AbstractGridFieldComponent implements GridField_URLHandler, GridField_HTMLProvider
{
    public const FEATURE = 'transfer-target';

    public const MODES = ['move', 'copy'];

    private const MAX = 200;

    /** @var list<string> */
    private readonly array $modes;

    /**
     * @param list<string> $sources Names of accepted source GridFields
     * @param Closure(DataObject, GridField, string): void $apply
     * @param list<string> $modes Subset of ['move', 'copy']
     */
    public function __construct(private readonly array $sources, private readonly Closure $apply, array $modes = self::MODES)
    {
        $this->modes = array_values(array_intersect(self::MODES, $modes));
    }

    #[\Override]
    public function getHTMLFragments($gridField): array
    {
        return [
            'before' => ClientMarker::html(self::FEATURE, [
                'url' => $gridField->Link('ywli/transfer/receive'),
                'securityID' => SecurityToken::inst()->getValue(),
                'accept' => $this->sources,
                'modes' => $this->modes,
                'strings' => [
                    'move' => _t(self::class . '.MOVE', 'Move here'),
                    'copy' => _t(self::class . '.COPY', 'Copy here'),
                    'cancel' => _t(self::class . '.CANCEL', 'Cancel'),
                    'done' => _t(self::class . '.DONE', '{count} done.'),
                    'partial' => _t(self::class . '.PARTIAL', '{count} done, {failed} failed.'),
                    'failed' => _t(self::class . '.FAILED', 'The transfer failed.'),
                ],
            ]),
        ];
    }

    #[\Override]
    public function getURLHandlers($gridField): array
    {
        return ['POST ywli/transfer/receive' => 'handleReceive'];
    }

    public function handleReceive(GridField $gridField, HTTPRequest $request): HTTPResponse
    {
        if (!SecurityToken::inst()->checkRequest($request)) {
            return GridFieldInlineEdit::jsonError('csrf', 'Security token expired. Reload the page and try again.', 403);
        }

        try {
            $body = json_decode($request->getBody(), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return GridFieldInlineEdit::jsonError('bad_request', 'Malformed JSON body.', 400);
        }

        $sourceName = is_array($body) ? ($body['source'] ?? null) : null;
        $mode = is_array($body) ? ($body['mode'] ?? null) : null;
        $raw = is_array($body) ? ($body['ids'] ?? null) : null;
        if (!is_string($sourceName) || !is_string($mode) || !is_array($raw) || $raw === [] || !array_is_list($raw)) {
            return GridFieldInlineEdit::jsonError('bad_request', 'Expected {source, ids, mode}.', 400);
        }
        if (!in_array($mode, $this->modes, true)) {
            return GridFieldInlineEdit::jsonError('invalid_mode', 'This transfer mode is not allowed here.', 422);
        }
        if (!in_array($sourceName, $this->sources, true)) {
            return GridFieldInlineEdit::jsonError('invalid_source', 'This grid is not accepted here.', 422);
        }
        if (count($raw) > self::MAX) {
            return GridFieldInlineEdit::jsonError('too_many', 'Too many records.', 413);
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                return GridFieldInlineEdit::jsonError('bad_request', 'IDs must be positive integers.', 400);
            }
            $ids[$id] = $id;
        }

        $source = $gridField->getForm()?->Fields()->dataFieldByName($sourceName);
        if (!$source instanceof GridField || $source->getConfig()->getComponentByType(GridFieldTransferSource::class) === null) {
            return GridFieldInlineEdit::jsonError('invalid_source', 'Source grid not found.', 422);
        }

        $member = Security::getCurrentUser();
        $records = [];
        foreach ($source->getList()->filter('ID', array_values($ids)) as $record) {
            $records[(int) $record->ID] = $record;
        }

        $processed = 0;
        $failed = [];
        foreach ($ids as $id) {
            $record = $records[$id] ?? null;
            if (!$record instanceof DataObject || !$record->canView($member)) {
                $failed[] = ['id' => $id, 'message' => 'Record not found.'];
                continue;
            }
            $allowed = $mode === 'move' ? $record->canEdit($member) : $record->canCreate($member);
            if (!$allowed) {
                $failed[] = ['id' => $id, 'message' => 'Not allowed.'];
                continue;
            }

            try {
                DB::get_conn()->withTransaction(function () use ($record, $mode, $gridField): void {
                    $subject = $mode === 'copy' ? $record->duplicate(false) : $record;
                    ($this->apply)($subject, $gridField, $mode);
                    $subject->write();
                });
                ++$processed;
            } catch (ValidationException $e) {
                $failed[] = ['id' => $id, 'message' => $e->getMessage()];
            } catch (Throwable $e) {
                Injector::inst()->get(LoggerInterface::class)->error($e->getMessage(), ['exception' => $e]);
                $failed[] = ['id' => $id, 'message' => 'The transfer failed for this record.'];
            }
        }

        return GridFieldInlineEdit::json(['ok' => true, 'processed' => $processed, 'failed' => $failed]);
    }
}
