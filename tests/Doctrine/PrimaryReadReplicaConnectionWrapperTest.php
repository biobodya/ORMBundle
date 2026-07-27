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
use PHPUnit\Framework\TestCase;

class PrimaryReadReplicaConnectionWrapperTest extends TestCase
{
    private PrimaryReadReplicaConnectionWrapper $connection;

    protected function setUp(): void
    {
        $params = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'primary' => ['memory' => true],
            'replica' => [['memory' => true]],
        ];
        $config = new Configuration();
        $driver = DriverManager::getConnection($params, $config)->getDriver();

        $this->connection = new PrimaryReadReplicaConnectionWrapper($params, $driver, $config);
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
                $driverException = new Exception('Connection lost');

                throw new ConnectionException($driverException, null);
            }

            return 'success';
        });

        $this->assertSame('success', $result);
    }

    public function testReconnectIfFailRethrowsConnectionExceptionAfterMaxRetries(): void
    {
        $callCount = 0;
        $driverException = new Exception('Connection lost');

        $this->expectException(ConnectionException::class);

        $this->connection->reconnectIfFail(static function () use ($driverException, &$callCount) {
            ++$callCount;

            throw new ConnectionException($driverException, null);
        });

        $this->assertSame(6, $callCount); // 1 initial attempt + 5 retries (default)
    }

    public function testReconnectIfFailWithNonConnectionExceptionRethrows(): void
    {
        $exception = new \RuntimeException('Non-connection error');

        $this->expectException(\RuntimeException::class);

        $this->connection->reconnectIfFail(static function () use ($exception) {
            throw $exception;
        });
    }

    public function testReconnectIfFailDoesNotRetryPlainDriverExceptionWithCode7(): void
    {
        // Every fatal PostgreSQL error carries driver code 7 (PGRES_FATAL_ERROR).
        // A bare DriverException (not classified as ConnectionException by DBAL) is
        // deterministic and must be surfaced immediately, without retrying.
        $callCount = 0;

        try {
            $this->connection->reconnectIfFail(static function () use (&$callCount) {
                ++$callCount;
                $pdoException = new Exception('SQLSTATE[42703]: Undefined column: 7 ERROR', null, 7);

                throw new DriverException($pdoException, null);
            });
            $this->fail('Expected DriverException was not thrown');
        } catch (DriverException $e) {
            $this->assertSame(1, $callCount);
        }
    }

    public function testReconnectIfFailDoesNotRetryUniqueConstraintViolation(): void
    {
        // Regression: a unique-constraint violation (driver code 7) previously
        // triggered a bogus reconnect+retry, which closed the connection, rolled back
        // the running migration transaction and re-ran the failing statement against a
        // half-reverted schema, masking the real error with a misleading one.
        $callCount = 0;

        try {
            $this->connection->reconnectIfFail(static function () use (&$callCount) {
                ++$callCount;
                $pdoException = new Exception(
                    'SQLSTATE[23505]: Unique violation: 7 ERROR: could not create unique index',
                    null,
                    7,
                );

                throw new UniqueConstraintViolationException($pdoException, null);
            });
            $this->fail('Expected UniqueConstraintViolationException was not thrown');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame(1, $callCount);
        }
    }

    public function testReconnectIfFailDoesNotRetryDriverExceptionWithNonConnectionCode(): void
    {
        $callCount = 0;

        try {
            $this->connection->reconnectIfFail(static function () use (&$callCount) {
                ++$callCount;
                $pdoException = new Exception('SQLSTATE[42P01]: Undefined table', null, 0);

                throw new DriverException($pdoException, null);
            });
            $this->fail('Expected DriverException was not thrown');
        } catch (DriverException $e) {
            $this->assertSame(1, $callCount);
        }
    }

    public function testDoesNotCloseConnectionOnPlainDriverExceptionWithCode7(): void
    {
        $params = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'primary' => ['memory' => true],
            'replica' => [['memory' => true]],
        ];
        $config = new Configuration();
        $driver = DriverManager::getConnection($params, $config)->getDriver();

        $connection = $this->getMockBuilder(PrimaryReadReplicaConnectionWrapper::class)
            ->setConstructorArgs([$params, $driver, $config])
            ->onlyMethods(['close'])
            ->getMock()
        ;

        $connection->expects($this->never())
            ->method('close')
        ;

        try {
            $connection->reconnectIfFail(static function () {
                $pdoException = new Exception('SQLSTATE[23505]: Unique violation: 7 ERROR', null, 7);

                throw new DriverException($pdoException, null);
            });
            $this->fail('Expected DriverException was not thrown');
        } catch (DriverException $e) {
            // expected: no reconnect for a deterministic driver error
        }
    }

    public function testUsesCustomRetryOptions(): void
    {
        $params = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'primary' => [
                'memory' => true,
                'driverOptions' => [
                    'backoff_options' => [
                        'max_attempts' => 3,
                    ],
                ],
            ],
            'replica' => [['memory' => true]],
        ];
        $config = new Configuration();
        $driver = DriverManager::getConnection($params, $config)->getDriver();
        $connection = new PrimaryReadReplicaConnectionWrapper($params, $driver, $config);

        $callCount = 0;
        $driverException = new Exception('Connection lost');

        $this->expectException(ConnectionException::class);

        $connection->reconnectIfFail(static function () use ($driverException, &$callCount) {
            ++$callCount;

            throw new ConnectionException($driverException, null);
        });

        $this->assertSame(3, $callCount); // Custom max_attempts from primary section
    }

    public function testExtractsRetryOptionsFromPrimarySection(): void
    {
        $params = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'primary' => [
                'memory' => true,
                'driverOptions' => [
                    'backoff_options' => [
                        'max_attempts' => 2, // Different from default
                    ],
                ],
            ],
            'replica' => [
                ['memory' => true, 'driverOptions' => ['backoff_options' => ['max_attempts' => 5]]], // Should be ignored
            ],
            'driverOptions' => [
                'backoff_options' => ['max_attempts' => 7], // Should be ignored for primary-replica
            ],
        ];
        $config = new Configuration();
        $driver = DriverManager::getConnection($params, $config)->getDriver();
        $connection = new PrimaryReadReplicaConnectionWrapper($params, $driver, $config);

        $callCount = 0;
        $driverException = new Exception('Connection lost');

        $this->expectException(ConnectionException::class);

        $connection->reconnectIfFail(static function () use ($driverException, &$callCount) {
            ++$callCount;

            throw new ConnectionException($driverException, null);
        });

        $this->assertSame(2, $callCount); // Should use primary section, not replica or root
    }

    public function testClosesConnectionOnConnectionException(): void
    {
        $params = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'primary' => ['memory' => true],
            'replica' => [['memory' => true]],
        ];
        $config = new Configuration();
        $driver = DriverManager::getConnection($params, $config)->getDriver();

        $connection = $this->getMockBuilder(PrimaryReadReplicaConnectionWrapper::class)
            ->setConstructorArgs([$params, $driver, $config])
            ->onlyMethods(['close'])
            ->getMock()
        ;

        $connection->expects($this->once())
            ->method('close')
        ;

        $callCount = 0;
        $driverException = new Exception('Connection lost');

        $connection->reconnectIfFail(static function () use (&$callCount, $driverException) {
            ++$callCount;
            if (1 === $callCount) {
                throw new ConnectionException($driverException, null);
            }

            return 'success';
        });
    }
}
