<?php

namespace PlentymarketsAdapter\RequestGenerator\Payment;

use PlentymarketsAdapter\PlentymarketsAdapter;
use SystemConnector\IdentityService\Exception\NotFoundException;
use SystemConnector\IdentityService\IdentityServiceInterface;
use SystemConnector\TransferObject\Currency\Currency;
use SystemConnector\TransferObject\Payment\Payment;
use SystemConnector\TransferObject\PaymentMethod\PaymentMethod;

class PaymentRequestGenerator implements PaymentRequestGeneratorInterface
{
    /**
     * @var IdentityServiceInterface
     */
    private $identityService;

    public function __construct(IdentityServiceInterface $identityService)
    {
        $this->identityService = $identityService;
    }

    /**
     * {@inheritdoc}
     *
     * @throws NotFoundException
     * @throws NotFoundException
     */
    public function generate(Payment $payment): array
    {
        $paymentMethodIdentity = $this->identityService->findOneBy([
            'objectIdentifier' => $payment->getPaymentMethodIdentifier(),
            'objectType' => PaymentMethod::TYPE,
            'adapterName' => PlentymarketsAdapter::NAME,
        ]);

        if (null === $paymentMethodIdentity) {
            throw new NotFoundException('payment method not mapped');
        }

        $currencyIdentity = $this->identityService->findOneBy([
            'objectIdentifier' => $payment->getCurrencyIdentifier(),
            'objectType' => Currency::TYPE,
            'adapterName' => PlentymarketsAdapter::NAME,
        ]);

        if (null === $currencyIdentity) {
            throw new NotFoundException('currency not mapped');
        }

        $paymentParams = [
            'amount' => $payment->getAmount(),
            'exchangeRatio' => 1,
            'mopId' => $paymentMethodIdentity->getAdapterIdentifier(),
            'currency' => $currencyIdentity->getAdapterIdentifier(),
            'type' => 'credit',
            'transactionType' => 2,
            'status' => 2,
        ];

        $paymentParams['properties'] = [
            [
                'typeId' => 23,
                'value' => 4,
            ],
            [
                'typeId' => 11,
                'value' => $payment->getAccountHolder(),
            ],
            [
                'typeId' => 1,
                'value' => $payment->getTransactionReference(),
            ],
            [
                'typeId' => 3,
                'value' => $payment->getTransactionReference(),
            ],
        ];

        return $paymentParams;
    }
}
