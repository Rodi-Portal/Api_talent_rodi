<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Avance extends Model
{
    use HasFactory;

    // Conexión a la base de datos
    protected $connection = 'rodi_main';

    // Nombre de la tabla
    protected $table = 'avance';

    // Desactivar timestamps automáticos
    public $timestamps = false;

    // Campos que se pueden asignar de forma masiva
    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'fecha_solicitud',
        'finalizado',
        'fecha_finalizado',
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
