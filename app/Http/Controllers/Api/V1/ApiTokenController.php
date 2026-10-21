<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum 个人访问令牌管理。
 *
 * 安全边界：
 *  - 只能查看/吊销当前登录用户自己的 token（跨用户一律 404）
 *  - 列表与详情永不返回 token 哈希
 *  - 明文 token 只在创建响应中返回一次
 */
final class ApiTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentId = $this->currentTokenId($request);

        $tokens = $request->user()
            ->tokens()
            ->orderByDesc('id')
            ->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'is_current' => $currentId !== null && $currentId === $token->id,
            ])
            ->values();

        return response()->json(['data' => $tokens]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $expiresAt = isset($data['expires_at'])
            ? CarbonImmutable::parse($data['expires_at'])
            : null;

        // 当前路由未做 ability 校验，统一签发全权 token，避免“受限 token”的误判
        $newToken = $request->user()->createToken($data['name'], ['*'], $expiresAt);

        return response()->json([
            'data' => [
                'id' => $newToken->accessToken->id,
                'name' => $newToken->accessToken->name,
                'abilities' => $newToken->accessToken->abilities,
                'expires_at' => $newToken->accessToken->expires_at?->toIso8601String(),
                'plain_text_token' => $newToken->plainTextToken,
            ],
        ], 201);
    }

    public function destroy(Request $request, int $token): JsonResponse
    {
        $deleted = $request->user()
            ->tokens()
            ->whereKey($token)
            ->delete();

        if ($deleted === 0) {
            abort(404, 'Token not found.');
        }

        return response()->json(null, 204);
    }

    public function destroyCurrent(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        // Sanctum::actingAs 注入的是 TransientToken，无持久化记录，直接视为已注销
        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }

        return response()->json(null, 204);
    }

    private function currentTokenId(Request $request): ?int
    {
        $current = $request->user()->currentAccessToken();

        return $current instanceof PersonalAccessToken ? (int) $current->getKey() : null;
    }
}
