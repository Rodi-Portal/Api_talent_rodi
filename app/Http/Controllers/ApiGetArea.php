<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Area;
use Illuminate\Support\Facades\DB;

class ApiGetArea extends Controller
{
    public function getArea($param)
    {
        try {
            // Seleccionar todos los campos de 'area' y concatenar el nombre del usuario responsable
            $area = Area::select('area.*', DB::raw("CONCAT(usuario.nombre,' ',usuario.paterno,' ',usuario.materno) as responsable"))
                        ->join('usuario', 'usuario.id', '=', 'area.usuario_responsable');

            // Verificar si el parámetro es un número (ID) o una cadena (nombre)
            if (is_numeric($param)) {
                $area->where('area.id', $param);
            } else {
                $area->where('area.nombre', 'DOPING FORANEO')
                     ->where('area.status', 1);
            }

            $area = $area->first();

            if (!$area) {
                return response()->json(['error' => 'No se encontró el área '.$param], 404);
            }

            return response()->json($area);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener el área: ' . $e->getMessage()], 500);
        }
    }
}