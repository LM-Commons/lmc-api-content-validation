<?php

declare(strict_types=1);

namespace Lmc\Api\ContentValidation;

use Laminas\InputFilter\InputFilterPluginManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use function assert;
use function is_array;

final class ContentValidationMiddlewareFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container): ContentValidationMiddleware
    {
        /** @var array $config */
        $config                  = $container->get('config') ?? [];
        $restConfig              = [];
        $contentValidationConfig = [];
        if (isset($config['lmc_api'])) {
            assert(is_array($config['lmc_api']));
            /** @var array $restConfig */
            $restConfig = $config['lmc_api']['rest'] ?? [];
            /** @var array $contentValidationConfig */
            $contentValidationConfig = $config['lmc_api']['content_validation'] ?? [];
        }
        /** @psalm-suppress MixedArgument */
        return new ContentValidationMiddleware(
            $contentValidationConfig,
            $restConfig,
            $container->get(InputFilterPluginManager::class),
        );
    }
}
