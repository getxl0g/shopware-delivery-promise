<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

/**
 * Assigns a product page visitor to one of four kinds — in the shop, from the user agent. Only the result goes to
 * xl0g (the kind and, for AI agents, a name from the fixed list), never the user agent itself.
 *
 * The lists follow xl0g `internal/botnamen` (as of 2026-10-17), with one difference: Googlebot and Applebot count
 * here as a search engine ("bot"), not as an AI agent — on a product page their visit is the search engine.
 * Order as at xl0g: name from the list → tool → recognisable robot → browser; otherwise robot.
 *
 * Pure function → unit-testable.
 */
class VisitorClassifier
{
    public const BROWSER = 'browser';
    public const AGENT = 'agent';
    public const BOT = 'bot';
    public const TOOL = 'werkzeug';

    /** AI agents reported by name (a subset of xl0g's list of known agents). */
    public const AGENTS = [
        'GPTBot', 'ClaudeBot', 'PerplexityBot', 'OAI-SearchBot', 'CCBot', 'Bytespider', 'Amazonbot',
        'ChatGPT-User', 'Claude-User', 'Claude-SearchBot', 'Perplexity-User', 'meta-externalagent',
        'DuckAssistBot', 'MistralAI-User',
    ];

    /** Programs and remote-controlled browsers (xl0g internal/botnamen werkzeugMuster = tool patterns). */
    private const TOOLS = [
        'curl/', 'wget/', 'go-http-client', 'python-requests', 'python-urllib', 'aiohttp', 'httpx', 'okhttp',
        'node-fetch', 'axios/', 'headlesschrome', 'phantomjs', 'java/', 'libwww-perl',
    ];

    /** How an automatic visitor without a name can be recognised (xl0g internal/botnamen generischeMuster = generic patterns). */
    private const BOTS = ['bot', 'spider', 'crawl', 'slurp', 'baidu', 'yandex', 'bingbot', 'ahrefs', 'semrush', 'petalbot'];

    /**
     * @return array{visitor: string, agent: string}
     */
    public function classify(string $userAgent): array
    {
        $low = strtolower($userAgent);
        foreach (self::AGENTS as $name) {
            if (str_contains($low, strtolower($name))) {
                return ['visitor' => self::AGENT, 'agent' => $name];
            }
        }
        foreach (self::TOOLS as $m) {
            if (str_contains($low, $m)) {
                return ['visitor' => self::TOOL, 'agent' => ''];
            }
        }
        foreach (self::BOTS as $m) {
            if (str_contains($low, $m)) {
                return ['visitor' => self::BOT, 'agent' => ''];
            }
        }
        if (str_contains($low, 'mozilla')) {
            return ['visitor' => self::BROWSER, 'agent' => ''];
        }

        return ['visitor' => self::BOT, 'agent' => ''];
    }

    /**
     * The fields for the request to xl0g (GET /api/promise): page_sku, page_shop, visitor, visitor_agent. Empty if the
     * product number does not fit — then the plugin reports nothing (xl0g would not accept it anyway).
     *
     * @return array<string, string>
     */
    public function pageViewQuery(string $sku, string $host, string $userAgent): array
    {
        $sku = trim($sku);
        if (!preg_match('~^[A-Za-z0-9._/\-]{1,64}$~', $sku)) {
            return [];
        }
        $c = $this->classify($userAgent);
        $q = ['page_sku' => $sku, 'visitor' => $c['visitor']];
        $host = strtolower(trim($host));
        if ($host !== '') {
            $q['page_shop'] = $host;
        }
        if ($c['agent'] !== '') {
            $q['visitor_agent'] = $c['agent'];
        }

        return $q;
    }
}
