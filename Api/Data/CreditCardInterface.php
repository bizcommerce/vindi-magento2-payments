<?php

namespace Vindi\VP\Api\Data;

/**
 * Interface CreditCardInterface
 *
 * @package Vindi\VP\Api\Data
 */
interface CreditCardInterface
{
    public function getEntityId(): int;
    public function setEntityId(int $entityId): void;

    public function getCustomerId(): int;
    public function setCustomerId(int $customerId): void;

    public function getCardToken(): string;
    public function setCardToken(string $cardToken): void;

    public function getCustomerEmail(): string;
    public function setCustomerEmail(string $customerEmail): void;

    public function getStatus(): string;
    public function setStatus(string $status): void;

    public function getType(): string;
    public function setType(string $type): void;

    public function getCcType(): string;
    public function setCcType(string $ccType): void;

    public function getCcLast4(): string;
    public function setCcLast4(string $ccLast4): void;

    public function getCcName(): string;
    public function setCcName(string $ccName): void;

    public function getCcExpDate(): string;
    public function setCcExpDate(string $ccExpDate): void;

    public function getCreatedAt(): string;
    public function setCreatedAt(string $createdAt): void;

    public function getUpdatedAt(): string;
    public function setUpdatedAt(string $updatedAt): void;
}
