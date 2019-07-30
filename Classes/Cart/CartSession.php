<?php
declare(strict_types=1);

namespace PunktDe\Sylius\Cart\Cart;

/*
 *  (c) 2018 punkt.de GmbH - Karlsruhe, Germany - http://punkt.de
 *  All rights reserved.
 */

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("session")
 */
class CartSession
{
    /**
     * @var int
     */
    protected $syliusCartId = 0;

    /**
     * @var int
     */
    protected $cartItemCount = 0;

    /**
     * @return int
     */
    public function getSyliusCartId(): int
    {
        return $this->syliusCartId;
    }

    /**
     * @param int $syliusCartId
     */
    public function setSyliusCartId(int $syliusCartId): void
    {
        $this->syliusCartId = $syliusCartId;
    }

    /**
     * @return bool
     */
    public function cartIsInitialized(): bool
    {
        return $this->syliusCartId !== 0;
    }

    /**
     * @return int
     */
    public function getCartItemCount(): int
    {
        return $this->cartItemCount;
    }

    /**
     * @param int $cartItemCount
     */
    public function setCartItemCount(int $cartItemCount): void
    {
        $this->cartItemCount = $cartItemCount;
    }
}
