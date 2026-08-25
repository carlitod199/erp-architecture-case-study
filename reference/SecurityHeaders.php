<?php

declare(strict_types=1);

namespace Reference;

/**
 * SecurityHeaders — the response headers, and why each one is set.
 *
 * These are cheap. Every one of them is a single header that turns a whole class
 * of bug from "exploitable" into "does not work", and none of them requires the
 * application to be rewritten. On a stack with no WAF, no CDN rules, and no
 * platform layer to configure, they are most of the defence in depth available.
 *
 * The headers are produced as an array by a pure function and only then sent, so
 * the policy can be asserted in a test rather than eyeballed in a browser.
 *
 *   Strict-Transport-Security
 *     Locks the browser to HTTPS for this host for a year, so a downgrade or a
 *     plain-HTTP link cannot leak the session cookie. Sent ONLY over HTTPS: sent
 *     over plaintext it is ignored by browsers and, if it were honoured, it would
 *     be an unauthenticated way to break a host. `preload` is deliberately NOT
 *     included by default — submitting to the preload list is close to
 *     irreversible and must be a deployment decision, not a library default.
 *
 *   X-Content-Type-Options: nosniff
 *     Stops the browser second-guessing Content-Type. Without it, a user upload
 *     served as text/plain can be sniffed as HTML and executed in your origin.
 *
 *   X-Frame-Options / frame-ancestors
 *     Clickjacking. An ERP where one click approves a payment is exactly the sort
 *     of thing worth framing invisibly. frame-ancestors in the CSP is the modern
 *     control; X-Frame-Options stays for older browsers.
 *
 *   Referrer-Policy: strict-origin-when-cross-origin
 *     URLs in this kind of application carry record ids and filters. Cross-origin
 *     requests get the origin only; same-origin navigation keeps the full path.
 *
 *   Permissions-Policy
 *     Turns off device APIs the application does not use, so an injected script
 *     cannot quietly reach for the microphone or geolocation.
 *
 *   Cross-Origin-Opener-Policy / Cross-Origin-Resource-Policy
 *     Severs the window reference a popup opener would otherwise keep, and stops
 *     other origins embedding responses from this one.
 *
 *   Content-Security-Policy
 *     The real one. See the trade-off below.
 *
 * THE CSP TRADE-OFF, HONESTLY
 * A policy with 'unsafe-inline' in script-src stops almost nothing: the whole
 * point of CSP is that injected inline script does not run, and 'unsafe-inline'
 * is the switch that lets it run. It exists here as an intermediate state for a
 * codebase written before CSP, full of inline handlers and inline <style>.
 *
 * The migration that makes it real, in order:
 *   1. Ship the policy in Report-Only. Nothing breaks; violations are collected.
 *   2. Fix what reports, starting with third-party origins — self-host fonts and
 *      scripts so the policy can name 'self' and nothing else.
 *   3. Generate a per-response nonce, put it on every legitimate <script>, and
 *      drop 'unsafe-inline' from script-src. Inline handler attributes
 *      (onclick="...") cannot carry a nonce and have to become listeners; this is
 *      the expensive step and the one that is genuinely worth it.
 *   4. Enforce. Keep style-src permissive if you must — inline STYLE is a far
 *      smaller weapon than inline SCRIPT — and finish it later.
 *
 * Publishing the policy as `enforce=false` and calling the application "protected
 * by CSP" would be a lie. This class makes the state explicit in one boolean.
 */
final class SecurityHeaders
{
    public function __construct(
        private bool $https,
        private bool $enforceCsp = false,
        private ?string $nonce = null,
        private int $hstsMaxAge = 31_536_000
    ) {
    }

    public static function forRequest(array $server, bool $enforceCsp = false, ?string $nonce = null): self
    {
        return new self(self::isHttps($server), $enforceCsp, $nonce);
    }

    /**
     * TLS detection has to account for a terminating proxy, which is how every
     * one of these deployments works: PHP sees plain HTTP on port 80 from the
     * load balancer while the browser is on HTTPS. Trusting X-Forwarded-Proto is
     * only safe because nothing but the proxy can reach the origin; on a host
     * where clients can connect directly, that header is attacker-controlled.
     */
    public static function isHttps(array $server): bool
    {
        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            return true;
        }
        if (strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        return (int) ($server['SERVER_PORT'] ?? 0) === 443;
    }

    /** A fresh nonce for one response. Never reuse one across responses. */
    public static function newNonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /** @return array<string,string> header name => value */
    public function headers(): array
    {
        $headers = [
            'X-Content-Type-Options'        => 'nosniff',
            'X-Frame-Options'               => 'SAMEORIGIN',
            'Referrer-Policy'               => 'strict-origin-when-cross-origin',
            'Permissions-Policy'            => 'geolocation=(), microphone=(), camera=(), payment=(), usb=()',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Cross-Origin-Opener-Policy'    => 'same-origin',
            'Cross-Origin-Resource-Policy'  => 'same-origin',
        ];

        if ($this->https) {
            $headers['Strict-Transport-Security'] = 'max-age=' . $this->hstsMaxAge . '; includeSubDomains';
        }

        $headers[$this->enforceCsp ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only']
            = $this->contentSecurityPolicy();

        return $headers;
    }

    public function contentSecurityPolicy(): string
    {
        // With a nonce, inline <script nonce="..."> runs and injected inline
        // script does not. Without one, we are still in the transitional state
        // and have to allow inline script to keep the application working.
        $scriptSrc = $this->nonce !== null
            ? "'self' 'nonce-" . $this->nonce . "'"
            : "'self' 'unsafe-inline'";

        return implode('; ', [
            "default-src 'self'",
            'script-src ' . $scriptSrc,
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }

    /**
     * Idempotent and silent when output has already started — a security header
     * that throws during rendering turns a hardening measure into an outage.
     */
    public function send(): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($this->headers() as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /** Responses that must never be cached by a shared proxy or the back button. */
    public static function noStore(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
        }
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    echo "--- transitional: plain HTTP, report-only, no nonce ---", PHP_EOL;
    foreach ((new SecurityHeaders(https: false))->headers() as $name => $value) {
        echo $name, ': ', $value, PHP_EOL;
    }

    echo PHP_EOL, "--- target: HTTPS, enforced, nonce ---", PHP_EOL;
    $nonce = SecurityHeaders::newNonce();
    foreach ((new SecurityHeaders(https: true, enforceCsp: true, nonce: $nonce))->headers() as $name => $value) {
        echo $name, ': ', $value, PHP_EOL;
    }
}
