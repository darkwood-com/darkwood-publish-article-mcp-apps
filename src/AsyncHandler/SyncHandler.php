<?php

declare(strict_types=1);

namespace App\AsyncHandler;

use Flow\AsyncHandlerInterface;
use Flow\Event;
use Flow\Event\AsyncEvent;
use Flow\Event\PoolEvent;
use Flow\IpPool;

/**
 * Runs jobs synchronously: invokes the job with ip->data, then calls the callback with the result.
 * No async capabilities; keeps the flow sync.
 *
 * @template T
 * @implements AsyncHandlerInterface<T>
 */
final class SyncHandler implements AsyncHandlerInterface
{
    private IpPool $ipPool;

    public function __construct()
    {
        $this->ipPool = new IpPool();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Event::ASYNC => 'async',
            Event::POOL => 'pool',
        ];
    }

    public function async(AsyncEvent $event): void
    {
        $ip = $event->getIp();
        $popIp = $this->ipPool->addIp($ip);
        $job = $event->getJob();
        $data = $job($ip->data);
        $event->getCallback()($data);
        $popIp();
    }

    public function pool(PoolEvent $event): void
    {
        $event->addIps($this->ipPool->getIps());
    }
}
