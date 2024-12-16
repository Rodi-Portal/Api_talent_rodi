<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;  // Asegúrate de importar el trait
use Illuminate\Database\Eloquent\Model;
class Notificacion extends Model
{
    use HasFactory;

    // Si no estás utilizando las migraciones, se debe indicar la conexión y la tabla de la base de datos.
    protected $connection = 'portal_main'; // Asegúrate de que sea la conexión correcta
    protected $table = 'notificaciones_empleados'; // El nombre de la tabla en la base de datos

    // Indicamos que no se manejen timestamps
    public $timestamps = false;

    // Definir los campos que son asignables en masa
    protected $fillable = [
        'creacion',
        'correo',
        'correo1',
        'correo2',
        'cursos',
        'evaluaciones',
        'expediente',
        'horarios',
        'id_cliente',
        'id_portal',
        'id_usuario',
        'ladaSeleccionada',
        'ladaSeleccionada2',
        'notificacionesActivas',
        'status',
        'telefono1',
        'telefono2',
        'whatsapp',
    ];

    // Relación con la tabla 'cliente'
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    // Relación con la tabla 'portal'
    public function portal()
    {
        return $this->belongsTo(Portal::class, 'id_portal');
    }

    // Relación con la tabla 'usuarios_portal'
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }
}
