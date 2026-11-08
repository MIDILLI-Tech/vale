<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\OrderStatus;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\DownloadRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;

/**
 * Side effects shared by every path that changes an order into/out of a paid
 * state (admin status update, API status update, refund approval, manual order
 * creation). Keeping them in one place prevents the web UI and the REST API
 * from drifting apart on coupon usage, download revocation and tagging.
 */
final class OrderStatusEffects
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly CouponRepositoryInterface $coupons,
        private readonly ?SubscriberRepositoryInterface $subscribers = null,
        private readonly ?DownloadRepositoryInterface $downloadRepo = null,
        private readonly ?AutomationService $automationService = null,
    ) {
    }

    /** Return or re-consume the coupon use when an order enters/leaves a paid state
     *  or ends up unpaid without ever having been paid. */
    public function syncCouponUsage(Order $order, string $oldStatus, string $newStatus): void
    {
        $couponCode = $order->getCouponCode();
        if ($couponCode === null || $couponCode === '') {
            return;
        }

        $wasPaid = OrderStatus::isPaid($oldStatus);
        $isPaid  = OrderStatus::isPaid($newStatus);

        if ($wasPaid && !$isPaid) {
            // Leaving the paid state: return the use once.
            $this->returnCouponUse($order, (string) $couponCode);
            return;
        }

        if (!$wasPaid && $isPaid && (
            $order->isInventoryRestored() === true || $order->isCouponReturned() === true
        )) {
            // Re-entering the paid state after a prior return: consume again.
            $coupon = $this->coupons->findByCode((string) $couponCode);
            if ($coupon !== null
                && $this->coupons->incrementUsesIfAvailable((int) $coupon->getId())
            ) {
                $this->orders->resetCouponReturn((int) $order->getId());
                $order->setCouponReturned(false);
            }
            return;
        }

        // Never reached a paid state but ended in a terminal unpaid status
        // (cancelled / failed / refunded) — the use reserved at creation must
        // go back, exactly like the checkout and gateway "payment failed"
        // paths do. The atomic claim makes this idempotent.
        if (!$wasPaid && !$isPaid && OrderStatus::isUnpaidTerminal($newStatus)) {
            $this->returnCouponUse($order, (string) $couponCode);
        }
    }

    /** Atomically return a coupon use exactly once per order. */
    private function returnCouponUse(Order $order, string $couponCode): void
    {
        if (!$this->orders->claimCouponReturn((int) $order->getId())) {
            return;
        }
        $order->setCouponReturned(true);
        $coupon = $this->coupons->findByCode($couponCode);
        if ($coupon !== null) {
            $this->coupons->decrementUses((int) $coupon->getId());
        }
    }

    /** Revoke digital download grants once an order is cancelled or refunded. */
    public function syncDownloadRevocation(Order $order, string $status): void
    {
        if ($this->downloadRepo === null) {
            return;
        }

        if (OrderStatus::isReversal($status)) {
            $this->downloadRepo->setRevokedByOrderId($order->getId(), true);
        }
    }

    /** Tag the buyer as a customer when their order becomes paid. */
    public function tagSubscriberPaid(Order $order): void
    {
        if ($this->subscribers === null) {
            return;
        }
        $email = strtolower(trim((string) ($order->getBilling()['email'] ?? '')));
        if ($email === '') {
            return;
        }
        $subscriber = $this->subscribers->findByEmail($email);
        if ($subscriber === null) {
            return;
        }
        $tags    = $subscriber->getTags();
        $changed = false;
        if (!in_array('customer', $tags, true)) {
            $tags[]  = 'customer';
            $changed = true;
        }
        $idx = array_search('pending_order', $tags, true);
        if ($idx !== false) {
            array_splice($tags, $idx, 1);
            $changed = true;
        }
        if ($changed) {
            $subscriber->setTags($tags);
            $this->subscribers->save($subscriber);
            $this->automationService?->enrollSubscriberByTag($subscriber, 'customer');
        }
    }

    /** Drop the customer/pending tags when an order is cancelled or refunded. */
    public function tagSubscriberReversed(Order $order): void
    {
        if ($this->subscribers === null) {
            return;
        }
        $email = strtolower(trim((string) ($order->getBilling()['email'] ?? '')));
        if ($email === '') {
            return;
        }
        $subscriber = $this->subscribers->findByEmail($email);
        if ($subscriber === null) {
            return;
        }

        // Keep the customer tag when the buyer still has other paid orders.
        $hasOtherPaidOrders = $this->orders->hasPaidOrdersByEmail($email, $order->getId());

        $tags    = $subscriber->getTags();
        $changed = false;

        $idx = array_search('pending_order', $tags, true);
        if ($idx !== false) {
            array_splice($tags, $idx, 1);
            $changed = true;
        }

        if (!$hasOtherPaidOrders) {
            $idx = array_search('customer', $tags, true);
            if ($idx !== false) {
                array_splice($tags, $idx, 1);
                $changed = true;
            }
        }

        if ($changed) {
            $subscriber->setTags($tags);
            $this->subscribers->save($subscriber);
        }
    }
}
