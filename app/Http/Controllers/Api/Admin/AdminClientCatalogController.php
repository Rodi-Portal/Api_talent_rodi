<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminClientCatalogController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope
    ) {}

    public function index(Request $request): JsonResponse
    {
        $administrator = $request->user();

        if (! ($administrator instanceof AdministradorAuth)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $portalId = (int) $administrator->id_portal;

        if ($portalId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $clientIds = $this->clientScope
            ->permittedClientIds($administrator);

        if ($clientIds === []) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $clients = DB::connection('portal_main')
            ->table('cliente')
            ->select('id', 'nombre')
            ->where('id_portal', $portalId)
            ->whereIn('id', $clientIds)
            ->orderBy('nombre')
            ->get()
            ->map(fn ($client) => [
                'id' => (int) $client->id,
                'name' => trim((string) $client->nombre),
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $clients,
        ]);
    }
}