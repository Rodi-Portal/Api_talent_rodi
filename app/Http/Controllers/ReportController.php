<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Log;

use App\Models\Candidato;

use App\Models\CandidatoDocumento;//* ?
use App\Models\CandidatoSeccion;//all
use App\Models\CandidatoPruebas;//* ?
use App\Models\Doping;//* ?
use App\Models\VerificacionDocumento;//*
use App\Models\CandidatoEstudio;//*all
use App\Models\VerificacionMayoresEstudio;//* esta  va   con la de candidato revisar
use App\Models\VerificacionEstudios;//* all
use App\Models\VerificacionEstudiosDetalle;
use App\Models\CandidatoAntecedentesSociales;
use App\Models\CandidatoPersona; //* ? esta  esta  une  tres   tablas
use App\Models\CandidatoPersonaMismoTrabajo;//* all
use App\Models\CandidatoEgresos;//*  esta  une  dos  tablas
use App\Models\CandidatoRefLaboral;//* all
use App\Models\VerificacionNoMencionados ;//*  all
use App\Models\CandidatoAntecedenteLaboral; //* all
use App\Models\ContactoRefLaboral;//* lleva  join
use App\Models\StatusRefLaboral;//*  all
use App\Models\StatusRefLaboralDetalle;//*   lleva  join
use App\Models\CandidatoGaps;//*  all
use App\Models\CandidatoRefPersonal;//*  all
use App\Models\CandidatoFinalizado;//*  all
use App\Models\CandidatoHabitacion;//*  lleva joins
use App\Models\CandidatoVecino;//*  all
use App\Models\VerificacionLegal;//*  all
use App\Models\CandidatoSalud;//*  all
use App\Models\CandidatoServicio;//*  all
use App\Models\CandidatoHistorialCrediticio ;//*  all
use App\Models\CandidatoGlobalSearches;//*  all
use App\Models\VerificacionPenales;//*  all
use App\Models\VerificacionPenalesDetalle;//*   lleva  join
use App\Models\ReferenciaCliente;//*  all
use App\Models\CandidatoEmpresa;//*  all
use App\Models\CandidatoRefProfesional;//*  all


/*

use App\Models\ReferenciaAcademica;//*  all

 */
class ReportController extends Controller
{
    public function getReport($id_candidato)
    {

        try {
            $data = [];
            $candidato = new Candidato();
            $candidatoDocumento = new CandidatoDocumento();
            $candidatoSeccion = new CandidatoSeccion();
            $candidatoPruebas = new CandidatoPruebas();
            $doping = new Doping();
            $verificacionDocumento = new VerificacionDocumento();
            $candidatoEstudio = new CandidatoEstudio();
            $verificacionMayoresEstudio = new VerificacionMayoresEstudio();
            $verificacionEstudios = new VerificacionEstudios();
            $detalleVerificacion = new VerificacionEstudiosDetalle();
            $antecedentesSociales = new CandidatoAntecedentesSociales();
            $candidatoPersona = new CandidatoPersona();
            $candidatoPersonaMismoTrabajo = new CandidatoPersonaMismoTrabajo();
            $candidatoEgresos = new CandidatoEgresos();
            $candidatoRefLaboral = new CandidatoRefLaboral();
            $verificacionNoMencionados = new VerificacionNoMencionados();
            $candidatoAntecedenteLaboral = new CandidatoAntecedenteLaboral();
            $contactoRefLaboral = new ContactoRefLaboral();
            $statusRefLaboral = new StatusRefLaboral();
            $statusRefLaboralDetalle = new StatusRefLaboralDetalle();
            $candidatoGaps = new CandidatoGaps();
            $candidatoRefPersonal = new CandidatoRefPersonal();
            $candidatoFinalizado = new CandidatoFinalizado();
            $candidatoHabitacion = new CandidatoHabitacion();
            $candidatoVecino = new CandidatoVecino();
            $verificacionLegal = new VerificacionLegal();
            $candidatoSalud = new CandidatoSalud();
            $candidatoServicio = new CandidatoServicio();
            $candidatoHistorialCrediticio = new CandidatoHistorialCrediticio();
            $candidatoGlobalSearches = new CandidatoGlobalSearches();
            $verificacionPenales = new VerificacionPenales();
            $verificacionPenalesDetalle = new VerificacionPenalesDetalle();
            $referenciaCliente = new ReferenciaCliente();
            $candidatoEmpresa = new CandidatoEmpresa();
            $candidatoRefProfesional  = new CandidatoRefProfesional();

            
            // Detalles del candidato
          
            $data['info'] = $candidato->getDetallesPDF($id_candidato);
            $data['docs'] = $candidatoDocumento->getDocumentacion($id_candidato);
            $data['secciones'] = $candidatoSeccion->getByCandidatoId($id_candidato);
            $data['pruebas'] = $candidatoPruebas->getExamenes($id_candidato);
            $data['doping'] = $doping->getDoping($id_candidato);
            $data['verDoc'] = $verificacionDocumento->getByCandidatoId($id_candidato);
            $data['academico'] = $candidatoEstudio->getByCandidatoId($id_candidato);
            $data['verMayoresEstudios'] = $verificacionMayoresEstudio->getMayoresByCandidatoId($id_candidato);
            $data['verificacionEstudios'] =$verificacionEstudios->getVerificarEstudiosByCandidatoId($id_candidato);
            $data['verificacionDetallesEstudios'] = $detalleVerificacion->getDetalleVerificacion($id_candidato);
            $data['sociales'] = $antecedentesSociales->getAntecedentesSocialesByIdCandidato($id_candidato);
            $data['familia'] = $candidatoPersona->getPersonaByIdCandidato($id_candidato);
            $data['contacto_trabajo'] = $candidatoPersonaMismoTrabajo->getContactosMismoTrabajo($id_candidato);
            $data['finanzas'] = $candidatoEgresos->getById($id_candidato);
            $data['empleos'] = $candidatoRefLaboral->getById($id_candidato);
            $data['nom'] = $verificacionNoMencionados->getById($id_candidato);
            $data['laborales'] = $candidatoAntecedenteLaboral->getById($id_candidato);
            $data['contactos'] = $contactoRefLaboral->getObservacionesContactoById($id_candidato);
            $data['verificacionEmpleos'] = $statusRefLaboral->getByIdCandidato($id_candidato);
            $data['verificacionDetallesEmpleos'] = $statusRefLaboralDetalle->getDetalleVerificacion($id_candidato);
            $data['gaps'] = $candidatoGaps->getGapsByCandidatoId($id_candidato);
            $data['refPersonal'] = $candidatoRefPersonal->getByCandidatoId($id_candidato);
            $data['finalizado'] = $candidatoFinalizado->getByCandidatoId($id_candidato);
            $data['conclusion'] = $candidato->getBGCById($id_candidato);
            $data['vivienda'] = $candidatoHabitacion->getById($id_candidato);
            $data['refVecinal'] = $candidatoVecino->getById($id_candidato);
            $data['legal'] = $verificacionLegal->getById($id_candidato);
            $data['salud'] = $candidatoSalud->getById($id_candidato);
            $data['servicios'] = $candidatoServicio->getById($id_candidato);
            $data['credito'] = $candidatoHistorialCrediticio->getById($id_candidato);
            $data['global_searches'] = $candidatoGlobalSearches->getById($id_candidato);
            $data['verificacionCriminal'] = $verificacionPenales->getByCandidatoId($id_candidato);
            $data['verificacionDetallesCriminal'] = $verificacionPenalesDetalle->getDetalleVerificacion($id_candidato);
            $data['refClientes'] = $referenciaCliente->getReferenciasCliente($id_candidato);
            $data['empresa'] = $candidatoEmpresa->getById($id_candidato);
            $data['refProfesionales'] = $candidatoRefProfesional->getById($id_candidato);



            /* 
            
            $data['refAcademicas'] = CandidatoRefAcademica::getById($id_candidato);
             */
            // Devolver el resultado como JSON
            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Error al obtener los datos', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Error al obtener los datos'], 500);
        }
    }
}
