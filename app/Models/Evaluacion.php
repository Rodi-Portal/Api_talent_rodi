<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Evaluacion extends Model
{
    use HasFactory;

    // Especifica la tabla si no sigue la convención plural
    protected $table = 'evaluaciones';
    public $timestamps = false;
    protected $connection = 'portal_main';
    // Clave primaria
    protected $primaryKey = 'id';
    // Define los atributos que son asignables en masa
    protected $fillable = [
        'id_portal',
        'id_usuario', // Nuevo campo
        'name',
        'numero_participantes', // Nuevo campo
        'departamento', // Nuevo campo
        'name_document',
        'id_opcion_evaluaciones',
        'description',
        'conclusiones', // Nuevo campo
        'acciones', // Nuevo campo
        'expiry_date',
        'expiry_reminder',
        'origen', // Nuevo campo
    ];

    // Si tienes timestamps en la tabla y quieres que se manejen automáticamente

}