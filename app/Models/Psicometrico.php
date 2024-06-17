<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Psicometrico extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'psicometrico';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'archivo',
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