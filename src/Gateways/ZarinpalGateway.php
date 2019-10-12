<?php

namespace AmirrezaNasiri\LaravelToman\Gateways;

use Illuminate\Support\Arr;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\URL;
use AmirrezaNasiri\LaravelToman\Utils;
use GuzzleHttp\Exception\ClientException;
use AmirrezaNasiri\LaravelToman\PaymentRequest;
use AmirrezaNasiri\LaravelToman\Exceptions\GatewayException;
use AmirrezaNasiri\LaravelToman\Exceptions\InvalidConfigException;

class ZarinpalGateway extends BaseGateway
{
    public function callback($callbackUrl)
    {
        $this->data('CallbackURL', $callbackUrl);

        return $this;
    }

    public function amount($amount)
    {
        $this->data('Amount', $amount);

        return $this;
    }

    public function mobile($mobile)
    {
        $this->data('Mobile', $mobile);

        return $this;
    }

    public function email($email)
    {
        $this->data('Email', $email);

        return $this;
    }

    public function merchant($merchantID)
    {
        $this->data('MerchantID', $merchantID);

        return $this;
    }

    public function description($description)
    {
        $this->data('Description', $description);

        return $this;
    }

    public function request(): PaymentRequest
    {
        $requestData = $this->makeRequestData();
        $requestURL = $this->makeRequestURL();
        try {
            $response = $this->client->post(
                $requestURL,
                [RequestOptions::JSON => $requestData]
            );
        } catch (\Exception $exception) {
            $this->throwGatewayException($exception);
        }

        $data = Utils::getResponseData($response);

        $transactionId = Arr::get($data, 'Authority');

        if (Arr::get($data, 'Status') !== 100 || ! $transactionId) {
            $this->throwGatewayException($data);
        }

        return new PaymentRequest($transactionId, $this->getPaymentUrlFor($transactionId));
    }

    /**
     * @param array|ClientException|\Exception $data
     * @throws GatewayException
     */
    private function throwGatewayException($data)
    {
        $previous = null;
        if ($data instanceof ClientException) {
            $previous = $data;
            $data = Utils::getResponseData($data);
        }

        $status = Arr::get($data, 'Status', 0);

        if ($errors = Arr::get($data, 'errors')) {
            $message = Arr::flatten($errors)[0];
        } else {
            $message = $this->getStatusMessage($status);
        }

        throw new GatewayException($message, $status, $previous);
    }

    private function getStatusMessage($status)
    {
        $messages = [
            '-1' => 'اطلاعات ارسال شده ناقص است.',
            '-2' => 'IP و يا مرچنت كد پذيرنده صحيح نيست',
            '-3' => 'با توجه به محدوديت هاي شاپرك امكان پرداخت با رقم درخواست شده ميسر نمي باشد',
            '-4' => 'سطح تاييد پذيرنده پايين تر از سطح نقره اي است.',
            '-11' => 'درخواست مورد نظر يافت نشد.',
            '-12' => 'امكان ويرايش درخواست ميسر نمي باشد.',
            '-21' => 'هيچ نوع عمليات مالي براي اين تراكنش يافت نشد',
            '-22' => 'تراكنش نا موفق ميباشد',
            '-33' => 'رقم تراكنش با رقم پرداخت شده مطابقت ندارد',
            '-34' => 'سقف تقسيم تراكنش از لحاظ تعداد يا رقم عبور نموده است',
            '-40' => 'اجازه دسترسي به متد مربوطه وجود ندارد.',
            '-41' => 'اطلاعات ارسال شده مربوط به AdditionalData غيرمعتبر ميباشد.',
            '-42' => 'مدت زمان معتبر طول عمر شناسه پرداخت بايد بين 30 دقيه تا 45 روز مي باشد.',
            '-54' => 'درخواست مورد نظر آرشيو شده است',
            '101' => 'عمليات پرداخت موفق بوده و قبلا PaymentVerification تراكنش انجام شده است.',
        ];

        return Arr::get($messages, $status, 'An unknown payment gateway error occurred.');
    }

    public function getPaymentUrlFor($transactionId)
    {
        return $this->getHost()."/pg/StartPay/{$transactionId}";
    }

    private function makeRequestURL()
    {
        return $this->getHost().'/pg/rest/WebGate/PaymentRequest.json';
    }

    private function getHost()
    {
        $subdomain = $this->isSandbox() ? 'sandbox' : 'www';

        return "https://{$subdomain}.zarinpal.com";
    }

    private function makeRequestData()
    {
        return array_merge($this->data, [
            'MerchantID' => $this->getMerchantId(),
            'CallbackURL' => $this->getCallbackUrl(),
            'Description' => $this->getDescription(),
        ]);
    }

    private function getCallbackUrl()
    {
        if ($data = $this->getData('CallbackURL')) {
            return $data;
        }

        if ($defaultRoute = config('toman.callback_route')) {
            return URL::route($defaultRoute);
        }
    }

    private function getDescription()
    {
        $description = $this->getData('Description');

        if (! $description) {
            $description = config('toman.description');
        }

        return str_replace(':amount', $this->getData('Amount'), $description);
    }

    private function isSandbox()
    {
        $sandbox = $this->getConfig('sandbox');

        if ($sandbox === null || $sandbox === false) {
            return false;
        } elseif ($sandbox === true) {
            return true;
        }

        throw new InvalidConfigException('sandbox');
    }

    private function getMerchantId()
    {
        $merchantId = $this->getData('MerchantID');

        if (! $merchantId) {
            $merchantId = $this->getConfig('merchant_id');
        }

        return $merchantId;
    }
}