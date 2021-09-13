<?php

declare(strict_types=1);

namespace Helper\Module;

use \League\OpenAPIValidation\PSR7\ValidatorBuilder;
use \League\OpenAPIValidation\PSR7\RequestValidator;
use \League\OpenAPIValidation\PSR7\ResponseValidator;
use \League\OpenAPIValidation\PSR7\OperationAddress;
use \Helper\Module\Api;

final class OpenApiValidator extends \Codeception\Module implements \Codeception\Lib\Interfaces\DependsOnModule
{

    /** @var array<string> */
    protected $requiredFields = ['openapi'];

    /** @var ValidatorBuilder */
    private $validator;

    /** @var RequestValidator */
    private $requestValidator;

    /** @var ResponseValidator */
    private $responseValidator;

    /** @var Api */
    private $restModule;

    /** @return array<mixed> */
    public function _depends(): array
    {
        return ['\Helper\Module\Api' => 'Api module is a mandatory dependency of OpenApiValidator'];
    }

    public function _inject(\Helper\Module\Api $restModule): void
    {
        $this->restModule = $restModule;
    }

    public function _initialize(): void
    {
        $this->validator = (new ValidatorBuilder)->fromYamlFile($this->config['openapi']);
        $this->requestValidator = $this->validator->getRequestValidator();
        $this->responseValidator = $this->validator->getResponseValidator();
    }

    /**
     * Validates the API request against the OpenAPI spec
     * 
     * @return void
     */
    public function validateRequest(): void
    {
        $this->requestValidator->validate($this->restModule->getRequest());
    }

    /**
     * Validates the API response against the OpenAPI spec
     * 
     * @return void
     */
    public function validateResponse(): void
    {
        $address = new OperationAddress($this->restModule->getUrl(), $this->restModule->getMethod());
        $this->responseValidator->validate($address, $this->restModule->getResponse());
    }
}