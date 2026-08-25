<?php

declare(strict_types=1);

namespace Reference;

/**
 * ErrorBoundary — one failure path for a codebase that serves both HTML and JSON.
 *
 * THE PROBLEM
 * The same PHP process answers a browser navigating to a page, an in-page fetch
 * from that same browser, and a native mobile client. When something throws, the
 * default behaviour is bad in three different ways at once:
 *
 *   - With display_errors on, a stack trace containing file paths, SQL, and
 *     sometimes credentials is printed to whoever asked.
 *   - With display_errors off, the browser gets a blank white page and the
 *     mobile client gets an empty body where it expected JSON, so it reports a
 *     network error and retries — turning one server fault into a retry storm.
 *   - If the failure happens AFTER rendering has begun, the user gets a page
 *     that looks finished but is missing half its content. That is the worst
 *     outcome of the three, because it is the only one nobody notices. A screen
 *     that renders a table with three of its eleven rows, and no error anywhere,
 *     is a data-integrity incident waiting to be believed.
 *
 * THE DECISIONS
 *
 * 1. Errors become exceptions. A PHP warning is converted to ErrorException by
 *    the error handler, so `$row['missing']` cannot half-work its way into a
 *    calculation. One failure path instead of two.
 *
 * 2. Content negotiation happens once, up front. The boundary decides "this
 *    caller wants JSON" from the path, the Accept header, and the AJAX header,
 *    and every failure obeys that decision — including the fatal-error shutdown
 *    handler, which is the one people forget and the one that fires for a
 *    memory exhaustion or a call to an undefined method.
 *
 * 3. Failures after output has started are declared, not hidden. Nothing can
 *    take back the bytes already flushed, so the boundary appends a visible
 *    banner and CLOSES the document. The page is honestly broken rather than
 *    dishonestly complete. A caller who is streaming JSON gets the same
 *    treatment in kind: the connection is aborted rather than completed with a
 *    valid-looking envelope.
 *
 * 4. The user gets an opaque message, the log gets everything. Same event, two
 *    audiences: a correlation id printed in both is what makes a support call
 *    ("it said error 20260825-a1b2c3d4") resolvable without asking the user to
 *    reproduce anything.
 *
 * TRADE-OFF ACCEPTED
 * Promoting every warning to an exception makes the application strict all at
 * once, and in a legacy codebase that means previously "working" pages start
 * dying on undefined array keys. That is the point — but it is a decision with a
 * migration cost, and it belongs behind a switch you can flip per environment
 * while the backlog of warnings is worked off, not a flag you set on a Friday.
 *
 * OUTPUT BUFFERING, THE CHEAPER HALF OF THE FIX
 * Opening an output buffer at the start of a request lets a failure discard a
 * partial page entirely and send a clean error instead — no banner, no truncated
 * document. It costs memory proportional to the page and does not survive an
 * explicit flush, so it is offered here rather than assumed.
 */
final class ErrorBoundary
{
    private string $correlationId;

    public function __construct(
        private bool $expectsJson,
        private ?\Closure $logger = null,
        private bool $buffer = false
    ) {
        $this->correlationId = date('Ymd') . '-' . bin2hex(random_bytes(4));
    }

    public static function forRequest(array $server, ?\Closure $logger = null, bool $buffer = false): self
    {
        return new self(self::wantsJson($server), $logger, $buffer);
    }

    /**
     * Three signals, in order of how much they can be trusted:
     *   - the path: an /api/ route is JSON no matter what the client asked for
     *   - X-Requested-With: an explicit in-page fetch
     *   - Accept: the client's stated preference; checked last because browsers
     *     send a long Accept header that happens to include application/json in
     *     some configurations, and text/html in it means a navigation
     */
    public static function wantsJson(array $server): bool
    {
        $path = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (str_contains($path, '/api/')) {
            return true;
        }

        if (strtolower((string) ($server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower((string) ($server['HTTP_ACCEPT'] ?? ''));

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /** Install the three handlers. Call this before anything else can fail. */
    public function install(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');

        if ($this->buffer) {
            ob_start();
        }

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            // Respect @-suppression and the configured error_reporting level:
            // returning false lets PHP's own handling take over for those.
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (\Throwable $e): void {
            $this->handle($e);
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if ($error === null || !in_array($error['type'], $fatal, true)) {
                return;
            }

            // A fatal never reached the exception handler, so this is the only
            // chance to say anything at all to the caller.
            $this->handle(new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            ));
        });
    }

    public function handle(\Throwable $e): void
    {
        $this->log($e);
        $this->respond();
        exit(1);
    }

    private function log(\Throwable $e): void
    {
        $line = sprintf(
            '[error][%s] %s: %s in %s:%d%s%s',
            $this->correlationId,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL,
            $e->getTraceAsString()
        );

        if ($this->logger !== null) {
            ($this->logger)($line, $e);

            return;
        }

        error_log($line);
    }

    private function respond(): void
    {
        // A buffered partial page is discarded wholesale: this is the branch that
        // produces a clean error instead of a banner bolted onto half a screen.
        if ($this->buffer && ob_get_level() > 0 && !headers_sent()) {
            ob_end_clean();
        }

        $started = headers_sent();

        if (!$started) {
            http_response_code(500);
            header('Cache-Control: no-store');
            header('Content-Type: ' . ($this->expectsJson
                ? 'application/json; charset=utf-8'
                : 'text/html; charset=utf-8'));
        }

        echo $this->body($started);
    }

    /**
     * The response body, as a pure function of (wants JSON, output already sent).
     * Pure so the four combinations can be asserted in a test instead of being
     * discovered in production.
     */
    public function body(bool $outputAlreadyStarted): string
    {
        if ($this->expectsJson) {
            if ($outputAlreadyStarted) {
                // Half a JSON document is worse than none: a client that parses it
                // successfully would treat truncated data as complete. Emitting a
                // second, un-parseable object guarantees the client's decoder
                // fails and its error path runs.
                return "\n" . json_encode([
                    'ok'    => false,
                    'error' => 'internal_error',
                    'note'  => 'response truncated; discard it',
                    'id'    => $this->correlationId,
                ], JSON_UNESCAPED_SLASHES) . "\n";
            }

            return (string) json_encode([
                'ok'      => false,
                'error'   => 'internal_error',
                'message' => 'The server could not complete this request.',
                'id'      => $this->correlationId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($outputAlreadyStarted) {
            // Say it in the page, in the user's line of sight, and then close the
            // document so nothing downstream renders on top of it.
            return '<div role="alert" style="margin:16px;padding:14px 16px;border:1px solid currentColor;'
                . 'border-radius:8px;font:14px/1.5 system-ui,sans-serif">'
                . '<strong>Part of this page could not be loaded.</strong> '
                . 'The error was logged (reference ' . htmlspecialchars($this->correlationId, ENT_QUOTES) . '). '
                . 'Reload the page; if it keeps happening, send that reference to support.'
                . '</div></main></body></html>';
        }

        $id = htmlspecialchars($this->correlationId, ENT_QUOTES);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Something went wrong</title>'
            . '<style>:root{color-scheme:light dark}body{margin:0;min-height:100vh;display:grid;'
            . 'place-items:center;font:14px/1.5 system-ui,sans-serif}'
            . '.box{max-width:420px;margin:24px;padding:32px;border:1px solid;border-radius:12px;text-align:center}'
            . 'code{font-size:12px;opacity:.75}</style></head><body>'
            . '<main class="box"><h1 style="font-size:20px;margin:0 0 10px">Something went wrong</h1>'
            . '<p>The error was logged and nothing was saved. Try again in a moment.</p>'
            . '<p><code>' . $id . '</code></p></main></body></html>';
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $requests = [
        'browser navigation' => ['REQUEST_URI' => '/inventory/items', 'HTTP_ACCEPT' => 'text/html,application/xhtml+xml'],
        'in-page fetch'      => ['REQUEST_URI' => '/inventory/items', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        'mobile client'      => ['REQUEST_URI' => '/api/v1/sync/items', 'HTTP_ACCEPT' => 'application/json'],
    ];

    foreach ($requests as $label => $server) {
        $boundary = ErrorBoundary::forRequest($server);
        printf("%-20s wants JSON: %-5s%s", $label, ErrorBoundary::wantsJson($server) ? 'yes' : 'no', PHP_EOL);
        echo '  before output: ', substr(preg_replace('/\s+/', ' ', $boundary->body(false)) ?? '', 0, 110), PHP_EOL;
        echo '  mid-render   : ', substr(preg_replace('/\s+/', ' ', $boundary->body(true)) ?? '', 0, 110), PHP_EOL;
    }
}
