<?php

declare(strict_types=1);

namespace Synap\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Synap\SDK\Exception\SynapException;
use Synap\SDK\SynapConfig;
use Synap\SDK\TransportMode;

final class SynapConfigTest extends TestCase
{
    public function testConfigCreation(): void
    {
        $config = SynapConfig::create('http://localhost:15500');

        $this->assertSame('http://localhost:15500', $config->getBaseUrl());
        $this->assertSame(30, $config->getTimeout());
        $this->assertNull($config->getAuthToken());
        $this->assertSame(3, $config->getMaxRetries());
    }

    public function testWithTimeout(): void
    {
        $config = SynapConfig::create('http://localhost:15500')
            ->withTimeout(60);

        $this->assertSame(60, $config->getTimeout());
    }

    public function testWithAuthToken(): void
    {
        $config = SynapConfig::create('http://localhost:15500')
            ->withAuthToken('test-token');

        $this->assertSame('test-token', $config->getAuthToken());
    }

    public function testWithMaxRetries(): void
    {
        $config = SynapConfig::create('http://localhost:15500')
            ->withMaxRetries(5);

        $this->assertSame(5, $config->getMaxRetries());
    }

    public function testImmutability(): void
    {
        $config1 = SynapConfig::create('http://localhost:15500');
        $config2 = $config1->withTimeout(60);

        $this->assertNotSame($config1, $config2);
        $this->assertSame(30, $config1->getTimeout());
        $this->assertSame(60, $config2->getTimeout());
    }

    /**
     * An empty URL falls back to the same default the constructor's own
     * parameter carries — `synap://127.0.0.1:15501`. The test used to expect a
     * "Base URL cannot be empty" exception, which the constructor stopped
     * throwing when that default was introduced.
     */
    public function testEmptyBaseUrlFallsBackToTheDefaultEndpoint(): void
    {
        $explicitlyEmpty = new SynapConfig('');
        $defaulted = new SynapConfig();

        $this->assertSame(TransportMode::SYNAP_RPC, $explicitlyEmpty->getTransport());
        $this->assertSame($defaulted->getRpcHost(), $explicitlyEmpty->getRpcHost());
        $this->assertSame($defaulted->getRpcPort(), $explicitlyEmpty->getRpcPort());
        $this->assertSame($defaulted->getBaseUrl(), $explicitlyEmpty->getBaseUrl());
    }

    public function testBaseUrlTrimsTrailingSlash(): void
    {
        $config = SynapConfig::create('http://localhost:15500/');

        $this->assertSame('http://localhost:15500', $config->getBaseUrl());
    }
}
