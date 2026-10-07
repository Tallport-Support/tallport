<?php

namespace App\Http\Middleware;

use App\Api\ApiAccess;
use App\Api\ApiKey;
use Closure;

/**
 * REST API: the key in the X-Tallport-API-Key (or FreeScout's X-FreeScout-API-Key) header, an api_key
 * parameter, Bearer or Basic authorization (the key as user name). The
 * global key may do everything; a user's key acts as its owner.
 */
class ApiAuthenticate
{
    public function handle($request, Closure $next)
    {
        $token = $this->token($request);
        $key = null;

        if ($token === '' || !hash_equals(ApiKey::globalKey(), $token)) {
            $key = $token !== '' ? ApiKey::findByToken($token) : null;
            if (!$key || !$key->user || $key->user->isDeleted() || !$key->user->isActive()) {
                return response()->json(['message' => 'Not Authorized'], 401);
            }
            if (!$key->canWrite() && !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
                return response()->json(['message' => 'Provided API key allows only read-operations.'], 403);
            }
            auth()->onceUsingId($key->user_id);
            $key->markUsed();
        }

        $request->attributes->set('api_access', new ApiAccess($key));
        $request->attributes->set('user_api_key', $key);

        if (!\Eventy::filter('api.auth.allowed', true, $request)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }

    protected function token($request)
    {
        $token = $request->header('X-Tallport-API-Key') ?: $request->header('X-FreeScout-API-Key') ?: $request->input('api_key');
        if (!$token) {
            $token = $request->bearerToken() ?: $request->getUser();
        }

        return trim((string) $token);
    }
}
