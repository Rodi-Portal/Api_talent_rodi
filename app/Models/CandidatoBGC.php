<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoBGC extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'candidato_bgc';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'identidad_check',
        'empleo_check',
        'estudios_check',
        'visita_check',
        'penales_check',
        'ofac_check',
        'laboratorio_check',
        'medico_check',
        'oig_check',
        'global_searches_check',
        'domicilios_check',
        'sam_check',
        'data_juridica_check',
        'credito_check',
        'sex_offender_check',
        'professional_accreditation_check',
        'ref_academica_check',
        'ref_profesional_check',
        'nss_check',
        'ciudadania_check',
        'mvr_check',
        'militar_check',
        'credencial_academica_check',
        'curp_check',
        'check_cv',
        'comentario_final',
        'tiempo',
    ];

    // Relación con el modelo Usuario
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    // Relación con el modelo Candidato
    public function candidato()
    {
        return $this->belongsTo(Candidato::class, 'id_candidato');
    }
}