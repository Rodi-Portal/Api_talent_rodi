<?php

namespace App\Models;
use App\Models\Usuario;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Area extends Model
{

    use HasFactory;
    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'area'; // Nombre de la tabla en la base de datos

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'nombre',
        'descripcion',
        'usuario_responsable',
        'profesion_responsable',
        'firma',
        'cedula',
        'status',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }
   
}