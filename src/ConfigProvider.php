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
        return [
            'factories' => [
                ContentValidationMiddleware::class => ContentValidationMiddlewareFactory::class,
            ],
        ];
    }

    private function getLmcApiConfig(): array
    {
        return [
            'content_validation' => [],
        ];
    }
}
