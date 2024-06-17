<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Candidato extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'candidato';

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_usuario_cliente',
        'id_usuario_subcliente',
        'id_cliente',
        'id_subcliente',
        'id_aspirante',
        'tipo_formulario',
        'id_proyecto',
        'subproyecto',
        'id_tipo_proceso',
        'id_puesto',
        'fecha_alta',
        'fecha_documentos',
        'fecha_contestado',
        'fecha_inicio',
        'token',
        'nombre',
        'paterno',
        'materno',
        'puesto',
        'correo',
        'celular',
        'fecha_nacimiento',
        'nacionalidad',
        'edad',
        'lugar_nacimiento',
        'genero',
        'tipo_sanguineo',
        'estado_civil',
        'id_grado_estudio',
        'estudios_periodo',
        'estudios_escuela',
        'estudios_ciudad',
        'estudios_certificado',
        'religion',
        'telefono_casa',
        'telefono_otro',
        'domicilio_internacional',
        'calle',
        'exterior',
        'interior',
        'cp',
        'colonia',
        'entre_calles',
        'id_estado',
        'id_municipio',
        'pais',
        'tiempo_dom_actual',
        'tiempo_traslado',
        'tipo_transporte',
        'tel_emergencia',
        'contacto_emergencia',
        'fecha_acta',
        'acta',
        'localidad',
        'fecha_vencimiento',
        'fecha_domicilio',
        'cuenta_domicilio',
        'id_tipo_comprobante',
        'ine',
        'emision_ine',
        'emision_rfc',
        'rfc',
        'curp',
        'emision_curp',
        'nss',
        'emision_nss',
        'ingresos',
        'ingresos_extra',
        'aporte',
        'fecha_retencion_impuestos',
        'retencion_impuestos',
        'fecha_licencia',
        'licencia',
        'id_tipo_casa',
        'monto_mensual',
        'gas',
        'luz',
        'agua',
        'internet_cable',
        'mantenimiento',
        'despensa',
        'transporte',
        'otros_gastos',
        'solucion_gastos',
        'anos_habitados',
        'meses_habitados',
        'id_tipo_nivel_zona',
        'id_tipo_vivienda',
        'muebles',
        'adeudo_muebles',
        'recamaras',
        'banos',
        'cuarto_servicio',
        'cocina',
        'comedor',
        'patio',
        'jardin',
        'garaje',
        'sala',
        'id_tipo_mobiliario',
        'vivienda_suficiente',
        'id_tipo_condiciones',
        'vigencia_migratoria',
        'numero_migratorio',
        'fecha_visa',
        'visa',
        'tiempo_radica',
        'tipo_identificacion',
        'afore',
        'centro_costo',
        'fecha_entrevista',
        'trabajo_gobierno',
        'trabajo_inactivo',
        'trabajo_enterado',
        'trabajo_razon',
        'trabajo_expectativa',
        'idempresa',
        'comentario',
        'status',
        'status_bgc',
        'visitador',
        'tiempo_parcial',
        'privacidad',
        'applicant_id',
        'liberado',
        'cancelado',
        'eliminado'
    ];

    // Relaciones

    public function aspirante()
    {
        return $this->belongsTo(RequisicionAspirante::class, 'id_aspirante');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    public function estado()
    {
        return $this->belongsTo(Estado::class, 'id_estado');
    }

    public function gradoEstudio()
    {
        return $this->belongsTo(GradoEstudio::class, 'id_grado_estudio');
    }

    public function municipio()
    {
        return $this->belongsTo(Municipio::class, 'id_municipio');
    }

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'id_proyecto');
    }

    public function subcliente()
    {
        return $this->belongsTo(Subcliente::class, 'id_subcliente');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

  
    public function dopings()
    {
        return $this->hasMany(Doping::class, 'id_candidato');
    }
}
