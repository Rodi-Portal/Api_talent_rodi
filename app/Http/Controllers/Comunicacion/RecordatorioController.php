<?php
namespace App\Http\Controllers\Comunicacion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicacion\RecordatorioRequest;
use App\Models\Comunicacion\Recordatorio;
use App\Models\Comunicacion\RecordatorioEvidencia;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RecordatorioController extends Controller
{
    /* ===== LISTA con filtros ===== */
    public function index(Request $r)
    {
        Log::info('Recordatorios@index IN', [
            'path'   => $r->path(),
            'method' => $r->method(),
            'query'  => $r->query(),
            'ip'     => $r->ip(),
            'ua'     => $r->userAgent(),
        ]);

        $portal   = (int) $r->query('portal');
        $clientes = trim((string) $r->query('clientes', ''));
        if (! $portal || ! $clientes) {
            return response()->json(['error' => 'portal y clientes requeridos'], 400);
        }

        $idsClientes = collect(explode(',', $clientes))->filter()->map(fn($x) => (int) $x)->all();
        $q           = $r->query('q');
        $desde       = $r->query('desde');
        $hasta       = $r->query('hasta');
        $activo      = $r->query('activo'); // '', '1', '0'

        $sort = in_array($r->query('sort'), ['nombre', 'proxima_fecha', 'tipo', 'activo']) ? $r->query('sort') : 'proxima_fecha';
        $dir  = strtolower($r->query('dir')) === 'desc' ? 'desc' : 'asc';
        $per  = min(100, max(1, (int) $r->query('per_page', 20)));
        $page = max(1, (int) $r->query('page', 1));

        $query = Recordatorio::query()
            ->where('id_portal', $portal)
            ->whereIn('id_cliente', $idsClientes)
            ->vigentes()
            ->when($q, fn($w) => $w->where(function ($qq) use ($q) {
                $qq->where('nombre', 'like', "%$q%")->orWhere('descripcion', 'like', "%$q%");
            }))
            ->when($desde, fn($w) => $w->where('fecha_base', '>=', $desde))
            ->when($hasta, fn($w) => $w->where('fecha_base', '<=', $hasta))
            ->when(($activo === '0' || $activo === '1'), fn($w) => $w->where('activo', (int) $activo))
            ->orderBy($sort, $dir);

        $p = $query->paginate($per, ['*'], 'page', $page);

        $resp = [
            'data'     => $p->items(),
            'page'     => $p->currentPage(),
            'per_page' => $p->perPage(),
            'total'    => $p->total(),
        ];

        Log::info('Recordatorios@index OUT', [
            'count'   => count($resp['data']),
            'page'    => $resp['page'],
            'perPage' => $resp['per_page'],
            'total'   => $resp['total'],
        ]);

        return response()->json($resp);
    }

    /* ===== CREAR usando /_recordadorios/{portal}/{cliente} ===== */
    public function storeForPortalCliente($idPortal, $idCliente, RecordatorioRequest $req)
    {
        Log::info('Recordatorios@store IN', [
            'path'    => $req->path(),
            'method'  => $req->method(),
            'portal'  => (int) $idPortal,
            'cliente' => (int) $idCliente,
            'payload' => $req->all(),
            'ip'      => $req->ip(),
            'ua'      => $req->userAgent(),
        ]);

        $d   = $req->validated();
        $now = now()->format('Y-m-d H:i:s');

        $payload = array_merge($d, [
            'id_portal'      => (int) $idPortal,
            'id_cliente'     => (int) $idCliente,
            'proxima_fecha'  => $this->calcProximaFecha($d['tipo'], $d['fecha_base'], $d['intervalo_meses'] ?? null),
            'id_usuario'     => $req->filled('id_usuario') ? (int) $req->input('id_usuario') : null,
            'creado_en'      => $now,
            'actualizado_en' => $now,
        ]);

        $id = Recordatorio::insertGetId($payload);

        Log::info('Recordatorios@store OUT', ['id' => $id]);
        return response()->json(['id' => $id], 201);
    }

    /* ===== ACTUALIZAR usando /_recordadorios/{portal}/{cliente}/{id} ===== */
    public function updateForPortalCliente($idPortal, $idCliente, $id, RecordatorioRequest $req)
    {
        Log::info('Recordatorios@update IN', [
            'path'    => $req->path(),
            'method'  => $req->method(),
            'portal'  => (int) $idPortal,
            'cliente' => (int) $idCliente,
            'id'      => (int) $id,
            'payload' => $req->all(),
            'ip'      => $req->ip(),
            'ua'      => $req->userAgent(),
        ]);

        $rec = Recordatorio::where('id', $id)
            ->where('id_portal', (int) $idPortal)
            ->where('id_cliente', (int) $idCliente)
            ->vigentes()
            ->first();

        if (! $rec) {
            return response()->json(['error' => 'no encontrado'], 404);
        }

        $d = $req->validated();

        if (isset($d['tipo']) || isset($d['fecha_base']) || isset($d['intervalo_meses'])) {
            $tipo               = $d['tipo'] ?? $rec->tipo;
            $base               = $d['fecha_base'] ?? $rec->fecha_base->format('Y-m-d');
            $int                = $d['intervalo_meses'] ?? $rec->intervalo_meses;
            $d['proxima_fecha'] = $this->calcProximaFecha($tipo, $base, $int);
        }

        if ($req->filled('id_usuario')) {
            $d['id_usuario'] = (int) $req->input('id_usuario');
        }

        $d['actualizado_en'] = now()->format('Y-m-d H:i:s');
        $rec->fill($d)->save();

        Log::info('Recordatorios@update OUT', ['ok' => true, 'id' => (int) $id]);
        return response()->json(['ok' => true, 'id' => (int) $id]);
    }

    /* ===== ELIMINAR (borrado lógico) ===== */
    public function destroy($id, Request $r)
    {
        $rec = Recordatorio::where('id', $id)->vigentes()->first();
        if (! $rec) {
            return response()->json(['error' => 'no encontrado'], 404);
        }

        if ($r->filled('id_usuario')) {
            $rec->id_usuario = (int) $r->input('id_usuario');
        }

        $rec->eliminado      = 1;
        $rec->actualizado_en = now()->format('Y-m-d H:i:s');
        $rec->save();

        // Si también quieres ocultar evidencias asociadas:
        $rec->evidencias()->getQuery()->update([
            'eliminado_en'   => now()->format('Y-m-d H:i:s'),
            'actualizado_en' => now()->format('Y-m-d H:i:s'),
            // opcional: registra quién lo hizo si llega en la petición
            'id_usuario'     => $r->filled('id_usuario') ? (int) $r->input('id_usuario') : null,
        ]);

        return response()->json(['ok' => true]);
    }

    /* ===== EVIDENCIAS ===== */
    public function evidenciasIndex($id)
    {
        Log::info('Evidencias@index IN', ['recordatorio_id' => (int) $id]);

        $rec = Recordatorio::find($id);
        if (! $rec) {
            return response()->json(['error' => 'no encontrado'], 404);
        }

        // OJO: no pedimos 'creado_en' para evitar error si no existe en tu tabla
        $data = $rec->evidencias()
            ->orderBy('id', 'desc')
            ->get(['id', 'tipo', 'titulo', 'descripcion', 'monto', 'moneda', 'referencia', 'url_archivo', 'actualizado_en']);

        Log::info('Evidencias@index OUT', ['count' => count($data)]);
        return response()->json(['data' => $data]);
    }

    public function evidenciasStore($id, Request $r)
    {
        Log::info('Evidencias@store IN', [
            'path'            => $r->path(),
            'method'          => $r->method(),
            'recordatorio_id' => (int) $id,
            'has_files'       => $r->hasFile('files'),
            'files_count'     => $r->hasFile('files') ? count($r->file('files')) : 0,
            'ip'              => $r->ip(),
            'ua'              => $r->userAgent(),
        ]);

        $rec = Recordatorio::find($id);
        if (! $rec) {
            return response()->json(['error' => 'no encontrado'], 404);
        }

        $r->validate([
            'files.*'    => 'file|max:8192|mimes:pdf,jpg,jpeg,png,webp,heic',
            'id_usuario' => 'nullable|integer',
        ]);

        $ids = [];
        if ($r->hasFile('files')) {
            foreach ($r->file('files') as $file) {
                // nombre seguro
                $ext      = strtolower($file->getClientOriginalExtension() ?: 'bin');
                $filename = now()->format('Ymd_His') . '_' . Str::random(12) . '.' . $ext;

                // rutas/url destino
                [$absPath, $url] = $this->buildPathsAndUrl((int) $rec->id_portal, (int) $rec->id_cliente, $filename);

                // mover archivo
                $this->ensureDir(dirname($absPath));
                $file->move(dirname($absPath), basename($absPath));

                Log::info('Evidencias@file STORED', ['path' => $absPath, 'url' => $url]);

                // insertar DB (sin creado_en si no existe en tu tabla)
                $ids[] = RecordatorioEvidencia::insertGetId([
                    'id_portal'       => $rec->id_portal,
                    'id_cliente'      => $rec->id_cliente,
                    'id_recordatorio' => $rec->id,
                    'tipo'            => 'archivo',
                    'titulo'          => $file->getClientOriginalName(),
                    'descripcion'     => null,
                    'url_archivo'     => $url,
                    'id_usuario'      => $r->filled('id_usuario') ? (int) $r->input('id_usuario') : null,
                ]);
            }
        }

        Log::info('Evidencias@store OUT', ['subidos' => count($ids), 'ids' => $ids]);
        return response()->json(['subidos' => count($ids), 'ids' => $ids], 201);
    }

    public function evidenciasDestroy($docId, Request $r)
    {
        $doc = RecordatorioEvidencia::find($docId);
        if (! $doc) {
            return response()->json(['error' => 'no encontrado'], 404);
        }

        // Soft delete + quién lo hizo
        $doc->eliminado_en = now()->format('Y-m-d H:i:s');
        if ($r->filled('id_usuario')) {
            $doc->id_usuario = (int) $r->input('id_usuario');
        }

        $doc->actualizado_en = now()->format('Y-m-d H:i:s');
        $doc->save();

        return response()->json(['ok' => true]);
    }

    /* ===== Toggle activo ===== */
    public function toggle($id, Request $r)
    {
        Log::info('Recordatorios@toggle IN', [
            'id'      => (int) $id,
            'payload' => $r->all(),
        ]);

        $r->validate(['activo' => 'required|in:0,1']);
        $rec = Recordatorio::find($id);
        if (! $rec) {
            return response()->json(['error' => 'no encontrado'], 404);
        }

        if ($r->filled('id_usuario')) {
            $rec->id_usuario = (int) $r->input('id_usuario');
        }

        $rec->activo         = (int) $r->activo;
        $rec->actualizado_en = now()->format('Y-m-d H:i:s');
        $rec->save();

        Log::info('Recordatorios@toggle OUT', ['ok' => true, 'id' => (int) $id, 'activo' => $rec->activo]);
        return response()->json(['ok' => true]);
    }

    /* ===== Utilidad: próxima fecha ===== */
    private function calcProximaFecha(string $tipo, string $fechaBase, ?int $intervaloMeses): string
    {
        $dt = Carbon::createFromFormat('Y-m-d', $fechaBase);
        if ($tipo === 'mensual' && $intervaloMeses && $intervaloMeses > 0) {
            $target = $dt->copy()->addMonthsNoOverflow($intervaloMeses);
            return $target->format('Y-m-d');
        }
        return $dt->format('Y-m-d');
    }

    /* ===== Helpers de media ===== */

    private function mediaBase(): array
    {
        $isProd = app()->environment('production');

        $basePath = rtrim(
            $isProd
                ? (config('paths.prod_images') ?: '')
                : (config('paths.local_images') ?: ''),
            "/\\"
        );

        $baseUrl = rtrim(
            $isProd
                ? (config('paths.prod_images_url') ?: '')
                : (config('paths.local_images_url') ?: ''),
            "/"
        );

        // Fallback → public/storage
        if (! $basePath || ! $baseUrl) {
            $basePath = rtrim(public_path('storage'), "/\\");
            $baseUrl  = url('storage');
        }
        return [$basePath, $baseUrl];
    }

    private function ensureDir(string $dir): void
    {
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
    }

    private function buildPathsAndUrl(int $portalId, int $clienteId, string $filename): array
    {
        [$basePath, $baseUrl] = $this->mediaBase();

        $dir = $basePath . DIRECTORY_SEPARATOR . '_recordatorios'
            . DIRECTORY_SEPARATOR . $portalId
            . DIRECTORY_SEPARATOR . $clienteId;

        $this->ensureDir($dir);

        $absPath = $dir . DIRECTORY_SEPARATOR . $filename;
        $url     = $baseUrl . '/_recordatorios/' . $portalId . '/' . $clienteId . '/' . $filename;

        return [$absPath, $url];
    }

    private function absolutePathFromUrl(string $urlOrPath): ?string
    {
        [$basePath, $baseUrl] = $this->mediaBase();
        $urlOrPath            = trim($urlOrPath);

        if ($baseUrl && str_starts_with($urlOrPath, $baseUrl)) {
            $relative = substr($urlOrPath, strlen($baseUrl));
        } else {
            $parsed   = parse_url($urlOrPath);
            $relative = $parsed['path'] ?? $urlOrPath;
        }

        $relative = ltrim($relative, "/\\");
        if ($relative === '') {
            return null;
        }

        return rtrim($basePath, "/\\") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }

    public function evidenciasShow($docId, Request $r)
    {
        $doc = RecordatorioEvidencia::find($docId);
        if (! $doc || $doc->eliminado_en) {
            return response()->json(['error' => 'no encontrado'], 404);
        }

        $abs = $this->absolutePathFromUrl((string) $doc->url_archivo);
        if (! $abs || ! File::exists($abs)) {
            return response()->json(['error' => 'archivo no existe'], 404);
        }

        // Nombre "seguro" para el header
        $downloadName = $doc->titulo ?: basename($abs);
        $downloadName = Str::of($downloadName)->replaceMatches('/[^\w\-. ]+/u', '')->value();

        // 'inline' para verlo en el navegador
        $mime = File::mimeType($abs) ?: 'application/octet-stream';
        return response()->file($abs, [
            'Content-Type'           => $mime,
            'Content-Disposition'    => 'inline; filename="' . $downloadName . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function evidenciasDownload($docId, Request $r)
    {
        $doc = RecordatorioEvidencia::find($docId);
        if (! $doc || $doc->eliminado_en) {
            return response()->json(['error' => 'no encontrado'], 404);
        }

        $abs = $this->absolutePathFromUrl((string) $doc->url_archivo);
        if (! $abs || ! File::exists($abs)) {
            return response()->json(['error' => 'archivo no existe'], 404);
        }

        $downloadName = $doc->titulo ?: basename($abs);
        $downloadName = Str::of($downloadName)->replaceMatches('/[^\w\-. ]+/u', '')->value();

        // 'attachment' fuerza la descarga
        return response()->download($abs, $downloadName);
    }
}
