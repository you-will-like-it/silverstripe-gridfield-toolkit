<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Bulk\Action;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/** Deletes records the member may delete (`canDelete()`); on versioned objects that is the draft delete. */
class DeleteBulkAction extends CallbackBulkAction
{
    public function __construct(string $name = 'delete', ?string $label = null, ?string $confirm = null)
    {
        parent::__construct(
            $name,
            $label ?? _t(self::class . '.LABEL', 'Delete'),
            static function (DataObject $record, ?Member $member): void {
                $record->delete();
            },
            static fn (DataObject $record, ?Member $member): bool => (bool) $record->canDelete($member),
            destructive: true,
            confirm: $confirm ?? _t(self::class . '.CONFIRM', 'Delete the selected records?'),
        );
    }
}
