<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Service;

use SilverStripe\Control\Session;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Per-user undo mementos in a scoped session array, keyed by an unguessable single-use token.
 *
 * The UI countdown is purely cosmetic; expiry is enforced here (ttl + a small grace period for latency).
 */
final class UndoStore
{
    public const SESSION_KEY = 'ywli.undo';
    public const GRACE_SECONDS = 10;
    public const MAX_ENTRIES = 25;

    public function __construct(private readonly Session $session)
    {
    }

    /**
     * @return string Token
     */
    public function remember(UndoMemento $memento): string
    {
        $entries = $this->entries();
        $token = bin2hex(random_bytes(16));
        $entries[$token] = $memento->toArray();

        // Oldest first: drop the oldest entries beyond the cap.
        $entries = array_slice($entries, -self::MAX_ENTRIES, null, true);

        $this->session->set(self::SESSION_KEY, $entries);

        return $token;
    }

    /**
     * Fetch and consume a memento. Returns null for unknown, expired, or foreign (other member/grid) tokens.
     * A foreign token is left untouched.
     */
    public function take(string $token, int $memberID, string $gridField): ?UndoMemento
    {
        $entries = $this->entries();
        $memento = isset($entries[$token]) ? UndoMemento::fromArray($entries[$token]) : null;

        if ($memento === null) {
            return null;
        }
        if ($memento->memberID !== $memberID || $memento->gridField !== $gridField) {
            return null;
        }

        unset($entries[$token]);
        $this->session->set(self::SESSION_KEY, $entries);

        return $memento->expires >= $this->now() ? $memento : null;
    }

    public function expiryFor(int $ttlSeconds): int
    {
        return $this->now() + $ttlSeconds + self::GRACE_SECONDS;
    }

    /** @return array<string, array> live entries; expired ones are pruned */
    private function entries(): array
    {
        $entries = $this->session->get(self::SESSION_KEY);
        if (!is_array($entries)) {
            return [];
        }

        $now = $this->now();

        return array_filter(
            $entries,
            static fn (mixed $entry): bool => is_array($entry) && (int) ($entry['expires'] ?? 0) >= $now
        );
    }

    private function now(): int
    {
        return DBDatetime::now()->getTimestamp();
    }
}
