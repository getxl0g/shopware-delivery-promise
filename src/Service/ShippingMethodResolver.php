<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Resolves the xl0g shipping method for a widget context.
 *
 * Checkout: the Shopware shipping method selected in the {@see SalesChannelContext} carries its xl0g
 * counterpart in the custom field {@see self::CUSTOM_FIELD_NAME} (maintained per shipping method in the
 * admin). If the customer changes the shipping method, Shopware renders the page with the new context —
 * the resolved value then flows automatically into the server render, `data-shipping-method` and thus
 * into the proxy/API cache key.
 *
 * Product page: deliberately ALWAYS the configured default shipping method — before the shipping method
 * is chosen there is no customer decision that would justify a mapping.
 *
 * Fallback principle: no mapping maintained, empty or no valid slug ⇒ default from the plugin config.
 * The widget is never hidden because of missing maintenance — only on real API problems.
 *
 * Excluded shipping methods: {@see self::isProductShippingMethodExcluded()} checks the product custom
 * field against the shipping methods excluded in the config — excluded products get no xl0g delivery
 * promise but the Shopware/theme standard display.
 */
class ShippingMethodResolver
{
    public const CUSTOM_FIELD_NAME = 'custom_xl0g_delivery_promise_shipping_method_mapping';

    /**
     * Product custom field (set "product_additionalInformation"): shipping method per product.
     * Entity selection "delivery method" ⇒ the UUID of the shipping method is stored; what is used is
     * its xl0g mapping from {@see self::CUSTOM_FIELD_NAME} — the same source as for the mapping of the
     * shipping method selected in the checkout, and stable against renaming in the admin.
     */
    public const PRODUCT_CUSTOM_FIELD_NAME = 'shippingMethod';

    /**
     * Same slug rule as in the {@see \Xl0g\DeliveryPromise\Storefront\Controller\DeliveryPromiseController}:
     * the value flows into cache keys and the API call — anything else falls back to the default.
     */
    private const SLUG_PATTERN = '/^[a-z0-9_-]{1,32}$/';

    /** Request-local memoisation: UUID ⇒ xl0g mapping (null = unknown/no mapping). */
    private array $shippingMethodMappings = [];

    /** Request-local memoisation: sales channel ID ⇒ excluded shipping method IDs (config). */
    private array $excludedShippingMethodIds = [];

    public function __construct(
        private readonly Xl0gPromiseClient $client,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $shippingMethodRepository,
    ) {
    }

    /**
     * Shipping method per PRODUCT from the product custom field {@see self::PRODUCT_CUSTOM_FIELD_NAME}
     * (entity selection ⇒ UUID of the shipping method): what is used is the xl0g mapping
     * ({@see self::CUSTOM_FIELD_NAME}) of the referenced shipping method, normalised to lower case.
     * No value, unknown UUID, no mapping maintained or no valid slug ⇒ previous logic
     * ({@see self::resolve()}: checkout mapping or config default). Non-UUID values are treated as a text
     * slug (tolerant of a future switch of the field to free text).
     *
     * Whether the xl0g API knows the value is decided only by its answer — the fallback chain on an
     * empty answer runs in the widget (server render: xl0g_delivery_promise_with_fallback, JS: refetch
     * with data-fallback-shipping-method), never here.
     *
     * @param string|array|null $productValue UUID/text; arrays (multi-select) ⇒ first value
     */
    public function resolveForProduct(
        string|array|null $productValue,
        SalesChannelContext $salesChannelContext,
        string $widgetContext = 'checkout'
    ): string {
        if (\is_array($productValue)) {
            $productValue = $productValue === [] ? null : reset($productValue);
        }

        $value = trim((string) $productValue);

        if ($value === '') {
            return $this->resolve($salesChannelContext, $widgetContext);
        }

        if (Uuid::isValid($value)) {
            $value = (string) $this->shippingMethodMapping($value);
            if ($value === '') {
                // Unknown shipping method or no mapping maintained ⇒ previous logic.
                return $this->resolve($salesChannelContext, $widgetContext);
            }
        }

        $method = strtolower($value);

        if (!preg_match(self::SLUG_PATTERN, $method)) {
            // Make maintenance errors visible, but never break the storefront.
            $this->logger->warning('xl0g delivery promise: ignoring invalid product shipping method value.', [
                'value' => $productValue,
                'resolved' => $method,
            ]);

            return $this->resolve($salesChannelContext, $widgetContext);
        }

        return $method;
    }

    /**
     * Excluded shipping methods (config "Excluded shipping methods", multi-select): true if the product
     * custom field {@see self::PRODUCT_CUSTOM_FIELD_NAME} points to an excluded Shopware shipping method.
     * The callers (widget switches, JSON-LD subscriber) then show the Shopware/theme standard display and
     * do not call xl0g at all — the exclusion applies deliberately BEFORE the resolution/fallback chain of
     * {@see self::resolveForProduct()}.
     *
     * No product value or nothing excluded ⇒ false (previous behaviour). Non-UUID values (tolerated free
     * text slugs) count as excluded if they match the xl0g mapping of an excluded shipping method — the
     * same (memoised) mapping source as everywhere.
     *
     * @param string|array|null $productValue UUID/text; arrays (multi-select) ⇒ first value
     */
    public function isProductShippingMethodExcluded(string|array|null $productValue, ?string $salesChannelId): bool
    {
        if (\is_array($productValue)) {
            $productValue = $productValue === [] ? null : reset($productValue);
        }

        $value = strtolower(trim((string) $productValue));
        if ($value === '') {
            return false;
        }

        $excludedIds = $this->excludedShippingMethodIds($salesChannelId);
        if ($excludedIds === []) {
            return false;
        }

        if (Uuid::isValid($value)) {
            return \in_array($value, $excludedIds, true);
        }

        foreach ($excludedIds as $id) {
            $mapping = $this->shippingMethodMapping($id);
            if ($mapping !== null && strtolower(trim($mapping)) === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Excluded shipping method IDs from the plugin config (via {@see Xl0gPromiseClient}), memoised
     * request-locally per sales channel — the switch runs per cart line item.
     *
     * @return list<string>
     */
    private function excludedShippingMethodIds(?string $salesChannelId): array
    {
        $key = $salesChannelId ?? '';

        return $this->excludedShippingMethodIds[$key]
            ??= $this->client->excludedShippingMethodIds($salesChannelId);
    }

    /**
     * xl0g mapping ({@see self::CUSTOM_FIELD_NAME}) of the referenced shipping method.
     * Memoised request-locally — exactly one indexed primary key query per distinct UUID.
     */
    private function shippingMethodMapping(string $id): ?string
    {
        if (\array_key_exists($id, $this->shippingMethodMappings)) {
            return $this->shippingMethodMappings[$id];
        }

        // Default context ⇒ system default language, independent of the storefront language
        // (the mapping is maintained language-neutrally).
        $method = $this->shippingMethodRepository
            ->search(new Criteria([$id]), Context::createDefaultContext())
            ->first();

        if (!$method instanceof ShippingMethodEntity) {
            $this->logger->warning('xl0g delivery promise: product references unknown shipping method.', [
                'shippingMethodId' => $id,
            ]);

            return $this->shippingMethodMappings[$id] = null;
        }

        // Custom fields are translatable — getTranslated() contains the (possibly inherited)
        // language state; the raw customFields property can be null.
        $fields = $method->getTranslated()['customFields'] ?? $method->getCustomFields() ?? [];
        $mapping = trim((string) ($fields[self::CUSTOM_FIELD_NAME] ?? ''));

        return $this->shippingMethodMappings[$id] = ($mapping !== '' ? $mapping : null);
    }

    public function resolve(SalesChannelContext $salesChannelContext, string $widgetContext = 'checkout'): string
    {
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        if ($widgetContext !== 'checkout') {
            return $this->client->shippingMethod($salesChannelId);
        }

        $method = $salesChannelContext->getShippingMethod();
        // The shipping method's custom fields are translatable — getTranslated() contains the
        // (possibly inherited) language state; the raw customFields property can be null.
        $fields = $method->getTranslated()['customFields'] ?? $method->getCustomFields() ?? [];
        $mapping = strtolower(trim((string) ($fields[self::CUSTOM_FIELD_NAME] ?? '')));

        if ($mapping === '') {
            return $this->client->shippingMethod($salesChannelId);
        }

        if (!preg_match(self::SLUG_PATTERN, $mapping)) {
            // Make maintenance errors visible, but never break the storefront.
            $this->logger->warning('xl0g delivery promise: ignoring invalid shipping method mapping.', [
                'shippingMethodId' => $method->getId(),
                'mapping' => $mapping,
            ]);

            return $this->client->shippingMethod($salesChannelId);
        }

        return $mapping;
    }
}
