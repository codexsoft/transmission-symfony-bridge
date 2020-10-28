<?php


namespace CodexSoft\Transmission\SymfonyBridge;


use CodexSoft\Transmission\Schema\Elements\AbstractElement;
use CodexSoft\Transmission\Schema\Elements\CollectionElement;
use CodexSoft\Transmission\Schema\Elements\JsonElement;
use CodexSoft\Transmission\Schema\Elements\ScalarElement;
use CodexSoft\Transmission\Schema\Exceptions\GenericTransmissionException;
use CodexSoft\Transmission\Schema\Exceptions\InvalidJsonSchemaException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Base class for building HTTP API
 * todo: make version that extends Symfony Abstract Controller?
 */
abstract class AbstractHttpController extends AbstractController implements RequestSchemaInterface
{
    protected Request $request;
    protected RequestStack $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $request = $requestStack->getCurrentRequest();
        if ($request === null) {
            throw new \InvalidArgumentException('RequestStack is empty, failed to get current Request!');
        }
        $this->request = $request;
        $this->requestStack = $requestStack;
        $this->init();
    }

    /**
     * Must be overriden in concrete controller class.
     *
     * @param RequestData $data normalized and validated data according to parameter schemas
     * @param RequestData $extradata extra data that present in request but is not described
     *
     * @return Response
     */
    abstract protected function handle(RequestData $data, RequestData $extradata): Response;

    /**
     * Expected request cookie parameters
     * @return AbstractElement[]
     */
    public static function cookieParametersSchema(): array
    {
        return [];
    }

    /**
     * Expected request query parameters
     * @return AbstractElement[]
     */
    public static function queryParametersSchema(): array
    {
        return [];
    }

    /**
     * Expected request path parameters
     * Because path parameters are always strings, schema elements should not be strict for
     * non-string types.
     * @return AbstractElement[]
     */
    public static function pathParametersSchema(): array
    {
        return [];
    }

    /**
     * Expected request body parameters (JSON for example)
     *
     * todo: consider improvement — allow return JsonElement object to allow denyExtraFields etc.
     *
     * @return AbstractElement[]
     */
    public static function bodyParametersSchema(): array
    {
        return [];
    }

    /**
     * Expected request body parameters
     * @return AbstractElement[]
     */
    public static function headerParametersSchema(): array
    {
        return [];
    }

    protected function init(): void
    {
    }

    /**
     * Place for some actions that should be done before handling request
     */
    protected function beforeHandle(): void
    {
    }

    /**
     * You can modify response, if needed
     * @param Response $response
     *
     * @return Response|null
     */
    protected function afterHandle(Response $response): ?Response
    {
        return null;
    }

    /**
     * You can also throw exception in overriden method and handle it by Subscriber, for example.
     * @param InvalidJsonSchemaException $e
     *
     * @return Response
     */
    protected function onInvalidBodyInputSchema(InvalidJsonSchemaException $e): Response
    {
        return new JsonResponse([
            'message' => 'body schema is invalid',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function onInvalidHeadersSchema(InvalidJsonSchemaException $e): Response
    {
        return new JsonResponse([
            'message' => 'headers schema is invalid',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function onInvalidQuerySchema(InvalidJsonSchemaException $e): Response
    {
        return new JsonResponse([
            'message' => 'query schema is invalid',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function onInvalidPathSchema(InvalidJsonSchemaException $e): Response
    {
        return new JsonResponse([
            'message' => 'path schema is invalid',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function onInvalidCookiesSchema(InvalidJsonSchemaException $e): Response
    {
        return new JsonResponse([
            'message' => 'cookies schema is invalid',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Just a helper function for serializing detected violations data
     * @param ConstraintViolationListInterface $violations
     *
     * @return array
     */
    protected function prepareViolationsData(ConstraintViolationListInterface $violations): array
    {
        $violationsData = [];

        /** @var ConstraintViolationInterface $violation */
        foreach ($violations as $violation) {
            $violationsData[$violation->getPropertyPath()] = [
                'violation' => $violation->getMessage(),
                'invalidValue' => $violation->getInvalidValue(),
            ];
        }

        return $violationsData;
    }

    protected function onViolationsDetected(
        ConstraintViolationListInterface $bodyViolations,
        ConstraintViolationListInterface $headersViolations,
        ConstraintViolationListInterface $queryViolations,
        ConstraintViolationListInterface $pathViolations,
        ConstraintViolationListInterface $cookiesViolations
    ): Response
    {
        return new JsonResponse([
            'message' => 'Malformed request data',
            'violations' => [
                'body' => $this->prepareViolationsData($bodyViolations),
                'headers' => $this->prepareViolationsData($headersViolations),
                'path' => $this->prepareViolationsData($pathViolations),
                'query' => $this->prepareViolationsData($queryViolations),
                'cookies' => $this->prepareViolationsData($cookiesViolations),
            ],
        ], Response::HTTP_BAD_REQUEST);
    }

    protected function onJsonDecodeFailure(\JsonException $e): Response
    {
        return new JsonResponse([
            'message' => 'Malformed JSON body in request: '.$e->getMessage(),
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * 'html' => ['text/html', 'application/xhtml+xml'],
     * 'txt' => ['text/plain'],
     * 'js' => ['application/javascript', 'application/x-javascript', 'text/javascript'],
     * 'css' => ['text/css'],
     * 'json' => ['application/json', 'application/x-json'],
     * 'jsonld' => ['application/ld+json'],
     * 'xml' => ['text/xml', 'application/xml', 'application/x-xml'],
     * 'rdf' => ['application/rdf+xml'],
     * 'atom' => ['application/atom+xml'],
     * 'rss' => ['application/rss+xml'],
     * 'form' => ['application/x-www-form-urlencoded'],
     *
     * @param Request $request
     *
     * @return array|mixed
     * @throws \JsonException
     */
    protected function extractBody(Request $request)
    {
        if ($request->getContentType() === 'json') {
            $requestBody = $this->request->getContent();

            if (empty($requestBody)) {
                return [];
            }

            return \json_decode($this->request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $request->request->all();
    }

    protected static function contentTypeWhitelist(): array
    {
        return [];
    }

    protected static function contentTypeBlacklist(): array
    {
        return [];
    }

    /**
     * @throws GenericTransmissionException
     */
    protected function ensureContentTypeAcceptable(): void
    {
        $contentType = $this->request->headers->get('CONTENT_TYPE');

        $whiteList = static::contentTypeWhitelist();
        if ($whiteList) {
            foreach ($whiteList as $whiteListItem) {
                /*
                 * Sometimes Content-Type is not just 'application/json'
                 * Content-Type: text/html; charset=UTF-8
                 * Content-Type: multipart/form-data; boundary=something
                 */
                if (\str_ends_with($whiteListItem, '*')) {
                    $whiteListItem = \rtrim($whiteListItem, '*');
                }
                if (\str_starts_with($contentType, $whiteListItem)) {
                    return;
                }
            }

            throw new GenericTransmissionException('Request content type '.$contentType.' is not acceptable (not in whitelist)');
        }

        $blackList = static::contentTypeBlacklist();
        if ($blackList && \in_array($contentType, $blackList, true)) {
            throw new GenericTransmissionException('Request content type '.$contentType.' is not acceptable (is in blacklist)');
        }
    }

    /**
     * @param array $parametersSchema
     *
     * @throws InvalidJsonSchemaException
     */
    protected function ensureAllElementsAreScalar(array $parametersSchema): void
    {
        foreach ($parametersSchema as $key => $value) {
            if (!$value instanceof ScalarElement) {
                throw new InvalidJsonSchemaException($key.' in path schema must be scalar type');
            }
        }
    }

    /**
     * @return Response
     */
    protected function _handleRequest(): Response
    {
        try {
            $this->ensureContentTypeAcceptable();
        } catch (GenericTransmissionException $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], Response::HTTP_NOT_ACCEPTABLE);
        }

        /**
         * todo: ->denyExtraFields() optionally?
         * todo: inject files to body via Accept::file() element?
         */
        try {
            $bodySchema = (new JsonElement(static::bodyParametersSchema()));
        } catch (InvalidJsonSchemaException $e) {
            return $this->onInvalidBodyInputSchema($e);
        }

        try {
            $headersParametersSchema = static::headerParametersSchema();
            $lowercaseHeadersParametersSchema = [];
            foreach ($headersParametersSchema as $key => $headersParametersSchemaItem) {
                $lowercaseHeadersParametersSchema[\mb_strtolower($key)] = $headersParametersSchemaItem;
            }
            $this->ensureAllElementsAreScalar($lowercaseHeadersParametersSchema);
            $headersSchema = (new JsonElement($lowercaseHeadersParametersSchema));
        } catch (InvalidJsonSchemaException $e) {
            return $this->onInvalidHeadersSchema($e);
        }

        try {
            $queryParametersSchema = static::queryParametersSchema();

            /*
             * Query parameters can be arrays, not only scalars
             */
            foreach ($queryParametersSchema as $key => $value) {
                if (!$value instanceof ScalarElement && !$value instanceof CollectionElement) {
                    throw new InvalidJsonSchemaException($key.' in path schema must be scalar or array type');
                }
            }

            $querySchema = (new JsonElement($queryParametersSchema));
        } catch (InvalidJsonSchemaException $e) {
            return $this->onInvalidQuerySchema($e);
        }

        try {
            $pathParametersSchema = static::pathParametersSchema();
            $this->ensureAllElementsAreScalar($pathParametersSchema);
            /* Prevent strict type checks for path variables */
            foreach ($pathParametersSchema as $key => $value) {
                $value->strict(false);
            }
            $pathSchema = new JsonElement($pathParametersSchema);
        } catch (InvalidJsonSchemaException $e) {
            return $this->onInvalidPathSchema($e);
        }

        try {
            $cookiesParametersSchema = static::cookieParametersSchema();
            $this->ensureAllElementsAreScalar($cookiesParametersSchema);
            $cookiesSchema = (new JsonElement($cookiesParametersSchema));
        } catch (InvalidJsonSchemaException $e) {
            return $this->onInvalidCookiesSchema($e);
        }

        /**
         * Gathering request data
         */

        try {
            $bodyInputData = $this->extractBody($this->request);
        } catch (\JsonException $e) {
            return $this->onJsonDecodeFailure($e);
        }

        $cookiesInputData = $this->request->cookies->all();
        $queryInputData = $this->request->query->all();

        if ($this->request->attributes->has('_route_params')) {
            $pathInputData = $this->request->attributes->get('_route_params');
        } else {
            /*
             * In forwarded requests path variables are not in _route_params, but simply in attributes
             */
            $pathInputData = [];
            foreach ($this->request->attributes->all() as $parameter => $value) {
                if (\str_starts_with($parameter, '_')) {
                    continue;
                }
                $pathInputData[$parameter] = $value;
            }
        }

        $headersInputData = [];
        foreach ($this->request->headers->keys() as $headerName) {
            $headersInputData[$headerName] = $this->request->headers->get($headerName);
        }

        $files = $this->request->files->all();

        /**
         * Validating and normalizing request data according to parameter schemas
         */

        $bodyValidationResult = $bodySchema->validateNormalizedData($bodyInputData);
        $headersValidationResult = $headersSchema->validateNormalizedData($headersInputData);
        $queryValidationResult = $querySchema->validateNormalizedData($queryInputData);
        $pathValidationResult = $pathSchema->validateNormalizedData($pathInputData);
        $cookiesValidationResult = $cookiesSchema->validateNormalizedData($cookiesInputData);

        $violationsDetected =
            $bodyValidationResult->getViolations()->count() ||
            $headersValidationResult->getViolations()->count() ||
            $queryValidationResult->getViolations()->count() ||
            $pathValidationResult->getViolations()->count() ||
            $cookiesValidationResult->getViolations()->count();

        if ($violationsDetected) {
            return $this->onViolationsDetected(
                $bodyValidationResult->getViolations(),
                $headersValidationResult->getViolations(),
                $queryValidationResult->getViolations(),
                $pathValidationResult->getViolations(),
                $cookiesValidationResult->getViolations()
            );
        }

        /**
         * Data prepared. Handling request.
         */

        $requestData = new RequestData();
        $requestData->body = $bodyValidationResult->getData();
        $requestData->headers = $headersValidationResult->getData();
        $requestData->query = $queryValidationResult->getData();
        $requestData->path = $pathValidationResult->getData();
        $requestData->cookies = $cookiesValidationResult->getData();

        $extraData = new RequestData();
        $extraData->body = $bodyValidationResult->getExtraData();
        $extraData->headers = $headersValidationResult->getExtraData();
        $extraData->query = $queryValidationResult->getExtraData();
        $extraData->path = $pathValidationResult->getExtraData();
        $extraData->cookies = $cookiesValidationResult->getExtraData();

        $this->beforeHandle();
        $response = $this->handle($requestData, $extraData);

        $modifiedResponse = $this->afterHandle($response);
        if ($modifiedResponse instanceof Response) {
            $response = $modifiedResponse;
        }

        return $response;
    }

    /**
     * @return Response
     */
    public function __invoke(): Response
    {
        return $this->_handleRequest();
    }
}
