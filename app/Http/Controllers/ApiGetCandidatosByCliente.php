<?php

namespace App\Http\Controllers;
use App\Models\Candidato;
use App\Models\ExamEmpleado;
use Carbon\Carbon;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Empleados\EmpleadoController;
use Illuminate\Http\Request;

// Añade esta línea

class ApiGetCandidatosByCliente extends Controller
{
    /**
     * Display a listing of the resource filtered by id_cliente_talent.
     *
     * @param int $id_cliente_talent
     * @return \Illuminate\Http\Response
     */
    public function getByClienteTalent($id_cliente_talent)
    {
        // Validar que id_cliente_talent sea un entero
        if (!is_numeric($id_cliente_talent)) {
            return response()->json(['error' => 'Invalid id_cliente_talent'], 400);
        }

        // Realizar la consulta combinada de Candidato y CandidatoSync
        $results = Candidato::leftJoin('candidato_sync AS CSY', 'candidato.id', '=', 'CSY.id_candidato_rodi')
            ->leftJoin('usuario AS US', 'US.id', '=', 'candidato.id_usuario')
            ->leftJoin('candidato_seccion AS CAS', 'CAS.id_candidato', '=', 'candidato.id')
            ->leftJoin('candidato_bgc AS BGC', 'BGC.id_candidato', '=', 'candidato.id')
            ->leftJoin('candidato_pruebas AS CAP', 'CAP.id_candidato', '=', 'candidato.id')
            ->leftJoin('doping AS DOP', 'DOP.id_candidato', '=', 'candidato.id')
            ->leftJoin('medico AS MED', 'MED.id_candidato', '=', 'candidato.id')
            ->leftJoin('psicometrico AS PSI', 'PSI.id_candidato', '=', 'candidato.id')

            ->where('CSY.id_cliente_talent', $id_cliente_talent)
            ->where('candidato.eliminado', 0) // Asegúrate de que 0 representa no eliminado

            ->select(
                'candidato.*',
                'candidato.id AS id',
                DB::raw("CONCAT(COALESCE(candidato.nombre, ''), ' ', COALESCE(candidato.paterno, ''), ' ', COALESCE(candidato.materno, '')) as candidato"),
                'candidato.nombre AS nombre',
                'candidato.paterno AS paterno',
                'candidato.materno AS materno',
                'candidato.celular AS celular',
                'candidato.correo AS correo',
                'candidato.fecha_contestado AS fecha_contestado',
                'candidato.fecha_alta AS fecha_alta',
                'candidato.fecha_documentos AS fecha_documentos',
                'candidato.tiempo_parcial AS tiempo_parcial',
                'candidato.cancelado AS cancelado',

                'CSY.creacion AS creacion',
                'CSY.edicion AS edicion',
                DB::raw("CONCAT(US.nombre, ' ', US.paterno, ' ', COALESCE(US.materno, '')) as usuario"),
                'CAS.tipo_conclusion',
                'CAS.proyecto AS nombre_proyecto',
                'BGC.creacion AS fecha_bgc',

                'CAP.status_doping as doping_hecho',
                'CAP.tipo_antidoping',
                'CAP.medico',
                'CAP.psicometrico',
                'CAP.socioeconomico',
                
                'MED.id AS idMedico',
                'MED.imagen_historia_clinica AS imagen',
                'MED.conclusion',
                'MED.descripcion',
                'MED.archivo_examen_medico',

                'PSI.id as idPsicometrico',
                'PSI.archivo',
           
                'DOP.id as idDoping',
                'DOP.fecha_resultado',
                'DOP.resultado as resultado_doping',
                'DOP.status as statusDoping',

            )
            ->get();
           // Log::info('Datos del candidato: ' . print_r($results->toArray(), true));

        return response()->json($results);

    }

    public function sendCandidateToEmployee($id_candidato)
    {
        // Validar que id_candidato sea un número
        if (!is_numeric($id_candidato)) {
            return response()->json(['error' => 'Invalid id_candidato'], 400);
        }
        $fechaHoy = $this->getCurrentDateTime();
        // Realizar la consulta combinada de Candidato y sus tablas relacionadas
        $candidate = Candidato::leftJoin('candidato_sync AS CSY', 'candidato.id', '=', 'CSY.id_candidato_rodi')
            ->leftJoin('usuario AS US', 'US.id', '=', 'candidato.id_usuario')
            ->leftJoin('candidato_seccion AS CAS', 'CAS.id_candidato', '=', 'candidato.id')
            ->leftJoin('candidato_bgc AS BGC', 'BGC.id_candidato', '=', 'candidato.id')
            ->leftJoin('candidato_pruebas AS CAP', 'CAP.id_candidato', '=', 'candidato.id')
            ->leftJoin('doping AS DOP', 'DOP.id_candidato', '=', 'candidato.id')
            ->leftJoin('medico AS MED', 'MED.id_candidato', '=', 'candidato.id')
            ->leftJoin('psicometrico AS PSI', 'PSI.id_candidato', '=', 'candidato.id')
            ->where('CSY.id_candidato_rodi', $id_candidato)
            ->where('candidato.eliminado', 0) 
            ->select(
                'candidato.id AS id',
                'candidato.id_usuario',
                'candidato.nombre AS nombre',
                'candidato.calle',
                'candidato.exterior AS num_ext',
                'candidato.interior AS num_int',
                'candidato.cp',
                'candidato.colonia',
                'candidato.pais',
                'candidato.paterno AS paterno',
                'candidato.materno AS materno',
                'candidato.celular AS telefono',
                'candidato.correo AS correo',
                'candidato.fecha_nacimiento',
                'candidato.rfc',
                'candidato.nss',
                'candidato.curp',
                'candidato.fecha_contestado AS fecha_contestado',
                'candidato.fecha_alta AS fecha_alta',
                'candidato.fecha_documentos AS fecha_documentos',
                'candidato.tiempo_parcial AS tiempo_parcial',
                'candidato.cancelado AS cancelado',
                'CSY.creacion AS creacion',
                'CSY.edicion AS edicion',
                'CSY.id_portal',
                'CSY.id_cliente_talent AS id_cliente_talent',
                'CAS.tipo_conclusion',
                'CAS.proyecto AS nombre_proyecto',
                'BGC.creacion AS fecha_bgc',
                'CAP.status_doping as doping_hecho',
                'CAP.tipo_antidoping',
                'CAP.medico',
                'CAP.psicometrico',
                'CAP.socioeconomico',
                'MED.id AS idMedico',
                'MED.imagen_historia_clinica AS imagen',
                'MED.conclusion',
                'MED.descripcion',
                'MED.archivo_examen_medico',
                'PSI.id as idPsicometrico',
                'PSI.archivo',
                'DOP.id as idDoping',
                'DOP.fecha_resultado',
                'DOP.resultado as resultado_doping',
                'DOP.status as statusDoping',
            )
            ->first();  // Usamos 'first' porque es un solo candidato
    
        if (!$candidate) {
            return response()->json(['error' => 'Candidato no encontrado'], 404);
        }
         $resultString = ""; // Variable para almacenar los resultados

        // Evaluar cada opción y concatenar el string correspondiente
        if ($candidate->socioeconomico == 1) {
          $resultString .= "Bgv \n "; // Concatenar el valor del proyecto
        }
  
        if ($candidate->tipo_antidoping > 0) {
          $resultString .= "Drug Test\n "; // Concatenar el valor del paquete
        }
  
        if ($candidate->psicometrico == 1) {
          $resultString .= "Psicométric \n" ; // Concatenar el valor psicométrico
        }
  
        if ($candidate->medico == 1) {
          $resultString .= "Medical Test "; // Concatenar el valor del examen médico
        }
        // Iniciar una transacción para asegurarse de que todas las operaciones se realicen correctamente
        DB::beginTransaction();
        
    
        try {
            // Actualizar o insertar el candidato en la tabla de Empleados
            $validatedData = [
                'creacion' => $fechaHoy,
                'edicion' => $fechaHoy,
                'id_portal' => $candidate->id_portal, 
                'id_usuario' => $candidate->id_usuario,
                'id_cliente' => $candidate->id_cliente_talent,
                'id_empleado' => $candidate->id,
                'correo' => $candidate->correo,
                'fecha_nacimiento' => $candidate->fecha_naciemiento,
                'curp' => $candidate->curp, 
                'rfc' => $candidate->rfc,
                'nss' => $candidate->nss,
                'nombre' => $candidate->nombre,
                'paterno' => $candidate->paterno,
                'materno' => $candidate->materno,
                'puesto' => null, 
                'telefono' => $candidate->telefono,
                'domicilio_empleado' => [
                    'calle' => $candidate->calle,
                    'num_ext' => $candidate->num_ext,
                    'num_int' => $candidate->num_int,
                    'colonia' => $candidate->colonia,
                    'ciudad' => null, 
                    'estado' => null, 
                    'pais' => $candidate->pais,
                    'cp' => $candidate->cp,
                ],
            ];
            $empleadoController = new EmpleadoController();
            // Llamar a la función store con los datos mapeados
            $response = $empleadoController->store(new Request($validatedData));

            if ($resultString != "") {
                // Verifica si la respuesta contiene la propiedad 'data' antes de acceder a ella
                $empleadoData = $response->getData()->data ?? null;
            
                // Asegúrate de que el objeto empleado existe
                if ($empleadoData) {
                    $empleadoId = $empleadoData->id;
            
                    $examEmpleado = new ExamEmpleado([
                        'creacion' => $fechaHoy,
                        'edicion' => $fechaHoy,
                        'employee_id' => $empleadoId,
                        'name' => $resultString,
                        'id_opcion' => null,
                        'descripcion' => null,
                        'expiry_date' => null,
                        'expiry_reminder' => null,
                        'id_candidato' => $candidate->id ?? null,
                    ]);
            
                    Log::info('Insertando en Empleado:', ['examEmpleado' => $examEmpleado->toArray()]);
            
                    $examEmpleado->save();
                } else {
                    // Si el objeto empleado no está disponible, puedes manejar el error aquí
                    Log::error('No se pudo encontrar el empleado en la respuesta', ['response' => $response]);
                }
            }
            $candidate->eliminado = 1;
            $candidate->save();
            // Si todo es correcto, confirmamos la transacción
            DB::commit();
    
            // Devolvemos la respuesta con los datos del candidato actualizado
            return response()->json(['success' => 'Candidato procesado correctamente', 'candidato' => $candidate]);
    
        } catch (\Exception $e) {
            // Si ocurre un error, revertimos la transacción
            DB::rollBack();
            return response()->json(['error' => 'Ocurrió un error al procesar el candidato', 'details' => $e->getMessage()], 500);

        }
    }

     public function getCurrentDateTime()
    {
        // Obtiene la fecha y hora actuales en la zona horaria de México
        $currentDateTime = Carbon::now('America/Mexico_City');

        // Formatea la fecha y hora
        return $currentDateTime->format('Y-m-d H:i:s');
    }


}
