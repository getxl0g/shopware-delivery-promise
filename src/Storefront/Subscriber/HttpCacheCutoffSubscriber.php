<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Storefront\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Xl0g\DeliveryPromise\Service\PromiseResult;
use Xl0g\DeliveryPromise\Service\RenderedPromiseCollector;

/**
 * Caps the storefront page's `s-maxage` at the cutoff of the rendered date.
 *
 * The date sits as finished HTML in the cached page (product page) — without capping, a page cached at
 * 13:58 with a cutoff at 14:00 lives until the default TTL expires (~2 h) and shows the old date that
 * long. With capping the copy expires exactly at the cutoff; the next visitor renders fresh and (thanks to
 * the cutoff capping in the object cache) gets the new date right away.
 *
 * Deliberately narrow scope:
 * - only responses that Shopware has already given an `s-maxage` (HTTP cache active, cacheable
 *   route — in practice the product page; checkout and cart carry no s-maxage),
 * - only if a date with a cutoff was actually rendered in this request
 *   ({@see RenderedPromiseCollector}),
 * - only shortening — never extending beyond Shopware's value.
 *
 * Priority -1600: AFTER Shopware's CacheResponseSubscriber (setResponseCache, -1500), which sets the
 * default s-maxage.
 */
class HttpCacheCutoffSubscriber implements EventSubscriberInterface
{
    /** Lower bound in seconds — prevents zero TTLs and render storms right before the cutoff. */
    private const MIN_TTL = 5;

    public function __construct(
        private readonly RenderedPromiseCollector $collector,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -1600]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Always consume (even if there is nothing to cap below): the collector must never leak
        // into the next request in long-running runtimes.
        $cutoffAt = $this->collector->consume();
        if ($cutoffAt === null) {
            return;
        }

        $response = $event->getResponse();
        $sMaxAge = $response->headers->getCacheControlDirective('s-maxage');
        if (!is_numeric($sMaxAge) || (int) $sMaxAge <= 0) {
            return;
        }

        $current = (int) $sMaxAge;
        // Cutoff already passed (fresh render during the engine rollover) ⇒ MIN_TTL, so the page
        // picks up the updated date quickly.
        $capped = PromiseResult::ttlUntilCutoff($cutoffAt, $current, self::MIN_TTL, self::MIN_TTL);
        if ($capped < $current) {
            $response->setSharedMaxAge($capped);
        }
    }
}
