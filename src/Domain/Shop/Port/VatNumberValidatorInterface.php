<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Port;

use ValeApp\Domain\Shop\ValueObject\VatNumber;
use ValeApp\Domain\Shop\ValueObject\VatValidation;

interface VatNumberValidatorInterface
{
    public function validate(VatNumber $vatNumber): VatValidation;
}
