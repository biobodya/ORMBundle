<?php

declare(strict_types=1);

namespace ORMBundle\Doctrine;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverInterfaceException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Result;
use ORMBundle\Backoff\BackoffFactoryInterface;
use ORMBundle\DependencyInjection\DBAL\Configuration;

/**
 * @mixin Connection
 */
trait ReconnectTrait
{
    private ?BackoffFactoryInterface $backoffFactory = null;
    private array $backoffOptions = [];

    public function executeQuery($query, array $params = [], $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        return $this->reconnectIfFail(
            fn () => parent::executeQuery($query, $params, $types, $qcp),
        );
    }

    public function executeUpdate($query, array $params = [], array $types = []): int
    {
        return $this->reconnectIfFail(
            fn () => parent::executeStatement($query, $params, $types),
        );
    }

    public function query(...$args): Result
    {
        return $this->reconnectIfFail(
            fn () => parent::executeQuery(...$args),
        );
    }

    public function exec($statement): int
    {
        return $this->reconnectIfFail(
            fn () => parent::executeStatement($statement),
        );
    }

    private function getBackoffFactory(): BackoffFactoryInterface
    {
        if (null === $this->backoffFactory) {
            /** @var Configuration $configuration */
            $configuration = $this->getConfiguration();
            $this->backoffFactory = $configuration->getBackoffFactory();
            $this->backoffOptions = $configuration->getBackoffOptions();
        }

        return $this->backoffFactory;
    }

    public function reconnectIfFail(callable $action): mixed
    {
        $backoff = $this->getBackoffFactory()->create($this->backoffOptions);

        return $backoff->attempt(
            function () use ($action) {
                try {
                    return $action();
                } catch (DriverException $e) {
                    if (!$this->isConnectionLost($e)) {
                        // Deterministic errors (unique/not-null/foreign-key violation,
                        // undefined column, syntax error, ...) must be surfaced as-is:
                        // never reconnect (that would roll back an open transaction and
                        // mask the real error) and never retry.
                        throw $e;
                    }

                    // The connection is dead: drop it so the next operation opens a fresh
                    // one. Without this the broken connection stays in the pool and, e.g.,
                    // a worker keeps failing every message even after the DB recovers.
                    $this->close();

                    if ($e instanceof ConnectionException) {
                        throw $e;
                    }

                    // PostgreSQL frequently reports a dropped connection as a bare
                    // DriverException (SQLSTATE HY000 / none, PDO code 7) that DBAL does
                    // not convert to a ConnectionException. Normalize it so the backoff
                    // retries it and callers get a meaningful exception type.
                    $driverException = $e->getPrevious();

                    throw $driverException instanceof DriverInterfaceException ? new ConnectionException($driverException, null) : $e;
                }
            },
            [ConnectionException::class],
        );
    }

    /**
     * A connection loss is reported either without a SQLSTATE or with the generic
     * 'HY000' (PostgreSQL: PDO code 7 / PGRES_FATAL_ERROR) — in that case DBAL leaves it
     * as a bare DriverException instead of a ConnectionException — or with SQLSTATE
     * class 08 (connection exception) / 57P0x (admin or crash shutdown).
     *
     * Deterministic server-side errors always carry a specific SQLSTATE (class 23 =
     * integrity constraint, 42 = syntax/access rule, ...), so they are NOT treated as
     * connection losses even though every fatal PostgreSQL error shares PDO code 7.
     */
    private function isConnectionLost(DriverException $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        $sqlState = $e->getSQLState();

        if (null === $sqlState || 'HY000' === $sqlState) {
            return true;
        }

        return \str_starts_with($sqlState, '08') || \in_array($sqlState, ['57P01', '57P02', '57P03'], true);
    }
}
