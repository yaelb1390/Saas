<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación de la API v1 mediante tokens personales de Sanctum.
 *
 * El token queda ligado al usuario (y, por tanto, a su empresa): todas las peticiones de la API
 * operan aisladas por esa empresa. Los permisos siguen aplicándose por rol en cada ruta.
 */
final class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Un único mensaje para credenciales inválidas O cuenta desactivada: no se revela cuál.
        if ($user === null || ! $user->is_active || ! Hash::check($data['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son válidas o la cuenta está desactivada.'],
            ]);
        }

        $token = $user->createToken($data['device_name']);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'company_id' => $user->company_id,
            ],
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'company_id' => $user->company_id,
            'roles' => $user->getRoleNames()->values(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoca solo el token con el que se hizo esta petición.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }
}
