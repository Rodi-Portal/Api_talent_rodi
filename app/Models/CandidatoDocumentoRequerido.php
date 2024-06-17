<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoDocumentoRequerido extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $primaryKey = 'id';
    protected $table = 'candidato_documento_requerido';
    // Deshabilitar timestamps automáticos si no tienes created_at y updated_at
    public $timestamps = false;
   
    protected $fillable = [
        'id_candidato',
        'id_tipo_documento',
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
    ];

    // Si la tabla no sigue la convención de nombres de Laravel
   
}
