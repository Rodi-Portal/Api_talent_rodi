<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoSync extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'candidato_sync';
    protected $fillable = [
        'creacion',
        'edicion',
        'id_cliente_talent',
        'id_usuario_talent',
        'id_aspirante_talent',
        'nombre_cliente_talent', 
        'id_portal', 
        'id_candidato_rodi', 
        'id_puesto_talent', 
        'status',
    ];

   

}
