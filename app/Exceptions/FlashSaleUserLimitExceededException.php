<?php

namespace App\Exceptions;

use RuntimeException;

class FlashSaleUserLimitExceededException extends RuntimeException
{
    public int $flashSaleItemId;
    public int $perUserLimit;
    public int $userPurchasedQuantity;
    public int $requestedQuantity;

    public function __construct(int $flashSaleItemId, int $perUserLimit, int $userPurchasedQuantity, int $requestedQuantity)
    {
        parent::__construct('FLASH_SALE_USER_LIMIT_EXCEEDED');
        $this->flashSaleItemId = $flashSaleItemId;
        $this->perUserLimit = $perUserLimit;
        $this->userPurchasedQuantity = $userPurchasedQuantity;
        $this->requestedQuantity = $requestedQuantity;
    }
}
