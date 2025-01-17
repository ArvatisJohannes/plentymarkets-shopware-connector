<?php

namespace ShopwareAdapter\ServiceBus\CommandHandler\Order;

use DateTime;
use Psr\Log\LoggerInterface;
use Shopware\Components\Api\Manager;
use Shopware\Components\Api\Resource\Order as OrderResource;
use Shopware\Models\Order\Status;
use ShopwareAdapter\DataPersister\Attribute\AttributeDataPersisterInterface;
use ShopwareAdapter\ShopwareAdapter;
use SystemConnector\IdentityService\IdentityServiceInterface;
use SystemConnector\ServiceBus\Command\CommandInterface;
use SystemConnector\ServiceBus\Command\TransferObjectCommand;
use SystemConnector\ServiceBus\CommandHandler\CommandHandlerInterface;
use SystemConnector\ServiceBus\CommandType;
use SystemConnector\TransferObject\Order\Order;
use SystemConnector\TransferObject\Order\Package\Package;
use SystemConnector\TransferObject\OrderStatus\OrderStatus;
use SystemConnector\TransferObject\PaymentStatus\PaymentStatus;
use SystemConnector\ValueObject\Attribute\Attribute;

class HandleOrderCommandHandler implements CommandHandlerInterface
{
    /**
     * @var IdentityServiceInterface
     */
    private $identityService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var AttributeDataPersisterInterface
     */
    private $attributePersister;

    public function __construct(
        IdentityServiceInterface $identityService,
        LoggerInterface $logger,
        AttributeDataPersisterInterface $attributePersister
    ) {
        $this->identityService = $identityService;
        $this->logger = $logger;
        $this->attributePersister = $attributePersister;
    }

    /**
     * @param CommandInterface $command
     *
     * @return bool
     */
    public function supports(CommandInterface $command): bool
    {
        return $command instanceof TransferObjectCommand &&
            $command->getAdapterName() === ShopwareAdapter::NAME &&
            $command->getObjectType() === Order::TYPE &&
            $command->getCommandType() === CommandType::HANDLE;
    }

    /**
     * {@inheritdoc}
     *
     * @param TransferObjectCommand $command
     */
    public function handle(CommandInterface $command): bool
    {
        /**
         * @var Order $order
         */
        $order = $command->getPayload();

        $orderIdentity = $this->identityService->findOneBy([
            'objectIdentifier' => $order->getIdentifier(),
            'objectType' => Order::TYPE,
            'adapterName' => ShopwareAdapter::NAME,
        ]);

        if (null === $orderIdentity) {
            return false;
        }

        $params = [
            'details' => [],
        ];

        $package = $this->getPackage($order);

        if (null !== $package) {
            $this->addShippingProviderAttribute($order, $package);

            $params['trackingCode'] = $package->getShippingCode();
        }

        $orderStatusIdentity = $this->identityService->findOneBy([
            'objectIdentifier' => $order->getOrderStatusIdentifier(),
            'objectType' => OrderStatus::TYPE,
            'adapterName' => ShopwareAdapter::NAME,
        ]);

        if (null !== $orderStatusIdentity) {
            $params['orderStatusId'] = $orderStatusIdentity->getAdapterIdentifier();
        } else {
            $this->logger->notice('order status not mapped', ['order' => $order]);
        }

        $paymentStatusIdentity = $this->identityService->findOneBy([
            'objectIdentifier' => $order->getPaymentStatusIdentifier(),
            'objectType' => PaymentStatus::TYPE,
            'adapterName' => ShopwareAdapter::NAME,
        ]);

        if (null !== $paymentStatusIdentity) {
            $params['paymentStatusId'] = $paymentStatusIdentity->getAdapterIdentifier();

            if ((int) $paymentStatusIdentity->getAdapterIdentifier() === Status::PAYMENT_STATE_COMPLETELY_PAID) {
                $params['cleareddate'] = new DateTime('now');
            }
        } else {
            $this->logger->notice('payment status not mapped', ['order' => $order]);
        }

        $resource = $this->getOrderResource();
        $orderModel = $resource->update($orderIdentity->getAdapterIdentifier(), $params);

        $this->attributePersister->saveOrderAttributes(
            $orderModel,
            $order->getAttributes()
        );

        return true;
    }

    /**
     * @param Order $order
     *
     * @return null|Package
     */
    private function getPackage(Order $order)
    {
        $packages = $order->getPackages();

        if (empty($packages)) {
            return null;
        }

        return array_shift($packages);
    }

    /**
     * @param Order   $order
     * @param Package $package
     */
    private function addShippingProviderAttribute(Order $order, Package $package)
    {
        if (null === $package->getShippingProvider()) {
            return;
        }

        $attributes = $order->getAttributes();

        $shippingProvider = new Attribute();
        $shippingProvider->setKey('shippingProvider');
        $shippingProvider->setValue($package->getShippingProvider());

        $attributes[] = $shippingProvider;

        $order->setAttributes($attributes);
    }

    /**
     * @return OrderResource
     */
    private function getOrderResource(): OrderResource
    {
        // without this reset the entitymanager sometimes the status is not found correctly.
        Shopware()->Container()->reset('models');

        return Manager::getResource('Order');
    }
}
