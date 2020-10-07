<?php


namespace CodexSoft\Transmission\SymfonyBridge;


use CodexSoft\Transmission\Schema\Contracts\JsonEndpointInterface;
use CodexSoft\Transmission\Schema\Elements\AbstractElement;
use CodexSoft\Transmission\Schema\Elements\JsonElement;
use CodexSoft\Transmission\Schema\Exceptions\IncompatibleInputDataTypeException;
use CodexSoft\Transmission\Schema\Exceptions\InvalidJsonSchemaException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Base class for building HTTP JSON API
 * Consumes JSON and produces JSON
 */
abstract class AbstractJsonController implements JsonEndpointInterface
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
     * Expected request JSON schema
     * @return AbstractElement[]
     */
    abstract public static function bodyInputSchema(): array;

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
     * @return AbstractElement[]
     */
    public static function pathParametersSchema(): array
    {
        return [];
    }

    /**
     * Expected request body parameters
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

    /**
     * Implement this method to handle input JSON data
     *
     * @param array $data
     * @param array $extraData
     *
     * @return Response
     */
    abstract protected function handle(array $data, array $extraData = []): Response;

    protected function init(): void
    {
    }

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
        return new JsonResponse([], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function onInvalidHeadersSchema(InvalidJsonSchemaException $e): Response
    {
        return new JsonResponse([], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function onInvalidQuerySchema(InvalidJsonSchemaException $e): Response
    {
        return new JsonResponse([], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function onInvalidPathSchema(InvalidJsonSchemaException $e): Response
    {
        return new JsonResponse([], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function prepareViolationsData(ConstraintViolationListInterface $violations): array
    {
        $violationsData = [];

        /** @var ConstraintViolationInterface $violation */
        foreach ($violations as $violation) {
            $violationsData[] = $violation->getPropertyPath().': '.$violation->getInvalidValue().': '.$violation->getMessage();
        }

        return $violationsData;
    }

    protected function onViolationsDetected(
        ConstraintViolationListInterface $bodyViolations,
        ConstraintViolationListInterface $queryViolations,
        ConstraintViolationListInterface $pathViolations,
        ConstraintViolationListInterface $headersViolations
    ): Response
    {
        return new JsonResponse([
            'message' => 'Malformed request data',
            'violations' => [
                'body' => $this->prepareViolationsData($bodyViolations),
                'query' => $this->prepareViolationsData($queryViolations),
                'path' => $this->prepareViolationsData($pathViolations),
                'headers' => $this->prepareViolationsData($headersViolations),
            ],
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * todo: current implementation prevents to calculate violations by validator in case of normalization fails.
     *
     * @param IncompatibleInputDataTypeException $e
     *
     * @return Response
     */
    protected function onNormalizationFail(IncompatibleInputDataTypeException $e): Response
    {
        return new JsonResponse([], Response::HTTP_NOT_ACCEPTABLE);
    }

    protected function onJsonDecodeFailure(\JsonException $e): Response
    {
        return new JsonResponse([
            'message' => 'Malformed JSON body in request: '.$e->getMessage(),
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * By default, empty body is allowed and empty input data will be handled.
     * You can change this behaviour by overriding this method.
     * @param mixed $requestBody
     *
     * @return Response
     */
    protected function onEmptyBody($requestBody): Response
    {
        return $this->_handleRequest([]);
    }

    protected function _handleRequest(array $data, array $extraData = []): Response
    {
        $this->beforeHandle();
        $response = $this->handle($data, $extraData);
        $this->afterHandle($response);

        return $response;
    }

    /**
     * @return Response
     */
    public function __invoke(): Response
    {
        try {
            $bodySchema = (new JsonElement(static::bodyInputSchema()));
        } catch (InvalidJsonSchemaException $e) {
            return $this->onInvalidBodyInputSchema($e);
        }

        try {
            $headersSchema = (new JsonElement(static::headerParametersSchema()));
        } catch (InvalidJsonSchemaException $e) {
            return $this->onInvalidHeadersSchema($e);
        }

        try {
            $querySchema = (new JsonElement(static::queryParametersSchema()));
        } catch (InvalidJsonSchemaException $e) {
            return $this->onInvalidQuerySchema($e);
        }

        try {
            $pathSchema = (new JsonElement(static::pathParametersSchema()));
        } catch (InvalidJsonSchemaException $e) {
            return $this->onInvalidPathSchema($e);
        }

        // todo: cookies?

        $requestBody = $this->request->getContent();
        //if (empty($requestBody)) {
        //    return $this->onEmptyBody($requestBody);
        //}

        try {
            $bodyInputData = \json_decode($this->request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->onJsonDecodeFailure($e);
        }

        $pathInputData = $this->request->attributes->all();
        $headersInputData = $this->request->headers->all();
        $queryInputData = $this->request->query->all();

        try {
            $bodyValidationResult = $bodySchema->getValidatedNormalizedData($bodyInputData);
        } catch (IncompatibleInputDataTypeException $e) {
            return $this->onNormalizationFail($e);
        }

        try {
            $headersValidationResult = $headersSchema->getValidatedNormalizedData($headersInputData);
        } catch (IncompatibleInputDataTypeException $e) {
            return $this->onNormalizationFail($e);
        }

        try {
            $queryValidationResult = $querySchema->getValidatedNormalizedData($queryInputData);
        } catch (IncompatibleInputDataTypeException $e) {
            return $this->onNormalizationFail($e);
        }

        try {
            $pathValidationResult = $pathSchema->getValidatedNormalizedData($pathInputData);
        } catch (IncompatibleInputDataTypeException $e) {
            return $this->onNormalizationFail($e);
        }

        $violationsDetected =
            $bodyValidationResult->getViolations()->count() ||
            $headersValidationResult->getViolations()->count() ||
            $queryValidationResult->getViolations()->count() ||
            $pathValidationResult->getViolations()->count();

        if ($violationsDetected) {
            return $this->onViolationsDetected(
                $bodyValidationResult->getViolations(),
                $headersValidationResult->getViolations(),
                $queryValidationResult->getViolations(),
                $pathValidationResult->getViolations()
            );
        }

        $this->beforeHandle();
        $response = $this->handle($bodyValidationResult->getData(), $bodyValidationResult->getExtraData());
        $modifiedResponse = $this->afterHandle($response);
        if ($modifiedResponse instanceof Response) {
            $response = $modifiedResponse;
        }

        return $response;
    }
}
