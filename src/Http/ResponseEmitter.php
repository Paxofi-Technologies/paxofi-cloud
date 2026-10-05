<?php

declare(strict_types=1);

namespace PaxofiCloud\Http;

use Paxofi\Core\Contracts\HttpResponse;

/** Sends a PCF response through the SAPI (PCF has no emitter). */
final class ResponseEmitter
{
    public function emit(HttpResponse $response): void
    {
        if (!headers_sent()) {
            http_response_code($response->status());
            foreach ($response->headers() as $name => $values) {
                foreach ($values as $value) {
                    // Defence in depth against header injection.
                    if (preg_match('/[\r\n]/', $value) !== 1) {
                        header($name . ': ' . $value, false);
                    }
                }
            }
        }

        echo $response->body();
    }
}
