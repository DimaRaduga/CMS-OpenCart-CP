<?php

/**
 * Class ControllerPaymentCloudPayments
 *
 * @property Language $language
 * @property \Cart\Currency $currency
 * @property Config $config
 * @property Url $url
 * @property Loader $load
 * @property Session $session
 * @property Request $request
 * @property Response $response
 *
 * @property ModelAccountOrder $model_account_order
 * @property ModelCheckoutOrder $model_checkout_order
 * @property ModelPaymentCloudPayments $model_payment_cloudpayments
 */
class ControllerPaymentCloudPayments extends Controller
{
    const PAYMENT_RESULT_SUCCESS = 0;
    const PAYMENT_RESULT_ERROR_INVALID_ORDER = 10;
    const PAYMENT_RESULT_ERROR_INVALID_COST = 11;
    const PAYMENT_RESULT_ERROR_NOT_ACCEPTED = 13;
    const PAYMENT_RESULT_ERROR_EXPIRED = 20;

    const NOTIFY_CHECK = 'check';
    const NOTIFY_PAY = 'pay';
    const NOTIFY_CONFIRM = 'confirm';
    const NOTIFY_REFUND = 'refund';
    const NOTIFY_FAIL = 'fail';

    /** @var  resource */
    private $curl;

    /** @var  Log */
    private $extension_log;

    public function index()
    {
        $this->load->language('payment/cloudpayments');

        $this->load->model('checkout/order');

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $lang_code = $this->language->get('code');
        switch ($lang_code) {
            case 'ru':
                $widget_lang = 'ru-RU';
                break;
            default:
                $widget_lang = 'en-US';
        }

        $data = array(
            'button_pay' => $this->language->get('button_pay'),
            'public_id' => $this->config->get('cloudpayments_public_id'),
            'language' => $widget_lang,
            'order_currency' => $order_info['currency_code'],
            'description' => sprintf($this->language->get('order_description'), $order_info['order_id']),
            'order_total' => $order_info['total'],
            'order_invoice_id' => $order_info['order_id'],
            'order_email' => $order_info['email'],
            'order_phone' => $order_info['telephone'],
            'customer_id' => $order_info['customer_id'],
            'widget_pay_method' => $this->config->get('cloudpayments_two_steps') ? 'auth' : 'charge',
            'success_url' => $this->url->link('checkout/success'),
            'failure_url' => $this->url->link('checkout/failure'),
            'widget_data' => array(
                'name' => trim($order_info['firstname'] . ' ' . $order_info['lastname']),
                'phone' => $order_info['telephone'],
            )
        );

        if ($this->config->get('cloudpayments_kkt')) {
            $data['widget_data']['cloudPayments'] = array(
                'customerReceipt' => $this->getReceiptData($order_info)
            );
        }

        return $this->load->view('payment/cloudpayments', $data);
    }

    private function getReceiptData($order_info)
    {

        $this->load->model('account/order');

        $receiptData = array(
            'Items' => array(),
            'taxationSystem' => $this->config->get('cloudpayments_taxation_system'),
            'email' => $order_info['email'],
            'phone' => $order_info['telephone']
        );

        $order_products = $this->model_account_order->getOrderProducts($order_info['order_id']);

        $vat = $this->config->get('cloudpayments_vat');
        foreach ($order_products as $order_product) {
            $item = array(
                'label' => trim($order_product['name'] . ' ' . $order_product['model']),
                'price' => floatval($order_product['price']),
                'quantity' => floatval($order_product['quantity']),
                'amount' => floatval($order_product['total']),
            );
            if (!empty($vat)) {
                $item['vat'] = $vat;
            }
            $receiptData['Items'][] = $item;
        }

        // Order Totals
        $order_totals = array();
        foreach ($this->model_account_order->getOrderTotals($order_info['order_id']) as $row) {
            $order_totals[$row['code']] = $row;
        };

        if (isset($order_totals['shipping']) && $order_totals['shipping']['value'] > 0) {
            $item = array(
                'label' => $order_totals['shipping']['title'],
                'price' => floatval($order_totals['shipping']['value']),
                'quantity' => 1,
                'amount' => floatval($order_totals['shipping']['value'])
            );

            $vat = $this->config->get('cloudpayments_vat_delivery');
            if (!empty($vat)) {
                $item['vat'] = $vat;
            }
            $receiptData['Items'][] = $item;
        }

        return $receiptData;
    }

    /**
     * Check notify request
     */
    public function notifyCheck()
    {
        $this->processNotifyRequest(self::NOTIFY_CHECK);
    }

    /**
     * Pay notify request
     */
    public function notifyPay()
    {
        $this->processNotifyRequest(self::NOTIFY_PAY);
    }

    /**
     * Confirm notify request
     */
    public function notifyConfirm()
    {
        $this->processNotifyRequest(self::NOTIFY_CONFIRM);
    }

    /**
     * Refund notify request
     */
    public function notifyRefund()
    {
        $this->processNotifyRequest(self::NOTIFY_REFUND);
    }

    /**
     * Fail notify request
     */
    public function notifyFail()
    {
        $this->processNotifyRequest(self::NOTIFY_FAIL);
    }

    /**
     * @param $notify
     */
    private function processNotifyRequest($notify)
    {
        $this->response->addHeader('Content-Type: application/json');
        if (!$this->validateRequest()) {
            $this->response->setOutput(json_encode(array('code' => self::PAYMENT_RESULT_ERROR_NOT_ACCEPTED)));
            return;
        }

        $order = $this->retrieveOrderFromRequest();
        if (!$order) {
            $this->response->setOutput(json_encode(array('code' => self::PAYMENT_RESULT_ERROR_INVALID_ORDER)));
        }

        $order_valid_result = $this->validateOrder($order);
        if ($order_valid_result !== true) {
            $this->response->setOutput(json_encode(array('code' => $order_valid_result)));
            return;
        }

        if ($notify == self::NOTIFY_CHECK) {
            $this->response->setOutput(json_encode(array('code' => self::PAYMENT_RESULT_SUCCESS)));
            return;
        }

        $transaction_status = '';
        $new_order_status = 0;
        switch ($notify) {
            case self::NOTIFY_PAY:
                if (isset($this->request->post['Status']) && $this->request->post['Status'] == 'Authorized') {
                    $transaction_status = 'Authorized';
                    $new_order_status = $this->config->get('cloudpayments_order_status_auth');
                } else {
                    $transaction_status = 'Completed';
                    $new_order_status = $this->config->get('cloudpayments_order_status_pay');
                }
                break;
            case self::NOTIFY_CONFIRM:
                $transaction_status = 'Completed';
                $new_order_status = $this->config->get('cloudpayments_order_status_confirm');
                break;
            case self::NOTIFY_REFUND:
                $transaction_status = 'Refunded';
                $new_order_status = $this->config->get('cloudpayments_order_status_refund');
                break;
            case self::NOTIFY_FAIL:
                $transaction_status = 'Failed';
                $new_order_status = $this->config->get('cloudpayments_order_status_fail');
                break;
        }
        $this->saveTransaction($order['order_id'], $transaction_status);

        if ($new_order_status) {
            if ($order['order_status_id'] != $new_order_status) {
                $this->load->model('payment/cloudpayments');
                $this->model_payment_cloudpayments->addOrderHistory($order['order_id'], $new_order_status);
            }
        }

        $this->response->setOutput(json_encode(array('code' => self::PAYMENT_RESULT_SUCCESS)));
    }

    /**
     * @return bool
     */
    private function validateRequest()
    {

        //Check HMAC sign
        $post_data = file_get_contents('php://input');
        $check_sign = base64_encode(hash_hmac('SHA256', $post_data,
            $this->config->get('cloudpayments_secret_key'), true));
        $request_sign = isset($this->request->server['HTTP_CONTENT_HMAC']) ? $this->request->server['HTTP_CONTENT_HMAC'] : '';

        if ($check_sign !== $request_sign) {
            $this->logNotifyError('Wrong HMAC');
            return false;
        };

        //Check required fields
        $required_fields = array(
            'InvoiceId',
            'Amount',
            'TransactionId',
            'DateTime'
        );

        foreach ($required_fields as $f) {
            if (!isset($this->request->post[$f])) {
                $this->logNotifyError('Field required ' . $f);
                return false;
            }
        }

        return true;
    }

    /**
     * @return array|bool
     */
    private function retrieveOrderFromRequest()
    {
        $order_id = isset($this->request->post['InvoiceId']) ? intval($this->request->post['InvoiceId']) : null;
        if (!$order_id) {
            $this->logNotifyError('Order not found');
            return false;
        }

        $this->load->model('checkout/order');

        return $this->model_checkout_order->getOrder($order_id);

    }

    /**
     * @param $order
     * @return bool|int
     */
    private function validateOrder($order)
    {
        if (floatval($order['total']) != floatval($this->request->post['Amount'])) {
            $this->logNotifyError('Order cost invalid');
            return self::PAYMENT_RESULT_ERROR_INVALID_COST;
        }

        if (isset($this->request->post['Currency']) && $order['currency_code'] != $this->request->post['Currency']) {
            $this->logNotifyError('Order currency invalid');
            return self::PAYMENT_RESULT_ERROR_INVALID_COST;
        }

        return true;
    }

    /**
     * @param $msg
     */
    private function logNotifyError($msg)
    {
        if (!$this->extension_log) {
            $this->extension_log = new Log("cloudpayments.log");
        }
        $data = $this->request->post;
        $data['REQUEST_URI'] = isset($this->request->server['REQUEST_URI']) ? $this->request->server['REQUEST_URI'] : '';
        $data['CONTENT_HMAC'] = isset($this->request->server['HTTP_CONTENT_HMAC']) ? $this->request->server['HTTP_CONTENT_HMAC'] : '';
        $data['REMOTE_IP'] = isset($this->request->server['REMOTE_ADDR']) ? $this->request->server['REMOTE_ADDR'] : '';

        $this->extension_log->write('CloudPayments Failed notify request: ' . $msg . "\n" . print_r($data, true));
    }

    /**
     * @param $order_id
     * @param $status
     */
    private function saveTransaction($order_id, $status)
    {
        $transaction_data = array(
            'order_id' => $order_id,
            'transaction_status' => $status,
            'transaction_id' => '',
            'transaction_amount' => '',
            'transaction_currency' => '',
            'transaction_datetime' => '',
            'reason' => '',
            'reason_code' => 0,
            'ip' => '',
        );

        $fields = array(
            'transaction_id' => 'TransactionId',
            'transaction_amount' => 'Amount',
            'transaction_currency' => 'Currency',
            'transaction_datetime' => 'DateTime',
            'reason' => 'Reason',
            'reason_code' => 'ReasonCode',
            'ip' => 'IpAddress',
        );

        foreach ($fields as $field => $post_field) {
            if (isset($this->request->post[$post_field])) {
                $transaction_data[$field] = $this->request->post[$post_field];
            }
        }

        $this->load->model('payment/cloudpayments');
        $this->model_payment_cloudpayments->addTransaction($transaction_data);
    }

    /**
     * Trigger after change order status
     *
     * @param $output
     * @param $order_id
     * @param $status_id
     * @throws Exception
     */
    public function changeOrderStatus($route, $output, $order_id, $status_id)
    {
        if (!$order_id || !$status_id) {
            return;
        }

        $this->load->language('payment/cloudpayments');
        $this->load->model('checkout/order');

        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info || $order_info['payment_code'] != 'cloudpayments') {
            return;
        }

        if (isset($this->request->get['route']) && $this->request->get['route'] == 'api/order/add') {
            //Change status or create order from admin or API
            if (in_array($status_id, $this->config->get('cloudpayments_order_status_for_send_order_link'))) {
                $this->sendOrderLink($order_id);
            }
        } else {
            if ($this->config->get('cloudpayments_two_steps') &&
                in_array($status_id, $this->config->get('cloudpayments_order_status_for_confirm'))
            ) {
                $this->confirmPayment($order_id);
            } elseif (in_array($status_id, $this->config->get('cloudpayments_order_status_for_cancel'))) {
                $this->cancelPayment($order_id);
            }
        }
    }

    /**
     * Trigger before delete order
     *
     * @param $route
     * @param $args
     */
    public function deleteOrderBefore($route, $order_id)
    {
        if (!$order_id) {
            return;
        }

        $this->load->language('payment/cloudpayments');

        $this->cancelPayment($order_id);
    }

    /**
     * @param $order_id
     * @return bool|mixed
     */
    public function getPaymentStatus($order_id)
    {
        $this->load->model('payment/cloudpayments');
        $order_transaction = $this->model_payment_cloudpayments->getLastOrderTransaction($order_id);
        if (empty($order_transaction['transaction_id'])) {
            return false;
        }

        $transactionInfo = $this->makeRequest('payments/get', array(
            'TransactionId' => $order_transaction['transaction_id'],
        ));

        return isset($transactionInfo['Model']['Status']) ? $transactionInfo['Model']['Status'] : false;
    }

    /**
     * @param $order_id
     * @return bool
     */
    public function cancelPayment($order_id)
    {
        $status = $this->getPaymentStatus($order_id);
        switch ($status) {
            case 'Authorized':
                return $this->voidPayment($order_id);
                break;
            case 'Completed':
                return $this->refundPayment($order_id);
                break;
        }

        return false;
    }

    /**
     * @param $order_id
     * @return bool
     */
    public function refundPayment($order_id)
    {
        $this->load->model('payment/cloudpayments');
        if ($this->model_payment_cloudpayments->isOrderHasTransaction($order_id, 'Refunded')) {
            return true;
        }

        $order_transaction = $this->model_payment_cloudpayments->getLastOrderTransaction($order_id);
        if (empty($order_transaction)) {
            return false;
        }

        $response = $this->makeRequest('payments/refund', array(
            'TransactionId' => $order_transaction['transaction_id'],
            'Amount' => number_format($order_transaction['transaction_amount'], 2, '.', '')
        ));

        return $response !== false;
    }

    /**
     * @param $order_id
     * @return bool
     */
    public function voidPayment($order_id)
    {
        $this->load->model('payment/cloudpayments');
        if ($this->model_payment_cloudpayments->isOrderHasTransaction($order_id, 'Refunded')) {
            return true;
        }

        $order_transaction = $this->model_payment_cloudpayments->getLastOrderTransaction($order_id);
        if (empty($order_transaction)) {
            return false;
        }

        $response = $this->makeRequest('payments/void', array(
            'TransactionId' => $order_transaction['transaction_id'],
        ));

        return $response !== false;
    }

    /**
     * @param $order_id
     * @return bool
     */
    public function confirmPayment($order_id)
    {
        $this->load->model('payment/cloudpayments');
        if ($this->model_payment_cloudpayments->isOrderHasTransaction($order_id, 'Completed')) {
            return true;
        }

        $order_transaction = $this->model_payment_cloudpayments->getLastOrderTransaction($order_id);
        if (empty($order_transaction)) {
            return false;
        }

        $response = $this->makeRequest('payments/confirm', array(
            'TransactionId' => $order_transaction['transaction_id'],
            'Amount' => number_format($order_transaction['transaction_amount'], 2, '.', ''),
        ));

        return $response !== false;
    }

    /**
     * @param $order_id
     * @return bool
     */
    public function sendOrderLink($order_id)
    {
        $this->load->model('payment/cloudpayments');
        $this->load->model('checkout/order');

        $order_info = $this->model_checkout_order->getOrder($order_id);

        $lang_code = $this->language->get('code');
        switch ($lang_code) {
            case 'ru':
                $lang_code = 'ru-RU';
                break;
            default:
                $lang_code = 'en-US';
        }

        $params = array(
            'Amount' => number_format($order_info['total'], 2, '.', ''),
            'Currency' => $order_info['currency_code'],
            'Description' => sprintf($this->language->get('order_description'), $order_info['order_id']),
            'Email' => $order_info['email'],
            'RequireConfirmation' => (bool)$this->config->get('cloudpayments_two_steps'),
            'SendEmail' => true,
            'InvoiceId' => $order_info['order_id'],
            'Phone' => $order_info['telephone'],
            'SendSms' => true,
            'CultureInfo' => $lang_code,
            'JsonData' => array()
        );

        if ($order_info['customer_id']) {
            $params['AccountId'] = $order_info['customer_id'];
        }

        if ($this->config->get('cloudpayments_kkt')) {
            $params['JsonData']['cloudPayments'] = array(
                'customerReceipt' => $this->getReceiptData($order_info)
            );
        }
        $response = $this->makeRequest('orders/create', $params);

        return $response !== false;
    }

    /**
     * @param string $location
     * @param array $request
     * @return bool|array
     */
    private function makeRequest($location, $request = array())
    {
        if (!$this->curl) {
            $auth = $this->config->get('cloudpayments_public_id') . ':' . $this->config->get('cloudpayments_secret_key');
            $this->curl = curl_init();
            curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($this->curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($this->curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($this->curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($this->curl, CURLOPT_USERPWD, $auth);
        }

        curl_setopt($this->curl, CURLOPT_URL, 'https://api.cloudpayments.ru/' . $location);
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, array(
            "content-type: application/json"
        ));
        curl_setopt($this->curl, CURLOPT_POST, true);
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($request));

        $response = curl_exec($this->curl);
        if ($response === false || curl_getinfo($this->curl, CURLINFO_HTTP_CODE) != 200) {
            $this->log->write('CloudPayments Failed API request' .
                ' Location: ' . $location .
                ' Request: ' . print_r($request, true) .
                ' HTTP Code: ' . curl_getinfo($this->curl, CURLINFO_HTTP_CODE) .
                ' Error: ' . curl_error($this->curl)
            );

            return false;
        }
        $response = json_decode($response, true);
        if (!isset($response['Success']) || !$response['Success']) {
            $this->log->write('CloudPayments Failed API request' .
                ' Location: ' . $location .
                ' Request: ' . print_r($request, true) .
                ' HTTP Code: ' . curl_getinfo($this->curl, CURLINFO_HTTP_CODE) .
                ' Error: ' . curl_error($this->curl)
            );

            return false;
        }

        return $response;
    }
}