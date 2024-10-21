<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiCandidatoSinEseController;
use App\Http\Controllers\ApiClientesController;
use App\Http\Controllers\ApiCandidatoConProyectoPrevioController;
use App\Http\Controllers\ApiGetCandidatosByCliente;
use App\Http\Controllers\ApiGetDopingDetalles;
use App\Http\Controllers\ApiGetArea;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\ApiGetMedicoDetalles;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\Empleados\ApiEmpleadoController;
use App\Http\Controllers\Empleados\DocumentOptionController;
use App\Http\Controllers\Empleados\EmpleadoController;
use App\Http\Controllers\Empleados\MedicalInfoController;
use App\Http\Controllers\Empleados\EviarEmpleadoRodi;

use App\Http\Controllers\ProyectosHistorialController;



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
Route::middleware(['api'])->group(function () {
/* obtiene   los empleados  dl portal y calcula  si tiene algo vencido*/
Route::get('/empleados/documentos', [EmpleadoController::class, 'getEmpleadosConDocumentos']);


    Route::get('/empleados/check-email', [EmpleadoController::class, 'checkEmail']);

    Route::post('/empleados/register', [EmpleadoController::class, 'store']);
    Route::put('/empleados/update', [EmpleadoController::class, 'update']);
    Route::get('/medical-info/{id_empleado}', [MedicalInfoController::class, 'show']);
    Route::put('/medical-info/{id_empleado}', [MedicalInfoController::class, 'update']);
    Route::post('/documents', [DocumentOptionController::class, 'store']);
    Route::post('/exams', [DocumentOptionController::class, 'storeExams']);
    Route::get('/documents/{id}', [DocumentOptionController::class, 'getDocumentsByEmployeeId']);
    Route::get('/exam/{id}', [DocumentOptionController::class, 'getExamsByEmployeeId']);
     // Ruta para actualizar la expiración del documento
     Route::put('/documents/{id}', [DocumentOptionController::class, 'updateExpiration']);
     Route::get('/empleados/{id_empleado}/documentos', [EmpleadoController::class, 'getDocumentos']);
     //eliminar Documentos  del empleado 
     Route::delete('/documents', [DocumentOptionController::class, 'deleteDocument']);

     //  traer  los  paquetes    antidoping 
     Route::get('/antidoping-packages', [ApiEmpleadoController::class, 'getAntidopinPaquetes']);
    // Traer los proyectos  disponibles  o los del cliente 
    Route::get('/proyectos-historial', [ProyectosHistorialController::class, 'getproyectosPorCliente']);

    Route::post('/registrar-candidato', [EviarEmpleadoRodi::class, 'registrarCandidato']);

 // Asegúrate de tener un método para obtener datos.

    
});




Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});