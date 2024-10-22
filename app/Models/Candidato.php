<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
    public function getDetalles($id_candidato)
    {
        // Definición de la consulta
        return $this->where('id', $id_candidato)->first();
    }
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

       // Definir la relación con CandidatoBGC
       public function bgc()
       {
           return $this->hasOne(CandidatoBGC::class, 'id_candidato');
       }
       public function medico()
       {
           return $this->hasOne(Medico::class, 'id_candidato');
       }

       public function psicometrico()
       {
           return $this->hasOne(Psicometrico::class, 'id_candidato');
       }
       // Método para obtener BGC por id_candidato
       public static function getBGCById($id)
       {
           return self::select('candidato.id', 'candidato.status_bgc', 'candidato_bgc.*')
               ->join('candidato_bgc', 'candidato_bgc.id_candidato', '=', 'candidato.id')
               ->where('candidato.id', $id)
               ->first();
       }
       public static function getDetallesPDF($idCandidato)
    {
        return DB::table('candidato as c')
            ->select(
                'c.*',
                DB::raw("CONCAT(c.nombre, ' ', c.paterno, ' ', c.materno) as candidato"),
                'fin.creacion as fecha_fin',
                'cl.nombre as cliente',
                'cl.ingles as ingles',
                'dop.id as idDoping',
                'p.nombre as puestoSeleccionado',
                'MUN.nombre as municipio',
                'EST.nombre as estado',
                'GR.nombre as grado_estudio',
                'BGC.creacion as fecha_bgc'
            )
            ->leftJoin('candidato_finalizado as fin', 'fin.id_candidato', '=', 'c.id')
            ->leftJoin('candidato_bgc as BGC', 'BGC.id_candidato', '=', 'c.id')
            ->leftJoin('cliente as cl', 'cl.id', '=', 'c.id_cliente')
            ->leftJoin('doping as dop', 'dop.id_candidato', '=', 'c.id')
            ->leftJoin('puesto as p', 'p.id', '=', 'c.id_puesto')
            ->leftJoin('municipio as MUN', 'MUN.id', '=', 'c.id_municipio')
            ->leftJoin('estado as EST', 'EST.id', '=', 'c.id_estado')
            ->leftJoin('grado_estudio as GR', 'GR.id', '=', 'c.id_grado_estudio')
            ->where('c.id', $idCandidato)
            ->first();
    }

}
