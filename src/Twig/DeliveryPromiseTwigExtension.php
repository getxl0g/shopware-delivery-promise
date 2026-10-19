<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Twig;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Xl0g\DeliveryPromise\Service\PromiseResult;
use Xl0g\DeliveryPromise\Service\RenderedPromiseCollector;
use Xl0g\DeliveryPromise\Service\ShippingMethodResolver;
use Xl0g\DeliveryPromise\Service\Xl0gPromiseClient;

/**
 * Twig functions for the server-side first render of the widget (no layout shift):
 * the date comes from the object cache of the {@see Xl0gPromiseClient} — normally without API latency
 * in the render path. The storefront JS then only updates (countdown, refetch).
 *
 * Every rendered date is reported to the {@see RenderedPromiseCollector}, so the page's s-maxage can be
 * capped at the cutoff (HttpCacheCutoffSubscriber).
 */
class DeliveryPromiseTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly Xl0gPromiseClient $client,
        private readonly ShippingMethodResolver $shippingMethodResolver,
        private readonly RenderedPromiseCollector $collector,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('xl0g_delivery_promise', $this->promise(...)),
            new TwigFunction('xl0g_delivery_promise_with_fallback', $this->promiseWithFallback(...)),
            new TwigFunction('xl0g_delivery_countdown', $this->countdown(...)),
            new TwigFunction('xl0g_shipping_method', $this->shippingMethod(...)),
            new TwigFunction('xl0g_product_shipping_method', $this->productShippingMethod(...)),
            new TwigFunction('xl0g_is_shipping_method_excluded', $this->isShippingMethodExcluded(...)),
        ];
    }

    /**
     * Excluded shipping methods (config "Excluded shipping methods"): true ⇒ the template shows the
     * Shopware/theme standard display (parent()) and does not include the widget at all — so neither
     * the server-side call nor the JS fetch happens; xl0g is not asked.
     *
     * @param string|array|null $productValue value of the product custom field (UUID of the shipping method)
     */
    public function isShippingMethodExcluded(
        SalesChannelContext $salesChannelContext,
        string|array|null $productValue
    ): bool {
        return $this->shippingMethodResolver->isProductShippingMethodExcluded(
            $productValue,
            $salesChannelContext->getSalesChannelId()
        );
    }

    /**
     * Fallback chain for the server-side first render: first the primary shipping method (e.g. from the
     * product custom field), on an empty answer the fallback shipping method (previous logic).
     * Returns method AND date, so the template can set `data-shipping-method` consistently with the
     * rendered result. Both calls go through the client's object cache.
     *
     * @return array{shippingMethod: string, promise: PromiseResult|null}
     */
    public function promiseWithFallback(
        ?string $salesChannelId,
        string $shippingMethod,
        string $fallbackShippingMethod,
        string $context = 'checkout',
        ?string $countryIso = null
    ): array {
        $promise = $this->client->fetch($shippingMethod, $context, $salesChannelId, $countryIso);

        if ($promise === null && $fallbackShippingMethod !== '' && $fallbackShippingMethod !== $shippingMethod) {
            $fallbackPromise = $this->client->fetch($fallbackShippingMethod, $context, $salesChannelId, $countryIso);
            if ($fallbackPromise !== null) {
                $this->collector->note($fallbackPromise);

                return ['shippingMethod' => $fallbackShippingMethod, 'promise' => $fallbackPromise];
            }
        }

        $this->collector->note($promise);

        return ['shippingMethod' => $shippingMethod, 'promise' => $promise];
    }

    /**
     * Shipping method per product from the product custom field (entity selection ⇒ UUID, resolved to
     * the xl0g mapping of the referenced shipping method); empty/unknown/no mapping/invalid ⇒ previous
     * logic ({@see ShippingMethodResolver::resolveForProduct()}).
     *
     * @param string|array|null $productValue
     */
    public function productShippingMethod(
        SalesChannelContext $salesChannelContext,
        string|array|null $productValue,
        string $widgetContext = 'checkout'
    ): string {
        return $this->shippingMethodResolver->resolveForProduct($productValue, $salesChannelContext, $widgetContext);
    }

    /**
     * xl0g shipping method for the widget: checkout ⇒ custom field mapping of the selected shipping method,
     * product page/no mapping ⇒ configured default. The template writes the value into the server-side
     * call AND `data-shipping-method` — so the first render and the JS refetch (including the proxy's
     * HTTP cache key) are guaranteed to stay consistent.
     */
    public function shippingMethod(SalesChannelContext $salesChannelContext, string $widgetContext = 'checkout'): string
    {
        return $this->shippingMethodResolver->resolve($salesChannelContext, $widgetContext);
    }

    public function promise(
        ?string $salesChannelId,
        string $shippingMethod = '',
        string $context = 'checkout',
        ?string $countryIso = null
    ): ?PromiseResult {
        if ($shippingMethod === '') {
            $shippingMethod = $this->client->shippingMethod($salesChannelId);
        }

        $promise = $this->client->fetch($shippingMethod, $context, $salesChannelId, $countryIso);
        $this->collector->note($promise);

        return $promise;
    }

    /**
     * Time left until the cutoff — for the server-rendered initial value of the countdown.
     * `null` for a missing/unreadable/passed cutoff (the template then shows "—"; the JS takes over
     * right away anyway).
     *
     * @return array{hours: int, minutes: int}|null
     */
    public function countdown(?string $cutoffAt, ?\DateTimeImmutable $now = null): ?array
    {
        if ($cutoffAt === null || $cutoffAt === '') {
            return null;
        }

        try {
            $cutoff = new \DateTimeImmutable($cutoffAt);
        } catch (\Exception) {
            return null;
        }

        $remaining = $cutoff->getTimestamp() - ($now ?? new \DateTimeImmutable())->getTimestamp();
        if ($remaining <= 0) {
            return null;
        }

        return [
            'hours' => intdiv($remaining, 3600),
            'minutes' => intdiv($remaining % 3600, 60),
        ];
    }
}
