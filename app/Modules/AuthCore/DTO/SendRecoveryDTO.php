<?php

namespace App\Modules\AuthCore\DTO;

class SendRecoveryDTO
{
    public function __construct(
        public string $email
    ) {}
}