<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatDocumentoRequerimiento extends Model
{
    use HasFactory;

    protected $connection = 'portal_main'; // Cambia esto si necesitas una conexión específica
    protected $table = 'cat_documento_requerimiento'; // Nombre de la tabla
    public $timestamps = false; // Si no deseas usar los timestamps por defecto

    // Define los campos que se pueden asignar masivamente
    protected $fillable = [
        'creacion',
        'id_tipo_documento',
        'tipo',
        'nombre_espanol',
        'nombre_ingles',
        'label_ingles',
        'div_id',
        'input_id',
        'multiple',
        'width',
        'height',
        'obligatorio',
        'solicitado',
        'comentario',
        'comentario_ingles',
    ];
}