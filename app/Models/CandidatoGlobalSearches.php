<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoGlobalSearches extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'candidato_global_searches';

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'law_enforcement',
        'regulatory',
        'sanctions',
        'other_bodies',
        'media_searches',
        'usa_sanctions',
        'oig',
        'interpol',
        'facis',
        'bureau',
        'european_financial',
        'fda',
        'sdn',
        'ofac',
        'sam',
        'motor_vehicle_records',
        'global_comentarios',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];

    public function getById($id_candidato)
    {
        return $this->where('id_candidato', $id_candidato)->first();
    }
}
