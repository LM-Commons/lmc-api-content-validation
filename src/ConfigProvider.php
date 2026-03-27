<?php

declare(strict_types=1);

namespace Lmc\Api\ContentValidation;

final class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
            'lmc_api'      => $this->getLmcApiConfig(),
        ];
    }

    private function getDependencies(): array
    {
        return [];
    }

    private function getLmcApiConfig(): array
    {
        return [];
    }
}
