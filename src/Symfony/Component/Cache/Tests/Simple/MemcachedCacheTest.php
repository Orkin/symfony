<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Cache\Tests\Simple;

use PHPUnit\Framework\SkippedTestSuiteError;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Exception\CacheException;
use Symfony\Component\Cache\Simple\MemcachedCache;

/**
 * @group legacy
 * @group integration
 */
class MemcachedCacheTest extends CacheTestCase
{
    protected $skippedTests = [
        'testSetTtl' => 'Testing expiration slows down the test suite',
        'testSetMultipleTtl' => 'Testing expiration slows down the test suite',
        'testDefaultLifeTime' => 'Testing expiration slows down the test suite',
    ];

    protected static $client;

    public static function setUpBeforeClass(): void
    {
        if (!MemcachedCache::isSupported()) {
            throw new SkippedTestSuiteError('Extension memcached '.(\PHP_VERSION_ID >= 80100 ? '> 3.1.5' : '>= 2.2.0').' required.');
        }
        self::$client = AbstractAdapter::createConnection('memcached://'.getenv('MEMCACHED_HOST'));
        self::$client->get('foo');
        $code = self::$client->getResultCode();

        if (\Memcached::RES_SUCCESS !== $code && \Memcached::RES_NOTFOUND !== $code) {
            throw new SkippedTestSuiteError('Memcached error: '.strtolower(self::$client->getResultMessage()));
        }
    }

    public function createSimpleCache(int $defaultLifetime = 0): CacheInterface
    {
        $client = $defaultLifetime ? AbstractAdapter::createConnection('memcached://'.getenv('MEMCACHED_HOST'), ['binary_protocol' => false]) : self::$client;

        return new MemcachedCache($client, str_replace('\\', '.', __CLASS__), $defaultLifetime);
    }

    public function testCreatePersistentConnectionShouldNotDupServerList()
    {
        $instance = MemcachedCache::createConnection('memcached://'.getenv('MEMCACHED_HOST'), ['persistent_id' => 'persistent']);
        $this->assertCount(1, $instance->getServerList());

        $instance = MemcachedCache::createConnection('memcached://'.getenv('MEMCACHED_HOST'), ['persistent_id' => 'persistent']);
        $this->assertCount(1, $instance->getServerList());
    }

    public function testOptions()
    {
        $client = MemcachedCache::createConnection([], [
            'libketama_compatible' => false,
            'distribution' => 'modula',
            'compression' => true,
            'serializer' => 'php',
            'hash' => 'md5',
        ]);

        $this->assertSame(\Memcached::SERIALIZER_PHP, $client->getOption(\Memcached::OPT_SERIALIZER));
        $this->assertSame(\Memcached::HASH_MD5, $client->getOption(\Memcached::OPT_HASH));
        $this->assertTrue($client->getOption(\Memcached::OPT_COMPRESSION));
        $this->assertSame(0, $client->getOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE));
        $this->assertSame(\Memcached::DISTRIBUTION_MODULA, $client->getOption(\Memcached::OPT_DISTRIBUTION));
    }

    /**
     * @dataProvider provideBadOptions
     */
    public function testBadOptions(string $name, $value)
    {
        if (\PHP_VERSION_ID < 80000) {
            $this->expectException(\ErrorException::class);
            $this->expectExceptionMessage('constant(): Couldn\'t find constant Memcached::');
        } else {
            $this->expectException(\Error::class);
            $this->expectExceptionMessage('Undefined constant Memcached::');
        }

        MemcachedCache::createConnection([], [$name => $value]);
    }

    public function provideBadOptions(): array
    {
        return [
            ['foo', 'bar'],
            ['hash', 'zyx'],
            ['serializer', 'zyx'],
            ['distribution', 'zyx'],
        ];
    }

    public function testDefaultOptions()
    {
        $this->assertTrue(MemcachedCache::isSupported());

        $client = MemcachedCache::createConnection([]);

        $this->assertTrue($client->getOption(\Memcached::OPT_COMPRESSION));
        $this->assertSame(1, $client->getOption(\Memcached::OPT_BINARY_PROTOCOL));
        $this->assertSame(1, $client->getOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE));
    }

    public function testOptionSerializer()
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('MemcachedAdapter: "serializer" option must be "php" or "igbinary".');
        if (!\Memcached::HAVE_JSON) {
            $this->markTestSkipped('Memcached::HAVE_JSON required');
        }

        new MemcachedCache(MemcachedCache::createConnection([], ['serializer' => 'json']));
    }

    /**
     * @dataProvider provideServersSetting
     */
    public function testServersSetting(string $dsn, string $host, int $port)
    {
        $client1 = MemcachedCache::createConnection($dsn);
        $client2 = MemcachedCache::createConnection([$dsn]);
        $client3 = MemcachedCache::createConnection([[$host, $port]]);
        $expect = [
            'host' => $host,
            'port' => $port,
        ];

        $f = function ($s) { return ['host' => $s['host'], 'port' => $s['port']]; };
        $this->assertSame([$expect], array_map($f, $client1->getServerList()));
        $this->assertSame([$expect], array_map($f, $client2->getServerList()));
        $this->assertSame([$expect], array_map($f, $client3->getServerList()));
    }

    public function provideServersSetting(): iterable
    {
        yield [
            'memcached://127.0.0.1/50',
            '127.0.0.1',
            11211,
        ];
        yield [
            'memcached://localhost:11222?weight=25',
            'localhost',
            11222,
        ];
        if (filter_var(\ini_get('memcached.use_sasl'), \FILTER_VALIDATE_BOOLEAN)) {
            yield [
                'memcached://user:password@127.0.0.1?weight=50',
                '127.0.0.1',
                11211,
            ];
        }
        yield [
            'memcached:///var/run/memcached.sock?weight=25',
            '/var/run/memcached.sock',
            0,
        ];
        yield [
            'memcached:///var/local/run/memcached.socket?weight=25',
            '/var/local/run/memcached.socket',
            0,
        ];
        if (filter_var(\ini_get('memcached.use_sasl'), \FILTER_VALIDATE_BOOLEAN)) {
            yield [
                'memcached://user:password@/var/local/run/memcached.socket?weight=25',
                '/var/local/run/memcached.socket',
                0,
            ];
        }
    }
}
