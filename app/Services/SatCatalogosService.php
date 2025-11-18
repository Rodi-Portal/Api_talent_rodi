<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

class SatCatalogosService
{
    protected string $conn = 'portal_main';

    public function contratos(): array
    {
        return DB::connection($this->conn)
            ->table('sat_tipo_contrato')
            ->orderBy('clave')
            ->pluck('descripcion', 'clave')
            ->toArray(); // ['01' => 'Tiempo indeterminado', ...]
    }

    public function regimenes(): array
    {
        return DB::connection($this->conn)
            ->table('sat_tipo_regimen')
            ->orderBy('clave')
            ->pluck('descripcion', 'clave')
            ->toArray(); // ['02' => 'Sueldos', '09' => 'Asimilados...', '13'=> 'Asimilados (variante PAC)']
    }

    public function jornadas(): array
    {
        return DB::connection($this->conn)
            ->table('sat_tipo_jornada')
            ->orderBy('clave')
            ->pluck('descripcion', 'clave')
            ->toArray();
    }

    public function periodicidades(): array
    {
        return DB::connection($this->conn)
            ->table('sat_periodicidad_pago')
            ->orderBy('clave')
            ->pluck('descripcion', 'clave')
            ->toArray();
    }

    /** Utilidades: invertir (desc→clave) y normalizar */
    public static function invert(array $m): array
    {
        // '01' => 'Tiempo indeterminado'  =>  'tiempo indeterminado' => '01'
        $out = [];
        foreach ($m as $k => $v) {
            $out[mb_strtolower(trim($v))] = $k;
        }

        return $out;
    }

    public function percepciones(): array
    {
        return DB::connection($this->conn)
            ->table('cat_tipo_percepcion_sat')
            ->orderBy('clave')
            ->pluck('descripcion', 'clave')
            ->toArray();
    }

    public function deducciones(): array
    {
        return DB::connection($this->conn)
            ->table('cat_tipo_deduccion_sat')
            ->orderBy('clave')
            ->pluck('descripcion', 'clave')
            ->toArray();
    }

    public function incapacidades(): array
    {
        return DB::connection($this->conn)
            ->table('cat_tipo_incapacidad_sat')
            ->orderBy('clave')
            ->pluck('descripcion', 'clave')
            ->toArray();
    }
}
