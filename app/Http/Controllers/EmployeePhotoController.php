<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;

class EmployeePhotoController extends Controller
{
    use Illuminate\Support\Facades\File;

    public function show(?string $filename = null)
    {
        // ================================
        // 1) Resolver base path por entorno
        // ================================
        if (app()->environment(['production', 'produccion', 'sandbox'])) {
            $root = config('paths.prod_images');
        } else {
            $root = config('paths.local_images');
        }

        if (! $root) {
            abort(500, 'Image base path not configured');
        }

        $basePath = rtrim($root, '/\\') . '/_perfilEmpleado';

        // ================================
        // 2) Fallback por defecto
        // ================================
        $photoPath = $basePath . '/perfil.png';

        // ================================
        // 3) Si viene filename y existe
        // ================================
        if ($filename) {
            $candidate = $basePath . '/' . basename($filename);

            if (File::exists($candidate)) {
                $photoPath = $candidate;
            }
        }

        // ================================
        // 4) Validación final
        // ================================
        if (! File::exists($photoPath)) {
            abort(404);
        }

        // ================================
        // 5) Respuesta optimizada
        // ================================
        return response()->file($photoPath, [
            'Content-Type'  => mime_content_type($photoPath),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
