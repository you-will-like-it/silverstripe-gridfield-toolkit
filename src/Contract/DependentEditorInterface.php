<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Contract;

use InvalidArgumentException;
use SilverStripe\ORM\DataObject;

/** Editor whose option list depends on another column of the same record (Column B follows Column A). */
interface DependentEditorInterface extends OptionsEditorInterface
{
    public function getDependsOn(): string;

    /**
     * Called after all explicit changes of a patch were assigned and one of {column, dependsOn} was touched.
     * If the current value is no longer a valid option it is cleared (implicit) or rejected (explicit).
     *
     * @param bool $explicit True when this column itself was part of the patch
     * @return bool Whether the record was modified
     * @throws InvalidArgumentException When $explicit and the value is not valid for the current parent value
     */
    public function reconcile(DataObject $record, bool $explicit): bool;
}
