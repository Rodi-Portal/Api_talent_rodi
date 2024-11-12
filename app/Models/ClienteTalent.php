<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClienteTalent extends Model
{
    // Definir el nombre de la tabla (si es diferente al plural del nombre del modelo)
    use HasFactory;

    protected $connection = 'portal_main';
    protected $table = 'cliente'; // Cambia esto si el nombre de tu tabla es diferente
    public $timestamps = false;
    // Definir los campos que se pueden llenar masivamente
    protected $fillable = [
        'id_portal',
        'id_usuario',
        'id_datos_facturacion',
        'id_domicilios',
        'id_datos_generales',
        'nombre',
        'ingles',
        'clave',
        'icono',
        'url',
        'constancia_cliente',
        'habilitado',
        'candidatos_acceso',
        'bloqueado',
        'status',
        'eliminado',
    ];

    // Si no quieres que ciertos campos sean asignados masivamente, puedes especificarlos en $guarded
    // protected $guarded = ['id'];
}
