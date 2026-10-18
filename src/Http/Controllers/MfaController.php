<?php

declare(strict_types=1);

namespace PaxofiCloud\Http\Controllers;

use Paxofi\Core\Contracts\HttpRequest;
use Paxofi\Core\Contracts\HttpResponse;
use Paxofi\Core\Http\Response;
use PaxofiCloud\Application\Contracts\SignInStore;
use PaxofiCloud\Application\Identity\Mfa\InvalidMfaInput;
use PaxofiCloud\Application\Identity\Mfa\MfaAlreadyEnabled;
use PaxofiCloud\Application\Identity\Mfa\MfaNotEnabled;
use PaxofiCloud\Application\Identity\Mfa\MfaSettings;
use PaxofiCloud\Application\Identity\Mfa\MfaThrottled;
use PaxofiCloud\Application\Identity\Mfa\MfaUnavailable;
use PaxofiCloud\Application\Identity\Session\Session;
use PaxofiCloud\Application\Identity\Session\Sessions;
use PaxofiCloud\Http\Authenticated;
use PaxofiCloud\Http\JsonBody;
use PaxofiCloud\Http\ProblemDetails;
use PaxofiCloud\Http\RequestId;

/**
 * /api/v1/account/mfa… for the signed-in customer (SRS IAM-007, IAM-013,
 * IAM-014; threat model §6.13). One class, one action per route; secrets and
 * recovery codes appear in a response once and are never cached.
 */
final readonly class MfaController
{
    public function __construct(private MfaSettings $mfa, private Sessions $sessions, private SignInStore $identities)
    {
    }

    public function status(HttpRequest $request): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: false);
        if ($session instanceof HttpResponse) {
            return $session;
        }

        return self::json($this->mfa->status($session->identityId));
    }

    public function setup(HttpRequest $request): HttpResponse
    {
        return $this->write($request, function (Session $session, array $data): HttpResponse {
            $profile = $this->identities->findProfile($session->identityId);
            $setup = $this->mfa->setup($session->identityId, $profile['email'] ?? '', $data);

            return self::json(['secret' => $setup->secretBase32, 'otpauth_uri' => $setup->uri]);
        });
    }

    public function confirm(HttpRequest $request): HttpResponse
    {
        return $this->write($request, fn (Session $session, array $data): HttpResponse => self::json([
            'status' => 'enabled',
            'recovery_codes' => $this->mfa->confirm($session->identityId, $data),
        ]));
    }

    public function recoveryCodes(HttpRequest $request): HttpResponse
    {
        return $this->write($request, fn (Session $session, array $data): HttpResponse => self::json([
            'recovery_codes' => $this->mfa->regenerateRecoveryCodes($session->identityId, $data),
        ]));
    }

    public function disable(HttpRequest $request): HttpResponse
    {
        return $this->write($request, function (Session $session, array $data): HttpResponse {
            $this->mfa->disable($session->identityId, $data);

            return self::json(['status' => 'disabled']);
        });
    }

    /** @param \Closure(Session, array<array-key, mixed>): HttpResponse $action */
    private function write(HttpRequest $request, \Closure $action): HttpResponse
    {
        $session = Authenticated::session($request, $this->sessions, write: true);
        if ($session instanceof HttpResponse) {
            return $session;
        }
        $data = JsonBody::object($request);
        if ($data === null) {
            // `{}` decodes to an empty array, which JsonBody treats as a list; accept it as "no fields".
            $data = trim($request->body()) === '{}' ? [] : null;
        }
        if ($data === null) {
            return ProblemDetails::response(400, 'malformed_json', RequestId::of($request));
        }

        $id = RequestId::of($request);
        try {
            return $action($session, $data);
        } catch (InvalidMfaInput $e) {
            return ProblemDetails::response(422, 'validation_failed', $id, errors: $e->errors);
        } catch (MfaThrottled $e) {
            return ProblemDetails::response(429, 'too_many_attempts', $id, headers: ['retry-after' => (string) $e->retryAfterSeconds]);
        } catch (MfaAlreadyEnabled) {
            return ProblemDetails::response(409, 'mfa_already_enabled', $id);
        } catch (MfaNotEnabled) {
            return ProblemDetails::response(409, 'mfa_not_enabled', $id);
        } catch (MfaUnavailable) {
            return ProblemDetails::response(503, 'mfa_unavailable', $id);
        }
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body): HttpResponse
    {
        return new Response(
            200,
            ['content-type' => 'application/json', 'cache-control' => 'no-store'],
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
