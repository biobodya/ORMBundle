<?php

declare(strict_types=1);

namespace ORMBundle\Tests\Doctrine;

use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use ORMBundle\DependencyInjection\DBAL\Configuration;
use ORMBundle\Doctrine\PrimaryReadReplicaConnectionWrapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PrimaryReadReplicaConnectionWrapperTest extends TestCase
{
    private PrimaryReadReplicaConnectionWrapper $connection;

    protected function setUp(): void
    {
        $this->connection = new PrimaryReadReplicaConnectionWrapper(...$this->connectionArgs());
    }

    public function testExecWorksWithValidSql(): void
    {
        $this->connection->executeStatement('CREATE TABLE test (id INTEGER)');
        $affected = $this->connection->exec('INSERT INTO test (id) VALUES (1)');
        $this->assertSame(1, $affected);
    }

    public function testExecuteQueryWorksWithValidSql(): void
    {
        $result = $this->connection->executeQuery('SELECT 1 as test');
        $this->assertSame(1, $result->fetchOne());
    }

    public function testExecuteUpdateWorksWithValidSql(): void
    {
        $this->connection->executeStatement('CREATE TABLE test (id INTEGER)');
        $affected = $this->connection->executeUpdate('INSERT INTO test (id) VALUES (1)');
        $this->assertSame(1, $affected);
    }

    public function testQueryWorksWithValidSql(): void
    {
        $result = $this->connection->query('SELECT 1 as test');
        $this->assertSame(1, $result->fetchOne());
    }

    public function testReconnectIfFailSuccess(): void
    {
        $result = $this->connection->reconnectIfFail(static fn () => 'success');
        $this->assertSame('success', $result);
    }

    public function testReconnectIfFailSucceedsOnRetry(): void
    {
        $callCount = 0;
        $result = $this->connection->reconnectIfFail(static function () use (&$callCount) {
            ++$callCount;
            if (1 === $callCount) {
                throw new ConnectionException(new Exception('Connection lost', null, 7), null);
            }

            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertSame(2, $callCount);
    }

    public function testReconnectIfFailRethrowsConnectionExceptionAfterMaxRetries(): void
    {
        $this->expectException(ConnectionException::class);

        $this->connection->reconnectIfFail(static function () {
            throw new ConnectionException(new Exception('Connection lost', null, 7), null);
        });
    }

    public function testReconnectIfFailWithNonConnectionExceptionRethrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->connection->reconnectIfFail(static function () {
            throw new \RuntimeException('Non-connection error');
        });
    }

    /**
     * A dropped connection is often reported by PostgreSQL as a bare DriverException
     * (no SQLSTATE / 'HY000', PDO code 7) that DBAL does NOT convert to ConnectionException.
     * It must still trigger reconnect + retry.
     */
    public function testReconnectIfFailRetriesOnBareDriverExceptionConnectionLoss(): void
    {
        $callCount = 0;
        $result = $this->connection->reconnectIfFail(static function () use (&$callCount) {
            ++$callCount;
            if (1 === $callCount) {
                $pdoException = new Exception('SQLSTATE[HY000]: General error: 7 no connection to the server', null, 7);

                throw new DriverException($pdoException, null);
            }

            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertSame(2, $callCount);
    }

    public function testReconnectIfFailNormalizesBareConnectionLossAfterMaxRetries(): void
    {
        $this->expectException(ConnectionException::class);

        $this->connection->reconnectIfFail(static function () {
            $pdoException = new Exception('SQLSTATE[HY000]: General error: 7 no connection to the server', null, 7);

            throw new DriverException($pdoException, null);
        });
    }

    public function testReconnectIfFailDoesNotRetryDeterministicDriverException(): void
    {
        $callCount = 0;

        try {
            $this->connection->reconnectIfFail(static function () use (&$callCount) {
                ++$callCount;
                $pdoException = new Exception('Undefined table', '42P01', 7);

                throw new DriverException($pdoException, null);
            });
            $this->fail('Expected DriverException was not thrown');
        } catch (DriverException $e) {
            $this->assertSame(1, $callCount);
        }
    }

    public function testReconnectIfFailDoesNotRetryUniqueConstraintViolation(): void
    {
        // Regression: a unique-constraint violation (SQLSTATE 23505, PDO code 7) previously
        // triggered a bogus reconnect+retry, which closed the connection, rolled back the
        // running migration transaction and re-ran the failing statement against a
        // half-reverted schema, masking the real error with a misleading one.
        $callCount = 0;

        try {
            $this->connection->reconnectIfFail(static function () use (&$callCount) {
                ++$callCount;
                $pdoException = new Exception('could not create unique index', '23505', 7);

                throw new UniqueConstraintViolationException($pdoException, null);
            });
            $this->fail('Expected UniqueConstraintViolationException was not thrown');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame(1, $callCount);
        }
    }

    public function testClosesConnectionOnBareDriverExceptionConnectionLoss(): void
    {
        $connection = $this->createConnectionMockWithCloseSpy();

        $connection->expects($this->once())
            ->method('close')
        ;

        $callCount = 0;

        $connection->reconnectIfFail(static function () use (&$callCount) {
            ++$callCount;
            if (1 === $callCount) {
                $pdoException = new Exception('SQLSTATE[HY000]: General error: 7 no connection to the server', null, 7);

                throw new DriverException($pdoException, null);
            }

            return 'success';
        });
    }

    public function testDoesNotCloseConnectionOnDeterministicError(): void
    {
        $connection = $this->createConnectionMockWithCloseSpy();

        $connection->expects($this->never())
            ->method('close')
        ;

        try {
            $connection->reconnectIfFail(static function () {
                $pdoException = new Exception('could not create unique index', '23505', 7);

                throw new UniqueConstraintViolationException($pdoException, null);
            });
            $this->fail('Expected UniqueConstraintViolationException was not thrown');
        } catch (UniqueConstraintViolationException $e) {
            // expected: no reconnect for a deterministic error
        }
    }

    public function testClosesConnectionOnConnectionException(): void
    {
        $connection = $this->createConnectionMockWithCloseSpy();

        $connection->expects($this->once())
            ->method('close')
        ;

        $callCount = 0;

        $connection->reconnectIfFail(static function () use (&$callCount) {
            ++$callCount;
            if (1 === $callCount) {
                throw new ConnectionException(new Exception('Connection lost', null, 7), null);
            }

            return 'success';
        });
    }

    public function testUsesCustomRetryOptions(): void
    {
        $params = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'primary' => ['memory' => true],
            'replica' => [['memory' => true]],
        ];
        $config = new Configuration();
        $config->setBackoffOptions(['max_retries' => 3]);
        $driver = DriverManager::getConnection($params, $config)->getDriver();
        $connection = new PrimaryReadReplicaConnectionWrapper($params, $driver, $config);

        $callCount = 0;

        try {
            $connection->reconnectIfFail(static function () use (&$callCount) {
                ++$callCount;

                throw new ConnectionException(new Exception('Connection lost', null, 7), null);
            });
            $this->fail('Expected ConnectionException was not thrown');
        } catch (ConnectionException $e) {
            $this->assertSame(3, $callCount); // honors configured max_retries
        }
    }

    private function createConnectionMockWithCloseSpy(): PrimaryReadReplicaConnectionWrapper&MockObject
    {
        return $this->getMockBuilder(PrimaryReadReplicaConnectionWrapper::class)
            ->setConstructorArgs($this->connectionArgs())
            ->onlyMethods(['close'])
            ->getMock()
        ;
    }

    /**
     * @return array{0: array<string, mixed>, 1: \Doctrine\DBAL\Driver, 2: Configuration}
     */
    private function connectionArgs(): array
    {
        $params = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'primary' => ['memory' => true],
            'replica' => [['memory' => true]],
        ];
        $config = new Configuration();
        $driver = DriverManager::getConnection($params, $config)->getDriver();

        return [$params, $driver, $config];
    }
}
