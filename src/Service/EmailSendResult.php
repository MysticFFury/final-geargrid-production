<?php

namespace App\Service;

final class EmailSendResult
{
    private function __construct(
        public readonly bool $sent,
        public readonly ?string $userMessage = null,
    ) {
    }

    public static function success(): self
    {
        return new self(true);
    }

    public static function failure(string $userMessage): self
    {
        return new self(false, $userMessage);
    }
}
