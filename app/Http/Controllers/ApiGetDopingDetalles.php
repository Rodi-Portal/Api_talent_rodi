<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Doping; // Importa el modelo Doping
use App\Models\DopingDetalle; // Importa el modelo Doping


class ApiGetDopingDetalles extends Controller
{
    //
    public function getDatosDoping($id_doping)
    {
        $datosDoping = Doping::select(
            'doping.*',
            'c.nombre',
            'c.paterno',
            'c.materno',
            'paq.nombre as paquete',
            'paq.sustancias',
            'cl.nombre as cliente',
            'sub.nombre as subcliente',
            'det.id_sustancia',
            'pro.nombre as proyecto',
            'ide.nombre as identificacion',
            'c.fecha_nacimiento',
            'paq.nombre as drogas',
            \DB::raw("CONCAT(US.nombre,' ',US.paterno,' ',US.materno) as responsable"),
            'A.profesion_responsable',
            'A.firma as firmaResponsable',
            'A.cedula'
        )
        ->from('doping')
        ->join('doping_detalle as det', 'det.id_doping', '=', 'doping.id')
        ->join('candidato as c', 'c.id', '=', 'doping.id_candidato')
        ->join('antidoping_paquete as paq', 'paq.id', '=', 'doping.id_antidoping_paquete')
        ->join('cliente as cl', 'cl.id', '=', 'doping.id_cliente')
        ->leftJoin('subcliente as sub', 'sub.id', '=', 'doping.id_subcliente')
        ->leftJoin('proyecto as pro', 'pro.id', '=', 'doping.id_proyecto')
        ->leftJoin('tipo_identificacion as ide', 'ide.id', '=', 'doping.id_tipo_identificacion')
        ->leftJoin('area as A', 'A.id', '=', 'doping.id_area')
        ->leftJoin('usuario as US', 'US.id', '=', 'A.usuario_responsable')
        ->where('doping.id', $id_doping)
        ->where('doping.eliminado', 0)
        ->first();

        return response()->json($datosDoping);
    }


    public function getDopingDetalles($id_doping)
{
    $detallesDoping = DopingDetalle::where('id_doping', $id_doping)->get();

    if ($detallesDoping->isEmpty()) {
        return response()->json(['error' => 'No se encontraron detalles de doping para el ID especificado'], 404);
    }

    return response()->json($detallesDoping);
}
}
