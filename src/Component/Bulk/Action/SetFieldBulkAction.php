<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Bulk\Action;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/** Sets one field to a fixed value on every selected record: `new SetFieldBulkAction('archive', 'Archive', 'Status', 'archived')`. */
class SetFieldBulkAction extends CallbackBulkAction
{
    public function __construct(string $name, string $label, string $field, string|int|float|bool|null $value, ?string $confirm = null)
    {
        parent::__construct(
            $name,
            $label,
            static function (DataObject $record, ?Member $member) use ($field, $value): void {
                $record->setField($field, $value);
                $record->write();
            },
            confirm: $confirm,
        );
    }
}
