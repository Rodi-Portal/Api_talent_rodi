<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class CandidatoFinalizado extends Model
{
    use HasFactory;

    // Definir la tabla asociada al modelo
    protected $table = 'candidato_finalizado';

    // Definir la clave primaria de la tabla
    protected $primaryKey = 'id';

    // Deshabilitar marcas de tiempo automáticas si no están en la tabla
    public $timestamps = false;

    // Definir los campos que pueden ser asignados masivamente
    protected $fillable = [
        'creacion',
        'id_usuario',
        'id_candidato',
        'descripcion_personal1',
        'descripcion_personal2',
        'descripcion_personal3',
        'descripcion_personal4',
        'descripcion_laboral1',
        'descripcion_laboral2',
        'descripcion_socio1',
        'descripcion_socio2',
        'descripcion_visita1',
        'descripcion_visita2',
        'conclusion_investigacion',
        'recomendable',
        'tiempo',
        'comentario'
    ];
}