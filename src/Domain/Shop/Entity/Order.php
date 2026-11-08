<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Payment\Money;

class Order extends BaseEntity
{
    private string $orderNumber;
    private ?int $userId;
    private string $status              = 'pending';
    private string $locale              = 'en';
    private string $currency            = 'USD';
    private float $subtotal             = 0.0;
    private float $tax                  = 0.0;
    private float $shipping             = 0.0;
    private float $discount             = 0.0;
    private float $total                = 0.0;
    private ?string $couponCode         = null;
    private array $billing              = [];
    private array $shippingAddress      = [];
    private ?string $customerNote       = null;
    private ?string $paymentMethod      = null;
    private ?string $transactionId      = null;
    private ?string $paymentToken       = null;
    private ?\DateTimeImmutable $paidAt = null;
    private ?string $carrier            = null;
    private ?string $trackingNumber     = null;
    private string $orderType           = 'shop';
    private ?int $planId                = null;
    private ?string $subscriptionId     = null;
    private ?string $planName           = null;
    private ?bool $_inventory_processed = null;
    private ?bool $_inventory_restored  = null;
    private ?bool $_coupon_returned     = null;

    /** @var array<int, array<string,mixed>> */
    private array $items = [];

    public function __construct(string $orderNumber, array $billing, ?int $userId = null)
    {
        $this->orderNumber = $orderNumber;
        $this->billing     = $billing;
        $this->userId      = $userId;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }
    public function getUserId(): ?int
    {
        return $this->userId;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getLocale(): string
    {
        return $this->locale;
    }
    public function getCurrency(): string
    {
        return $this->currency;
    }
    public function getSubtotal(): float
    {
        return $this->subtotal;
    }
    public function getTax(): float
    {
        return $this->tax;
    }
    public function getShipping(): float
    {
        return $this->shipping;
    }
    public function getDiscount(): float
    {
        return $this->discount;
    }
    public function getTotal(): float
    {
        return $this->total;
    }
    public function getCouponCode(): ?string
    {
        return $this->couponCode;
    }
    public function getBilling(): array
    {
        return $this->billing;
    }
    public function getShippingAddress(): array
    {
        return $this->shippingAddress;
    }
    public function getCustomerNote(): ?string
    {
        return $this->customerNote;
    }
    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }
    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }    public function getPaymentToken(): ?string
    {
        return $this->paymentToken;
    }    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }
    public function getCarrier(): ?string
    {
        return $this->carrier;
    }
    public function getTrackingNumber(): ?string
    {
        return $this->trackingNumber;
    }
    public function getOrderType(): string
    {
        return $this->orderType;
    }
    public function getPlanId(): ?int
    {
        return $this->planId;
    }
    public function getSubscriptionId(): ?string
    {
        return $this->subscriptionId;
    }
    public function getPlanName(): ?string
    {
        return $this->planName;
    }
    public function isInventoryProcessed(): ?bool
    {
        return $this->_inventory_processed;
    }
    public function setInventoryProcessed(?bool $value): void
    {
        $this->_inventory_processed = $value;
    }
    public function isInventoryRestored(): ?bool
    {
        return $this->_inventory_restored;
    }
    public function setInventoryRestored(?bool $value): void
    {
        $this->_inventory_restored = $value;
    }

    public function isCouponReturned(): ?bool
    {
        return $this->_coupon_returned;
    }

    public function setCouponReturned(?bool $value): void
    {
        $this->_coupon_returned = $value;
    }
    /** @return array<int, array<string,mixed>> */
    public function getItems(): array
    {
        return $this->items;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }
    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }
    public function setSubtotal(float $amount): void
    {
        $this->subtotal = $amount;
    }
    public function setTax(float $amount): void
    {
        $this->tax = $amount;
    }
    public function setShipping(float $amount): void
    {
        $this->shipping = $amount;
    }
    public function setDiscount(float $amount): void
    {
        $this->discount = $amount;
    }
    public function setTotal(float $amount): void
    {
        $this->total = $amount;
    }
    public function setCouponCode(?string $code): void
    {
        $this->couponCode = $code;
    }
    public function setBilling(array $billing): void
    {
        $this->billing = $billing;
    }
    public function setShippingAddress(array $address): void
    {
        $this->shippingAddress = $address;
    }
    public function setCustomerNote(?string $note): void
    {
        $this->customerNote = $note;
    }
    public function setPaymentMethod(?string $method): void
    {
        $this->paymentMethod = $method;
    }
    public function setTransactionId(?string $id): void
    {
        $this->transactionId = $id;
    }    public function setPaymentToken(?string $token): void
    {
        $this->paymentToken = $token;
    }    public function setPaidAt(?\DateTimeImmutable $at): void
    {
        $this->paidAt = $at;
    }
    public function setCarrier(?string $carrier): void
    {
        $this->carrier = $carrier;
    }
    public function setTrackingNumber(?string $trackingNumber): void
    {
        $this->trackingNumber = $trackingNumber;
    }
    /** @param array<int, array<string,mixed>> $items */
    public function setItems(array $items): void
    {
        $this->items = $items;
    }

    public function isAllItemsDigital(ProductRepositoryInterface $products): bool
    {
        foreach ($this->items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                return false;
            }
            $product = $products->findById($productId);
            if ($product === null || !$product->isDigital()) {
                return false;
            }
            $variationId = $item['variation_id'] ?? null;
            if ($variationId !== null) {
                $variations = $products->findVariationsByProductId($productId);
                foreach ($variations as $v) {
                    if ((int) $v['id'] === (int) $variationId) {
                        $mode = $v['download_mode'] ?? 'use_parent';
                        if ($mode === 'non_digital') {
                            $mode = 'physical';
                        }
                        if ($mode === 'physical') {
                            return false;
                        }
                        break;
                    }
                }
            }
        }
        return true;
    }

    public function calculateTotal(): void
    {
        $this->total = Money::toFloat(Money::round2(
            Money::sub(Money::add(Money::add((string) $this->subtotal, (string) $this->tax), (string) $this->shipping), (string) $this->discount)
        ));
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, array $items = []): self
    {
        $billing = [];
        if (!empty($row['billing'])) {
            $billing = json_decode($row['billing'], true) ?? [];
        }

        $order = new self(
            $row['order_number'],
            $billing,
            isset($row['user_id']) ? (int) $row['user_id'] : null,
        );

        $order->id              = (int) $row['id'];
        $order->status          = $row['status']   ?? 'pending';
        $order->locale          = $row['locale']   ?? 'en';
        $order->currency        = $row['currency'] ?? 'USD';
        $order->subtotal        = (float) ($row['subtotal'] ?? 0);
        $order->tax             = (float) ($row['tax'] ?? 0);
        $order->shipping        = (float) ($row['shipping'] ?? 0);
        $order->discount        = (float) ($row['discount'] ?? 0);
        $order->total           = (float) ($row['total'] ?? 0);
        $order->couponCode      = $row['coupon_code']      ?? null;
        $order->customerNote    = $row['customer_note']    ?? null;
        $order->paymentMethod   = $row['payment_method']   ?? null;
        $order->transactionId   = $row['transaction_id']   ?? null;
        $order->paymentToken    = $row['payment_token']    ?? null;
        $order->carrier         = $row['carrier']          ?? null;
        $order->trackingNumber  = $row['tracking_number']  ?? null;
        $order->orderType       = $row['order_type']       ?? 'shop';
        $order->planId          = isset($row['plan_id']) ? (int) $row['plan_id'] : null;
        $order->subscriptionId  = $row['subscription_id']  ?? null;
        $order->planName        = $row['plan_name']        ?? null;
        if (array_key_exists('inventory_processed', $row)) {
            $order->_inventory_processed = $row['inventory_processed'] === null ? null : (bool) $row['inventory_processed'];
        }
        if (array_key_exists('inventory_restored', $row)) {
            $order->_inventory_restored = $row['inventory_restored'] === null ? null : (bool) $row['inventory_restored'];
        }
        if (array_key_exists('coupon_returned', $row)) {
            $order->_coupon_returned = $row['coupon_returned'] === null ? null : (bool) $row['coupon_returned'];
        }
        $order->items           = $items;

        if (!empty($row['shipping_address'])) {
            $order->shippingAddress = json_decode($row['shipping_address'], true) ?? [];
        }
        if (!empty($row['paid_at'])) {
            $order->paidAt = new \DateTimeImmutable($row['paid_at']);
        }
        if (!empty($row['created_at'])) {
            $order->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $order->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }

        return $order;
    }
}
