<?php
/**
 * Order Returns - Front Controller
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'wfeorderreturns/wfeorderreturns.php';
require_once _PS_MODULE_DIR_ . 'wfeorderreturns/classes/WfeOrderReturnRequest.php';
require_once _PS_MODULE_DIR_ . 'wfeorderreturns/classes/WfeOrderReturnRequestRepository.php';

class WfeOrderReturnsReturnrequestModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ssl  = true;

    /** @var WfeOrderReturnRequestRepository */
    private $repo;

    /** @var bool */
    private $formSuccess = false;

    /** @var array */
    private $formErrors = [];

    /** @var array */
    private $formData = [];

    private const GUEST_SESSION_KEY = 'wfeorderreturns_guest_verified';

    private const GUEST_SESSION_TTL = 1800; // 30 minutes

    public function __construct()
    {
        parent::__construct();
        $this->repo = new WfeOrderReturnRequestRepository();
    }

    public function postProcess()
    {
        if (Tools::getValue('reset') === '1') {
            $this->clearGuestSessionPayload();
            Tools::redirect($this->context->link->getModuleLink('wfeorderreturns', 'returnrequest'));
            return;
        }
        if (Tools::isSubmit('submitGuestVerify')) {
            $this->handleGuestVerification();
            return;
        }

        if (Tools::isSubmit('submitReturnRequest')) {
            $this->handleReturnSubmission();
        }
    }

    public function initContent()
    {
        parent::initContent();

        $isLogged   = (bool) $this->context->customer->isLogged();
        $periodDays = (int) Configuration::get(WfeOrderReturns::CONFIG_RETURN_PERIOD);

        $previousReturns = [];
        $customerOrders  = [];

        if ($isLogged) {
            $previousReturns = $this->repo->findByCustomerEmail(
                $this->context->customer->email,
                $periodDays
            );
            $customerOrders = $this->repo->getOrdersForCustomer(
                (int) $this->context->customer->id,
                $periodDays
            );
        }

        $orderExclusionsMap = [];
        foreach ($customerOrders as $order) {
            $excluded = [];
            foreach ($order['products'] as $p) {
                if ($p['excluded']) {
                    $excluded[] = $p['id_order_detail'];
                }
            }
            $orderExclusionsMap[(int) $order['id_order']] = [
                'excluded_order_detail_ids' => $excluded,
                'has_excluded'              => $order['has_excluded_products'],
                'all_excluded'              => $order['all_excluded'],
            ];
        }

        $exclusionLabels = [
            'customized' => $this->trans('Personalized product – cannot be returned', [], 'Modules.Wfeorderreturns.Returnrequest'),
            'virtual'    => $this->trans('Digital/virtual product – cannot be returned', [], 'Modules.Wfeorderreturns.Returnrequest'),
            'category'   => $this->trans('This product is excluded from returns', [], 'Modules.Wfeorderreturns.Returnrequest'),
        ];

        $guestVerified     = false;
        $guestOrderData    = null;
        $guestExclusions   = ['excluded_order_detail_ids' => [], 'has_excluded' => false, 'all_excluded' => false];

        if (!$isLogged) {
            $payload = $this->getGuestSessionPayload();
            if ($payload !== null) {
                $guestVerified   = true;
                $guestOrderData  = $payload;

                $excl = [];
                foreach ($payload['products'] as $p) {
                    if ($p['excluded']) {
                        $excl[] = $p['id_order_detail'];
                    }
                }
                $hasExcluded = false;
                $allExcluded = !empty($payload['products']);

                foreach ($payload['products'] as $p) {
                    if (!empty($p['excluded'])) {
                        $hasExcluded = true;
                    } else {
                        $allExcluded = false;
                    }

                    if ($hasExcluded && !$allExcluded) {
                        break;
                    }
                }
                $guestExclusions = [
                    'excluded_order_detail_ids' => $excl,
                    'has_excluded'              => $hasExcluded,
                    'all_excluded'              => $allExcluded,
                ];
            }
        }

        $this->context->smarty->assign([
            'is_logged'            => $isLogged,
            'customer_orders'      => $customerOrders,
            'customer_email'       => $isLogged ? $this->context->customer->email : '',
            'period_days'          => $periodDays,
            'csrf_token'           => $this->getCsrfToken(),
            'form_action'          => $this->context->link->getModuleLink('wfeorderreturns', 'returnrequest'),
            'success'              => $this->formSuccess,
            'errors'               => $this->formErrors,
            'form_data'            => $this->formSuccess ? [] : $this->formData,
            'previous_returns'     => $previousReturns,
            'order_exclusions_map' => json_encode($orderExclusionsMap),
            'exclusion_labels'     => $exclusionLabels,
            'guest_verified'       => $guestVerified,
            'guest_order_data'     => $guestOrderData,
            'guest_exclusions'     => json_encode($guestExclusions),
            'status_labels'        => [
                'new'        => $this->trans('New', [], 'Modules.Wfeorderreturns.Admin'),
                'processing' => $this->trans('Processing', [], 'Modules.Wfeorderreturns.Admin'),
                'completed'  => $this->trans('Completed', [], 'Modules.Wfeorderreturns.Admin'),
                'rejected'   => $this->trans('Rejected', [], 'Modules.Wfeorderreturns.Admin'),
            ],
        ]);

        $this->setTemplate('module:wfeorderreturns/views/templates/front/return_request.tpl');
    }

    private function handleGuestVerification(): void
    {
        if (!$this->validateCsrfToken()) {
            $this->formErrors[] = $this->trans('Invalid security token. Please refresh and try again.', [], 'Modules.Wfeorderreturns.Returnrequest');
            return;
        }

        if ($this->isRateLimited('verify', 5, 600)) {
            //$this->formErrors[] = $this->trans('Too many attempts. Please wait a few minutes before trying again.', [], 'Modules.Wfeorderreturns.Returnrequest');
            //return;
        }

        $reference = trim(strip_tags((string) Tools::getValue('order_reference')));
        $email     = trim(strtolower((string) Tools::getValue('customer_email')));
        $date      = trim((string) Tools::getValue('order_date'));

        $formatOk = Validate::isReference($reference)
            && Validate::isEmail($email)
            && Validate::isDate($date);

        if (!$formatOk) {
            $this->formErrors[] = $this->trans('We could not find an order matching the details provided.', [], 'Modules.Wfeorderreturns.Returnrequest');
            return;
        }

        $order = $this->verifyGuestOrder($reference, $email, $date);

        if ($order === null) {
            $this->formErrors[] = $this->trans('We could not find an order matching the details provided.', [], 'Modules.Wfeorderreturns.Returnrequest');
            return;
        }

        $periodDays = (int) Configuration::get(WfeOrderReturns::CONFIG_RETURN_PERIOD);
		$orderDate = (new DateTimeImmutable($order['last_status_date']))
					->setTime(0, 0, 0);

		$cutoffDate = (new DateTimeImmutable('today'))
					->modify('-' . $periodDays . ' days');		
		
		if ($orderDate < $cutoffDate) {				
            $this->formErrors[] = sprintf(
                $this->trans('The return period of %d days has expired for this order.', [], 'Modules.Wfeorderreturns.Returnrequest'),
                $periodDays
            );
            return;
        }

        if ($this->repo->existsForOrder($order['reference'], $email)) {
            $this->formErrors[] = $this->trans('A return request for this order has already been submitted.', [], 'Modules.Wfeorderreturns.Returnrequest');
            return;
        }

        // $products = $this->repo->getProductsForOrder((int) $order['id_order']);

        $this->storeGuestSessionPayload([
            'id_order'  => (int) $order['id_order'],
            'reference' => $order['reference'],
            'date'      => date('Y-m-d', strtotime($order['date_add'])),
			'delivery_date' => date('Y-m-d', strtotime($order['last_status_date'])),
            'email'     => $email,
            //'products'  => $products,
            'expires'   => time() + self::GUEST_SESSION_TTL,
        ]);

        Tools::redirect($this->context->link->getModuleLink('wfeorderreturns', 'returnrequest'));
    }

    private function verifyGuestOrder(string $reference, string $email, string $inputDate): ?array
    {
        $row = Db::getInstance()->getRow(
/*            'SELECT o.`id_order`, o.`reference`, o.`date_add`, c.`email`
             FROM `' . _DB_PREFIX_ . 'orders` o
             INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
             WHERE o.`reference` = \'' . pSQL($reference) . '\''*/
			'SELECT 
            o.`id_order`, 
            o.`reference`, 
            o.`date_add`, 
            c.`email`,
            last_status.`id_order_state` AS `last_status_id`,
            last_status.`date_add` AS `last_status_date`
			FROM `' . _DB_PREFIX_ . 'orders` o
			INNER JOIN `' . _DB_PREFIX_ . 'customer` c 
				ON c.`id_customer` = o.`id_customer`
			LEFT JOIN (
				SELECT 
					oh1.`id_order`,
					oh1.`id_order_state`,
					oh1.`date_add`
				FROM `' . _DB_PREFIX_ . 'order_history` oh1
				INNER JOIN (
					SELECT 
						`id_order`,
						MAX(`date_add`) AS `last_status_date`
					FROM `' . _DB_PREFIX_ . 'order_history`
					WHERE `id_order_state` IN (5, 35)
					GROUP BY `id_order`
				) oh2 
					ON oh2.`id_order` = oh1.`id_order`
				   AND oh2.`last_status_date` = oh1.`date_add`
				WHERE oh1.`id_order_state` IN (5, 35)
			) last_status 
				ON last_status.`id_order` = o.`id_order`
			WHERE o.`reference` = \'' . pSQL($reference) . '\''			
        );

        if (!$row) {
            return null;
        }

        if (strtolower($row['email']) !== strtolower($email)) {
            return null;
        }

        $actualDate = date('Y-m-d', strtotime($row['date_add']));
        if ($actualDate !== $inputDate) {
            return null;
        }

        return $row;
    }

    private function handleReturnSubmission(): void
    {
        $isLogged = (bool) $this->context->customer->isLogged();

        $this->formData = $this->sanitizeFormData();

        if ($this->isRateLimited('submit', 10, 600)) {
            $this->formErrors[] = $this->trans('Too many attempts. Please wait a few minutes before trying again.', [], 'Modules.Wfeorderreturns.Returnrequest');
            return;
        }

        if (!$this->validateCsrfToken()) {
            $this->formErrors[] = $this->trans('Invalid security token. Please refresh and try again.', [], 'Modules.Wfeorderreturns.Returnrequest');
            return;
        }

        if (!Tools::getValue('confirm_request')) {
            $this->formErrors[] = $this->trans('You must confirm the return request.', [], 'Modules.Wfeorderreturns.Returnrequest');
        }

        $guestPayload = null;
        if (!$isLogged) {
            $guestPayload = $this->getGuestSessionPayload();
            if ($guestPayload === null) {
                $this->formErrors[] = $this->trans('Your session has expired. Please verify your order details again.', [], 'Modules.Wfeorderreturns.Returnrequest');
                return;
            }
            $this->formData['id_order']        = $guestPayload['id_order'];
            $this->formData['order_reference'] = $guestPayload['reference'];
            $this->formData['order_date']      = $guestPayload['date'];
			$this->formData['delivery_date']     = $guestPayload['delivery_date'];
            $this->formData['customer_email']  = $guestPayload['email'];
        }

        if (empty($this->formErrors)) {
            $this->formErrors = $this->validateReturnData($this->formData);
        }

        if (empty($this->formErrors)) {
            $this->processSubmission($guestPayload);
        }
    }

    private function sanitizeFormData(): array
    {
        $isLogged = (bool) $this->context->customer->isLogged();

        $data = [
            'id_order'        => 0,
            'order_reference' => trim(strip_tags((string) Tools::getValue('order_reference'))),
            'order_date'      => trim((string) Tools::getValue('order_date')),
            'customer_email'  => trim(strtolower((string) Tools::getValue('customer_email'))),
            'full_return'     => ((string) Tools::getValue('full_return') === '1') ? 1 : 0,
            'products'        => trim(strip_tags((string) Tools::getValue('products'))),
            'reason'          => trim(strip_tags((string) Tools::getValue('reason'))),
        ];

        if ($isLogged) {
            $data['customer_email'] = $this->context->customer->email;
            $selectedOrderId = (int) Tools::getValue('id_order');
            if ($selectedOrderId > 0) {
                $order = new Order($selectedOrderId);
                if (Validate::isLoadedObject($order)
                    && (int) $order->id_customer === (int) $this->context->customer->id
                ) {
                    $data['id_order']        = $selectedOrderId;
                    $data['order_reference'] = $order->reference;
                    $data['order_date']      = date('Y-m-d', strtotime($order->date_add));
                }
            }
        }

        return $data;
    }

    private function validateReturnData(array $data): array
    {
        $errors = [];

        if ((bool) $this->context->customer->isLogged()) {
            if ((int) $data['id_order'] === 0 || empty($data['order_reference'])) {
                $errors[] = $this->trans('Please select an order from the list.', [], 'Modules.Wfeorderreturns.Returnrequest');
            }
        }

        if (!$data['full_return'] && empty($data['products'])) {
            $errors[] = $this->trans('Please specify the products and quantities to return.', [], 'Modules.Wfeorderreturns.Returnrequest');
        }

        if (!empty($data['reason']) && mb_strlen($data['reason']) > 2000) {
            $errors[] = $this->trans('Reason is too long (max 2000 characters).', [], 'Modules.Wfeorderreturns.Returnrequest');
        }

        if (!empty($data['products']) && mb_strlen($data['products']) > 2000) {
            $errors[] = $this->trans('Products field is too long (max 2000 characters).', [], 'Modules.Wfeorderreturns.Returnrequest');
        }

        if ((bool) $this->context->customer->isLogged()
            && !empty($data['order_date'])
            && Validate::isDate($data['order_date'])
        ) {
            $periodDays      = (int) Configuration::get(WfeOrderReturns::CONFIG_RETURN_PERIOD);
			$orderDate = (new DateTimeImmutable($data['delivery_date']))
				->setTime(0, 0, 0);

			$cutoffDate = (new DateTimeImmutable('today'))
				->modify('-' . $periodDays . ' days');
			
            if ($orderDate < $cutoffDate) {
                $errors[] = sprintf(
                    $this->trans('The return period of %d days has expired for this order.', [], 'Modules.Wfeorderreturns.Returnrequest'),
                    $periodDays
                );
            }
        }

        if (empty($errors) && $data['full_return'] && $data['id_order'] > 0) {
            $moduleInstance = Module::getInstanceByName('wfeorderreturns');
            if ($moduleInstance instanceof WfeOrderReturns) {
                $excluded = $moduleInstance->getExcludedOrderDetailIds((int) $data['id_order']);
                if (!empty($excluded)) {
                    $errors[] = $this->trans(
                        'Your order contains products that cannot be returned (personalized, digital, or excluded category). Please select a partial return and choose only the eligible products.',
                        [],
                        'Modules.Wfeorderreturns.Returnrequest'
                    );
                }
            }
        }

        return $errors;
    }

    private function processSubmission(?array $guestPayload): void
    {
        $data     = $this->formData;
        $isLogged = (bool) $this->context->customer->isLogged();

        if (!$isLogged) {
            if ($this->repo->existsForOrder($data['order_reference'], $data['customer_email'])) {
                $this->formErrors[] = $this->trans('A return request for this order has already been submitted.', [], 'Modules.Wfeorderreturns.Returnrequest');
                return;
            }
        } else {
            if ($this->repo->existsForOrder($data['order_reference'], $data['customer_email'])) {
                $this->formErrors[] = $this->trans('A return request for this order has already been submitted.', [], 'Modules.Wfeorderreturns.Returnrequest');
                return;
            }
        }

        if (!$this->repo->save($data)) {
            $this->formErrors[] = $this->trans('An error occurred while saving your request. Please try again.', [], 'Modules.Wfeorderreturns.Returnrequest');
            return;
        }

        if (!$isLogged) {
            $this->clearGuestSessionPayload();
        }

        $this->formSuccess = true;
        $this->sendConfirmationEmail($data);
    }

	private function storeGuestSessionPayload(array $payload): void
	{
		$json = json_encode($payload);

		if ($json === false) {
			PrestaShopLogger::addLog(
				'WfeOrderReturns: could not encode guest payload: '
				. json_last_error_msg(),
				3
			);

			return;
		}

		$encoded   = base64_encode($json);
		$signature = $this->signPayload($json);

		$this->context->cookie->wfeorderreturns_guest = $encoded;
		$this->context->cookie->wfeorderreturns_gsig  = $signature;

		$this->context->cookie->write();
	}

	private function getGuestSessionPayload(): ?array
	{
		$encoded = isset($this->context->cookie->wfeorderreturns_guest)
			? (string) $this->context->cookie->wfeorderreturns_guest
			: '';

		$signature = isset($this->context->cookie->wfeorderreturns_gsig)
			? (string) $this->context->cookie->wfeorderreturns_gsig
			: '';

		if ($encoded === '' || $signature === '') {
			return null;
		}

		$json = base64_decode($encoded, true);

		if ($json === false || $json === '') {
			$this->clearGuestSessionPayload();
			return null;
		}

		$expectedSignature = $this->signPayload($json);

		if (!hash_equals($expectedSignature, $signature)) {
			$this->clearGuestSessionPayload();
			return null;
		}

		$payload = json_decode($json, true);

		if (!is_array($payload)) {
			$this->clearGuestSessionPayload();
			return null;
		}

		if (
			empty($payload['id_order'])
			|| empty($payload['reference'])
			|| empty($payload['email'])
			|| empty($payload['expires'])
		) {
			$this->clearGuestSessionPayload();
			return null;
		}

		if (time() > (int) $payload['expires']) {
			$this->clearGuestSessionPayload();
			return null;
		}

		$payload['products'] = $this->repo->getProductsForOrder(
			(int) $payload['id_order']
		);

		return $payload;
	}

    private function clearGuestSessionPayload(): void
    {
	    unset($this->context->cookie->wfeorderreturns_guest);
	    unset($this->context->cookie->wfeorderreturns_gsig);
		
        $this->context->cookie->write();
    }

    private function signPayload(string $json): string
    {
        return hash_hmac('sha256', $json, _COOKIE_KEY_ . 'wfeorderreturns_guest_v1');
    }

    /**
     * @param string $action  Separate counters per action ('verify', 'submit')
     * @param int    $max     Max attempts allowed in the window
     * @param int    $window  Window in seconds
     */
    private function isRateLimited(string $action, int $max, int $window): bool
    {
        $ip  = md5(Tools::getRemoteAddr());
        $key = 'wfeorderreturns_rl_' . $action . '_' . $ip;

        $data = ['count' => 0, 'since' => time()];

        if (isset($this->context->cookie->$key)) {
            $stored = json_decode($this->context->cookie->$key, true);
            if (is_array($stored) && (time() - $stored['since']) < $window) {
                $data = $stored;
            }
        }

        $data['count']++;
        $this->context->cookie->$key = json_encode($data);
        $this->context->cookie->write();

        return $data['count'] > $max;
    }

    private function getCsrfToken(): string
    {
        if (empty($this->context->cookie->wfeorderreturns_csrf)) {
            $this->context->cookie->wfeorderreturns_csrf = bin2hex(random_bytes(16));
            $this->context->cookie->write();
        }
        return (string) $this->context->cookie->wfeorderreturns_csrf;
    }

    private function validateCsrfToken(): bool
    {
        $submitted = (string) Tools::getValue('csrf_token');
        $stored    = (string) ($this->context->cookie->wfeorderreturns_csrf ?? '');
        return $stored !== '' && hash_equals($stored, $submitted);
    }

    private function sendConfirmationEmail(array $data): void
    {
        $vars = [
            '{order_reference}' => $data['order_reference'],
            '{customer_email}'  => $data['customer_email'],
            '{order_date}'      => $data['order_date'],
            '{full_return}'     => $data['full_return'] ? $this->trans('Yes', [], 'Modules.Wfeorderreturns.Returnrequest') : $this->trans('No - partial', [], 'Modules.Wfeorderreturns.Returnrequest'),
            '{products}'        => !empty($data['products']) ? $data['products'] : 'N/A',
            '{reason}'          => $data['reason'],
            '{shop_name}'       => Configuration::get('PS_SHOP_NAME'),
            '{request_date}' => date('d/m/Y H:i', time()),
        ];

        Mail::send(
            (int) $this->context->language->id,
            'return_confirmations',
            $this->trans('Return Request Confirmation', [], 'Modules.Wfeorderreturns.Returnrequest'),
            $vars,
            $data['customer_email'],
            null,
            Configuration::get('PS_SHOP_EMAIL'),
            Configuration::get('PS_SHOP_NAME'),
            null,
            null,
            _PS_MODULE_DIR_ . 'wfeorderreturns/mails/'
        );
    }
}
