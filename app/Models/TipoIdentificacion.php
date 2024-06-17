<?php

namespace App\Models;
use App\Models\Usuario;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipoIdentificacion extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $connection = 'rodi_main';

    protected $table = 'tipo_identificacion'; // Nombre de la tabla en la base de datos

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'nombre',
        'status',
        'eliminado',
    ];

    // Relación con Usuario si es necesaria
    public function usuario()
    {
       return $this->belongsTo(Usuario::class, 'id_usuario');
     }
}