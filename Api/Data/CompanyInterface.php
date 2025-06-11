<?php

/**
 * Interface CompanyInterface
 *
 * @package Vindi\VP\Api\Data
 */
interface CompanyInterface
{
    /**
     * @return string
     */
    public function getCnpj(): string;

    /**
     * @param string $cnpj
     * @return void
     */
    public function setCnpj(string $cnpj): void;

    /**
     * @return string
     */
    public function getTradeName(): string;

    /**
     * @param string $tradeName
     * @return void
     */
    public function setTradeName(string $tradeName): void;

    /**
     * @return string
     */
    public function getCompanyName(): string;

    /**
     * @param string $companyName
     * @return void
     */
    public function setCompanyName(string $companyName): void;
}
