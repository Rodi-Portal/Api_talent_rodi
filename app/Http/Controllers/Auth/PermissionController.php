<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\PermissionService;

class PermissionController extends Controller
{
    public function effective(Request $request, PermissionService $service)
    {
        $userId = (int) $request->query('user_id');         // obligatorio
        $module = (string) $request->query('module', 'exempleados'); // por defecto

        if (!$userId) {
            return response()->json(['error' => 'user_id es requerido'], 422);
        }

        $data = $service->getEffectivePermissions($userId, $module);

        return response()->json([
            'user_id'   => $userId,
            'module'    => $module,
            'grants'    => $data['grants'],
            'denies'    => $data['denies'],
            'effective' => $data['effective'],
        ]);
    }
}
