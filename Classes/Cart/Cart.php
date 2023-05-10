<?php
declare(strict_types=1);

namespace PunktDe\Sylius\Cart\Cart;

/*
 *  (c) 2018 punkt.de GmbH - Karlsruhe, Germany - http://punkt.de
 *  All rights reserved.
 */

use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use PunktDe\Sylius\Api\Dto\Cart as SyliusCart;
use PunktDe\Sylius\Api\Dto\CartItem;
use PunktDe\Sylius\Api\Exception\SyliusApiException;
use PunktDe\Sylius\Api\Resource\CartItemResource;
use PunktDe\Sylius\Api\Resource\CartResource;
use PunktDe\Sylius\Api\Service\CartManagementService;

class Cart
{
    // phpcs:disable
    /**
     * @FLow\Inject
     * @var CartItemResource
     */
    protected $cartItemResource;

    /**
     * @var SyliusCart
     */
    protected $syliusCart;

    /**
     * @FLow\Inject
     * @var CartManagementService
     */
    protected $cartManagementService;

    /**
     * @var CartItem[]
     */
    protected $cartItems = [];

    /**
     * @FLow\Inject
     * @var CartResource
     */
    protected $cartResource;

    /**
     * @Flow\Inject
     * @var CartSession
     */
    protected $cartSession;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    // phpcs:enable

    /**
     * @param SyliusCart $syliusCart
     */
    public function __construct(SyliusCart $syliusCart)
    {
        $this->syliusCart = $syliusCart;
    }

    public function initializeObject()
    {
        $this->cartItems = $this->cartManagementService->getCartItems($this->syliusCart);
    }

    /**
     * @return int
     */
    public function getCartId(): int
    {
        return $this->syliusCart->getId();
    }

    /**
     * @return string
     */
    public function getCustomerEMail(): ?string
    {
        return $this->syliusCart->getCustomer()['email'] ?? null;
    }

    /**
     * @return SyliusCart
     */
    public function getSyliusCart(): SyliusCart
    {
        return $this->syliusCart;
    }

    /**
     * @param string $productVariant
     * @param int $quantity
     * @return CartItem
     * @throws SyliusApiException
     */
    public function addItem(string $productVariant, int $quantity): CartItem
    {
        $existingCartItem = $this->cartItems[$productVariant] ?? null;

        $cartItem = (new CartItem())
            ->setCartId($this->syliusCart->getId())
            ->setVariant($productVariant)
            ->setQuantity($quantity);

        if ($existingCartItem instanceof CartItem) {
            $cartItem
                ->setId($existingCartItem->getId())
                ->setQuantity($existingCartItem->getQuantity() + $quantity);
            $this->cartItemResource->update($cartItem);
        } else {
            $cartItem = $this->cartItemResource->add($cartItem);
        }

        $this->refreshCartFromRemote();

        return $cartItem;
    }

    /**
     * @param string $productVariant
     * @return bool
     * @throws SyliusApiException
     */
    public function deleteItem(string $productVariant): bool
    {
        $existingCartItem = $this->cartItems[$productVariant] ?? null;
        if (!$existingCartItem instanceof CartItem) {
            return false;
        }

        $result = $this->cartItemResource->delete((string)$existingCartItem->getId(), $existingCartItem->getCartIdentifier());

        $this->refreshCartFromRemote();
        return $result;
    }

    /**
     * @return CartItem[]
     */
    public function getItems(): array
    {
        return $this->cartItems;
    }

    /**
     * Refresh the internal Sylius cart
     */
    public function refreshCartFromRemote()
    {
        $syliusCart = $this->cartResource->get($this->syliusCart->getIdentifier());

        if (!$syliusCart instanceof SyliusCart) {
            $this->logger->warning(sprintf('Tried to refresh cart data from remote, but the cart with id %s was not found', $this->syliusCart->getIdentifier()), LogEnvironment::fromMethodName(__METHOD__));
            return;
        }

        $this->syliusCart = $syliusCart;
        $this->cartItems = $this->cartManagementService->getCartItems($this->syliusCart);
        $this->cartSession->setCartItemCount(count($this->cartItems));
    }

    /**
     * @return float
     */
    public function getTotalPrice(): float
    {
        return $this->syliusCart->getItemsTotal();
    }

    /**
     * @return string
     */
    public function getToken(): string
    {
        return $this->syliusCart->getTokenValue();
    }
}
