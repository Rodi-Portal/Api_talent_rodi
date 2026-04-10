<?php
namespace App\Services\Rodi;

use Illuminate\Support\Facades\DB;
use stdClass;

class ReporteBecasService
{
    /**
     * Si esta info vive en otra conexión, cambia aquí.
     * Ejemplo:
     * protected string $connection = 'mysql_rodi';
     */
    protected ?string $connection = null;

    protected function db()
    {
        return $this->connection
            ? DB::connection($this->connection)
            : DB::connection();
    }

    public function getReporteData(int $idCandidato): array
    {
        $datosGenerales = $this->getDatosGeneralesCandidato($idCandidato);

        // Validar liberado
        $liberado = $datosGenerales->liberado ?? 0;

        return [
            'datos_generales' => $datosGenerales ?: new stdClass(),
            'familiares'      => $this->getFamiliaresCandidato($idCandidato) ?: [],
            'vivienda'        => $this->getViviendaCandidato($idCandidato) ?: new stdClass(),
            'economia'        => $this->getEconomiaCandidato($idCandidato) ?: new stdClass(),
            'becas'           => $this->getBecasCandidato($idCandidato) ?: new stdClass(),
            'fotos'           => $this->getDocumentacion($idCandidato) ?: [],

            // 👇 Aquí está la condición
            'datos_cedula'    => $liberado == 1
                ? ($this->getCedula() ?: new stdClass())
                : new stdClass(),
        ];
    }

    public function getDocumentacion(int $idCandidato): array
    {
        return $this->db()
            ->table('candidato_documento as d')
            ->selectRaw('d.id, d.id_tipo_documento, d.archivo, tipo.nombre as tipo')
            ->join('tipo_documentacion as tipo', 'tipo.id', '=', 'd.id_tipo_documento')
            ->where('d.id_candidato', $idCandidato)
            ->where('d.eliminado', 0)
            ->orderBy('d.id_tipo_documento', 'ASC')
            ->get()
            ->map(fn($row) => (array) $row)
            ->toArray();
    }

    public function getDatosGeneralesCandidato(int $idCandidato): ?object
    {
        return $this->db()
            ->table('candidato as c')
            ->selectRaw("
                c.id,
                c.id_cliente,
                c.nombre,
                c.paterno,
                c.materno,
                CONCAT_WS(' ', c.nombre, c.paterno, c.materno) AS nombre_completo,
                c.correo,
                c.puesto,
                c.fecha_nacimiento,
                c.edad,
                c.nacionalidad,
                c.lugar_nacimiento,
                c.genero,
                c.tipo_sanguineo,
                c.estado_civil,
                c.religion,
                c.celular,
                c.telefono_casa,
                c.telefono_otro,
                c.domicilio_internacional,
                c.calle,
                c.exterior,
                c.interior,
                c.cp,
                c.colonia,
                c.entre_calles,
                c.localidad,
                c.pais,
                c.estudios_periodo,
                c.estudios_escuela,
                c.estudios_ciudad,
                c.estudios_certificado,
                c.id_grado_estudio,
                c.status_bgc,
                c.edicion as fecha_final,
                c.liberado,
                ge.nombre AS grado_estudio,
                c.id_estado,
                e.nombre AS estado,
                c.id_municipio,
                m.nombre AS municipio,
                m.completo AS municipio_completo,
                u.nombre,
                u.paterno,
                u.materno,
                CONCAT_WS(' ', u.nombre, u.paterno, u.materno) AS nombre_analista
            ")
            ->leftJoin('grado_estudio as ge', function ($join) {
                $join->on('ge.id', '=', 'c.id_grado_estudio')
                    ->where('ge.eliminado', 0);
            })
            ->leftJoin('usuario as u', 'u.id', '=', 'c.id_usuario')
            ->leftJoin('estado as e', 'e.id', '=', 'c.id_estado')
            ->leftJoin('municipio as m', function ($join) {
                $join->on('m.id', '=', 'c.id_municipio')
                    ->on('m.id_estado', '=', 'c.id_estado');
            })
            ->where('c.id', $idCandidato)
            ->where('c.eliminado', 0)
            ->limit(1)
            ->first();
    }

    public function getFamiliaresCandidato(int $idCandidato): array
    {
        return $this->db()
            ->table('candidato_persona as cp')
            ->selectRaw("
                cp.id,
                cp.id_candidato,
                cp.nombre,
                cp.id_tipo_parentesco,
                tp.nombre AS parentesco,
                cp.edad,
                cp.fech_nacimiento,
                cp.estado_civil,
                cp.id_grado_estudio,
                ge.nombre AS grado_estudio,
                cp.licenciatura,
                cp.depende_candidato,
                cp.misma_vivienda,
                cp.familiar,
                cp.ciudad,
                cp.empresa,
                cp.puesto,
                cp.sueldo,
                cp.antiguedad,
                cp.monto_aporta,
                cp.muebles,
                cp.adeudo,
                cp.sexo,
                CASE
                    WHEN cp.sexo = 0 THEN 'Masculino'
                    WHEN cp.sexo = 1 THEN 'Femenino'
                    ELSE ''
                END AS sexo_texto,
                cp.tipo_ingreso,
                CASE
                    WHEN cp.tipo_ingreso = 0 THEN 'Permanente'
                    WHEN cp.tipo_ingreso = 1 THEN 'Eventual'
                    ELSE ''
                END AS tipo_ingreso_texto
            ")
            ->leftJoin('tipo_parentesco as tp', function ($join) {
                $join->on('tp.id', '=', 'cp.id_tipo_parentesco')
                    ->where('tp.eliminado', 0);
            })
            ->leftJoin('tipo_escolaridad as ge', function ($join) {
                $join->on('ge.id', '=', 'cp.id_grado_estudio')
                    ->where('ge.eliminado', 0);
            })
            ->where('cp.id_candidato', $idCandidato)
            ->where('cp.eliminado', 0)
            ->where('cp.status', 1)
            ->orderBy('cp.id', 'ASC')
            ->get()
            ->map(fn($row) => (array) $row)
            ->toArray();
    }

    public function getViviendaCandidato(int $idCandidato): ?object
    {
        return $this->db()
            ->table('candidato_habitacion as ch')
            ->selectRaw("
                ch.id,
                ch.id_candidato,
                ch.tipo_propiedad,
                ch.condicion_vivienda,
                CASE
                    WHEN ch.condicion_vivienda = 1 THEN 'Propia'
                    WHEN ch.condicion_vivienda = 2 THEN 'En pago'
                    WHEN ch.condicion_vivienda = 3 THEN 'Renta'
                    WHEN ch.condicion_vivienda = 4 THEN 'Prestada'
                    ELSE ''
                END AS condicion_vivienda_texto,

                ch.calidad_construccion,
                ch.estado_vivienda,
                ch.superficie,
                ch.num_piso,
                ch.tipo_piso,
                ch.material_muros,
                ch.material_techo,
                ch.tiempo_residencia,
                ch.tipo_zona,

                ch.agua,
                CASE
                    WHEN ch.agua = 1 THEN 'Sí'
                    WHEN ch.agua = 0 THEN 'No'
                    ELSE ''
                END AS agua_texto,

                ch.drenaje,
                CASE
                    WHEN ch.drenaje = 1 THEN 'Sí'
                    WHEN ch.drenaje = 0 THEN 'No'
                    ELSE ''
                END AS drenaje_texto,

                ch.electricidad,
                CASE
                    WHEN ch.electricidad = 1 THEN 'Sí'
                    WHEN ch.electricidad = 0 THEN 'No'
                    ELSE ''
                END AS electricidad_texto,

                ch.id_tipo_nivel_zona,
                ch.id_tipo_vivienda,
                ch.recamaras,
                ch.banios,
                ch.sala,
                ch.comedor,
                ch.cocina,
                ch.patio,
                ch.cochera,
                ch.cuarto_servicio,
                ch.jardin,
                ch.distribucion,

                ch.calidad_mobiliario,
                CASE
                    WHEN ch.calidad_mobiliario = 1 THEN 'Buena'
                    WHEN ch.calidad_mobiliario = 2 THEN 'Regular'
                    WHEN ch.calidad_mobiliario = 3 THEN 'Mala'
                    WHEN ch.calidad_mobiliario = 0 THEN ''
                    ELSE ''
                END AS calidad_mobiliario_texto,

                ch.mobiliario,
                CASE
                    WHEN ch.mobiliario = 0 THEN 'Incompleto'
                    WHEN ch.mobiliario = 1 THEN 'Completo'
                    WHEN ch.mobiliario = -1 THEN ''
                    ELSE ''
                END AS mobiliario_texto,

                ch.nivel_menaje,
                CASE
                    WHEN ch.nivel_menaje = 1 THEN 'Equipado'
                    WHEN ch.nivel_menaje = 2 THEN 'Básico'
                    WHEN ch.nivel_menaje = 3 THEN 'Austero'
                    ELSE ''
                END AS nivel_menaje_texto,

                ch.tamanio_vivienda,
                CASE
                    WHEN ch.tamanio_vivienda = 1 THEN 'Amplia'
                    WHEN ch.tamanio_vivienda = 2 THEN 'Media'
                    WHEN ch.tamanio_vivienda = 3 THEN 'Reducida'
                    ELSE ''
                END AS tamanio_vivienda_texto,

                ch.id_tipo_condiciones,
                ch.mantenimiento_inmueble,

                ch.limpieza_vivienda,
                CASE
                    WHEN ch.limpieza_vivienda = 1 THEN 'Limpia'
                    WHEN ch.limpieza_vivienda = 2 THEN 'Regular'
                    WHEN ch.limpieza_vivienda = 3 THEN 'Sucia'
                    ELSE ''
                END AS limpieza_vivienda_texto,

                ch.estufa,
                ch.refrigerador,
                ch.lavadora,
                ch.plancha_ropa,
                ch.tv,
                ch.dvd,
                ch.microondas,
                ch.equipo_musica,
                ch.computadora,
                ch.licuadora,
                ch.secadora_ropa,
                ch.tel_fijo,
                ch.ventilador,
                ch.tostadora,
                ch.observacion
            ")
            ->where('ch.id_candidato', $idCandidato)
            ->orderBy('ch.id', 'DESC')
            ->limit(1)
            ->first();
    }

    public function getEconomiaCandidato(int $idCandidato): ?object
    {
        return $this->db()
            ->table('candidato_egresos as ce')
            ->selectRaw("
                ce.id,
                ce.id_candidato,
                ce.sueldo,
                ce.aportacion,
                ce.bienes,
                ce.deudas,

                ce.renta,
                ce.alimentos,
                ce.luz,
                ce.telefono,
                ce.gas,
                ce.gasolina,
                ce.agua,
                ce.educacion,
                ce.vestimenta,
                ce.medicamento,
                ce.diversion,
                ce.cable,
                ce.internet,
                ce.tarjeta_credito,
                ce.celular,
                ce.infonavit,
                ce.servicios,
                ce.transporte,
                ce.transporte_diario,
                ce.otros,

                ce.unico_solvente,
                ce.solvencia,

                ce.tiene_credito_banco,
                ce.credito_banco_importe,
                ce.credito_banco_saldo,
                ce.credito_banco_estatus,

                ce.tiene_credito_infonavit,
                ce.credito_infonavit_importe,
                ce.credito_infonavit_saldo,
                ce.credito_infonavit_estatus,

                ce.tiene_otro_credito,
                ce.credito_otro_importe,
                ce.credito_otro_saldo,
                ce.credito_otro_estatus,

                ce.tiene_casa,
                ce.casa_ubicacion,
                ce.casa_valor,
                ce.casa_titular,

                ce.tiene_automovil,
                ce.automovil,
                ce.automovil_valor,
                ce.automovil_titular,

                ce.observacion
            ")
            ->where('ce.id_candidato', $idCandidato)
            ->orderBy('ce.id', 'DESC')
            ->limit(1)
            ->first();
    }

    public function getBecasCandidato(int $idCandidato): ?object
    {
        return $this->db()
            ->table('candidato_becas as cb')
            ->selectRaw("
                cb.id,
                cb.id_candidato,
                cb.no_expediente,
                cb.pasaporte,
                cb.grado,
                cb.escuela,
                cb.promedio,
                cb.servicio_apoyo,
                cb.descripcion_apoyo,
                cb.diagnostico_social,
                cb.instituciones,
                cb.enfermedades,
                cb.observaciones,
                cb.cruz,
                cb.departamento,
                cb.tipo_dictamen,
                CASE
                    WHEN cb.tipo_dictamen = 1 THEN 'Nueva'
                    WHEN cb.tipo_dictamen = 2 THEN 'Se ratifica'
                    ELSE ''
                END AS tipo_dictamen_texto,
                cb.porcentaje,
                cb.fecha_apertura,
                cb.estatus_final,
                CASE
                    WHEN cb.estatus_final = 1 THEN 'Aprobado'
                    WHEN cb.estatus_final = 2 THEN 'Rechazado'
                    ELSE ''
                END AS estatus_final_texto,
                cb.compromisos,
                cb.uso_dinero,
                cb.conclusiones
            ")
            ->where('cb.id_candidato', $idCandidato)
            ->where('cb.eliminado', 0)
            ->where('cb.status', 1)
            ->orderBy('cb.id', 'DESC')
            ->limit(1)
            ->first();
    }

    public function getCedula(): ?object
    {
        return $this->db()
            ->table('area')
            ->selectRaw('nombre, firma, cedula')
            ->where('profesion_responsable', 'like', '%LTS%')
            ->limit(1)
            ->first();
    }

    public function getAnalista(int $idCandidato): ?object
    {
        return $this->db()
            ->table('candidato as c')
            ->selectRaw("CONCAT_WS(' ', u.nombre, u.paterno, u.materno) AS nombre_completo")
            ->leftJoin('usuario as u', 'u.id', '=', 'c.id_usuario')
            ->where('c.id', $idCandidato)
            ->where('c.eliminado', 0)
            ->where('c.status', 1)
            ->first();
    }
}