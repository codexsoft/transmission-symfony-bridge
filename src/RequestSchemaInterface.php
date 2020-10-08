<?php


namespace CodexSoft\Transmission\SymfonyBridge;


use CodexSoft\Transmission\Schema\Elements\AbstractElement;

interface RequestSchemaInterface
{
    /**
     * Expected request cookie parameters
     * @return AbstractElement[]
     */
    public static function cookieParametersSchema(): array;

    /**
     * Expected request query parameters
     * @return AbstractElement[]
     */
    public static function queryParametersSchema(): array;

    /**
     * Expected request path parameters
     * Because path parameters are always strings, schema elements should not be strict for
     * non-string types.
     * @return AbstractElement[]
     */
    public static function pathParametersSchema(): array;

    /**
     * Expected request body parameters (JSON for example)
     * @return AbstractElement[]
     */
    public static function bodyParametersSchema(): array;

    /**
     * Expected request body parameters
     * @return AbstractElement[]
     */
    public static function headerParametersSchema(): array;
}
