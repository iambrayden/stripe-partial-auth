<?php

namespace StripePartial;

class StripeError extends \Exception
{
    public $response;

    public function __construct($message, $response = [])
    {
        parent::__construct($message);
        $this->response = $response;
    }
}

class Client
{
    const API_BASE = 'https://api.stripe.com/v1/';

    private $secretKey;
    private $apiVersion;

    public function __construct($secretKey, $apiVersion = '')
    {
        $this->secretKey = trim((string) $secretKey);
        $this->apiVersion = trim((string) $apiVersion);
    }

    public function get($path, array $query = [])
    {
        return $this->request('GET', $path, $query);
    }

    public function post($path, array $data = [], $idempotencyKey = null)
    {
        return $this->request('POST', $path, $data, $idempotencyKey);
    }

    private function request($method, $path, array $data, $idempotencyKey = null)
    {
        $url = self::API_BASE . ltrim($path, '/');
        $body = http_build_query($data, '', '&', PHP_QUERY_RFC1738);

        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/x-www-form-urlencoded',
        ];
        if ($this->apiVersion !== '') {
            $headers[] = 'Stripe-Version: ' . $this->apiVersion;
        }
        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init();
        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_URL, $body ? $url . '?' . $body : $url);
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new StripeError('Stripe connection error: ' . $curlErr);
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new StripeError('Invalid response from Stripe (HTTP ' . $status . ')');
        }
        if ($status >= 400 || isset($json['error'])) {
            $msg = isset($json['error']['message']) ? $json['error']['message'] : 'Stripe error (HTTP ' . $status . ')';
            throw new StripeError($msg, $json);
        }

        return $json;
    }
}

function zeroDecimalCurrencies()
{
    return ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
}

function toMinorUnits($amount, $currency)
{
    if (in_array(strtoupper($currency), zeroDecimalCurrencies(), true)) {
        return (int) round($amount);
    }
    return (int) round($amount * 100);
}

function fromMinorUnits($amount, $currency)
{
    if (in_array(strtoupper($currency), zeroDecimalCurrencies(), true)) {
        return (float) $amount;
    }
    return round($amount / 100, 2);
}

function minimumCharge($currency)
{
    $minimums = [
        'USD' => 50, 'AUD' => 50, 'CAD' => 50, 'EUR' => 50, 'NZD' => 50, 'CHF' => 50,
        'GBP' => 30, 'JPY' => 50, 'MXN' => 1000, 'HKD' => 400, 'SGD' => 50,
    ];
    $currency = strtoupper($currency);
    return isset($minimums[$currency]) ? $minimums[$currency] : 50;
}

function parseToken($token)
{
    $data = json_decode((string) $token, true);
    if (is_array($data) && !empty($data['customer']) && !empty($data['method'])) {
        return $data;
    }
    return null;
}

function feeFromCharge($charge, $currency)
{
    if (is_array($charge) && isset($charge['balance_transaction']['fee'])) {
        return fromMinorUnits($charge['balance_transaction']['fee'], $currency);
    }
    return 0;
}
