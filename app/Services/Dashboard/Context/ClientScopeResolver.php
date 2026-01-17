<?php

namespace App\Services\Dashboard\Context;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ClientScopeResolver
{
    public static function resolve(
        Request $request,
        string $conn,
        int $userId
    ): array {
        $db = DB::connection($conn);

        // =========================
        // 1) Clientes permitidos por usuario
        // =========================
        $allowedClientsAll = $db->table('usuario_permiso')
            ->where('id_usuario', $userId)
            ->whereNotNull('id_cliente')
            ->distinct()
            ->pluck('id_cliente')
            ->map(fn($v) => (int) $v)
            ->filter(fn($v) => $v > 0)
            ->unique()
            ->values();

        if ($allowedClientsAll->isEmpty()) {
            return [
                'allowedClients' => collect(),
                'clientId'       => null,
                'scopeClientIds' => [],
                'hasClients'     => false,
            ];
        }

        // =========================
        // 2) Leer client_id del request
        // =========================
        $clientIdParam = $request->query('client_id', null);

        if (is_array($clientIdParam)) {
            $picked = collect($clientIdParam)
                ->map(fn($v) => (int) $v)
                ->filter(fn($v) => $v > 0)
                ->unique()
                ->values();
        } elseif (is_null($clientIdParam) || $clientIdParam === '') {
            $picked = collect(); // vacío
        } else {
            $picked = collect([(int) $clientIdParam])->filter(fn($v) => $v > 0);
        }

        // =========================
        // 3) Default = todos los permitidos
        // =========================
        if ($picked->isEmpty()) {
            $picked = $allowedClientsAll->values();
        }

        // =========================
        // 4) Intersección de seguridad
        // =========================
        $picked = $picked
            ->filter(fn($v) => $allowedClientsAll->contains($v))
            ->values();

        if ($picked->isEmpty()) {
            return [
                'allowedClients' => collect(),
                'clientId'       => null,
                'scopeClientIds' => [],
                'hasClients'     => false,
            ];
        }

        // =========================
        // 5) Scope final (SIEMPRE array)
        // =========================
        $scopeClientIds = $picked->all();

        // 👇 Forzamos modo whereIn siempre
        return [
            'allowedClients' => collect($scopeClientIds),
            'clientId'       => null,
            'scopeClientIds' => $scopeClientIds,
            'hasClients'     => true,
        ];
    }
}
