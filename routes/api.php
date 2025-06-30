<?php

use App\Http\Controllers\ApiCandidatoConProyectoPrevioController;
use App\Http\Controllers\ApiCandidatoSinEseController;
use App\Http\Controllers\ApiClientesController;
use App\Http\Controllers\ApiGetArea;
use App\Http\Controllers\ApiGetCandidatosByCliente;
use App\Http\Controllers\ApiGetDopingDetalles;
use App\Http\Controllers\ApiGetMedicoDetalles;
//use App\Http\Controllers\AvanceController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Empleados\ApiEmpleadoController;
use App\Http\Controllers\Empleados\CsvController;
use App\Http\Controllers\Empleados\CursosController;
use App\Http\Controllers\Empleados\DocumentOptionController;
use App\Http\Controllers\Empleados\EmpleadoController;
use App\Http\Controllers\Empleados\EvaluacionController;
use App\Http\Controllers\Empleados\EviarEmpleadoRodi;
use App\Http\Controllers\Empleados\LaboralesController;
use App\Http\Controllers\Empleados\MedicalInfoController;
use App\Http\Controllers\Empleados\NotificacionController;
use App\Http\Controllers\ExEmpleados\FormerEmpleadoController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\PeriodoNominaController;
use App\Http\Controllers\PreEmpleado\PreEmpleadoController;
use App\Http\Controllers\ProyectosHistorialController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
 */
//  rutas  para  envio de  mensajes  de  whatsssApp

Route::middleware(['api'])->group(function () {

    Route::post('/send-message', [WhatsAppController::class, 'sendMessage']);
    Route::post('/send-message-movimiento', [WhatsAppController::class, 'sendMessage_movimiento_aspirante']);
    Route::post('/send-message-comentario-reclu', [WhatsAppController::class, 'sendMessage_comentario_reclu']);
    Route::post('/send-message-comentario-cliente', [WhatsAppController::class, 'sendMessage_comentario_cliente']);
    Route::post('/send-message-requisicion-cliente', [WhatsAppController::class, 'sendMessage_requisicion_cliente']);

// ruta  de  examen  medico
    Route::get('/medico/{id}', [ApiGetMedicoDetalles::class, 'getDatosMedico']);
    Route::get('/test', [TestController::class, 'testPost']);

    Route::get('file/{path}', [ImageController::class, 'getFile'])->where('path', '.*');
    Route::post('/upload', [DocumentController::class, 'upload']);

//  rutas    para  candidatos  socioeconomicos  y doping
    Route::post('/candidatoconprevio', [ApiCandidatoConProyectoPrevioController::class, 'store']);
    Route::post('/candidatos', [ApiCandidatoSinEseController::class, 'store']);
    Route::post('/existe-cliente', [ApiClientesController::class, 'VerificarCliente']);
    Route::get('candidato-sync/{id_cliente_talent}', [ApiGetCandidatosByCliente::class, 'getByClienteTalent']);

    Route::get('doping/{id}', [ApiGetDopingDetalles::class, 'getDatosDoping']);
    Route::get('doping-detalles/{id}', [ApiGetDopingDetalles::class, 'getDopingDetalles']);

// ruta  para  cargar
    Route::get('area/{nombre}', [ApiGetArea::class, 'getArea']);

// EndPoints bgv  reportes
    Route::get('/report/{id_candidato}', [ReportController::class, 'getReport']);

// Emdpoints Empleados
    Route::get('empleados', [ApiEmpleadoController::class, 'index']);
    Route::post('empleados/{id}/foto', [ApiEmpleadoController::class, 'updateProfilePicture']);
    Route::get('/document-options', [DocumentOptionController::class, 'index']);
                                                                                                 //descargar plantilla Empleados Masivos
    Route::get('/download-template', [CsvController::class, 'downloadTemplate']);                // plantilla para  carga   desde 0
    Route::get('/download-template-medical', [CsvController::class, 'downloadTemplateMedical']); // plantilla  para   carga  y actualizacion de medical info
    Route::post('/upload-medical-info', [CsvController::class, 'uploadMedicalInfo']);            // cargar plantilla medical info
    Route::get('/download-template-general', [CsvController::class, 'downloadTemplateGeneral']); // plantilla  para   carga  y actualizacion de general info
    Route::post('/upload-general-info', [CsvController::class, 'importGeneralInfo']);            // cargar plantilla general info  uploadLaboralesInfo

    Route::get('/download-template-laborales', [CsvController::class, 'downloadTemplateLaboral']); // cargar plantilla laborales
    Route::post('/upload-laborales-info', [CsvController::class, 'uploadLaboralesInfo']);

    // Ruta para la importación de empleados desde un archivo CSV o Excel
    Route::post('/empleados/importar', [CsvController::class, 'import']);
    // ruta para eliminar EMpleados
    Route::delete('/delempleados/{id}', [EmpleadoController::class, 'deleteEmpleado']);

    //***************  Ruta para  los  laborales del empleado ************************/

    Route::get('/empleado/{id_empleado}/laborales', [LaboralesController::class, 'obtenerDatosLaborales']);
    Route::post('/empleados/laborales', [LaboralesController::class, 'guardarDatosLaborales']);
    Route::put('/empleados/laborales/{id_empleado}', [LaboralesController::class, 'actualizarDatosLaborales']);

    //peridodos_nomina
    Route::get('/periodos-nomina', [PeriodoNominaController::class, 'index']);
    Route::get('/periodos-nomina-con-datos', [PeriodoNominaController::class, 'periodosConPrenomina']);
    Route::post('/periodos-nomina', [PeriodoNominaController::class, 'store']);
    Route::put('/periodos-nomina/{id}', [PeriodoNominaController::class, 'update']);

    //Pre Nomina Empleados //
    Route::post('/empleados/registro_prenomina', [LaboralesController::class, 'guardarPrenomina']);
    Route::get('/empleados/obtener_prenomina_masiva_ultima', [LaboralesController::class, 'empleadosMasivoPrenomina']);
    Route::post('/empleados/registro_prenomina_masiva', [LaboralesController::class, 'guardarPrenominaMasiva']);

    //***************  Fin para  los  laborales del empleado ********************/

    //  obtener  el status  de general  de los empleados
    Route::get('/empleados/status', [EmpleadoController::class, 'getEmpleadosStatus']);
    /* obtiene   los empleados  dl portal y calcula  si tiene algo vencido*/
    Route::get('/empleados/documentos', [EmpleadoController::class, 'getEmpleadosConDocumentos']);
    /* obtiene   los empleados  dl portal y calcula  si tiene algo vencido*/
    Route::get('/empleados/check-email', [EmpleadoController::class, 'checkEmail']);

    //Ruta  para    eliminar campo extra  de los  empleados
    Route::delete('/empleados/campo-extra/{id}', [EmpleadoController::class, 'eliminarCampoExtra']);
    //Ruta  para  registrar  un empleado   desde el formulario
    Route::post('/empleados/register', [EmpleadoController::class, 'store']);
    //Ruta  para  Actualizar   un empleado   desde el formulario
    Route::put('/empleados/update', [EmpleadoController::class, 'update']);

    Route::get('/medical-info/{id_empleado}', [MedicalInfoController::class, 'show']);
    Route::put('/medical-info/{id_empleado}', [MedicalInfoController::class, 'update']);
    Route::post('/documents', [DocumentOptionController::class, 'store']);
    Route::post('/exams', [DocumentOptionController::class, 'storeExams']);
    Route::get('/documents/{id}', [DocumentOptionController::class, 'getDocumentsByEmployeeId']);
    Route::get('/exam/{id}', [DocumentOptionController::class, 'getExamsByEmployeeId']);
    // Ruta para actualizar la expiración del documento, cursos y examanes
    Route::put('documents/{id}', [DocumentOptionController::class, 'updateDocuments']);
    Route::get('/empleados/{id_empleado}/documentos', [EmpleadoController::class, 'getDocumentos']);
    //eliminar Documentos  del empleado
    Route::delete('/documents', [DocumentOptionController::class, 'deleteDocument']);

    //  traer  los  paquetes    antidoping
    Route::get('/antidoping-packages', [ApiEmpleadoController::class, 'getAntidopinPaquetes']);
    // Traer los proyectos  disponibles  o los del cliente
    Route::get('/proyectos-historial', [ProyectosHistorialController::class, 'getproyectosPorCliente']);

    Route::post('/registrar-candidato', [EviarEmpleadoRodi::class, 'registrarCandidato']);

    // para  guardar cursos
    Route::post('/cursos/registrar', [CursosController::class, 'store']);
    Route::get('/cursos/empleado', [CursosController::class, 'obtenerCursosPorEmpleado']);
    Route::get('/clientes/{clienteId}/cursos', [CursosController::class, 'getCursosPorCliente']);
    Route::get('/clientes/{id}/exportar-cursos', [CursosController::class, 'exportCursosPorCliente']);

    // validar  si hay cursos   vencidos
    Route::get('/empleados/cursos', [CursosController::class, 'getEmpleadosConCursos']);

/*  rutas  para  subir  las  evaluaciones   */
    Route::post('/evaluaciones', [EvaluacionController::class, 'store']);
    Route::get('/evaluaciones', [EvaluacionController::class, 'getEvaluations']);
    Route::put('/evaluaciones/{id}', [EvaluacionController::class, 'update']);

/*Descomprimir  archivos  */
    Route::post('/unzip', [DocumentController::class, 'unzipFile']);
    Route::post('/delete', [DocumentController::class, 'deleteFile']);
    Route::post('/download-zip', [DocumentController::class, 'downloadZip']);
    Route::post('/upload-zip', [DocumentController::class, 'uploadZip']);

/** Former Employe   endpoints */
// enviar   empleado  a exempleados
    Route::post('/comentarios-former-empleado', [FormerEmpleadoController::class, 'storeComentarioFormer']);
    Route::get('empleados/{id_empleado}/documentos-y-cursos', [FormerEmpleadoController::class, 'getDocumentosYCursos']);
    Route::post('/documentos/former', [FormerEmpleadoController::class, 'storeDocumentos']);
    Route::get('/conclusions/{id_empleado}', [FormerEmpleadoController::class, 'getConclusionsByEmployeeId']);
    // borrar comentario
    Route::delete('/comentarios-former-empleado/{id}', [FormerEmpleadoController::class, 'deleteComentario']);

// ruta  para   enviar     de pre employment  a employment
    Route::post('candidato-send/{id_candidato}', [ApiGetCandidatosByCliente::class, 'sendCandidateToEmployee']);

// ruta  para  guardar  y consultar  notificaciones Whats  y correo
    Route::post('/notificaciones/guardar', [NotificacionController::class, 'guardar']);
    Route::post('/notificaciones/guardarex', [NotificacionController::class, 'guardarExempleados']);

    Route::get('/notificaciones/consultar/{id_portal}/{id_cliente}/{status}', [NotificacionController::class, 'consultar']);

    Route::get('/notificaciones/consultarex/{id_portal}/{id_cliente}/{status}', [NotificacionController::class, 'consultarExempleo']);
});

/*notificaciones  via  whatsapp modulo empleados*/

Route::post('/send-notification', [WhatsAppController::class, 'sendMessage_notificacion_talentsafe']);

/*Este  endpoint  es para   mostrar  avances  de los  candidatos  en pre empleo  */
//Route::get('/check-avances', [AvanceController::class, 'checkAvances']);
Route::post('/preempleados/proceso-candidato', [PreEmpleadoController::class, 'verProcesoCandidato'])->name('preempleados.procesoCandidato');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
