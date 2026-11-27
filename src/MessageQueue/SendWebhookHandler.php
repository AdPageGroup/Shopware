<?php declare(strict_types=1);

namespace Tagging\MessageQueue;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendWebhookHandler
{
    private Client $httpClient;

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
    ) {
        $this->httpClient = new Client([
            'timeout' => 10,
            'http_errors' => false,
        ]);
    }

    public function __invoke(SendWebhookMessage $message): void
    {
        $context = Context::createDefaultContext();

        $lookupCriteria = new Criteria([$message->getOrderId()]);
        $orderForLanguage = $this->orderRepository->search($lookupCriteria, $context)->first();

        if (!$orderForLanguage) {
            $this->logger->error('Order not found for webhook', ['orderId' => $message->getOrderId()]);
            return;
        }

        // Read the config for the order's sales channel; Shopware falls back to
        // the "All Sales Channels" value when the channel has none.
        $salesChannelId = $orderForLanguage->getSalesChannelId();
        $webhookUrl = $this->systemConfigService->getString('Tagging.config.webhookUrl', $salesChannelId);

        if ($webhookUrl === '') {
            $this->logger->warning('Webhook URL not configured for sales channel, skipping', [
                'orderNumber' => $orderForLanguage->getOrderNumber(),
                'salesChannelId' => $salesChannelId,
            ]);
            return;
        }

        if (!str_starts_with($webhookUrl, 'https://')) {
            $this->logger->error('Webhook URL must use HTTPS', ['url' => $webhookUrl]);
            return;
        }

        $orderContext = new Context(
            new SystemSource(),
            [],
            $orderForLanguage->getCurrencyId(),
            [$orderForLanguage->getLanguageId()],
        );

        $criteria = new Criteria([$message->getOrderId()]);
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('billingAddress.countryState');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.countryState');

        $order = $this->orderRepository->search($criteria, $orderContext)->first();

        $transactionId = $message->getTransactionId();

        $customFields = $order->getCustomFields() ?? [];
        $marketingData = [];

        try {
            $decoded = json_decode(base64_decode($customFields['trytagging_user_data'] ?? ''), true);
            if (is_array($decoded)) {
                $marketingData = $decoded;
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to decode trytagging marketing data: ' . $e->getMessage());
        }

        if (isset($customFields['user_agent'])) {
            $marketingData['user_agent'] = $customFields['user_agent'];
        }

        if (isset($customFields['ip_address'])) {
            $marketingData['ip'] = $customFields['ip_address'];
        }

        $productItems = $order->getLineItems()->filterByType(LineItem::PRODUCT_LINE_ITEM_TYPE);

        $payload = [
            'event' => 'trytagging_purchase',
            'timestamp' => (new \DateTime())->format(\DateTime::ATOM),
            'order_timestamp' => $order->getOrderDateTime()->format(\DateTime::ATOM),
            'ecommerce' => [
                'transaction_id' => (string) $order->getOrderNumber(),
                'value' => (float) $order->getAmountTotal(),
                'currency' => $order->getCurrency()->getIsoCode(),
                'shipping' => (float) $order->getShippingTotal(),
                'tax' => (float) $order->getPrice()->getCalculatedTaxes()->getAmount(),
                'items' => array_values(array_map(function ($lineItem) {
                    $itemPayload = $lineItem->getPayload();
                    $variants = $this->extractVariantInfo($itemPayload);

                    $itemData = [
                        'item_id' => (string) ($itemPayload['productNumber'] ?? $lineItem->getProductId()),
                        'item_sku' => (string) ($itemPayload['productNumber'] ?? ''),
                        'item_name' => $lineItem->getLabel(),
                        'quantity' => (int) $lineItem->getQuantity(),
                        'price' => (float) $lineItem->getUnitPrice(),
                    ];

                    foreach ($variants as $index => $variant) {
                        $fieldName = $index === 0 ? 'item_variant' : 'item_variant' . ($index + 1);
                        $itemData[$fieldName] = $variant;
                    }

                    return $itemData;
                }, $productItems->getElements())),
            ],
            'marketing' => $marketingData,
            'user_data' => $this->buildUserData($order),
        ];

        $promotionItems = $order->getLineItems()->filterByType(LineItem::PROMOTION_LINE_ITEM_TYPE);
        $couponCodes = [];
        foreach ($promotionItems->getElements() as $promo) {
            $promoPayload = $promo->getPayload();
            if (isset($promoPayload['code']) && $promoPayload['code'] !== '') {
                $couponCodes[] = $promoPayload['code'];
            }
        }
        if ($couponCodes !== []) {
            $payload['ecommerce']['coupon'] = implode(',', $couponCodes);
        }

        $body = json_encode($payload);

        $webhookSecret = $this->systemConfigService->getString('Tagging.config.webhookSecret', $salesChannelId);
        $headers = ['Content-Type' => 'application/json'];

        if ($webhookSecret) {
            $timestamp = (string) time();
            $headers['X-Webhook-Timestamp'] = $timestamp;
            $headers['X-Webhook-Signature'] = hash_hmac('sha256', $timestamp . '.' . $body, $webhookSecret);
        }

        try {
            $response = $this->httpClient->post($webhookUrl . '/order_created', [
                'headers' => $headers,
                'body' => $body,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Webhook sent successfully', [
                    'orderNumber' => $order->getOrderNumber(),
                    'transactionId' => $transactionId,
                    'statusCode' => $statusCode,
                ]);

                $this->markWebhookAsSent($order, $transactionId, $orderContext);
            } else {
                $this->logger->error('Webhook failed with status', [
                    'orderNumber' => $order->getOrderNumber(),
                    'transactionId' => $transactionId,
                    'statusCode' => $statusCode,
                ]);

                throw new \RuntimeException('Webhook returned HTTP ' . $statusCode);
            }
        } catch (RequestException $e) {
            $this->logger->error('Failed to send webhook: ' . $e->getMessage(), [
                'orderNumber' => $order->getOrderNumber(),
                'transactionId' => $transactionId,
            ]);

            throw $e;
        }
    }

    private function extractVariantInfo(?array $payload): array
    {
        if (!$payload) {
            return [];
        }

        $variantValues = [];

        if (isset($payload['options']) && is_array($payload['options'])) {
            foreach ($payload['options'] as $option) {
                if (isset($option['option'])) {
                    $variantValues[] = $option['option'];
                } elseif (isset($option['value'])) {
                    $variantValues[] = $option['value'];
                }
            }
        }

        return array_values(array_filter(array_map('trim', $variantValues), fn($v) => $v !== ''));
    }

    private function buildUserData($order): array
    {
        $billing = $order->getBillingAddress();
        $delivery = $order->getDeliveries()->first();
        $shipping = $delivery?->getShippingOrderAddress();

        return [
            'customer_id' => (string) $order->getOrderCustomer()->getId(),
            'email' => $order->getOrderCustomer()->getEmail(),
            'first_name' => $order->getOrderCustomer()->getFirstName(),
            'last_name' => $order->getOrderCustomer()->getLastName(),
            'billing_address' => $billing?->getStreet() ?? '',
            'billing_city' => $billing?->getCity() ?? '',
            'billing_state' => $billing?->getCountryState()?->getName() ?? '',
            'billing_zip' => $billing?->getZipcode() ?? '',
            'billing_country' => $billing?->getCountry()?->getName() ?? '',
            'shipping_address' => $shipping?->getStreet() ?? '',
            'shipping_city' => $shipping?->getCity() ?? '',
            'shipping_state' => $shipping?->getCountryState()?->getName() ?? '',
            'shipping_zip' => $shipping?->getZipcode() ?? '',
            'shipping_country' => $shipping?->getCountry()?->getName() ?? '',
        ];
    }

    private function markWebhookAsSent($order, string $transactionId, Context $context): void
    {
        $customFields = $order->getCustomFields() ?? [];
        $webhookKey = 'webhook_sent_' . $transactionId;
        $processingKey = 'webhook_processing_' . $transactionId;

        $customFields[$webhookKey] = true;
        $customFields[$webhookKey . '_at'] = (new \DateTime())->format(\DateTime::ATOM);
        unset($customFields[$processingKey], $customFields[$processingKey . '_at']);

        $customFields['webhook_sent'] = true;
        $customFields['webhook_sent_at'] = (new \DateTime())->format(\DateTime::ATOM);

        $this->orderRepository->update([
            [
                'id' => $order->getId(),
                'customFields' => $customFields,
            ],
        ], $context);
    }
}
