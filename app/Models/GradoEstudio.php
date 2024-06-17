<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradoEstudio extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'grado_estudio';
  


    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'nombre',
        'nombre_ingles',
        'status',
        'eliminado'
    ];

    // Relaciones
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public function candidatos()
    {
        return $this->hasMany(Candidato::class, 'id_grado_estudio');
    }
}