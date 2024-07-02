<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;


class ContactoRefLaboral extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'contacto_ref_laboral';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_ref_laboral',
        'id_candidato',
        'numero_referencia',
        'nombre',
        'puesto',
        'puesto_inicio',
        'puesto_fin',
        'sueldo_inicio',
        'sueldo_fin',
        'fecha_entrada',
        'fecha_salida',
        'telefono',
        'correo',
        'actividades',
        'habilidades',
        'desempeno',
        'ausencia',
        'cumplimiento',
        'salud',
        'conflicto',
        'causa_separacion',
        'recontratar',
        'observacion',
    ];

    public static function getObservacionesContactoById($id)
    {
        return DB::table('contacto_ref_laboral as CON')
            ->select('CON.observacion', 'R.empresa')
            ->join('candidato_ref_laboral as R', 'R.id', '=', 'CON.id_ref_laboral')
            ->where('CON.id_candidato', $id)
            ->get();
    }
}