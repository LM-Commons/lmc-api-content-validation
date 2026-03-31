<?php

declare(strict_types=1);

namespace Lmc\Api\ContentValidation;

use Laminas\InputFilter\CollectionInputFilter;
use Laminas\InputFilter\InputFilter;
use Laminas\InputFilter\InputFilterInterface;
use Laminas\InputFilter\InputFilterPluginManager;
use Laminas\InputFilter\UnknownInputsCapableInterface;
use Laminas\Stdlib\ArrayUtils;
use Lmc\Api\Problem\ApiProblem;
use Lmc\Api\Problem\ApiProblemResponse;
use Mezzio\Router\RouteResult;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function sprintf;
use function strtoupper;

use const ARRAY_FILTER_USE_BOTH;

final class ContentValidationMiddleware implements MiddlewareInterface
{
    protected array $methodsWithoutBodies = [
        'GET',
        'HEAD',
        'OPTIONS',
    ];

    /** @var array<InputFilterInterface>  */
    protected array $inputFilters = [];

    public function __construct(
        private array $config,
        private array $restConfig,
        private InputFilterPluginManager $inputFilterPluginManager,
    ) {
        if (isset($config['methods_without_bodies']) && is_array($config['methods_without_bodies'])) {
            /** @var string $method */
            foreach ($config['methods_without_bodies'] as $method) {
                $this->addMethodWithoutBody($method);
            }
        }
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var RouteResult $routeResult */
        $routeResult      = $request->getAttribute(RouteResult::class);
        $routeMatchedName = $routeResult->getMatchedRouteName();
        // just in case, if there is no route
        if (false === $routeMatchedName) {
            return $handler->handle($request);
        }
        $method = $request->getMethod();

        $data = $receivedData = in_array($method, $this->methodsWithoutBodies)
            ? $request->getQueryParams()
            : $request->getParsedBody();

        if (null === $data) {
            $data = [];
        }

        $isCollection       = $this->isCollection($routeMatchedName, $data, $request);
        $inputFilterService = $this->getInputFilterService($routeMatchedName, $request->getMethod(), $isCollection);

        if (false === $inputFilterService) {
            return $handler->handle($request);
        }

        if (! $this->hasInputFilter($inputFilterService)) {
            return new ApiProblemResponse(
                new ApiProblem(
                    500,
                    sprintf('Listed input filter "%s" does not exist; cannot validate request', $inputFilterService)
                )
            );
        }

        $files = $request->getUploadedFiles();
        if (! $isCollection && 0 < count($files)) {
            // File uploads are not validated for collections; impossible to
            // match file fields to discrete sets
            $data = ArrayUtils::merge($data, $files, true);
        }

        $inputFilter = $this->getInputFilter($inputFilterService);

        if (
            $isCollection && ! in_array($method, $this->methodsWithoutBodies)
            && ! $inputFilter instanceof CollectionInputFilter
        ) {
            $collectionInputFilter = new CollectionInputFilter();
            $collectionInputFilter->setInputFilter([$inputFilter]);
            $inputFilter = $collectionInputFilter;
        }

        $request = $request->withAttribute(InputFilter::class, $inputFilter);

        $inputFilter->setData($data);
        $status = $inputFilter->isValid();
        /*
        $status = $request->getMethod() === 'PATCH'
            ? $this->validatePatch($inputFilter, $data, $isCollection)
            : $inputFilter->isValid();
        */

        // Invalid? Return a 422 response.
        if (false === $status) {
            return new ApiProblemResponse(
                new ApiProblem(422, 'Failed Validation', null, null, [
                    'validation_messages' => $inputFilter->getMessages(),
                ])
            );
        }

        // Should we use the raw data vs. the filtered data?
        // - If no `use_raw_data` flag is present, always use the raw data
        // - If the flag is present AND is boolean true, that is also
        //   an indicator that the raw data should be present.
        $useRawData = $this->useRawData($routeMatchedName);
        if (! $useRawData) {
            $data = $inputFilter->getValues();
        }

        // Should we remove empty data from received data?
        // - If no `remove_empty_data` flag is present, do nothing - use data as is
        // - If `remove_empty_data` flag is present AND is boolean true, then remove
        //   empty data from current data array
        // - Does not remove empty data if keys matched received data
        $removeEmptyData = $this->shouldRemoveEmptyData($routeMatchedName);
        if ($removeEmptyData) {
            $data = $this->removeEmptyData($data, $receivedData);
        }

        // If we don't have an instance of UnknownInputsCapableInterface, or no
        // unknown data is in the input filter, at this point we can just
        // set the current data into the data container.
        if (
            ! $inputFilter instanceof UnknownInputsCapableInterface
            || ! $inputFilter->hasUnknown()
        ) {
            return $handler->handle($request->withAttribute('validated_data', $data));
        }

        $unknowns = $inputFilter->getUnknown();
        if ($this->allowsOnlyFieldsInFilter($routeMatchedName)) {
            if ($inputFilter instanceof CollectionInputFilter) {
                $unknownFields = [];
                foreach ($unknowns as $key => $fields) {
                    $unknownFields[] = '[' . $key . ': ' . implode(', ', array_keys($fields)) . ']';
                }
                $fields = implode(', ', $unknownFields);
            } else {
                $fields = implode(', ', array_keys($unknowns));
            }
            $detail = sprintf('Unrecognized fields: %s', $fields);
            return new ApiProblemResponse(new ApiProblem(422, $detail));
        }

        return $handler->handle($request->withAttribute('validated_data', $inputFilter));
    }

    private function addMethodWithoutBody(string $method): void
    {
        $this->methodsWithoutBodies[] = $method;
    }

    private function getInputFilterService(
        false|string|null $routeMatchedName,
        string $method,
        bool $isCollection
    ): string|false {
        if (! array_key_exists($routeMatchedName, $this->config)) {
            return false;
        }
        $method = strtoupper($method);
        if ($isCollection && isset($this->config[$routeMatchedName][$method . '_COLLECTION'])) {
            return $this->config[$routeMatchedName][$method . '_COLLECTION'];
        }
        if (isset($this->config[$routeMatchedName][$method])) {
            return $this->config[$routeMatchedName][$method];
        }

        if ($method === 'DELETE' || in_array($method, $this->methodsWithoutBodies)) {
            return false;
        }

        if (isset($this->config[$routeMatchedName]['input_filter'])) {
            return $this->config[$routeMatchedName]['input_filter'];
        }

        return false;
    }

    private function isCollection(
        string $routeMatchedName,
        object|array|null $data,
        ServerRequestInterface $request
    ): bool {
        if (! array_key_exists($routeMatchedName, $this->restConfig)) {
            return false;
        }

        if ($request->getMethod() === 'POST' && (empty($data) || ArrayUtils::isHashTable($data))) {
            return false;
        }

        $identifierName = $this->restConfig[$routeMatchedName]['route_identifier_name'] ?? null;
        if (null !== $request->getAttribute($identifierName)) {
            return false;
        }
        return ! isset($request->getQueryParams()[$routeMatchedName]);
    }

    private function hasInputFilter(string $inputFilterService): bool
    {
        if (array_key_exists($inputFilterService, $this->inputFilters)) {
            return true;
        }

        if (! $this->inputFilterPluginManager->has($inputFilterService)) {
            return false;
        }

        $inputFilter = $this->inputFilterPluginManager->get($inputFilterService);
        if (! $inputFilter instanceof InputFilterInterface) {
            return false;
        }

        $this->inputFilters[$inputFilterService] = $inputFilter;
        return true;
    }

    private function getInputFilter(string $inputFilterService): InputFilterInterface|InputFilter|string
    {
        return $this->inputFilterPluginManager->get($inputFilterService);
    }

    private function useRawData(string $routeMatchedName): bool
    {
        if (
            ! isset($this->config[$routeMatchedName]['use_raw_data'])
            || (isset($this->config[$routeMatchedName]['use_raw_data'])
            && $this->config[$routeMatchedName]['use_raw_data'] === true)
        ) {
            return true;
        }
        return false;
    }

    private function shouldRemoveEmptyData(string $routeMatchedName): bool
    {
        if (
            isset($this->config[$routeMatchedName]['remove_empty_data'])
            && $this->config[$routeMatchedName]['remove_empty_data'] === true
        ) {
            return true;
        }
        return false;
    }

    private function removeEmptyData(array|object|null $data, object|array|null $compareTo = []): object|array|null
    {
        /**
         * Callback for array_filter() to remove null values (array_filter() removes 'false' values)
         */
        $removeNull = function (mixed $value, null|int|string $key = null) use ($compareTo): bool {
            // If comparison array is empty, do a straight comparison
            if (empty($compareTo)) {
                return null !== $value;
            }

            // If key exists in comparison array, the 'null' value is on purpose, leave as is
            if (array_key_exists($key, $compareTo)) {
                return true;
            }

            return null !== $value;
        };

            $data = array_filter($data, $removeNull, ARRAY_FILTER_USE_BOTH);

        if (empty($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (
                ! is_array($value)
                && (! empty($value) || is_bool($value) && ! in_array($key, $compareTo))
            ) {
                continue;
            }

            if (! is_array($value)) {
                unset($data[$key]);
                continue;
            }

            if (empty(array_filter($value, $removeNull, ARRAY_FILTER_USE_BOTH))) {
                unset($data[$key]);
                continue;
            }

            $tmpValue = array_key_exists($key, $compareTo) && is_array($compareTo[$key])
            ? $this->removeEmptyData($value, $compareTo[$key])
            : $this->removeEmptyData($value);

            // Additional check to ensure it's not an empty recursive result
            if (empty(array_filter($tmpValue, $removeNull, ARRAY_FILTER_USE_BOTH))) {
                unset($data[$key]);
                continue;
            }

            $data[$key] = $tmpValue;
        }

            return $data;
    }

    private function allowsOnlyFieldsInFilter(string $routeMatchedName): bool
    {
        if (isset($this->config[$routeMatchedName]['allows_only_fields_in_filter'])) {
            return true === $this->config[$routeMatchedName]['allows_only_fields_in_filter'];
        }

        return false;
    }
}
