<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class WfeOrderReturnRequestRepository
{
    private $db;
    private $table;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->table = _DB_PREFIX_ . WfeOrderReturns::TABLE_NAME;
    }

    public function findByCustomerEmail(string $email, int $periodDays): array
    {
        $periodDays = (int) $periodDays + 30;
        $email      = pSQL($email);

        return $this->db->executeS("
            SELECT `id_order_return_request`, `order_reference`, `status`, `date_add`			
            FROM `{$this->table}`
			INNER JOIN (
				SELECT 
					id_order,
					MAX(date_add) AS date_status_change
				FROM ps_order_history
				WHERE id_order_state IN (5, 35)
				GROUP BY id_order
			) status_filter 
				ON status_filter.id_order = o.id_order			
            WHERE `customer_email` = '{$email}'
			AND status_filter.date_status_change >= DATE_SUB(NOW(), INTERVAL {$periodDays} DAY)
            ORDER BY `date_add` DESC		
        ") ?: [];
    }

    public function findAll(int $page = 1, int $limit = 20, string $orderBy = 'date_add', string $orderDir = 'DESC'): array
    {
        $allowedOrderBy = ['date_add', 'order_reference', 'customer_email', 'status', 'order_date'];
        $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';
        $orderBy = in_array($orderBy, $allowedOrderBy, true) ? $orderBy : 'date_add';

        $offset = ($page - 1) * $limit;
        $limit = (int) $limit;
        $offset = (int) $offset;

        $sql = "SELECT * FROM `{$this->table}`
                ORDER BY `{$orderBy}` {$orderDir}
                LIMIT {$limit} OFFSET {$offset}";

        return $this->db->executeS($sql) ?: [];
    }

    public function countAll(): int
    {
        return (int) $this->db->getValue("SELECT COUNT(*) FROM `{$this->table}`");
    }

    public function countNewReturns(): int
    {
        return (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->table}` WHERE `viewed` = 0"
        );
    }

    public function findById(int $id): ?array
    {
        $result = $this->db->getRow(
            "SELECT * FROM `{$this->table}` WHERE `id_order_return_request` = " . (int) $id
        );

        return $result ?: null;
    }

    public function existsForOrder(string $orderReference, string $email): bool
    {
        $ref = pSQL($orderReference);
        $emailSanitized = pSQL($email);

        return (bool) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->table}`
             WHERE `order_reference` = '{$ref}'
             AND `customer_email` = '{$emailSanitized}'"
        );
    }

    public function markAllAsViewed(): bool
    {
        return $this->db->execute(
            "UPDATE `{$this->table}` SET `viewed` = 1 WHERE `viewed` = 0"
        );
    }

    public function updateEmployeeNote(int $id, string $note): bool
    {
        $note = pSQL($note);
        $id = (int) $id;

        return $this->db->execute(
            "UPDATE `{$this->table}` SET `employee_note` = '{$note}', `date_upd` = NOW()
             WHERE `id_order_return_request` = {$id}"
        );
    }

    public function updateStatus(int $id, string $status): bool
    {
        $allowedStatuses = ['new', 'processing', 'completed', 'rejected'];
        if (!in_array($status, $allowedStatuses, true)) {
            return false;
        }

        $id = (int) $id;
        $statusSanitized = pSQL($status);

        return $this->db->execute(
            "UPDATE `{$this->table}` SET `status` = '{$statusSanitized}', `date_upd` = NOW()
             WHERE `id_order_return_request` = {$id}"
        );
    }

    public function getOrdersForCustomer(int $customerId, int $periodDays): array
    {
        $periodDays = (int) $periodDays;
        $customerId = (int) $customerId;

        // Fetch orders	
		$sql = "SELECT o.`id_order`, o.`reference`, o.`date_add`
                FROM `" . _DB_PREFIX_ . "orders` o
				INNER JOIN (
					SELECT 
						id_order,
						MAX(date_add) AS date_status_change
					FROM ps_order_history
					WHERE id_order_state IN (5, 35)
					GROUP BY id_order
				) status_filter 
					ON status_filter.id_order = o.id_order				
                WHERE o.`id_customer` = {$customerId}
                AND status_filter.date_status_change >= DATE_SUB(NOW(), INTERVAL {$periodDays} DAY)
                ORDER BY o.`date_add` DESC";

        $orders = $this->db->executeS($sql) ?: [];

        if (empty($orders)) {
            return [];
        }

        $orderIds = implode(',', array_map('intval', array_column($orders, 'id_order')));

        $sqlProducts = "SELECT od.`id_order`, od.`id_order_detail`, od.`product_id`, od.`product_name`,
                               od.`product_quantity`, od.`product_reference`, od.`id_customization`,
                               p.`is_virtual`
                        FROM `" . _DB_PREFIX_ . "order_detail` od
                        LEFT JOIN `" . _DB_PREFIX_ . "product` p ON p.`id_product` = od.`product_id`
                        WHERE od.`id_order` IN ({$orderIds})
                        ORDER BY od.`id_order`, od.`id_order_detail`";

        $rows = $this->db->executeS($sqlProducts) ?: [];

        $blockCustomized = (bool) Configuration::get(WfeOrderReturns::CONFIG_BLOCK_CUSTOMIZED);
        $blockVirtual    = (bool) Configuration::get(WfeOrderReturns::CONFIG_BLOCK_VIRTUAL);
        $blockedCatIds   = array_filter(array_map('intval', explode(',', (string) Configuration::get(WfeOrderReturns::CONFIG_BLOCK_CATEGORIES))));

        $productIds = array_unique(array_column($rows, 'product_id'));
        $productInBlockedCat = [];

        if (!empty($blockedCatIds) && !empty($productIds)) {
            $pidList = implode(',', array_map('intval', $productIds));
            $catIds  = implode(',', $blockedCatIds);
            $catRows = $this->db->executeS(
                "SELECT DISTINCT `id_product`
                 FROM `" . _DB_PREFIX_ . "category_product`
                 WHERE `id_product` IN ({$pidList})
                 AND `id_category` IN ({$catIds})"
            ) ?: [];
            foreach ($catRows as $cr) {
                $productInBlockedCat[(int) $cr['id_product']] = true;
            }
        }

        $custIds = array_filter(array_column($rows, 'id_customization'));
        $validCustomizationIds = [];
        if ($blockCustomized && !empty($custIds)) {
            $custIdList = implode(',', array_map('intval', $custIds));
            $custRows   = $this->db->executeS(
                "SELECT DISTINCT `id_customization`
                 FROM `" . _DB_PREFIX_ . "customization`
                 WHERE `id_customization` IN ({$custIdList})"
            ) ?: [];
            foreach ($custRows as $cr) {
                $validCustomizationIds[(int) $cr['id_customization']] = true;
            }
        }

        $productsByOrder = [];
        foreach ($rows as $row) {
            $idProduct       = (int) $row['product_id'];
            $idOrderDetail   = (int) $row['id_order_detail'];
            $idCustomization = (int) $row['id_customization'];
            $isVirtual       = (bool) $row['is_virtual'];
            $excluded        = false;
            $excludedReason  = null;

            if ($blockCustomized && $idCustomization > 0 && isset($validCustomizationIds[$idCustomization])) {
                $excluded       = true;
                $excludedReason = 'customized';
            } elseif ($blockVirtual && $isVirtual) {
                $excluded       = true;
                $excludedReason = 'virtual';
            } elseif (!empty($blockedCatIds) && isset($productInBlockedCat[$idProduct])) {
                $excluded       = true;
                $excludedReason = 'category';
            }

            $productsByOrder[(int) $row['id_order']][] = [
                'id'              => $idProduct,
                'id_order_detail' => $idOrderDetail,
                'name'            => $row['product_name'],
                'reference'       => $row['product_reference'],
                'qty'             => (int) $row['product_quantity'],
                'excluded'        => $excluded,
                'excluded_reason' => $excludedReason,
            ];
        }

        foreach ($orders as &$order) {
            $products = $productsByOrder[(int) $order['id_order']] ?? [];
            $order['products']              = $products;
            $order['has_excluded_products'] = !empty(array_filter($products, function ($p) {return !empty($p['excluded']);}));
            $order['all_excluded']          = !empty($products) && empty(array_filter($products, function ($p) {return empty($p['excluded']);}));
        }
        unset($order);

        return $orders;
    }

    public function save(array $data): bool
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->insert(
            WfeOrderReturns::TABLE_NAME,
            [
                'id_order'        => (int) ($data['id_order'] ?? 0),
                'order_reference' => pSQL($data['order_reference']),
                'customer_email'  => pSQL($data['customer_email']),
                'order_date'      => pSQL($data['order_date']),
                'full_return'     => (int) $data['full_return'],
                'products'        => isset($data['products']) ? pSQL($data['products']) : null,
                'reason'          => pSQL($data['reason']),
                'employee_note'   => null,
                'status'          => 'new',
                'viewed'          => 0,
                'date_add'        => pSQL($now),
                'date_upd'        => pSQL($now),
            ]
        );
    }

    public function getLastInsertId(): int
    {
        return (int) $this->db->Insert_ID();
    }

    public function getProductsForOrder(int $idOrder): array
    {
        $idOrder = (int) $idOrder;

        $sqlProducts = "SELECT od.`id_order_detail`, od.`product_id`, od.`product_name`,
                               od.`product_quantity`, od.`product_reference`, od.`id_customization`,
                               p.`is_virtual`
                        FROM `" . _DB_PREFIX_ . "order_detail` od
                        LEFT JOIN `" . _DB_PREFIX_ . "product` p ON p.`id_product` = od.`product_id`
                        WHERE od.`id_order` = {$idOrder}
                        ORDER BY od.`id_order_detail`";

        $rows = $this->db->executeS($sqlProducts) ?: [];

        if (empty($rows)) {
            return [];
        }

        $blockCustomized = (bool) Configuration::get(WfeOrderReturns::CONFIG_BLOCK_CUSTOMIZED);
        $blockVirtual    = (bool) Configuration::get(WfeOrderReturns::CONFIG_BLOCK_VIRTUAL);
        $blockedCatIds   = array_filter(array_map('intval', explode(',', (string) Configuration::get(WfeOrderReturns::CONFIG_BLOCK_CATEGORIES))));

        $productIds = array_unique(array_column($rows, 'product_id'));
        $productInBlockedCat = [];
        if (!empty($blockedCatIds) && !empty($productIds)) {
            $pidList = implode(',', array_map('intval', $productIds));
            $catIds  = implode(',', $blockedCatIds);
            $catRows = $this->db->executeS(
                "SELECT DISTINCT `id_product`
                 FROM `" . _DB_PREFIX_ . "category_product`
                 WHERE `id_product` IN ({$pidList})
                 AND `id_category` IN ({$catIds})"
            ) ?: [];
            foreach ($catRows as $cr) {
                $productInBlockedCat[(int) $cr['id_product']] = true;
            }
        }

        $custIds = array_filter(array_column($rows, 'id_customization'));
        $validCustomizationIds = [];
        if ($blockCustomized && !empty($custIds)) {
            $custIdList = implode(',', array_map('intval', $custIds));
            $custRows   = $this->db->executeS(
                "SELECT DISTINCT `id_customization`
                 FROM `" . _DB_PREFIX_ . "customization`
                 WHERE `id_customization` IN ({$custIdList})"
            ) ?: [];
            foreach ($custRows as $cr) {
                $validCustomizationIds[(int) $cr['id_customization']] = true;
            }
        }

        $products = [];
        foreach ($rows as $row) {
            $idProduct       = (int) $row['product_id'];
            $idCustomization = (int) $row['id_customization'];
            $isVirtual       = (bool) $row['is_virtual'];
            $excluded        = false;
            $excludedReason  = null;

            if ($blockCustomized && $idCustomization > 0 && isset($validCustomizationIds[$idCustomization])) {
                $excluded       = true;
                $excludedReason = 'customized';
            } elseif ($blockVirtual && $isVirtual) {
                $excluded       = true;
                $excludedReason = 'virtual';
            } elseif (!empty($blockedCatIds) && isset($productInBlockedCat[$idProduct])) {
                $excluded       = true;
                $excludedReason = 'category';
            }

            $products[] = [
                'id'              => $idProduct,
                'id_order_detail' => (int) $row['id_order_detail'],
                'name'            => $row['product_name'],
                'reference'       => $row['product_reference'],
                'qty'             => (int) $row['product_quantity'],
                'excluded'        => $excluded,
                'excluded_reason' => $excludedReason,
            ];
        }

        return $products;
    }
}
