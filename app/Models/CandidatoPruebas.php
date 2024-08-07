<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoPruebas extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'candidato_pruebas';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario_cliente',
        'id_usuario_subcliente',
        'id_usuario',
        'id_candidato',
        'id_cliente',
        'socioeconomico',
        'tipo_antidoping',
        'antidoping',
        'status_doping',
        'tipo_psicometrico',
        'psicometrico',
        'medico',
        'buro_credito',
        'sociolaboral',
        'ofac',
        'resultado_ofac',
        'oig',
        'resultado_oig',
        'sam',
        'resultado_sam',
        'data_juridica',
        'res_data_juridica',
        'new_york_restricted',
        'res_new_york_restricted',
        'otro_requerimiento'
    ];

    // Relaciones
    public function candidato()
    {
        return $this->belongsTo(Candidato::class, 'id_candidato', 'id');
    }


    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    public function antidoping()
    {
        return $this->belongsTo(Antidoping::class, 'antidoping');
    }

    public function getExamenes($id_candidato)
    {
        return $this->select('candidato_pruebas.*', 'medico.edicion as fecha_medico', 'medico.conclusion as conclusion_medico', 'psicometrico.edicion as fecha_psicometrico', 'psicometrico.archivo', 'medico.id as idMedico', 'psicometrico.id as idPsicometrico')
                    ->leftJoin('medico', 'medico.id_candidato', '=', 'candidato_pruebas.id_candidato')
                    ->leftJoin('psicometrico', 'psicometrico.id_candidato', '=', 'candidato_pruebas.id_candidato')
                    ->where('candidato_pruebas.id_candidato', $id_candidato)
                    ->first();
    }
}