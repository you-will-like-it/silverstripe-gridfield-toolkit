<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\Bulk\Action;

use Closure;
use InvalidArgumentException;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use YouWillLikeIT\GridFieldToolkit\Contract\BulkActionInterface;

/** Bulk action from closures: `new CallbackBulkAction('publish', 'Publish', fn (Page $p) => $p->publishRecursive())`. */
class CallbackBulkAction implements BulkActionInterface
{
    /**
     * @param Closure(DataObject, ?Member): void $run
     * @param (Closure(DataObject, ?Member): bool)|null $can Defaults to canEdit()
     */
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly Closure $run,
        private readonly ?Closure $can = null,
        private readonly bool $destructive = false,
        private readonly ?string $confirm = null,
    ) {
        if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
            throw new InvalidArgumentException("Bulk action name '$name' must match [a-z0-9_-]+.");
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isDestructive(): bool
    {
        return $this->destructive;
    }

    public function getConfirmMessage(): ?string
    {
        return $this->confirm;
    }

    public function canRun(DataObject $record, ?Member $member): bool
    {
        return $this->can ? (bool) ($this->can)($record, $member) : (bool) $record->canEdit($member);
    }

    public function run(DataObject $record, ?Member $member): void
    {
        ($this->run)($record, $member);
    }
}
