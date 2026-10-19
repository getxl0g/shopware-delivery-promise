<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

/**
 * Collects, during a storefront request, the cutoffs of the dates actually RENDERED (first Twig render
 * of the widgets + JSON-LD subscriber) and remembers the earliest.
 *
 * Purpose: the {@see \Xl0g\DeliveryPromise\Storefront\Subscriber\HttpCacheCutoffSubscriber} uses it to
 * cap the page's `s-maxage` in the HTTP cache at the cutoff — a cached product page expires at the
 * latest when the date it shows definitely changes.
 *
 * Shared service with request lifecycle semantics: {@see consume()} reads AND clears, so that nothing
 * leaks into the next request in long-running runtimes (workers).
 */
final class RenderedPromiseCollector
{
    private ?string $earliestCutoffAt = null;

    private ?int $earliestTimestamp = null;

    /** Note the cutoff of a rendered date; without a (readable) cutoff nothing happens. */
    public function note(?PromiseResult $promise): void
    {
        if ($promise === null || $promise->cutoffAt === null || $promise->cutoffAt === '') {
            return;
        }

        try {
            $timestamp = (new \DateTimeImmutable($promise->cutoffAt))->getTimestamp();
        } catch (\Exception) {
            return;
        }

        if ($this->earliestTimestamp === null || $timestamp < $this->earliestTimestamp) {
            $this->earliestCutoffAt = $promise->cutoffAt;
            $this->earliestTimestamp = $timestamp;
        }
    }

    /** Return the earliest noted cutoff (RFC 3339) and clear the collector. */
    public function consume(): ?string
    {
        $cutoffAt = $this->earliestCutoffAt;
        $this->earliestCutoffAt = null;
        $this->earliestTimestamp = null;

        return $cutoffAt;
    }
}
