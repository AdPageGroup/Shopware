<?php declare(strict_types=1);

namespace Tagging\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Cookie;
use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Tagging\MessageQueue\SendWebhookMessage;

class OrderStateChangeSubscriber implements EventSubscriberInterface
{
    private const PROCESSING_FLAG_TTL_SECONDS = 600;

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        private readonly MessageBusInterface $messageBus,
    ) {
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
        $nextState = $event->getNextState()->getTechnicalName();

        if ($nextState !== OrderTransactionStates::STATE_PAID && $nextState !== OrderTransactionStates::STATE_AUTHORIZED) {
            return;
        }

        $orderTransactionId = $event->getTransition()->getEntityId();

        $transactionCriteria = new Criteria([$orderTransactionId]);
        $transactionCriteria->addAssociation('order');

        $transaction = $this->orderTransactionRepository
            ->search($transactionCriteria, $event->getContext())
            ->first();

        if (!$transaction) {
            $this->logger->error('Transaction not found: ' . $orderTransactionId);
            return;
        }

        $orderId = $transaction->getOrderId();

        $orderCriteria = new Criteria([$orderId]);
        $order = $this->orderRepository->search($orderCriteria, $event->getContext())->first();

        if (!$order) {
            $this->logger->error('Order not found: ' . $orderId);
            return;
        }

        // Invoice / buy-now-pay-later methods stay "authorized" until capture,
        // so sending on "authorized" is opt-in per sales channel.
        if ($nextState === OrderTransactionStates::STATE_AUTHORIZED
            && !$this->systemConfigService->getBool('Tagging.config.sendOnAuthorized', $order->getSalesChannelId())
        ) {
            return;
        }

        $this->logger->info('Transaction state changed to ' . $nextState . ' - dispatching webhook', [
            'transactionId' => $orderTransactionId,
            'fromState' => $event->getPreviousState()->getTechnicalName(),
            'toState' => $nextState,
        ]);

        if ($this->isWebhookAlreadySentForTransaction($order, $orderTransactionId)) {
            $this->logger->info('Webhook already sent for this transaction, skipping', [
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'transactionId' => $orderTransactionId,
            ]);
            return;
        }

        $this->markWebhookAsProcessing($order, $orderTransactionId, $event->getContext());

        $this->messageBus->dispatch(
            new SendWebhookMessage($orderId, $orderTransactionId)
        );
    }

    public function onCheckoutOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $request = $this->requestStack->getCurrentRequest();

        if (!$request) {
            return;
        }

        $userAgent = $request->headers->get('User-Agent');
        $ipAddress = $request->getClientIp();
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

            try {
                $this->orderRepository->update([
                    [
                        'id' => $order->getId(),
                        'customFields' => $customFields,
                    ],
                ], $event->getContext());
            } catch (\Exception $e) {
                $this->logger->error('Failed to save marketing data to order: ' . $e->getMessage());
            }
        }
    }

    public function onBeforeSendResponse(BeforeSendResponseEvent $event): void
    {
        $request = $event->getRequest();

        $route = $request->attributes->get('_route', '');
        if (!str_starts_with($route, 'frontend.') && !str_starts_with($route, 'store-api.')) {
            return;
        }

        if ($request->cookies->has('_taggingmk')) {
            return;
        }

        if (!$this->hasTrackingConsent($request)) {
            return;
        }

        try {
            $response = $event->getResponse();
            $taggingMkId = Uuid::randomHex();

            $cookie = Cookie::create(
                '_taggingmk',
                $taggingMkId,
                time() + (365 * 24 * 60 * 60),
                '/',
                null,
                $request->isSecure(),
                false,
                false,
                Cookie::SAMESITE_LAX
            );

            $response->headers->setCookie($cookie);
        } catch (\Exception $e) {
            $this->logger->error('Failed to set _taggingmk cookie: ' . $e->getMessage());
        }
    }

    private function hasTrackingConsent($request): bool
    {
        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        $salesChannelId = \is_string($salesChannelId) ? $salesChannelId : null;

        $consentCookieName = $this->systemConfigService->getString('Tagging.config.consentCookieName', $salesChannelId);

        if (!$consentCookieName) {
            return false;
        }

        $consentValue = $request->cookies->get($consentCookieName);

        if (!$consentValue) {
            return false;
        }

        $consentCategory = $this->systemConfigService->getString('Tagging.config.consentCategory', $salesChannelId) ?: 'statistics';

        // Cookiebot format: {stamp:'...',necessary:true,preferences:false,statistics:true,marketing:false}
        if (preg_match('/' . preg_quote($consentCategory, '/') . ':true/', $consentValue)) {
            return true;
        }

        // JSON format fallback
        $decoded = json_decode($consentValue, true);
        if (is_array($decoded) && !empty($decoded[$consentCategory])) {
            return true;
        }

        return false;
    }

    private function isWebhookAlreadySentForTransaction($order, string $transactionId): bool
    {
        $customFields = $order->getCustomFields();
        $webhookKey = 'webhook_sent_' . $transactionId;
        $processingKey = 'webhook_processing_' . $transactionId;

        if (isset($customFields[$webhookKey]) && $customFields[$webhookKey] === true) {
            return true;
        }

        if (isset($customFields[$processingKey]) && $customFields[$processingKey] === true) {
            $processingAt = $customFields[$processingKey . '_at'] ?? null;

            if ($processingAt) {
                $elapsed = time() - strtotime($processingAt);
                if ($elapsed > self::PROCESSING_FLAG_TTL_SECONDS) {
                    $this->logger->warning('Stale processing flag detected, allowing retry', [
                        'transactionId' => $transactionId,
                        'processingAt' => $processingAt,
                        'elapsedSeconds' => $elapsed,
                    ]);
                    return false;
                }
            }

            return true;
        }

        return false;
    }

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
                ],
            ], $context);
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark webhook as processing: ' . $e->getMessage());
        }
    }
}
