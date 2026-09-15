<?php

namespace App\Http\Controllers;

use App\Services\Documents\EmployeePhotoPathService;

class EmployeePhotoController extends Controller
{
    public function show(
        EmployeePhotoPathService $photoPaths,
        ?string $filename = null
    ) {
        $photoPath = $photoPaths->resolveReadablePathByFilename(
            $filename
        );

        if ($photoPath === null) {
            abort(404);
        }

        return response()->file($photoPath, [
            'Content-Type'  => mime_content_type($photoPath),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}