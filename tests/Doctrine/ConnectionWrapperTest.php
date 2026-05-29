<?php

declare(strict_types=1);

namespace ORMBundle\Tests\Doctrine;

use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use ORMBundle\DependencyInjection\DBAL\Configuration;
use ORMBundle\Doctrine\ConnectionWrapper;
use PHPUnit\Framework\TestCase;

class ConnectionWrapperTest extends TestCase
{
    private ConnectionWrapper $connection;

    protected function setUp(): void
    {
        $params = ['driver' => 'pdo_sqlite', 'memory' => true];
        $config = new Configuration();
        $driver = DriverManager::getConnection($params, $config)->getDriver();

        $this->connection = new ConnectionWrapper($params, $driver, $config);
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

    public function testReconnectIfFailRetriesOnDriverExceptionWithCode7(): void
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

    public function testReconnectIfFailRethrowsDriverExceptionWithCode7AfterMaxRetries(): void
    {
        $callCount = 0;

        $this->expectException(DriverException::class);

        $this->connection->reconnectIfFail(static function () use (&$callCount) {
            ++$callCount;
            $pdoException = new Exception('SQLSTATE[HY000]: General error: 7 no connection to the server', null, 7);

            throw new DriverException($pdoException, null);
        });

        $this->assertSame(5, $callCount);
    }

    public function testReconnectIfFailDoesNotRetryDriverExceptionWithNonConnectionCode(): void
    {
        $callCount = 0;

        $this->expectException(DriverException::class);

        $this->connection->reconnectIfFail(static function () use (&$callCount) {
            ++$callCount;
            $pdoException = new Exception('SQLSTATE[42P01]: Undefined table', null, 0);

            throw new DriverException($pdoException, null);
        });

        $this->assertSame(1, $callCount);
    }

    public function testClosesConnectionOnDriverExceptionWithCode7(): void
    {
        $params = ['driver' => 'pdo_sqlite', 'memory' => true];
        $config = new Configuration();
        $driver = DriverManager::getConnection($params, $config)->getDriver();

        $connection = $this->getMockBuilder(ConnectionWrapper::class)
            ->setConstructorArgs([$params, $driver, $config])
            ->onlyMethods(['close'])
            ->getMock()
        ;

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

    public function testUsesCustomRetryOptions(): void
    {
        $params = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'driverOptions' => [
                'backoff_options' => [
                    'max_attempts' => 3,
                ],
            ],
        ];
        $config = new Configuration();
        $driver = DriverManager::getConnection($params, $config)->getDriver();
        $connection = new ConnectionWrapper($params, $driver, $config);

        $callCount = 0;
        $driverException = new Exception('Connection lost');

        $this->expectException(ConnectionException::class);

        $connection->reconnectIfFail(static function () use ($driverException, &$callCount) {
            ++$callCount;

            throw new ConnectionException($driverException, null);
        });

        $this->assertSame(3, $callCount); // Custom max_attempts
    }

    public function testClosesConnectionOnConnectionException(): void
    {
        $params = ['driver' => 'pdo_sqlite', 'memory' => true];
        $config = new Configuration();
        $driver = DriverManager::getConnection($params, $config)->getDriver();

        $connection = $this->getMockBuilder(ConnectionWrapper::class)
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
