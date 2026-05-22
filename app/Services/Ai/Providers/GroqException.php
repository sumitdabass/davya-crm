<?php
namespace App\Services\Ai\Providers;

class GroqException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
