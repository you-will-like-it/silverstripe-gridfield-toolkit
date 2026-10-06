<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Service;

/**
 * Snapshot needed to revert one inline patch. Scalars only, so it survives session serialisation.
 */
final readonly class UndoMemento
{
    /**
     * @param array<string, mixed> $previous Column => value before the patch (only columns that actually changed)
     * @param string               $etag     Row etag right after the patch; a different etag at undo time means a concurrent change
     */
    public function __construct(
        public int $memberID,
        public string $gridField,
        public string $recordClass,
        public int $recordID,
        public array $previous,
        public string $etag,
        public int $expires,
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): ?self
    {
        if (!isset($data['memberID'], $data['gridField'], $data['recordClass'], $data['recordID'], $data['previous'],
            $data['etag'], $data['expires']) || !is_array($data['previous'])
        ) {
            return null;
        }

        return new self(
            (int) $data['memberID'],
            (string) $data['gridField'],
            (string) $data['recordClass'],
            (int) $data['recordID'],
            $data['previous'],
            (string) $data['etag'],
            (int) $data['expires'],
        );
    }
}
