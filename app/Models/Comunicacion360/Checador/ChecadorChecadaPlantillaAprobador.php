<?php

namespace App\Models\Comunicacion360\Checador;

use Illuminate\Database\Eloquent\Model;

class ChecadorChecadaPlantillaAprobador extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'checador_checada_plantilla_aprobadores';

    protected $fillable = [
        'id_plantilla',
        'tipo_evento_id',
        'id_empleado_aprobador',
        'nivel',
        'obligatorio',
        'activo',
    ];

    protected $casts = [
        'id_plantilla'          => 'integer',
        'tipo_evento_id'        => 'integer',
        'id_empleado_aprobador' => 'integer',
        'nivel'                 => 'integer',
        'obligatorio'           => 'boolean',
        'activo'                => 'boolean',
    ];
}