<?php

declare(strict_types=1);

namespace ORMBundle\Doctrine;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
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
                } catch (ConnectionException $e) {
                    // Only genuine connection losses are retryable. DBAL's driver
                    // ExceptionConverter already classifies them as ConnectionException
                    // (SQLSTATE class 08, e.g. 08006 "server closed the connection").
                    // Every other DriverException (unique/not-null/foreign-key violation,
                    // undefined column, syntax error, ...) is deterministic and must NOT
                    // be retried: it propagates unchanged so the real error is surfaced.
                    $this->close();

                    throw $e;
                }
            },
            [ConnectionException::class],
        );
    }
}
