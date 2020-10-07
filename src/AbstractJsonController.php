<?php


namespace CodexSoft\TransmissionSymfonyBridge;


use CodexSoft\TransmissionSchema\Contracts\JsonEndpointInterface;
use CodexSoft\TransmissionSchema\Elements\AbstractElement;
use CodexSoft\TransmissionSchema\Elements\JsonElement;
use CodexSoft\TransmissionSchema\Exceptions\IncompatibleInputDataTypeException;
use CodexSoft\TransmissionSchema\Exceptions\InvalidJsonSchemaException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Base class for building HTTP JSON API
 */
abstract class AbstractJsonController implements JsonEndpointInterface
{
    protected Request $request;

    public function __construct(RequestStack $requestStack)
    {
        $request = $requestStack->getCurrentRequest();
        if ($request === null) {
            throw new \InvalidArgumentException('RequestStack is empty, failed to get current Request!');
        }
        $this->request = $request;
        $this->init();
    }

    /**
     * Expected request JSON schema
     * @return AbstractElement[]
     */
    abstract public static function bodyInputSchema(): array;

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

    protected function afterHandle(Response $response): void
    {
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

    protected function onViolationsDetected(ConstraintViolationListInterface $violations): Response
    {
        $violationsData = [];

        /** @var ConstraintViolationInterface $violation */
        foreach ($violations as $violation) {
            $violationsData[] = $violation->getPropertyPath().': '.$violation->getInvalidValue().': '.$violation->getMessage();
        }

        return new JsonResponse([
            'message' => 'Invalid request data: '.\implode(', ', $violationsData),
            'data' => $violationsData,
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

    protected function onEmptyBody($requestBody): Response
    {
        $this->beforeHandle();
        $response = $this->handle([], []);
        $this->afterHandle($response);

        return $response;
    }

    /**
     * @return Response
     */
    public function __invoke(): Response
    {
        $requestBody = $this->request->getContent();
        if (empty($requestBody)) {
            return $this->onEmptyBody($requestBody);
        }

        try {
            $inputData = \json_decode($this->request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->onJsonDecodeFailure($e);
        }

        try {
            $schema = (new JsonElement(static::bodyInputSchema()));
        } catch (InvalidJsonSchemaException $e) {
            return $this->onInvalidBodyInputSchema($e);
        }

        try {
            $validationResult = $schema->getValidatedNormalizedData($inputData);
        } catch (IncompatibleInputDataTypeException $e) {
            return $this->onNormalizationFail($e);
        }

        if ($validationResult->getViolations()->count()) {
            return $this->onViolationsDetected($validationResult->getViolations());
        }

        $this->beforeHandle();
        $response = $this->handle($validationResult->getData(), $validationResult->getExtraData());
        $this->afterHandle($response);

        return $response;
    }
}
