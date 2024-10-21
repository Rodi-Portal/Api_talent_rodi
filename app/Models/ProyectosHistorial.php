<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProyectosHistorial extends Model
{
    use HasFactory;

    // Si la tabla no sigue la convención plural de Laravel, especifica el nombre de la tabla
    protected $table = 'proyectos_historial'; // Cambia esto al nombre real de tu tabla si es diferente

    protected $connection = 'portal_main';
    public $timestamps = false;// Si no usas timestamps de Laravel

    protected $fillable = [
        'creacion',
        'id_usuario',
        'id_usuario_cliente',
        'id_usuario_subcliente',
        'id_cliente',
        'proyecto',
        'secciones',
        'lleva_identidad',
        'lleva_empleos',
        'lleva_criminal',
        'lleva_estudios',
        'lleva_domicilios',
        'lleva_gaps',
        'lleva_credito',
        'lleva_sociales',
        'lleva_no_mencionados',
        'lleva_investigacion',
        'lleva_familiares',
        'lleva_egresos',
        'lleva_vivienda',
        'lleva_prohibited_parties_list',
        'lleva_salud',
        'lleva_servicio',
        'lleva_edad_check',
        'lleva_extra_laboral',
        'lleva_motor_vehicle_records',
        'lleva_curp',
        'id_seccion_datos_generales',
        'id_estudios',
        'id_seccion_historial_domicilios',
        'id_seccion_verificacion_docs',
        'id_seccion_global_search',
        'id_seccion_social',
        'id_finanzas',
        'id_ref_personales',
        'id_ref_profesional',
        'id_ref_vecinal',
        'id_ref_academica',
        'id_empleos',
        'id_vivienda',
        'id_salud',
        'id_servicio',
        'id_investigacion',
        'id_extra_laboral',
        'id_no_mencionados',
        'id_referencia_cliente',
        'id_candidato_empresa',
        'tiempo_empleos',
        'tiempo_criminales',
        'tiempo_domicilios',
        'tiempo_credito',
        'cantidad_ref_profesionales',
        'cantidad_ref_personales',
        'cantidad_ref_vecinales',
        'cantidad_ref_academicas',
        'cantidad_ref_clientes',
        'tipo_conclusion',
        'visita',
        'tipo_pdf',
        'status',
    ];

    // Opcionalmente, puedes definir relaciones aquí
}
