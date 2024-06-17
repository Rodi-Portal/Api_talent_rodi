<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClienteRodi extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'cliente'; // Cambia esto si el nombre de tu tabla es diferente
 public $timestamps = false;
    // Define los campos que se pueden asignar masivamente
    protected $fillable = [
        'nombre',
        'razon_social',
        'clave',
        'calle',
        'exterior',
        'interior',
        'colonia',
        'id_municipio',
        'id_estado',
        'cp',
        'rfc',
        'contacto',
        'telefono',
        'correo',
        'empresa',
        'icono',
        'url',
        'clave_consulta',
        'habilitado',
        'candidatos_acceso',
        'bloqueado',
        'status',
        'eliminado',
        'id_cliente_portal' // Asegúrate de tener este campo en tu tabla si planeas usarlo
    ];

}