<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\InlineEdit;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_URLHandler;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use YouWillLikeIT\GridFieldToolkit\Service\UndoMemento;
use YouWillLikeIT\GridFieldToolkit\Service\UndoStore;

/**
 * Opt-in undo for inline edits. Keeps no UI of its own: GridFieldInlineEdit asks it to remember a patch and
 * returns the resulting {token, ttl, url} to the client, which shows the toast.
 *
 * Endpoint: POST {gridfield}/ywli/undo/{token}  (X-SecurityID header)
 */
class GridFieldUndoManager extends AbstractGridFieldComponent implements GridField_URLHandler
{
    private readonly int $seconds;

    public function __construct(int $seconds = 5)
    {
        $this->seconds = max(2, min(60, $seconds));
    }

    public function getSeconds(): int
    {
        return $this->seconds;
    }

    /**
     * @param array<string, mixed> $previous
     * @return array{token: string, ttl: int, url: string, message: string, label: string}
     */
    public function remember(
        GridField $gridField,
        HTTPRequest $request,
        DataObject $record,
        array $previous,
        string $etag,
        ?Member $member,
    ): array {
        $store = new UndoStore($request->getSession());
        $token = $store->remember(new UndoMemento(
            memberID: (int) $member?->ID,
            gridField: $gridField->getName(),
            recordClass: $record::class,
            recordID: (int) $record->ID,
            previous: $previous,
            etag: $etag,
            expires: $store->expiryFor($this->seconds),
        ));

        return [
            'token' => $token,
            'ttl' => $this->seconds,
            'url' => $gridField->Link('ywli/undo/' . $token),
            'message' => _t(self::class . '.SAVED', 'Change saved.'),
            'label' => _t(self::class . '.UNDO', 'Undo'),
        ];
    }

    #[\Override]
    public function getURLHandlers($gridField): array
    {
        return ['POST ywli/undo/$Token' => 'handleUndo'];
    }

    public function handleUndo(GridField $gridField, HTTPRequest $request): HTTPResponse
    {
        $inline = $gridField->getConfig()->getComponentByType(GridFieldInlineEdit::class);
        if (!$inline instanceof GridFieldInlineEdit) {
            return GridFieldInlineEdit::jsonError('not_found', 'Inline edit is not enabled.', 404);
        }

        if (!SecurityToken::inst()->checkRequest($request)) {
            return GridFieldInlineEdit::jsonError(
                'csrf',
                _t(GridFieldInlineEdit::class . '.CSRF', 'Security token expired. Reload the page and try again.'),
                403
            );
        }

        $token = (string) $request->param('Token');
        $member = Security::getCurrentUser();
        $memento = preg_match('/^[a-f0-9]{32}$/', $token) === 1 && $member !== null
            ? (new UndoStore($request->getSession()))->take($token, (int) $member->ID, $gridField->getName())
            : null;

        if ($memento === null) {
            return GridFieldInlineEdit::jsonError(
                'undo_expired',
                _t(self::class . '.EXPIRED', 'The undo period has expired.'),
                410
            );
        }

        return $inline->revert($gridField, $memento, $member);
    }
}
