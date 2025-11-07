<?php
namespace App\Http\Controllers\Sat;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SatCatalogosController extends Controller
{
    /**
     * TTL del caché en segundos (24 horas).
     * Si prefieres 12h usa 43200.
     */
    private int $ttl = 1;

    /**
     * Convierte rows [{clave, descripcion}, ...] a dict clave=>descripcion
     */
    private function mapClaveDesc($rows): array
    {
        return collect($rows)
            ->mapWithKeys(fn($r) => [$r->clave => $r->descripcion])
            ->all();
    }

    /**
     * Respuesta JSON con ETag + Cache-Control y validación If-None-Match.
     */
    private function etagJson(Request $request, array $data, int $status = 200): Response
    {
        $json        = json_encode($data, JSON_UNESCAPED_UNICODE);
        $etag        = '"' . sha1($json) . '"';
        $ifNoneMatch = $request->headers->get('If-None-Match');

        if ($ifNoneMatch && trim($ifNoneMatch) === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response($json, $status)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=' . $this->ttl . ', must-revalidate')
            ->header('Expires', gmdate('D, d M Y H:i:s', time() + $this->ttl) . ' GMT');
    }

    public function contratos()
    {
        if (request()->boolean('refresh')) {
            \Cache::forget('sat:contratos');
        }
        $data = \Cache::remember('sat:contratos', $this->ttl, function () {
            return $this->mapClaveDesc(
                \DB::connection('portal_main')->table('sat_tipo_contrato')
                    ->select('clave', 'descripcion')->orderBy('clave')->get()
            );
        });
        return response()->json($data);
    }

    public function regimenes(Request $request)
    {
        try {
            $data = Cache::remember('sat:regimenes', $this->ttl, function () {
                $rows = DB::connection('portal_main')
                    ->table('sat_tipo_regimen')
                    ->select('clave', 'descripcion')
                // si 'clave' es VARCHAR, fuerza orden numérico:
                    ->orderByRaw('CAST(clave AS UNSIGNED)')
                    ->get();

                // DEVUELVE ARRAY ORDENADO (no dict)
                return $rows->map(fn($r) => [
                    'clave'       => (string) $r->clave,
                    'descripcion' => $r->descripcion,
                ])->all();
            });

            return $this->etagJson($request, $data); // sigue usando tu ETag
        } catch (\Throwable $e) {
            Log::error('SAT regimenes error', ['e' => $e->getMessage()]);
            return response()->json(['message' => 'Error al obtener regimenes SAT'], 500);
        }
    }

    public function jornadas(Request $request)
    {
        try {
            $data = Cache::remember('sat:jornadas', $this->ttl, function () {
                $rows = DB::connection('portal_main')
                    ->table('sat_tipo_jornada')
                    ->select('clave', 'descripcion')
                    ->orderBy('clave')
                    ->get();
                return $this->mapClaveDesc($rows);
            });

            return $this->etagJson($request, $data);
        } catch (\Throwable $e) {
            Log::error('SAT jornadas error', ['e' => $e->getMessage()]);
            return response()->json(['message' => 'Error al obtener jornadas SAT'], 500);
        }
    }

    public function periodicidades(Request $request)
    {
        try {
            $data = Cache::remember('sat:periodicidades', $this->ttl, function () {
                $rows = DB::connection('portal_main')
                    ->table('sat_periodicidad_pago')
                    ->select('clave', 'descripcion')
                    ->orderBy('clave')
                    ->get();
                return $this->mapClaveDesc($rows);
            });

            return $this->etagJson($request, $data);
        } catch (\Throwable $e) {
            Log::error('SAT periodicidades error', ['e' => $e->getMessage()]);
            return response()->json(['message' => 'Error al obtener periodicidades SAT'], 500);
        }
    }

    public function all(Request $request)
    {
        try {
            // Usa los métodos individuales (aprovechan la caché)
            // y empaqueta todo en una sola respuesta con ETag propio
            $contratos = Cache::remember('sat:contratos', $this->ttl, function () {
                $rows = DB::connection('portal_main')
                    ->table('sat_tipo_contrato')->select('clave', 'descripcion')->orderBy('clave')->get();
                return $this->mapClaveDesc($rows);
            });

            $regimenes = Cache::remember('sat:regimenes', $this->ttl, function () {
                $rows = DB::connection('portal_main')
                    ->table('sat_tipo_regimen')->select('clave', 'descripcion')->orderBy('clave')->get();
                return $this->mapClaveDesc($rows);
            });

            $jornadas = Cache::remember('sat:jornadas', $this->ttl, function () {
                $rows = DB::connection('portal_main')
                    ->table('sat_tipo_jornada')->select('clave', 'descripcion')->orderBy('clave')->get();
                return $this->mapClaveDesc($rows);
            });

            $periodicidades = Cache::remember('sat:periodicidades', $this->ttl, function () {
                $rows = DB::connection('portal_main')
                    ->table('sat_periodicidad_pago')->select('clave', 'descripcion')->orderBy('clave')->get();
                return $this->mapClaveDesc($rows);
            });

            $payload = [
                'contratos'      => $contratos,
                'regimenes'      => $regimenes,
                'jornadas'       => $jornadas,
                'periodicidades' => $periodicidades,
            ];

            return $this->etagJson($request, $payload);

        } catch (\Throwable $e) {
            Log::error('SAT all error', ['e' => $e->getMessage()]);
            return response()->json(['message' => 'Error al obtener catálogos SAT'], 500);
        }
    }

}
