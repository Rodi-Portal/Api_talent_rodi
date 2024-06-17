<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AntidopingPaquete extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'antidoping_paquete'; // Nombre de la tabla en la base de datos

    protected $fillable = [
        'creacion',
        'edicion',
        'nombre',
        'sustancias',
        'conjunto',
        'status',
        'eliminado',
    ];

    // Opcionalmente, puedes definir mutadores y accesores aquí si es necesario
}