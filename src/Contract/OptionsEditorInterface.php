<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Contract;

use SilverStripe\ORM\DataObject;

/** Editor choosing from a list. Option lists are ordered pairs because JSON objects reorder numeric keys. */
interface OptionsEditorInterface extends InlineEditorInterface
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function getOptions(DataObject $record): array;

    /** True when the list does not depend on the record, so it can be shipped once in the client schema. */
    public function isStatic(): bool;
}
