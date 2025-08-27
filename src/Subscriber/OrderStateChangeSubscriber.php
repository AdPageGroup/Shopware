<?php declare(strict_types=1);

namespace Tagging\Subscriber;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Shopware\Core\Framework\Event\BeforeSendResponseEvent;

class OrderStateChangeSubscriber implements EventSubscriberInterface
{
    private EntityRepository $orderRepository;
    private EntityRepository $orderTransactionRepository;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;
    private Client $httpClient;
    private RequestStack $requestStack;

    public function __construct(
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger,
        RequestStack $requestStack
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
        $this->requestStack = $requestStack;
        $this->httpClient = new Client([
            'timeout' => 10,
            'http_errors' => false,
        ]);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_machine.order_transaction.state_changed' => 'onOrderTransactionStateChange',
            CheckoutOrderPlacedEvent::class => 'onCheckoutOrderPlaced',
            BeforeSendResponseEvent::class => 'onBeforeSendResponse',
        ];
    }

    public function onOrderTransactionStateChange(StateMachineStateChangeEvent $event): void
    {
        // Check if the new state is 'paid'
        if ($event->getNextState()->getTechnicalName() !== OrderTransactionStates::STATE_PAID) {
            return;
        }

        // Get the order transaction ID from the event
        $orderTransactionId = $event->getTransition()->getEntityId();
        
        // Enhanced logging to track duplicate events
        $this->logger->info('Transaction state changed to paid - processing webhook', [
            'transactionId' => $orderTransactionId,
            'eventTimestamp' => (new \DateTime())->format(\DateTime::ATOM),
            'fromState' => $event->getPreviousState()->getTechnicalName(),
            'toState' => $event->getNextState()->getTechnicalName()
        ]);
        
        // First, load the transaction to get the order ID
        $transactionCriteria = new Criteria([$orderTransactionId]);
        $transactionCriteria->addAssociation('order');
        
        $transactions = $this->orderTransactionRepository->search($transactionCriteria, $event->getContext());
        
        if ($transactions->count() === 0) {
            $this->logger->error('Transaction not found: ' . $orderTransactionId);
            return;
        }
        
        $transaction = $transactions->first();
        $orderId = $transaction->getOrderId();
        
        // Now load the order with all associations
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('orderCustomer');
        $orderCriteria->addAssociation('lineItems');
        $orderCriteria->addAssociation('currency');
        $orderCriteria->addAssociation('transactions');
        $orderCriteria->addAssociation('billingAddress.country');
        $orderCriteria->addAssociation('deliveries.shippingOrderAddress.country');

        $orders = $this->orderRepository->search($orderCriteria, $event->getContext());
        
        if ($orders->count() === 0) {
            $this->logger->error('Order not found: ' . $orderId);
            return;
        }

        $order = $orders->first();
        
        $this->logger->info('Order loaded for webhook processing', [
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'transactionId' => $orderTransactionId,
            'processingTimestamp' => (new \DateTime())->format(\DateTime::ATOM)
        ]);
        
        if ($this->isWebhookAlreadySentForTransaction($order, $orderTransactionId)) {
            $this->logger->info('Webhook already sent for this transaction, skipping', [
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'transactionId' => $orderTransactionId
            ]);
            return;
        }
        
        $this->markWebhookAsProcessing($order, $orderTransactionId, $event->getContext());
        
        // Send webhook
        $this->sendWebhook($order, $orderTransactionId, $event->getContext());
    }

    public function onCheckoutOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $request = $this->requestStack->getCurrentRequest();
        
        if (!$request) {
            $this->logger->warning('No request available to capture user agent and IP');
            return;
        }

        // Capture user agent and IP
        $userAgent = $request->headers->get('User-Agent');
        $ipAddress = $this->getClientIpAddress($request);
        $trytaggingUserData = $request->cookies->get('trytagging_user_data');

        if ($userAgent || $ipAddress || $trytaggingUserData) {
            $customFields = $order->getCustomFields() ?? [];
            
            if ($userAgent) {
                $customFields['user_agent'] = $userAgent;
            }
            
            if ($ipAddress) {
                $customFields['ip_address'] = $ipAddress;
            }

            if ($trytaggingUserData) {
                $customFields['trytagging_user_data'] = $trytaggingUserData;
            }

            // Update the order with custom fields
            try {
                $this->orderRepository->update([
                    [
                        'id' => $order->getId(),
                        'customFields' => $customFields,
                    ]
                ], $event->getContext());
            } catch (\Exception $e) {
                $this->logger->error('Failed to save marketing data to order: ' . $e->getMessage());
            }
        }
    }

    /**
     * Set the _taggingmk cookie on response if it doesn't exist
     */
    public function onBeforeSendResponse(BeforeSendResponseEvent $event): void
    {
        try {
            $request = $event->getRequest();
            $response = $event->getResponse();
            
            // Only set cookie if it doesn't already exist
            if (!$request->cookies->has('_taggingmk')) {
                $taggingMkId = $this->generateUuid();
                
                // Create cookie that expires in 1 year, secure and httpOnly for security
                $cookie = Cookie::create(
                    '_taggingmk',
                    $taggingMkId,
                    time() + (365 * 24 * 60 * 60), // 1 year
                    '/', // path
                    null, // domain (null = current domain)
                    $request->isSecure(), // secure (only over HTTPS if request is secure)
                    false, // httpOnly
                    false, // raw
                    Cookie::SAMESITE_LAX // sameSite
                );
                
                $response->headers->setCookie($cookie);
                
                $this->logger->info('Set new _taggingmk cookie', [
                    'taggingMkId' => $taggingMkId,
                    'userAgent' => $request->headers->get('User-Agent'),
                    'ip' => $this->getClientIpAddress($request)
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to set _taggingmk cookie: ' . $e->getMessage());
        }
    }

    private function sendWebhook($order, string $transactionId, Context $context): void
    {
        $webhookUrl = $this->systemConfigService->get('Tagging.config.webhookUrl');

        if (!$webhookUrl) {
            $this->logger->info('Webhook URL not configured, skipping webhook');
            return;
        }

        try {
            $customFields = $order->getCustomFields() ?? [];
            $marketingData = [];

            try {
                // Should be base 64 decoded and json decoded
                $data = json_decode(base64_decode($customFields['trytagging_user_data'] ?? ''), true);

                if (is_array($data)) {
                    foreach ($data as $key => $value) {
                        $marketingData[$key] = $value;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to decode trytagging marketing data: ' . $e->getMessage());
            } catch (\TypeError $e) {
                $this->logger->error('Failed to decode trytagging marketing data: ' . $e->getMessage());
            }

            if (isset($customFields['user_agent'])) {
                $marketingData['user_agent'] = $customFields['user_agent'];
            }

            if (isset($customFields['ip_address'])) {
                $marketingData['ip'] = $customFields['ip_address'];
            }

            $items = $order->getLineItems()->getElements();

            // Prepare webhook payload
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
                        $payload = $lineItem->getPayload();
                        
                        // Extract variant information as separate fields
                        $variants = $this->extractVariantInfo($payload, $lineItem);
                        
                        $itemData = [
                            'item_id' => (string) $lineItem->getProductId(),
                            'item_sku' => (string) ($payload['productNumber'] ?? ''),
                            'item_name' => $lineItem->getLabel(),
                            'quantity' => (int) $lineItem->getQuantity(),
                            'price' => (float) $lineItem->getUnitPrice(),
                        ];
                        
                        // Add variant fields dynamically
                        foreach ($variants as $index => $variant) {
                            $fieldName = $index === 0 ? 'item_variant' : 'item_variant' . ($index + 1);
                            $itemData[$fieldName] = $variant;
                        }
                        
                        return $itemData;
                    }, $items)),
                ],
                'marketing' => $marketingData,
                'user_data' => [
                    'customer_id' => (string) $order->getOrderCustomer()->getId(),
                    'email' => $order->getOrderCustomer()->getEmail(),
                    'first_name' => $order->getOrderCustomer()->getFirstName(),
                    'last_name' => $order->getOrderCustomer()->getLastName(),
                    'billing_address' => $order->getBillingAddress() ? $order->getBillingAddress()->getStreet() : '',
                    'billing_city' => $order->getBillingAddress() ? $order->getBillingAddress()->getCity() : '',
                    'billing_state' => $order->getBillingAddress() && $order->getBillingAddress()->getCountryState() ? $order->getBillingAddress()->getCountryState()->getName() : '',
                    'billing_zip' => $order->getBillingAddress() ? $order->getBillingAddress()->getZipcode() : '',
                    'billing_country' => $order->getBillingAddress() && $order->getBillingAddress()->getCountry() ? $order->getBillingAddress()->getCountry()->getName() : '',
                    'shipping_address' => $order->getDeliveries()->first() && $order->getDeliveries()->first()->getShippingOrderAddress() ? $order->getDeliveries()->first()->getShippingOrderAddress()->getStreet() : '',
                    'shipping_city' => $order->getDeliveries()->first() && $order->getDeliveries()->first()->getShippingOrderAddress() ? $order->getDeliveries()->first()->getShippingOrderAddress()->getCity() : '',
                    'shipping_state' => $order->getDeliveries()->first() && $order->getDeliveries()->first()->getShippingOrderAddress() && $order->getDeliveries()->first()->getShippingOrderAddress()->getCountryState() ? $order->getDeliveries()->first()->getShippingOrderAddress()->getCountryState()->getName() : '',
                    'shipping_zip' => $order->getDeliveries()->first() && $order->getDeliveries()->first()->getShippingOrderAddress() ? $order->getDeliveries()->first()->getShippingOrderAddress()->getZipcode() : '',
                    'shipping_country' => $order->getDeliveries()->first() && $order->getDeliveries()->first()->getShippingOrderAddress() && $order->getDeliveries()->first()->getShippingOrderAddress()->getCountry() ? $order->getDeliveries()->first()->getShippingOrderAddress()->getCountry()->getName() : '',
                ],
            ];

            // Calculate signature if secret is configured
            $headers = [
                'Content-Type' => 'application/json',
            ];

            $response = $this->httpClient->post($webhookUrl . '/order_created', [
                'headers' => $headers,
                'body' => json_encode($payload),
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Webhook sent successfully', [
                    'orderNumber' => $order->getOrderNumber(),
                    'transactionId' => $transactionId,
                    'statusCode' => $statusCode
                ]);
                
                $this->markWebhookAsSent($order, $transactionId, $context);
            } else {
                $this->logger->error('Webhook failed with status', [
                    'orderNumber' => $order->getOrderNumber(),
                    'transactionId' => $transactionId,
                    'statusCode' => $statusCode,
                    'response' => $response->getBody()->getContents()
                ]);
                
                $this->removeWebhookProcessingFlag($order, $transactionId, $context);
            }
        } catch (RequestException $e) {
            $this->logger->error('Failed to send webhook: ' . $e->getMessage(), [
                'orderNumber' => $order->getOrderNumber(),
                'transactionId' => $transactionId
            ]);
            if ($e->hasResponse()) {
                $this->logger->error('Response: ' . $e->getResponse()->getBody());
            }
            
            $this->removeWebhookProcessingFlag($order, $transactionId, $context);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error sending webhook: ' . $e->getMessage(), [
                'orderNumber' => $order->getOrderNumber(),
                'transactionId' => $transactionId
            ]);
            
            $this->removeWebhookProcessingFlag($order, $transactionId, $context);
        } catch (\TypeError $e) {
            $this->logger->error('Failed to send webhook: ' . $e->getMessage(), [
                'orderNumber' => $order->getOrderNumber(),
                'transactionId' => $transactionId
            ]);
            
            $this->removeWebhookProcessingFlag($order, $transactionId, $context);
        }
    }

    /**
     * Extract variant information from line item payload as separate values
     */
    private function extractVariantInfo(?array $payload, $lineItem): array
    {
        if (!$payload) {
            return [];
        }

        $variantValues = [];

        // Check for options in payload (common structure)
        if (isset($payload['options']) && is_array($payload['options'])) {
            foreach ($payload['options'] as $option) {
                if (isset($option['option'])) {
                    $variantValues[] = $option['option'];
                } elseif (isset($option['value'])) {
                    $variantValues[] = $option['value'];
                }
            }
        }

        // Check custom fields for variant information
        $customFields = $lineItem->getCustomFields();
        if ($customFields && is_array($customFields)) {
            // Common custom field keys for variants
            $variantKeys = ['size', 'color', 'variant', 'option', 'selection'];
            foreach ($variantKeys as $key) {
                if (isset($customFields[$key]) && !empty($customFields[$key])) {
                    $variantValues[] = $customFields[$key];
                }
            }
        }

        // Check for other common payload keys
        $commonKeys = ['size', 'color', 'variant', 'style', 'material'];
        foreach ($commonKeys as $key) {
            if (isset($payload[$key]) && !empty($payload[$key])) {
                $variantValues[] = $payload[$key];
            }
        }

        // If no specific variant info found, try to extract from product label
        if (empty($variantValues)) {
            $label = $lineItem->getLabel();
            $productNumber = $payload['productNumber'] ?? '';
            
            // If the label contains more info than just the product name, use it
            if ($label && $productNumber && strpos($label, $productNumber) === false) {
                // Extract potential variant info from label (e.g., "T-Shirt - Size L - Red")
                $labelParts = explode(' - ', $label);
                if (count($labelParts) > 1) {
                    // Skip the first part (product name) and use the rest as variant info
                    $variantValues = array_slice($labelParts, 1);
                }
            }
        }

        // Clean up the values (remove empty strings, trim whitespace)
        $variantValues = array_filter(array_map('trim', $variantValues), function($value) {
            return !empty($value);
        });

        return array_values($variantValues);
    }

    /**
     * Get client IP address with proxy support
     */
    private function getClientIpAddress($request): ?string
    {
        // Check for IP from various headers (in order of preference)
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_REAL_IP',            // Nginx proxy
            'HTTP_X_FORWARDED_FOR',      // Standard forwarded header
            'HTTP_X_FORWARDED',          // Alternative forwarded header
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster environments
            'HTTP_FORWARDED_FOR',        // Alternative
            'HTTP_FORWARDED',            // RFC 7239
            'REMOTE_ADDR'                // Standard remote address
        ];

        foreach ($ipHeaders as $header) {
            $value = $request->server->get($header);
            if (!empty($value)) {
                // Handle comma-separated IPs (X-Forwarded-For can contain multiple IPs)
                $ips = explode(',', $value);
                $ip = trim($ips[0]); // Take the first IP
                
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                } elseif (filter_var($ip, FILTER_VALIDATE_IP)) {
                    // Accept private/reserved IPs as fallback
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Generate a random UUID v4
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Validate if string is a valid UUID
     */
    private function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    /**
     * Check if webhook has already been sent for this specific transaction
     */
    private function isWebhookAlreadySentForTransaction($order, string $transactionId): bool
    {
        $customFields = $order->getCustomFields();
        $webhookKey = 'webhook_sent_' . $transactionId;
        $processingKey = 'webhook_processing_' . $transactionId;
        
        // Check if webhook was already sent OR is currently being processed
        return (isset($customFields[$webhookKey]) && $customFields[$webhookKey] === true) ||
               (isset($customFields[$processingKey]) && $customFields[$processingKey] === true);
    }

    /**
     * Mark webhook as being processed for this transaction (prevents duplicate sends)
     */
    private function markWebhookAsProcessing($order, string $transactionId, Context $context): void
    {
        try {
            $customFields = $order->getCustomFields() ?? [];
            $processingKey = 'webhook_processing_' . $transactionId;
            $customFields[$processingKey] = true;
            $customFields[$processingKey . '_at'] = (new \DateTime())->format(\DateTime::ATOM);

            $this->orderRepository->update([
                [
                    'id' => $order->getId(),
                    'customFields' => $customFields,
                ]
            ], $context);

            $this->logger->info('Marked webhook as processing for transaction', [
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'transactionId' => $transactionId
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark webhook as processing: ' . $e->getMessage());
        }
    }

    /**
     * Mark webhook as successfully sent for this transaction
     */
    private function markWebhookAsSent($order, string $transactionId, Context $context): void
    {
        try {
            $customFields = $order->getCustomFields() ?? [];
            $webhookKey = 'webhook_sent_' . $transactionId;
            $processingKey = 'webhook_processing_' . $transactionId;
            
            // Mark as sent and remove processing flag
            $customFields[$webhookKey] = true;
            $customFields[$webhookKey . '_at'] = (new \DateTime())->format(\DateTime::ATOM);
            unset($customFields[$processingKey]);
            unset($customFields[$processingKey . '_at']);
            
            // Keep backwards compatibility
            $customFields['webhook_sent'] = true;
            $customFields['webhook_sent_at'] = (new \DateTime())->format(\DateTime::ATOM);

            $this->orderRepository->update([
                [
                    'id' => $order->getId(),
                    'customFields' => $customFields,
                ]
            ], $context);

            $this->logger->info('Marked webhook as sent for transaction', [
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'transactionId' => $transactionId
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark webhook as sent: ' . $e->getMessage());
        }
    }

    /**
     * Remove processing flag if webhook failed (allows retry)
     */
    private function removeWebhookProcessingFlag($order, string $transactionId, Context $context): void
    {
        try {
            $customFields = $order->getCustomFields() ?? [];
            $processingKey = 'webhook_processing_' . $transactionId;
            
            // Remove processing flag to allow retry
            unset($customFields[$processingKey]);
            unset($customFields[$processingKey . '_at']);

            $this->orderRepository->update([
                [
                    'id' => $order->getId(),
                    'customFields' => $customFields,
                ]
            ], $context);

            $this->logger->info('Removed processing flag for failed webhook', [
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'transactionId' => $transactionId
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to remove processing flag: ' . $e->getMessage());
        }
    }
}
