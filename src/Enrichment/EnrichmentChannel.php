<?php

declare(strict_types=1);

namespace Knossos\Enrichment;

use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Worker\RpcChannelInterface;
use Knossos\Scanner\Worker\WorkerException;

/**
 * The channel to an enricher, keeping its contributions raw.
 *
 * The protocol session decodes a contribution the moment it arrives and, for
 * a language worker, turns a malformed fact into an empty file with an error.
 * An enricher's output is handled differently: a missing confidence is
 * defaulted before decoding, and anything else malformed fails the whole run
 * so its previous facts are kept. So this channel takes each contribution
 * notification out of the stream before the session sees it, and hands the
 * session a notification it ignores in its place.
 *
 * It also holds the run to one deadline: each request's deadline is the
 * earlier of its own and the run's, so an enricher's `timeout_seconds`
 * bounds the handshake, the scan and the shutdown together.
 */
final class EnrichmentChannel implements RpcChannelInterface
{
    /** A notification method the session neither decodes nor rejects. */
    private const TAKEN = 'knossos/enrichment_taken';

    /** @var list<mixed> the params of each contribution notification, in arrival order */
    private array $contributions = [];

    /** @param int $runDeadline hrtime nanoseconds by which the whole run must be over */
    public function __construct(
        private readonly RpcChannelInterface $inner,
        private readonly int $runDeadline = PHP_INT_MAX,
    ) {}

    /** {@inheritDoc} */
    public function beginRequest(): int
    {
        if (hrtime(true) >= $this->runDeadline) {
            throw new WorkerException('WORKER_TIMEOUT', 'Enricher ran past its timeout.');
        }

        return min($this->inner->beginRequest(), $this->runDeadline);
    }

    /** {@inheritDoc} */
    public function send(array $message, ?callable $cancelled = null): void
    {
        $this->inner->send($message, $cancelled);
    }

    /** {@inheritDoc} */
    public function readMessage(int $deadline, ?callable $cancelled = null): array
    {
        $message = $this->inner->readMessage($deadline, $cancelled);
        if (!array_key_exists('id', $message) && ($message['method'] ?? null) === Protocol::NOTIFICATION_CONTRIBUTION) {
            $this->contributions[] = $message['params'] ?? null;

            return ['jsonrpc' => '2.0', 'method' => self::TAKEN];
        }

        return $message;
    }

    /**
     * The raw params of every contribution taken so far, and forget them.
     *
     * @return list<mixed>
     */
    public function takeContributions(): array
    {
        $taken = $this->contributions;
        $this->contributions = [];

        return $taken;
    }
}
