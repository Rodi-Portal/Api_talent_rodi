<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DopingDetalle extends Model
{

    use HasFactory;
    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'doping_detalle'; // Nombre de la tabla en la base de datos

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_doping',
        'id_candidato',
        'id_sustancia',
        'resultado',
    ];

    // Relación con Usuario (muchos a uno)
    public function usuarios()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    // Relación con Doping (muchos a uno)
    public function doping()
    {
        return $this->belongsTo(Doping::class, 'id_doping');
    }

    // Relación con Candidato (muchos a uno)
    public function candidato()
    {
        return $this->belongsTo(Candidato::class, 'id_candidato');
    }

    // Relación con Sustancia (muchos a uno)
    public function sustancia()
    {
        return $this->belongsTo(Sustancia::class, 'id_sustancia');
    }
}