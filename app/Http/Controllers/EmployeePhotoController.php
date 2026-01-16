<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;

class EmployeePhotoController extends Controller
{
    public function show(?string $filename = null)
    {
        $basePath = rtrim(env('LOCAL_IMAGE_PATH'), '/\\') . '/_perfilEmpleado';

        // 🔁 Fallback
        $photoPath = $basePath . '/perfil.png';

        // 📸 Si viene filename y existe, úsalo
        if ($filename) {
            $candidate = $basePath . '/' . basename($filename);

            if (File::exists($candidate)) {
                $photoPath = $candidate;
            }
        }

        if (!File::exists($photoPath)) {
            abort(404);
        }

        return response()->file($photoPath, [
            'Content-Type'  => mime_content_type($photoPath),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
