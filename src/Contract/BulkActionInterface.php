<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Contract;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/** One action of the stash bulk bar. Runs once per record; the framework collects successes and failures. */
interface BulkActionInterface
{
    /** Stable identifier used in the URL (`[a-z0-9_-]+`). */
    public function getName(): string;

    public function getLabel(): string;

    /** Destructive actions always ask for confirmation. */
    public function isDestructive(): bool;

    /** Optional confirmation text (shown for any action that has one). */
    public function getConfirmMessage(): ?string;

    /** Per-record permission check; a record that fails it is reported, not run. */
    public function canRun(DataObject $record, ?Member $member): bool;

    /** @throws \Throwable Any exception marks this record as failed; the others still run. */
    public function run(DataObject $record, ?Member $member): void;
}
