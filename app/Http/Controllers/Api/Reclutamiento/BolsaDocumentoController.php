<?php
namespace App\Http\Controllers\Api\Reclutamiento;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BolsaDocumentoController extends Controller
{
    public function ver(Request $request, int $documentId)
    {
        $administrator = $this->administrator($request);

        $document = DB::connection('portal_main')
            ->table('documentos_bolsa as db')
            ->join('bolsa_trabajo as bt', 'bt.id', '=', 'db.id_bolsa')
            ->where('db.id', $documentId)
            ->where('db.eliminado', 0)
            ->select([
                'db.id',
                'db.id_bolsa',
                'db.nombre_archivo',
                'bt.id_portal',
            ])
            ->first();

        if (! $document) {
            abort(404, 'Documento no encontrado.');
        }

        /*
         * documentos_bolsa pertenece a Bolsa de Trabajo y ésta es
         * una entidad a nivel portal. No todos los registros tienen
         * cliente/requisición asociado.
         */
        if ((int) $document->id_portal !== (int) $administrator->id_portal) {
            throw new AuthorizationException(
                'El documento no pertenece al portal autenticado.'
            );
        }

        $filename = basename(
            str_replace('\\', '/', trim((string) $document->nombre_archivo))
        );

        if ($filename === '') {
            abort(404, 'Archivo no disponible.');
        }

        /*
         * Ruta definitiva:
         *
         * storagetalentsafe/
         * portales/{portal}/
         * bolsa_trabajo/{id_bolsa}/
         * documentos/{archivo}
         */
        $documentsRoot = rtrim(
            (string) config('paths.documents_path'),
            '/\\'
        );

        $newPath = $documentsRoot
        . DIRECTORY_SEPARATOR . 'portales'
        . DIRECTORY_SEPARATOR . (int) $document->id_portal
        . DIRECTORY_SEPARATOR . 'bolsa_trabajo'
        . DIRECTORY_SEPARATOR . (int) $document->id_bolsa
            . DIRECTORY_SEPARATOR . 'documentos'
            . DIRECTORY_SEPARATOR . $filename;

        /*
         * Lectura dual durante la migración.
         */
        if (is_file($newPath) && is_readable($newPath)) {
            $filePath = $newPath;
        } else {
            $imagesRoot = rtrim(
                (string) config('paths.images_path'),
                '/\\'
            );

            $legacyPath = $imagesRoot
                . DIRECTORY_SEPARATOR . '_documentosBolsa'
                . DIRECTORY_SEPARATOR . $filename;

            if (! is_file($legacyPath) || ! is_readable($legacyPath)) {
                abort(404, 'Archivo no encontrado.');
            }

            $filePath = $legacyPath;
        }

        return response()->file($filePath, [
            'Content-Type'           => mime_content_type($filePath)
                ?: 'application/octet-stream',
            'Content-Disposition'    => 'inline; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function administrator(Request $request): AdministradorAuth
    {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
    }
}
