<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateUserToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.user_jwt_secret');
        $token = $request->bearerToken();

        if (! is_string($secret) || $secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Authentication is not configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $userId = $this->userIdFromToken($token, $secret);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set('authenticated_user_id', $userId);

        return $next($request);
    }

    private function userIdFromToken(string $token, string $secret): int
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new \InvalidArgumentException('Malformed bearer token.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;
        $header = json_decode($this->decodeSegment($encodedHeader), true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode($this->decodeSegment($encodedPayload), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256' || ! is_array($payload)) {
            throw new \InvalidArgumentException('Unsupported bearer token.');
        }

        $expectedSignature = hash_hmac(
            'sha256',
            $encodedHeader.'.'.$encodedPayload,
            $secret,
            true
        );

        if (! hash_equals($expectedSignature, $this->decodeSegment($encodedSignature))) {
            throw new \InvalidArgumentException('Invalid bearer token signature.');
        }

        $now = time();

        if (
            ! isset($payload['exp'])
            || ! is_numeric($payload['exp'])
            || (int) $payload['exp'] <= $now
            || (isset($payload['nbf']) && (! is_numeric($payload['nbf']) || (int) $payload['nbf'] > $now))
            || ! is_numeric($payload['sub'] ?? null)
            || (int) $payload['sub'] < 1
        ) {
            throw new \InvalidArgumentException('Expired or invalid bearer token.');
        }

        return (int) $payload['sub'];
    }

    private function decodeSegment(string $segment): string
    {
        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

        if ($decoded === false) {
            throw new \InvalidArgumentException('Malformed bearer token segment.');
        }

        return $decoded;
    }
}
