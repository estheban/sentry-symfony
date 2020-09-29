<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class TracingListener implements EventSubscriberInterface
{
    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var \SplObjectStorage
     */
    private $transactions;

    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
        $this->transactions = new \SplObjectStorage();
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $transactionContext = TransactionContext::fromTraceparent($request->headers->get('Sentry-Trace', ''));
        $transactionContext->setName(sprintf('%s %s%s%s', $request->getMethod(), $request->getSchemeAndHttpHost(), $request->getBaseUrl(), $request->getPathInfo()));
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp($request->server->get('REQUEST_TIME_FLOAT'));

        $transaction = $this->hub->startTransaction($transactionContext);

        $this->hub->configureScope(static function (Scope $scope) use ($transaction): void {
            $scope->setSpan($transaction);
        });

        $this->transactions[$request] = $transaction;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        /** @var Transaction|null $transaction */
        $transaction = $this->transactions[$event->getRequest()] ?? null;

        if (null !== $transaction) {
            $transaction->setHttpStatus($event->getResponse()->getStatusCode());
        }
    }

    public function onKernelFinishRequest(FinishRequestEvent $event): void
    {
        $request = $event->getRequest();

        /** @var Transaction|null $transaction */
        $transaction = $this->transactions[$request] ?? null;

        if (null !== $transaction) {
            $transaction->finish();
        }

        unset($this->transactions[$request]);
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
            KernelEvents::RESPONSE => 'onKernelResponse',
            KernelEvents::FINISH_REQUEST => 'onKernelFinishRequest',
        ];
    }
}
