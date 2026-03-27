<?php

declare(strict_types=1);

namespace LmcTest\Api\ContentValidation;

use Lmc\Api\ContentValidation\ConfigProvider;
use PHPUnit\Framework\TestCase;

final class ConfigProviderTest extends TestCase
{
    public function testConfigProvider(): void
    {
        $configProvider = new ConfigProvider();
        $this->assertIsArray($configProvider());
        $this->assertArrayHasKey('dependencies', $configProvider());
        $this->assertArrayHasKey('lmc_api', $configProvider());
    }
}
