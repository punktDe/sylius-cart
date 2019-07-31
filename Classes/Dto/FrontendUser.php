<?php
declare(strict_types=1);

namespace PunktDe\Sylius\Api\Dto;

/*
 *  (c) 2019 punkt.de GmbH - Karlsruhe, Germany - http://punkt.de
 *  All rights reserved.
 */

class FrontendUser
{
    /**
     * @var string
     */
    protected $email = '';

    /**
     * @var bool
     */
    protected $loggedIn = false;

    public function __construct(string $email, bool $loggedIn)
    {
        $this->email = $email;
        $this->loggedIn = $loggedIn;
    }

    /**
     * @return string
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }
}
