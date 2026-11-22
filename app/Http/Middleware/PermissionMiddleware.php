<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Allow the request when the user's role grants ANY of the given permissions.
     */
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $user = $request->user();

        if (!$user || !$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Insufficient permissions.'
            ], 403);
        }

        foreach ($permissions as $permission) {
            if (Permissions::allows($user->role, $permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized. Insufficient permissions.'
        ], 403);
    }
}
