<?php

declare(strict_types=1);

namespace ORMBundle\Doctrine;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
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
                        throw $e;
                    }

                    $this->close();

                    throw $e;
                }
            },
            [DriverException::class],
        );
    }

    private function isConnectionLost(DriverException $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        $previous = $e->getPrevious();

        return null !== $previous && 7 === $previous->getCode();
    }
}
