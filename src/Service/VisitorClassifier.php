<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Service;

/**
 * Ordnet einen Besucher der Produktseite einer von vier Arten zu — im Shop, aus dem User-Agent. An xl0g geht nur das
 * Ergebnis (Art und, bei KI-Agenten, ein Name aus der festen Liste), nie der User-Agent selbst.
 *
 * Die Listen folgen xl0g `internal/botnamen` (Stand 2026-10-17), mit einem Unterschied: Googlebot und Applebot zählen
 * hier als Suchdienst („bot“), nicht als KI-Agent — auf einer Produktseite ist ihr Besuch die Suchmaschine.
 * Reihenfolge wie bei xl0g: Name von der Liste → Werkzeug → erkennbarer Roboter → Browser; sonst Roboter.
 *
 * Reine Funktion → unit-testbar.
 */
class VisitorClassifier
{
    public const BROWSER = 'browser';
    public const AGENT = 'agent';
    public const BOT = 'bot';
    public const TOOL = 'werkzeug';

    /** KI-Agenten, die namentlich gemeldet werden (Teil von xl0g internal/botnamen.Allowlist). */
    public const AGENTS = [
        'GPTBot', 'ClaudeBot', 'PerplexityBot', 'OAI-SearchBot', 'CCBot', 'Bytespider', 'Amazonbot',
        'ChatGPT-User', 'Claude-User', 'Claude-SearchBot', 'Perplexity-User', 'meta-externalagent',
        'DuckAssistBot', 'MistralAI-User',
    ];

    /** Programme und ferngesteuerte Browser (xl0g internal/botnamen werkzeugMuster). */
    private const TOOLS = [
        'curl/', 'wget/', 'go-http-client', 'python-requests', 'python-urllib', 'aiohttp', 'httpx', 'okhttp',
        'node-fetch', 'axios/', 'headlesschrome', 'phantomjs', 'java/', 'libwww-perl',
    ];

    /** Woran ein automatischer Besucher ohne Namen erkennbar ist (xl0g internal/botnamen generischeMuster). */
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
     * Die Felder für den Abruf bei xl0g (GET /api/promise): page_sku, page_shop, visitor, visitor_agent. Leer, wenn die
     * Artikelnummer nicht passt — dann meldet das Plugin nichts (xl0g nähme sie ohnehin nicht an).
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
