<?php
declare(strict_types=1);

namespace Lumui\Http;

final class Response
{
    
    private $status;
    
    private $headers;
    
    private $body;

    
    public function __construct(int $status, array $headers, string $body = '')
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function status(): int
    {
        return $this->status;
    }

    
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(bool $head = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if (!$head && $this->status !== 304 && $this->status !== 204) {
            echo $this->body;
        }
    }
}

