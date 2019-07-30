<?php
declare(strict_types=1);

namespace PunktDe\Sylius\Cart\Cart;

/*
 *  (c) 2018 punkt.de GmbH - Karlsruhe, Germany - http://punkt.de
 *  All rights reserved.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\PsrSystemLoggerInterface;
use Neos\Flow\ObjectManagement\Exception\UnknownObjectException;
use Neos\Flow\ObjectManagement\ObjectManager;
use PunktDe\Sylius\Api\Dto\CartItem;
use PunktDe\Sylius\Api\Exception\SyliusApiException;
use PunktDe\Sylius\Api\Resource\CartItemResource;
use PunktDe\Sylius\Api\Resource\CartResource;
use PunktDe\Vvw\MyVvw\Domain\Model\User;
use PunktDe\Vvw\MyVvw\Domain\Service\UserService;
use PunktDe\Sylius\Api\Dto\Cart as SyliusCart;
use PunktDe\Vvw\NeosHotfixes\Utility\LogEnvironment;

/**
 * @Flow\Scope("singleton")
 */
class CartManager
{
    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\Inject
     * @var CartSession
     */
    protected $cartSession;

    /**
     * @var ObjectManager
     * @Flow\Inject
     */
    protected $objectManager;

    /**
     * @var CartResource
     */
    protected $cartResource;

    /**
     * @var CartItemResource
     */
    protected $cartItemResource;

    /**
     * @Flow\InjectConfiguration(path="anonymousCustomer")
     * @var string
     */
    protected $anonymousCustomerMail;

    /**
     * @var User
     */
    protected $frontendUser;

    /**
     * @var Cart
     */
    protected $currentCart;

    /**
     * @Flow\Inject
     * @var PsrSystemLoggerInterface
     */
    protected $logger;


    public function initializeObject()
    {
        $this->frontendUser = $this->userService->getLoggedInFrontendUser();
    }

    /**
     * @return bool
     * @throws UnknownObjectException
     * @throws SyliusApiException
     */
    public function hasCart(): bool
    {
        return $this->sessionCartExists() && $this->getCart() instanceof Cart;
    }

    /**
     * @Flow\Session(autoStart = TRUE)
     *
     * @return Cart
     * @throws SyliusApiException
     * @throws UnknownObjectException
     */
    public function getCart(): Cart
    {
        if (!$this->currentCart instanceof Cart) {

            if ($this->sessionCartExists()) {
                $this->currentCart = $this->retrieveExistingCartByCartId($this->cartSession->getSyliusCartId());
            }

            if (!$this->currentCart && $this->isUserLoggedIn()) {
                $this->currentCart = $this->retrieveExistingCartByCustomerMail();
            }

            if (!$this->currentCart instanceof Cart) {
                $this->currentCart = $this->createCart();
            }
        }

        return $this->currentCart;
    }

    /**
     * @return int
     */
    public function getNumberOfItemsInCart(): int
    {
        return (int)$this->cartSession->getCartItemCount();
    }

    /**
     * @return float
     * @throws SyliusApiException
     * @throws UnknownObjectException
     */
    public function getTotalPrice(): float
    {
        if (!$this->hasCart()) {
            return 0.0;
        } else {
            return $this->getCart()->getTotalPrice();
        }
    }

    /**
     * @return bool
     * @throws UnknownObjectException
     */
    public function deleteCart(): bool
    {
        if ($this->sessionCartExists()) {
            return $this->getCartResource()->delete((string)$this->cartSession->getSyliusCartId());
        }

        return false;
    }

    /**
     * Moves an anonymous cart to a personal cart of the user
     *
     * @throws SyliusApiException
     * @throws UnknownObjectException
     */
    public function transferCartToCurrentUser(): void
    {
        $itemCountAfterTransfer = 0;

        if ($this->sessionCartExists() === false || $this->isUserLoggedIn() === false) {
            return;
        }

        $this->logger->debug(sprintf('Cart transfer requested for cart %s to user %s', $this->cartSession->getSyliusCartId(), $this->getLoggedInUserEmail()), LogEnvironment::fromMethodName(__METHOD__));

        $this->currentCart = $this->retrieveExistingCartByCartId($this->cartSession->getSyliusCartId());

        if (!$this->currentCart instanceof Cart) {
            $this->logger->warning(sprintf('Cart with id %s not found while trying to transfer it to user %s', $this->cartSession->getSyliusCartId(), $this->getLoggedInUserEmail()), LogEnvironment::fromMethodName(__METHOD__));
            return;
        }

        if ($this->currentCart->getCustomerEMail() === $this->getLoggedInUserEmail()) {
            $this->logger->debug('The customer mail is equal to the current user mail - nothing to do.', LogEnvironment::fromMethodName(__METHOD__));
            return;
        }

        $anonymousCart = $this->currentCart;

        $existingUserCart = $this->retrieveExistingCartByCustomerMail();
        if ($existingUserCart instanceof Cart) {
            $userCart = $existingUserCart->getSyliusCart();
            $itemCountAfterTransfer = count($existingUserCart->getItems());
        } else {
            $userCart = (new SyliusCart())->setCustomer($this->getLoggedInUserEmail());
            /** @var SyliusCart $userCart */
            $userCart = $this->cartResource->add($userCart);
        }

        foreach ($anonymousCart->getItems() as $anonymousCartItem) {
            $userCartItem = (new CartItem())
                ->setCartId($userCart->getId())
                ->setVariant($anonymousCartItem->getVariant())
                ->setQuantity($anonymousCartItem->getQuantity());
            $this->getCartItemResource()->add($userCartItem);
            $itemCountAfterTransfer++;
        }

        $this->getCartResource()->delete((string)$anonymousCart->getCartId());

        $this->logger->info(sprintf('Copied %s items from anonymous cart %s to user %s (CartId: %s)', count($anonymousCart->getItems()), $anonymousCart->getCartId(), $this->getLoggedInUserEmail(), $userCart->getId()), LogEnvironment::fromMethodName(__METHOD__));

        $this->currentCart = new Cart($userCart);

        $this->updateCartSession($userCart);

        $this->cartSession->setCartItemCount($itemCountAfterTransfer);
    }

    /**
     * @return Cart
     * @throws SyliusApiException
     * @throws UnknownObjectException
     */
    private function createCart(): Cart
    {
        $userEmail = $this->getLoggedInUserEmail() ?? $this->anonymousCustomerMail;

        $syliusCart = (new SyliusCart())
            ->setCustomer($userEmail);

        /** @var SyliusCart $syliusCart */
        $syliusCart = $this->getCartResource()->add($syliusCart);
        $this->updateCartSession($syliusCart);

        return new Cart($syliusCart);
    }

    /**
     * @param int $cartId
     * @return Cart
     * @throws UnknownObjectException
     */
    private function retrieveExistingCartByCartId(int $cartId): ?Cart
    {
        $syliusCart = $this->getCartResource()->get((string)$cartId);

        if (!$syliusCart instanceof SyliusCart) {
            $this->cartSession->setCartItemCount(0);
            return null;
        }

        $this->updateCartSession($syliusCart);
        return new Cart($syliusCart);
    }

    /**
     * @return Cart|null
     * @throws UnknownObjectException
     */
    private function retrieveExistingCartByCustomerMail(): ?Cart
    {
        $cartCollection = $this->getCartResource()->getAll([
            'customer' => [
                'searchOption' => 'equal',
                'searchPhrase' => $this->getLoggedInUserEmail()
            ]
        ]);

        if ($cartCollection->count() !== 1) {
            $cartIds = [];
            /** @var SyliusCart $syliusCart */
            foreach ($cartCollection as $syliusCart) {
                $cartIds[] = $syliusCart->getId();
            }

            $this->logger->warning(sprintf('User %s has more than one cart in sylius: %s', $this->getLoggedInUserEmail(), implode(',', $cartIds)), LogEnvironment::fromMethodName(__METHOD__));
        }

        if ($cartCollection->current() instanceof SyliusCart) {
            /** @var SyliusCart $syliusCart */
            $syliusCart = $cartCollection->current();
            $this->updateCartSession($syliusCart);
            return new Cart($syliusCart);
        }

        return null;
    }

    /**
     * @param SyliusCart $syliusCart
     */
    private function updateCartSession(SyliusCart $syliusCart): void
    {
        $this->cartSession->setSyliusCartId($syliusCart->getId());
        $this->cartSession->setCartItemCount(count($syliusCart->getItems()));
        $this->logger->debug(sprintf('Updating cart session with cart id %s and item count %s', (string)$syliusCart->getId(), count($syliusCart->getItems())), LogEnvironment::fromMethodName(__METHOD__));
    }

    /**
     * @return bool
     */
    private function isUserLoggedIn(): bool
    {
        return $this->frontendUser instanceof User;
    }

    /**
     * @return null|string
     */
    private function getLoggedInUserEmail(): ?string
    {
        return $this->frontendUser instanceof User ? $this->frontendUser->getEmail() : null;
    }

    /**
     * @return bool
     */
    private function sessionCartExists(): bool
    {
        return $this->cartSession->cartIsInitialized() === true;
    }

    /**
     * @return CartResource
     * @throws UnknownObjectException
     */
    private function getCartResource(): CartResource
    {
        if (!$this->cartResource instanceof CartResource) {
            $this->cartResource = $this->objectManager->get(CartResource::class);
        }

        return $this->cartResource;
    }

    /**
     * @return CartItemResource
     * @throws UnknownObjectException
     */
    private function getCartItemResource(): CartItemResource
    {
        if (!$this->cartItemResource instanceof CartItemResource) {
            $this->cartItemResource = $this->objectManager->get(CartItemResource::class);
        }

        return $this->cartItemResource;
    }
}
