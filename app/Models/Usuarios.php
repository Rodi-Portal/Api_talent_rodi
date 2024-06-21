<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Usuarios extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'usuario';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'nombre',
        'paterno',
        'materno',
        'id_rol',
        'tipo_analista',
        'correo',
        'password',
        'clave',
        'logueado',
        'nuevo_password',
        'firma',
        'status',
        'eliminado',
    ];

    // Relación con el modelo Rol
    public function rol()
    {
        return $this->belongsTo(Rol::class, 'id_rol');
    }
}