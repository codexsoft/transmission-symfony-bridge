<?php


namespace CodexSoft\Transmission\SymfonyBridge;


class RequestData
{
    public array $body = [];
    public array $headers = [];
    public array $query = [];
    public array $path = [];
    public array $cookies = [];
}
