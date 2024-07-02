<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoAntecedenteLaboral extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'candidato_antecedente_laboral';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'numero_referencia',
        'empresa',
        'area',
        'calle',
        'colonia',
        'cp',
        'telefono',
        'tipo_empresa',
        'puesto',
        'periodo',
        'jefe_nombre',
        'jefe_puesto',
        'sueldo_inicial',
        'sueldo_final',
        'actividades',
        'causa_separacion',
        'trabajo_calidad',
        'trabajo_puntualidad',
        'trabajo_honesto',
        'trabajo_responsabilidad',
        'trabajo_adaptacion',
        'trabajo_actitud_jefes',
        'trabajo_actitud_companeros',
        'comentarios',
    ];

    /**
     * Get Candidato Antecedente Laboral by Candidato ID
     *
     * @param int $id
     * @return mixed
     */
    public function getById($id)
    {
        return $this->where('id_candidato', $id)->first();
    }
}
