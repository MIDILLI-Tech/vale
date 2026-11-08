<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Infrastructure\Security\Encrypter;

final class DbalOrderRepository implements OrderRepositoryInterface
{
    /**
     * Free-text PII fields inside the billing/shipping JSON that are encrypted
     * at rest. email, city, state, postcode and country stay plaintext (email is
     * needed for LIKE lookups, location fields for display and tax).
     *
     * Both `address` (checkout/DemoSeeder) and `address_1` (legacy/admin form)
     * are covered so either shape is encrypted.
     */
    private const ENCRYPTED_FIELDS = ['address', 'address_2', 'address_1', 'first_name', 'last_name', 'phone', 'company'];

    public function __construct(
        private readonly Connection $db,
        private readonly ?Encrypter $encrypter = null,
    ) {
    }

    public function findById(int $id): ?Order
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'orders' . ' WHERE id = ?', [$id], [ParameterType::INTEGER]);
        if ($row === false) {
            return null;
        }
        return Order::fromRow($this->decryptAddressRow($row), $this->fetchItems((int) $row['id']));
    }

    public function findByOrderNumber(string $orderNumber): ?Order
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'orders' . ' WHERE order_number = ?', [$orderNumber], [ParameterType::STRING]);
        if ($row === false) {
            return null;
        }
        return Order::fromRow($this->decryptAddressRow($row), $this->fetchItems((int) $row['id']));
    }

    public function findByPaymentToken(string $token): ?Order
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'orders' . ' WHERE payment_token = ?', [$token], [ParameterType::STRING]);
        if ($row === false) {
            return null;
        }
        return Order::fromRow($this->decryptAddressRow($row), $this->fetchItems((int) $row['id']));
    }

    /** @return Order[] */
    public function findAll(Pagination $pagination, array $filters = []): array
    {
        [$where, $params, $types] = $this->buildWhere($filters);
        $orderBy                  = $pagination->orderByClause(
            ['id', 'order_number', 'total', 'status', 'payment_method', 'created_at', 'coupon_code'],
            'id DESC',
        );
        $sql      = 'SELECT * FROM ' . VL_DB_PREFIX . 'orders' . $where . $orderBy . ' LIMIT ? OFFSET ?';
        $params[] = $pagination->perPage;
        $params[] = $pagination->offset;
        $types[]  = ParameterType::INTEGER;
        $types[]  = ParameterType::INTEGER;
        $rows     = $this->db->fetchAllAssociative($sql, $params, $types);
        return $this->hydrateOrderRows($rows);
    }

    public function count(array $filters = []): int
    {
        [$where, $params, $types] = $this->buildWhere($filters);
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'orders' . $where, $params, $types);
    }

    public function save(Order $order): Order
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'order_number'     => $order->getOrderNumber(),
            'user_id'          => $order->getUserId(),
            'status'           => $order->getStatus(),
            'locale'           => $order->getLocale(),
            'currency'         => $order->getCurrency(),
            'subtotal'         => $order->getSubtotal(),
            'tax'              => $order->getTax(),
            'shipping'         => $order->getShipping(),
            'discount'         => $order->getDiscount(),
            'total'            => $order->getTotal(),
            'coupon_code'      => $order->getCouponCode(),
            'billing'          => json_encode($this->encryptAddressFields($order->getBilling())),
            'shipping_address' => json_encode($this->encryptAddressFields($order->getShippingAddress())),
            'customer_note'    => $order->getCustomerNote(),
            'payment_method'   => $order->getPaymentMethod(),
            'transaction_id'   => $order->getTransactionId(),
            'payment_token'    => $order->getPaymentToken(),
            'paid_at'          => $order->getPaidAt()?->format('Y-m-d H:i:s'),
            'carrier'          => $order->getCarrier(),
            'tracking_number'  => $order->getTrackingNumber(),
            'order_type'       => $order->getOrderType(),
            'plan_id'          => $order->getPlanId(),
            'subscription_id'  => $order->getSubscriptionId(),
            'updated_at'       => $now,
        ];

        // Persist the inventory flags only when known — a freshly built entity
        // must not erase flags already stored by a concurrent process.
        if ($order->isInventoryProcessed() !== null) {
            $data['inventory_processed'] = $order->isInventoryProcessed() ? 1 : 0;
        }
        if ($order->isInventoryRestored() !== null) {
            $data['inventory_restored'] = $order->isInventoryRestored() ? 1 : 0;
        }
        if ($order->isCouponReturned() !== null) {
            $data['coupon_returned'] = $order->isCouponReturned() ? 1 : 0;
        }

        if ($order->getId() === null) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'orders', $data);
            $order->setId((int) $this->db->lastInsertId());

            // Persist order items on first save
            foreach ($order->getItems() as $item) {
                $this->db->insert(VL_DB_PREFIX . 'order_items', [
                    'order_id'       => $order->getId(),
                    'product_id'     => $item['product_id']     ?? null,
                    'variation_id'   => $item['variation_id']   ?? null,
                    'name'           => $item['name']           ?? '',
                    'sku'            => $item['sku']            ?? null,
                    'quantity'       => $item['qty']            ?? 1,
                    'price'          => $item['price']          ?? 0,
                    'tax_rate'       => isset($item['tax_rate']) && $item['tax_rate'] !== null
                        ? (float) $item['tax_rate']
                        : null,
                    'tax_amount'     => isset($item['tax_amount']) && $item['tax_amount'] !== null
                        ? (float) $item['tax_amount']
                        : null,
                    'subtotal'       => $item['subtotal']       ?? 0,
                    'total'          => $item['subtotal']       ?? 0,
                    'variation_data' => $item['variation_data'] ?? null,
                ]);
            }
        } else {
            $this->db->update(VL_DB_PREFIX . 'orders', $data, ['id' => $order->getId()]);
        }

        return $order;
    }

    public function generateOrderNumber(): string
    {
        return 'ORD-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'order_items', ['order_id' => $id]);
        $this->db->delete(VL_DB_PREFIX . 'orders', ['id' => $id]);
    }

    public function claimInventoryProcessing(int $orderId): bool
    {
        $affected = $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'orders SET inventory_processed = 1, inventory_restored = 0'
            . ' WHERE id = ? AND (inventory_processed IS NULL OR inventory_processed = 0)',
            [$orderId],
            [ParameterType::INTEGER],
        );
        return $affected > 0;
    }

    public function claimInventoryRestored(int $orderId): bool
    {
        $affected = $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'orders SET inventory_restored = 1, inventory_processed = 0'
            . ' WHERE id = ? AND (inventory_restored IS NULL OR inventory_restored = 0)',
            [$orderId],
            [ParameterType::INTEGER],
        );
        return $affected > 0;
    }

    public function claimCouponReturn(int $orderId): bool
    {
        $affected = $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'orders SET coupon_returned = 1'
            . ' WHERE id = ? AND (coupon_returned IS NULL OR coupon_returned = 0)',
            [$orderId],
            [ParameterType::INTEGER],
        );
        return $affected > 0;
    }

    public function resetCouponReturn(int $orderId): void
    {
        $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'orders SET coupon_returned = 0 WHERE id = ?',
            [$orderId],
            [ParameterType::INTEGER],
        );
    }

    public function hasPaidOrdersByEmail(string $email, ?int $excludeOrderId = null): bool
    {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $email);
        $sql     = 'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'orders'
            . ' WHERE LOWER(billing) LIKE LOWER(?)'
            . ' AND status NOT IN (\'pending\', \'cancelled\', \'refunded\', \'refund_requested\', \'refund_rejected\')';
        $params = ['%"email":"' . $escaped . '"%'];
        $types  = [ParameterType::STRING];

        if ($excludeOrderId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeOrderId;
            $types[]  = ParameterType::INTEGER;
        }

        return (int) $this->db->fetchOne($sql, $params, $types) > 0;
    }

    public function hasPurchasedProduct(string $email, int $productId): bool
    {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $email);
        $count   = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'orders' . ' o'
            . ' INNER JOIN ' . VL_DB_PREFIX . 'order_items' . ' oi ON oi.order_id = o.id'
            . ' WHERE LOWER(o.billing) LIKE LOWER(?) AND oi.product_id = ?'
            . ' AND o.status NOT IN (\'cancelled\', \'refunded\')',
            ['%"email":"' . $escaped . '"%', $productId],
            [ParameterType::STRING, ParameterType::INTEGER],
        );
        return $count > 0;
    }

    /** @return array<int, array<string,mixed>> */
    private function fetchItems(int $orderId): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'order_items' . ' WHERE order_id = ?',
            [$orderId],
            [ParameterType::INTEGER],
        );
        foreach ($rows as &$row) {
            if (!empty($row['variation_data'])) {
                $decoded               = json_decode((string) $row['variation_data'], true);
                $row['variation_data'] = is_array($decoded) ? $decoded : [];
            } else {
                $row['variation_data'] = [];
            }
        }
        return $rows;
    }

    /** @param array<array<string,mixed>> $rows @return Order[] */
    private function hydrateOrderRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $orderIds = array_map(fn (array $r) => (int) $r['id'], $rows);
        $itemsMap = $this->batchFetchItems($orderIds);

        return array_map(fn (array $row) => Order::fromRow(
            $this->decryptAddressRow($row),
            $itemsMap[(int) $row['id']] ?? [],
        ), $rows);
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    private function encryptAddressFields(array $address): array
    {
        if ($this->encrypter === null) {
            return $address;
        }
        $encrypter = $this->encrypter;
        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (isset($address[$field]) && $address[$field] !== '') {
                $address[$field] = $encrypter->encrypt((string) $address[$field]);
            }
        }
        return $address;
    }

    /**
     * Decrypt the PII fields of a raw order row's billing/shipping columns so
     * the domain entity always holds plaintext. Backward-compatible: values that
     * are not encrypted (legacy rows) or that fail decryption (wrong APP_KEY)
     * are left as-is / emptied, never thrown.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decryptAddressRow(array $row): array
    {
        if ($this->encrypter === null) {
            return $row;
        }
        $encrypter = $this->encrypter;
        foreach (['billing', 'shipping_address'] as $column) {
            if (empty($row[$column])) {
                continue;
            }
            $decoded = json_decode((string) $row[$column], true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach (self::ENCRYPTED_FIELDS as $field) {
                if (isset($decoded[$field]) && $decoded[$field] !== '') {
                    $decoded[$field] = $encrypter->decrypt((string) $decoded[$field]);
                }
            }
            $row[$column] = json_encode($decoded);
        }
        return $row;
    }

    /** @return array<int, array<int, array<string,mixed>>> orderId => items[] */
    private function batchFetchItems(array $orderIds): array
    {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $rows         = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'order_items'
            . " WHERE order_id IN ($placeholders) ORDER BY id ASC",
            $orderIds,
            array_fill(0, count($orderIds), ParameterType::INTEGER),
        );
        foreach ($rows as &$row) {
            if (!empty($row['variation_data'])) {
                $decoded               = json_decode((string) $row['variation_data'], true);
                $row['variation_data'] = is_array($decoded) ? $decoded : [];
            } else {
                $row['variation_data'] = [];
            }
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['order_id']][] = $row;
        }
        return $map;
    }

    /** @return Order[] */
    public function findByUserId(int $userId, int $limit = 20, int $offset = 0): array
    {
        $sql = 'SELECT o.*, mp.name AS plan_name FROM ' . VL_DB_PREFIX . 'orders' . ' o'
            . ' LEFT JOIN ' . VL_DB_PREFIX . 'membership_plans' . ' mp ON mp.id = o.plan_id'
            . ' WHERE o.user_id = ?'
            . ' ORDER BY o.id DESC LIMIT ? OFFSET ?';
        $rows = $this->db->fetchAllAssociative($sql, [$userId, $limit, $offset], [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER]);

        if (empty($rows)) {
            return [];
        }

        return array_map(fn (array $row) => Order::fromRow(
            $this->decryptAddressRow($row),
            $this->fetchItems((int) $row['id']),
        ), $rows);
    }

    public function countByUserId(int $userId, ?string $status = 'completed'): int
    {
        $sql    = 'SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'orders' . ' WHERE user_id = ?';
        $params = [$userId];
        $types  = [ParameterType::INTEGER];

        if ($status !== null) {
            $sql      .= ' AND status = ?';
            $params[] = $status;
            $types[]  = ParameterType::STRING;
        }

        return (int) $this->db->fetchOne($sql, $params, $types);
    }

    public function getTotalSpendByUserId(int $userId, ?string $status = 'completed'): float
    {
        $sql    = 'SELECT COALESCE(SUM(total), 0) FROM ' . VL_DB_PREFIX . 'orders' . ' WHERE user_id = ?';
        $params = [$userId];
        $types  = [ParameterType::INTEGER];

        if ($status !== null) {
            $sql      .= ' AND status = ?';
            $params[] = $status;
            $types[]  = ParameterType::STRING;
        }

        return (float) $this->db->fetchOne($sql, $params, $types);
    }

    public function getLastOrderDateByUserId(int $userId, ?string $status = 'completed'): ?\DateTimeImmutable
    {
        $sql    = 'SELECT created_at FROM ' . VL_DB_PREFIX . 'orders' . ' WHERE user_id = ?';
        $params = [$userId];
        $types  = [ParameterType::INTEGER];

        if ($status !== null) {
            $sql      .= ' AND status = ?';
            $params[] = $status;
            $types[]  = ParameterType::STRING;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT 1';

        $date = $this->db->fetchOne($sql, $params, $types);

        return $date ? new \DateTimeImmutable($date) : null;
    }

    public function getReportData(string $from, string $to): array
    {
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to . ' 23:59:59';

        $excluded = ['cancelled', 'refunded', 'refund_requested', 'refund_rejected'];

        // Summary
        $summary = $this->db->fetchAssociative(
            "SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN status NOT IN (?) THEN total ELSE 0 END), 0) AS total_revenue,
                COALESCE(AVG(CASE WHEN status NOT IN (?) THEN total ELSE NULL END), 0) AS avg_order_value,
                COALESCE(SUM(CASE WHEN status NOT IN (?) THEN tax ELSE 0 END), 0) AS total_tax,
                COALESCE(SUM(CASE WHEN status NOT IN (?) THEN shipping ELSE 0 END), 0) AS total_shipping,
                COALESCE(SUM(CASE WHEN status NOT IN (?) THEN discount ELSE 0 END), 0) AS total_discount,
                COALESCE(SUM(CASE WHEN status IN ('refunded','refund_requested','refund_rejected') THEN total ELSE 0 END), 0) AS total_refunded,
                COUNT(CASE WHEN status IN ('cancelled') THEN 1 END) AS cancelled_count,
                COUNT(CASE WHEN status IN ('refunded','refund_requested','refund_rejected') THEN 1 END) AS refunded_count
            FROM " . VL_DB_PREFIX . 'orders' . "
            WHERE created_at BETWEEN ? AND ?",
            [$excluded, $excluded, $excluded, $excluded, $excluded, $fromDt, $toDt],
            [ArrayParameterType::STRING, ArrayParameterType::STRING, ArrayParameterType::STRING, ArrayParameterType::STRING, ArrayParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );

        if ($summary === false) {
            $summary = [];
        }
        foreach (['total_revenue','avg_order_value','total_tax','total_shipping','total_discount','total_refunded'] as $k) {
            $summary[$k] = (float) ($summary[$k] ?? 0.0);
        }
        foreach (['total_orders','cancelled_count','refunded_count'] as $k) {
            $summary[$k] = (int) ($summary[$k] ?? 0);
        }

        // Monthly breakdown (for income chart + refund chart)
        $monthly = $this->db->fetchAllAssociative(
            "SELECT
                SUBSTR(created_at, 1, 7) AS month,
                COUNT(*) AS orders,
                COALESCE(SUM(CASE WHEN status NOT IN (?) THEN total ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN status IN ('refunded','refund_requested','refund_rejected') THEN total ELSE 0 END), 0) AS refunds
            FROM " . VL_DB_PREFIX . 'orders' . "
            WHERE created_at BETWEEN ? AND ?
            GROUP BY SUBSTR(created_at, 1, 7)
            ORDER BY month ASC",
            [$excluded, $fromDt, $toDt],
            [ArrayParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
        foreach ($monthly as &$m) {
            $m['orders']  = (int) $m['orders'];
            $m['revenue'] = (float) $m['revenue'];
            $m['refunds'] = (float) $m['refunds'];
        }
        unset($m);

        // Top 10 selling products (by qty)
        $topProducts = $this->db->fetchAllAssociative(
            "SELECT
                oi.product_id,
                oi.name AS product_name,
                SUM(oi.quantity) AS qty,
                COALESCE(SUM(oi.subtotal), 0) AS revenue
            FROM " . VL_DB_PREFIX . 'order_items' . " oi
            INNER JOIN " . VL_DB_PREFIX . 'orders' . " o ON o.id = oi.order_id
            WHERE o.created_at BETWEEN ? AND ?
              AND o.status NOT IN (?)
            GROUP BY oi.product_id, oi.name
            ORDER BY qty DESC
            LIMIT 10",
            [$fromDt, $toDt, $excluded],
            [ParameterType::STRING, ParameterType::STRING, ArrayParameterType::STRING],
        );
        foreach ($topProducts as &$p) {
            $p['qty']     = (int) ($p['qty'] ?? 0);
            $p['revenue'] = (float) ($p['revenue'] ?? 0);
        }
        unset($p);

        // Payment methods breakdown
        $paymentMethods = $this->db->fetchAllAssociative(
            "SELECT
                COALESCE(payment_method, 'unknown') AS method,
                COUNT(*) AS orders,
                COALESCE(SUM(CASE WHEN status NOT IN (?) THEN total ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN status IN (?) THEN 1 ELSE 0 END), 0) AS refunded_orders
            FROM " . VL_DB_PREFIX . 'orders' . "
            WHERE created_at BETWEEN ? AND ?
              AND payment_method IS NOT NULL
            GROUP BY payment_method
            ORDER BY revenue DESC",
            [$excluded, $excluded, $fromDt, $toDt],
            [ArrayParameterType::STRING, ArrayParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
        foreach ($paymentMethods as &$pm) {
            $pm['orders']          = (int) ($pm['orders'] ?? 0);
            $pm['revenue']         = (float) ($pm['revenue'] ?? 0);
            $pm['refunded_orders'] = (int) ($pm['refunded_orders'] ?? 0);
        }
        unset($pm);

        // Status breakdown
        $statusBreakdown = $this->db->fetchAllAssociative(
            "SELECT
                status,
                COUNT(*) AS orders,
                COALESCE(SUM(total), 0) AS total
            FROM " . VL_DB_PREFIX . 'orders' . "
            WHERE created_at BETWEEN ? AND ?
            GROUP BY status
            ORDER BY orders DESC",
            [$fromDt, $toDt],
            [ParameterType::STRING, ParameterType::STRING],
        );
        foreach ($statusBreakdown as &$s) {
            $s['orders'] = (int) ($s['orders'] ?? 0);
            $s['total']  = (float) ($s['total'] ?? 0);
        }
        unset($s);

        // Daily totals for chart (last 30 days or range)
        $daily = $this->db->fetchAllAssociative(
            "SELECT
                SUBSTR(created_at, 1, 10) AS day,
                COALESCE(SUM(CASE WHEN status NOT IN (?) THEN total ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN status IN (?) THEN 1 ELSE 0 END), 0) AS refunds
            FROM " . VL_DB_PREFIX . 'orders' . "
            WHERE created_at BETWEEN ? AND ?
            GROUP BY SUBSTR(created_at, 1, 10)
            ORDER BY day ASC",
            [$excluded, $excluded, $fromDt, $toDt],
            [ArrayParameterType::STRING, ArrayParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
        foreach ($daily as &$d) {
            $d['revenue'] = (float) ($d['revenue'] ?? 0);
            $d['refunds'] = (float) ($d['refunds'] ?? 0);
        }
        unset($d);

        return [
            'summary'          => $summary,
            'monthly'          => $monthly,
            'top_products'     => $topProducts,
            'payment_methods'  => $paymentMethods,
            'status_breakdown' => $statusBreakdown,
            'daily'            => $daily,
        ];
    }

    /** @return array{0: string, 1: array<mixed>, 2: array<mixed>} */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params     = [];
        $types      = [];

        if (!empty($filters['status'])) {
            $conditions[] = 'status = ?';
            $params[]     = $filters['status'];
            $types[]      = ParameterType::STRING;
        }

        if (!empty($filters['search'])) {
            $conditions[] = '(LOWER(order_number) LIKE LOWER(?) OR LOWER(billing) LIKE LOWER(?) OR LOWER(coupon_code) LIKE LOWER(?))';
            $term         = '%' . $filters['search'] . '%';
            $params[]     = $term;
            $params[]     = $term;
            $params[]     = $term;
            $types[]      = ParameterType::STRING;
            $types[]      = ParameterType::STRING;
            $types[]      = ParameterType::STRING;
        }

        if (!empty($filters['from'])) {
            $conditions[] = 'created_at >= ?';
            $params[]     = $filters['from'] . ' 00:00:00';
            $types[]      = ParameterType::STRING;
        }
        if (!empty($filters['to'])) {
            $conditions[] = 'created_at <= ?';
            $params[]     = $filters['to'] . ' 23:59:59';
            $types[]      = ParameterType::STRING;
        }

        if (!empty($filters['coupon_code'])) {
            $conditions[] = 'coupon_code = ?';
            $params[]     = $filters['coupon_code'];
            $types[]      = ParameterType::STRING;
        }

        if (!empty($filters['updated_since'])) {
            $since         = (string) $filters['updated_since'];
            $since         = preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) === 1 ? $since . ' 00:00:00' : $since;
            $conditions[]  = 'updated_at >= ?';
            $params[]      = $since;
            $types[]       = ParameterType::STRING;
        }

        if (!empty($filters['product_id'])) {
            $conditions[] = 'id IN (SELECT order_id FROM ' . VL_DB_PREFIX . 'order_items' . ' WHERE product_id = ?)';
            $params[]     = $filters['product_id'];
            $types[]      = ParameterType::INTEGER;
        }

        if (!empty($filters['user_id'])) {
            $conditions[] = 'user_id = ?';
            $params[]     = (int) $filters['user_id'];
            $types[]      = ParameterType::INTEGER;
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        return [$where, $params, $types];
    }
}
