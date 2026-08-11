<?php
use App\Http\Controllers\ApiCandidatoConProyectoPrevioController;
use App\Http\Controllers\ApiCandidatoSinEseController;
use App\Http\Controllers\ApiClientesController;
use App\Http\Controllers\ApiGetArea;
use App\Http\Controllers\ApiGetCandidatosByCliente;
use App\Http\Controllers\ApiGetDopingDetalles;
use App\Http\Controllers\ApiGetMedicoDetalles;
use App\Http\Controllers\Api\Admin\AdminAuthBridgeController;
use App\Http\Controllers\Api\Comunicacion360\AccesosChecadorController;
use App\Http\Controllers\Api\Comunicacion360\AccesosChecadorGestionController;
use App\Http\Controllers\Api\Comunicacion360\AccesosChecadorReportesController;
use App\Http\Controllers\Api\Comunicacion360\AccesosController;
use App\Http\Controllers\Api\Comunicacion360\AccesosIpController;
use App\Http\Controllers\Api\Comunicacion360\AccesosTareasController;
use App\Http\Controllers\Api\Comunicacion360\ChecadorEventosController;
use App\Http\Controllers\Api\Comunicacion360\Checador\ChecadaDispositivoController;
use App\Http\Controllers\Api\Comunicacion360\Checador\ChecadorAsignacionController;
use App\Http\Controllers\Api\Comunicacion360\Checador\ChecadorChecadaPlantillaController;
use App\Http\Controllers\Api\Comunicacion360\Checador\ChecadorChecadasMasivasController;
use App\Http\Controllers\Api\Comunicacion360\Checador\ChecadorHorarioPlantillaController;
use App\Http\Controllers\Api\Comunicacion360\Checador\ChecadorImportExportController;
use App\Http\Controllers\Api\Comunicacion360\Checador\ChecadorIncidenciasMasivasController;
use App\Http\Controllers\Api\Comunicacion360\Checador\ChecadorMetodoController;
use App\Http\Controllers\Api\Comunicacion360\Checador\ChecadorQrController;
use App\Http\Controllers\Api\Comunicacion360\Checador\ChecadorUbicacionesController;
use App\Http\Controllers\Api\Comunicacion360\Checador\ChecadorValidacionController;
use App\Http\Controllers\Api\Comunicacion360\Checador\HikvisionEventController;
use App\Http\Controllers\Api\Comunicacion360\EmployeeProfileAnalysisController;
use App\Http\Controllers\Api\Comunicacion360\Incidencias\IncidenciasCalendarioController;
use App\Http\Controllers\Api\Comunicacion360\PlantillasController;
use App\Http\Controllers\Api\Empleado\AuthController;
use App\Http\Controllers\Api\Empleado\EmpleadoApproversController;
use App\Http\Controllers\Api\Empleado\EmpleadoAprobacionesController;
use App\Http\Controllers\Api\Empleado\EmpleadoChecadorController;
use App\Http\Controllers\Api\Empleado\EmpleadoDashboardController;
use App\Http\Controllers\Api\Empleado\EmpleadoEventoConfirmacionesController;
use App\Http\Controllers\Api\Empleado\EmpleadoHorasExtraController;
use App\Http\Controllers\Api\Empleado\EmpleadoIncidenciasController;
use App\Http\Controllers\Api\Empleado\EmpleadoSucursalController;
use App\Http\Controllers\Api\Empleado\EmpleadoTareasController;
use App\Http\Controllers\Api\Empleado\ProfileController;
use App\Http\Controllers\Api\Rodi\ReporteBecasController;
use App\Http\Controllers\Auth\PermissionController;
use App\Http\Controllers\Comunicacion\CalendarioController;
use App\Http\Controllers\Comunicacion\ChecadasController;
use App\Http\Controllers\Comunicacion\ChecadorController;
use App\Http\Controllers\Comunicacion\PoliticasAsistenciaController;
use App\Http\Controllers\Comunicacion\RecordatorioController;
use App\Http\Controllers\ConfiguracionColumnasController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dashboard\OrganigramaController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Empleados\ApiEmpleadoController;
use App\Http\Controllers\Empleados\CatalogosController;
use App\Http\Controllers\Empleados\ClienteInformacionInternaController;
use App\Http\Controllers\Empleados\CsvController;
use App\Http\Controllers\Empleados\CursosController;
use App\Http\Controllers\Empleados\DocumentoInternoController;
use App\Http\Controllers\Empleados\DocumentOptionController;
use App\Http\Controllers\Empleados\EmpleadoController;
use App\Http\Controllers\Empleados\EvaluacionController;
use App\Http\Controllers\Empleados\EviarEmpleadoRodi;
use App\Http\Controllers\Empleados\IncidenciasController;
use App\Http\Controllers\Empleados\LaboralesController;
use App\Http\Controllers\Empleados\MedicalInfoController;
use App\Http\Controllers\Empleados\MensajeriaController;
use App\Http\Controllers\Empleados\NotificacionController;
use App\Http\Controllers\EmployeePhotoController;
use App\Http\Controllers\ExEmpleados\FormerEmpleadoController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\PeriodoNominaController;
use App\Http\Controllers\Plantillas\PlantillaController;
use App\Http\Controllers\PreEmpleado\PreEmpleadoController;
use App\Http\Controllers\ProyectosHistorialController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Sat\SatCatalogosController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\WhatsAppController;
use App\Modules\AuthCore\Controllers\AdminRecoveryController;
use App\Modules\AuthCore\Controllers\EmpleadoRecoveryController;
use Illuminate\Http\Request;

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
Route::prefix('rodi')->group(function () {
    Route::get('/reportes/becas/{id_candidato}', [ReporteBecasController::class, 'show']);
});

Route::prefix('empleado/auth')->group(function () {
    Route::post('/recovery/send', [EmpleadoRecoveryController::class, 'sendOtp']);
    Route::post('/recovery/verify', [EmpleadoRecoveryController::class, 'verifyOtp']);
    Route::post('/recovery/reset', [EmpleadoRecoveryController::class, 'resetPassword']);
    Route::post('recovery/check-user', [EmpleadoRecoveryController::class, 'checkUser']);
    Route::post('/recovery/verify-phone', [EmpleadoRecoveryController::class, 'verifyPhone']);
});

Route::prefix('admin/auth')->group(function () {
    Route::post(
        '/exchange',
        [AdminAuthBridgeController::class, 'exchange']
    )->middleware('throttle:20,1');

    Route::post(
        '/logout',
        [AdminAuthBridgeController::class, 'logout']
    )->middleware('auth:sanctum');
    Route::post('/recovery/check-user', [AdminRecoveryController::class, 'checkUser']);
    Route::post('/recovery/send', [AdminRecoveryController::class, 'sendOtp']);
    Route::post('/recovery/verify-phone', [AdminRecoveryController::class, 'verifyPhone']);
    Route::post('/recovery/verify', [AdminRecoveryController::class, 'verifyOtp']);
    Route::post('/recovery/reset', [AdminRecoveryController::class, 'resetPassword']);
});

Route::middleware(['api'])->group(function () {
    Route::get(
        '/usuarios/{idUsuario}/sucursales-permitidas',
        [EmpleadoSucursalController::class, 'sucursalesPermitidas']
    );
    Route::post(
        '/empleados/{idEmpleado}/cambiar-sucursal',
        [EmpleadoSucursalController::class, 'cambiarSucursal']
    );
    //// */ rutas  para  el organigrama

    Route::prefix('organigrama')->group(function () {

        Route::get('/root', [OrganigramaController::class, 'getRoot']);
        Route::get('/children', [OrganigramaController::class, 'getChildren']);
        Route::post('/bulk-children', [OrganigramaController::class, 'storeBulkChildren']);

        Route::get('/', [OrganigramaController::class, 'index']);

        Route::post('/', [OrganigramaController::class, 'store']);
        Route::put('/{id}', [OrganigramaController::class, 'update']);
        Route::delete('/{id}', [OrganigramaController::class, 'destroy']);
        Route::put('/{id}/remove-employee', [OrganigramaController::class, 'removeEmployee']);
        Route::get('/primer-cliente-con-datos', [OrganigramaController::class, 'primerClienteConDatos']);
        Route::get('/empleados-disponibles',
            [OrganigramaController::class, 'empleadosDisponibles']
        );
        Route::get('/options', [OrganigramaController::class, 'options']);

    });

    if (app()->environment('local')) {
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    } else {
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    }
    Route::get('/dashboard/kpi-detail', [DashboardController::class, 'kpiDetail']);
    Route::post('/send-message', [WhatsAppController::class, 'sendMessage']);
    Route::post('/send-message-movimiento', [WhatsAppController::class, 'sendMessage_movimiento_aspirante']);
    Route::post('/send-message-comentario-reclu', [WhatsAppController::class, 'sendMessage_comentario_reclu']);
    Route::post('/send-message-comentario-cliente', [WhatsAppController::class, 'sendMessage_comentario_cliente']);
    Route::post('/send-message-requisicion-cliente', [WhatsAppController::class, 'sendMessage_requisicion_cliente']);

    //// */ ruta  de  examen  medico
    Route::get('/medico/{id}', [ApiGetMedicoDetalles::class, 'getDatosMedico']);
    Route::get('/test', [TestController::class, 'testPost']);

    Route::get('file/{path}', [ImageController::class, 'getFile'])->where('path', '.*');
    Route::post('/upload', [DocumentController::class, 'upload']);

    /// */  rutas    para  candidatos  socioeconomicos  y doping
    Route::post('/candidatos', [ApiCandidatoSinEseController::class, 'store']);
    Route::post('/existe-cliente', [ApiClientesController::class, 'VerificarCliente']);
    Route::get('candidato-sync/{id_cliente_talent}', [ApiGetCandidatosByCliente::class, 'getByClienteTalent']);
    Route::post('/candidatoconprevio', [ApiCandidatoConProyectoPrevioController::class, 'store']);
    Route::get('doping/{id}', [ApiGetDopingDetalles::class, 'getDatosDoping']);
    Route::get('doping-detalles/{id}', [ApiGetDopingDetalles::class, 'getDopingDetalles']);

    // ruta  para  cargar
    Route::get('area/{nombre}', [ApiGetArea::class, 'getArea']);

    // EndPoints bgv  reportes
    Route::get('/report/{id_candidato}', [ReportController::class, 'getReport']);

    // Emdpoints Empleados
    Route::get('empleados', [ApiEmpleadoController::class, 'index']);
    Route::post('empleados/{id}/foto', [ApiEmpleadoController::class, 'updateProfilePicture']);
    Route::get('/empleados/{id}/foto', [ApiEmpleadoController::class, 'getProfilePicture'])
        ->withoutMiddleware('throttle:api');

    Route::get('/documentos/{carpeta}/{archivo}', [ApiEmpleadoController::class, 'verDocumento']);

    // ----- opciones  documentos, examenes y cursos ----- //
    // ----- opciones documentos, examenes y cursos ----- //
    Route::middleware([
        'auth:sanctum',
        'admin.session',
    ])->group(function () {
        Route::get(
            '/document-options',
            [DocumentOptionController::class, 'index']
        )->middleware(
            'admin.permission:empleados.expediente.documentos.ver,empleados.expediente.documentos.subir,empleados.expediente.documentos.editar,empleados.cursos.ver,empleados.cursos.agregar_interno,empleados.cursos.editar,empleados.expediente.bgv_examenes.ver,empleados.expediente.bgv_examenes.subir,empleados.expediente.bgv_examenes.editar'
        );

        Route::post(
            '/document-options/save',
            [DocumentOptionController::class, 'guardarOpcion']
        )->middleware(
            'admin.permission:empleados.expediente.documentos.editar,empleados.cursos.editar,empleados.expediente.bgv_examenes.editar'
        );

        Route::delete(
            '/document-options/delete',
            [DocumentOptionController::class, 'eliminarOpcion']
        )->middleware(
            'admin.permission:empleados.expediente.documentos.editar,empleados.cursos.editar,empleados.expediente.bgv_examenes.editar'
        );
    });
// ----- opciones documentos, examenes y cursos ----- //

    // ----- opciones  documentos, examenes y cursos ---- //

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
    Route::post('/periodos-nomina-get', [PeriodoNominaController::class, 'index']);
    Route::post('/periodos-nomina-con-datos', [PeriodoNominaController::class, 'periodosConPrenomina']);
    Route::post('/periodos-nomina', [PeriodoNominaController::class, 'store']);
    Route::put('/periodos-nomina/{id}', [PeriodoNominaController::class, 'update']);
    Route::get('/periodos-nomina-pre-nomina-registro', [PeriodoNominaController::class, 'obtenerPeriodosPendientes']);

    //Pre Nomina Empleados //
    Route::post('/empleados/registro_prenomina', [LaboralesController::class, 'guardarPrenomina']);
    Route::get('/empleados/obtener_prenomina_masiva_ultima', [LaboralesController::class, 'empleadosMasivoPrenomina']);
    Route::get('/empleados/periodicidades-disponibles', [LaboralesController::class, 'obtenerPeriodicidadesDisponibles']);
    Route::post('/empleados/registro_prenomina_masiva', [LaboralesController::class, 'guardarPrenominaMasiva']);
    // Incidencias pre nomina
    Route::post('/incidencias/preview', [IncidenciasController::class, 'preview']);

    //***************  Fin para  los  laborales del empleado ********************/

    //***************  Inicio para  los  catalogos del SAT ********************/

    Route::prefix('sat')->group(function () {
        Route::get('/catalogos', [SatCatalogosController::class, 'all']); // todos en una sola llamada
        Route::get('/contratos', [SatCatalogosController::class, 'contratos']);
        Route::get('/regimenes', [SatCatalogosController::class, 'regimenes']);
        Route::get('/jornadas', [SatCatalogosController::class, 'jornadas']);
        Route::get('/periodicidades', [SatCatalogosController::class, 'periodicidades']);

        Route::get('percepciones', [SatCatalogosController::class, 'percepciones']);
        Route::get('deducciones', [SatCatalogosController::class, 'deducciones']);
        Route::get('incapacidades', [SatCatalogosController::class, 'incapacidades']);
    });

    //***************  Fin para  los  catalogos del SAT ************************/

    //***************  Inicio para  los  catalogos del Puestos y Departamentos ************************/

    Route::get('catalogos/departamentos', [CatalogosController::class, 'departamentos']);
    Route::get('catalogos/puestos', [CatalogosController::class, 'puestos']);
    Route::post('catalogos/departamentos', [CatalogosController::class, 'crearDepartamento']); // opcional
    Route::post('catalogos/puestos', [CatalogosController::class, 'crearPuesto']);
    //***************  Fin para  los  catalogos del Puestos y Departamentos ************************/

    //***************  Rutas de Mensajería interna ************************/

    Route::middleware([
        'auth:sanctum',
        'admin.session',
        'admin.permission:module.comunicacion.ver',
    ])->group(function () {
        Route::get(
            '/plantillas',
            [PlantillaController::class, 'listar']
        )->middleware(
            'admin.permission:comunicacion.mensajeria.crear_plantilla,comunicacion.mensajeria.actualizar_plantilla'
        );

        Route::post(
            '/plantillas/vista-previa',
            [PlantillaController::class, 'vistaPrevia']
        )->middleware(
            'admin.permission:comunicacion.mensajeria.crear_plantilla,comunicacion.mensajeria.actualizar_plantilla'
        );

        Route::get(
            '/mensajeria/empleados',
            [MensajeriaController::class, 'obtenerEmpleados']
        )->middleware(
            'admin.permission:comunicacion.mensajeria.ver'
        );

        Route::post(
            '/mensajeria/enviar-correos',
            [MensajeriaController::class, 'enviarCorreos']
        )->middleware(
            'admin.permission:comunicacion.mensajeria.enviar_masivo'
        );

        Route::get(
            '/mensajeria/plantillas',
            [PlantillaController::class, 'index']
        )->middleware(
            'admin.permission:comunicacion.mensajeria.ver'
        );

        /*
         * El middleware permite entrar con crear o actualizar.
         * PlantillaController debe validar el permiso exacto según exista
         * o no el campo id. Lo implementaremos en el siguiente paso.
         */
        Route::post(
            '/mensajeria/plantillas',
            [PlantillaController::class, 'store']
        )->middleware(
            'admin.permission:comunicacion.mensajeria.crear_plantilla,comunicacion.mensajeria.actualizar_plantilla'
        );

        Route::get(
            '/mensajeria/configuracion/columnas',
            [ConfiguracionColumnasController::class, 'obtenerMensajeria']
        )->middleware(
            'admin.permission:comunicacion.mensajeria.ver'
        );

        Route::post(
            '/mensajeria/configuracion/columnas',
            [ConfiguracionColumnasController::class, 'guardarMensajeria']
        )->middleware(
            'admin.permission:comunicacion.mensajeria.configurar_columnas'
        );
    });

    /*
     * Se mantienen temporalmente fuera del grupo hasta revisar cómo se
     * consumen como recursos:
     */
    Route::get(
        '/descargar-adjunto/{id}',
        [PlantillaController::class, 'descargarAdjunto']
    );

    Route::get(
        'plantillas/{plantilla}/logo',
        [PlantillaController::class, 'mostrarLogo']
    );

    //***************  Fin de Mensajería interna ************************/

    //***************  Ruta para  los  Configuracion Columnas  del empleado ****************/

    Route::get('/configuracion/columnas', [ConfiguracionColumnasController::class, 'obtener']);
    Route::post('/configuracion/columnas', [ConfiguracionColumnasController::class, 'guardar']);

    //***************  Ruta para  los  Configuracion Columnas del empleado ******************/

    //*************** Ruta para Calendario  ************************/

    Route::get('/colaboradores-por-sucursal', [CalendarioController::class, 'colaboradoresPorSucursal']);
    Route::post('/setEventos', [CalendarioController::class, 'setEventos']);
    Route::put('/eventos/{id}', [CalendarioController::class, 'actualizarEvento']);
    Route::delete('/eventos/{id}', [CalendarioController::class, 'eliminarEvento']);

    Route::get('/eventos/tipos', [CalendarioController::class, 'getTiposEvento']);
    Route::get('/eventos', [CalendarioController::class, 'getEventosPorClientes']);
    Route::get('/eventos/ultimo-mes', [CalendarioController::class, 'getUltimoMesConEventos']);
    Route::get('/archivos/calendario/{id}/stream', [CalendarioController::class, 'streamArchivoCalendario']);
    Route::get('/archivos/{id}/download', [CalendarioController::class, 'downloadArchivoCalendario']);

    //***************  FIN Calendario  ****************/

    //***************  Inicio Politicas Asitencia  ****************/
    Route::get('/politicas-asistencia', [PoliticasAsistenciaController::class, 'index']);
    Route::get('/politicas-asistencia/{id}', [PoliticasAsistenciaController::class, 'show']);
    Route::post('/politicas-asistencia', [PoliticasAsistenciaController::class, 'store']);
    Route::put('/politicas-asistencia/{id}', [PoliticasAsistenciaController::class, 'update']);
    Route::delete('/politicas-asistencia/{id}', [PoliticasAsistenciaController::class, 'destroy']);
    // Festivos por política
    Route::get('/politicas-asistencia/{id}/festivos', [PoliticasAsistenciaController::class, 'listHolidays']);
    Route::match(['PUT', 'POST'], '/politicas-asistencia/{id}/festivos', [PoliticasAsistenciaController::class, 'saveHolidays']);
    Route::delete('/politicas-asistencia/{id}/festivos/{festivoId}',
        [PoliticasAsistenciaController::class, 'destroyHoliday']);

    //***************  Fin Politicas Asitencia  ****************/

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
    Route::put('/medical-info/{id_empleado}', [MedicalInfoController::class, 'upsert']);

    Route::post(
        '/documents',
        [DocumentOptionController::class, 'store']
    )->middleware([
        'auth:sanctum',
        'admin.session',
        'admin.permission:empleados.expediente.documentos.subir',
    ]);
    Route::post(
        '/exams',
        [DocumentOptionController::class, 'storeExams']
    )->middleware([
        'auth:sanctum',
        'admin.session',
        'admin.permission:empleados.expediente.bgv_examenes.subir',
    ]);
    Route::get(
        '/documents/{id}',
        [DocumentOptionController::class, 'getDocumentsByEmployeeId']
    )->middleware([
        'auth:sanctum',
        'admin.session',
        'admin.permission:empleados.expediente.documentos.ver',
    ]);
    Route::get(
        '/exam/{id}',
        [DocumentOptionController::class, 'getExamsByEmployeeId']
    )->middleware([
        'auth:sanctum',
        'admin.session',
        'admin.permission:empleados.expediente.bgv_examenes.ver',
    ]); // Ruta para actualizar la expiración del documento, cursos y examanes
    Route::put(
        'documents/{id}',
        [DocumentOptionController::class, 'updateDocuments']
    )->middleware([
        'auth:sanctum',
        'admin.session',
        'admin.permission:empleados.expediente.documentos.editar,empleados.cursos.editar,empleados.expediente.bgv_examenes.editar',
    ]);
    Route::put('documents/{id}/expiry', [DocumentOptionController::class, 'updateExpiry']);

    Route::get('/empleados/{id_empleado}/documentos', [EmpleadoController::class, 'getDocumentos']);
    //eliminar Documentos  del empleado
    Route::delete('/documents', [DocumentOptionController::class, 'deleteDocument']);

    //  traer  los  paquetes    antidoping
    Route::get('/antidoping-packages', [ApiEmpleadoController::class, 'getAntidopinPaquetes']);
    // Traer los proyectos  disponibles  o los del cliente
    Route::get('/proyectos-historial', [ProyectosHistorialController::class, 'getproyectosPorCliente']);

    Route::post('/registrar-candidato', [EviarEmpleadoRodi::class, 'registrarCandidato']);

    // para  guardar cursos
    Route::post(
        '/cursos/registrar',
        [CursosController::class, 'store']
    )->middleware([
        'auth:sanctum',
        'admin.session',
        'admin.permission:empleados.cursos.agregar_interno,empleados.cursos.agregar_externo',
    ]);

    Route::get(
        '/cursos/empleado',
        [CursosController::class, 'obtenerCursosPorEmpleado']
    )->middleware([
        'auth:sanctum',
        'admin.session',
        'admin.permission:empleados.cursos.ver',
    ]);

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
    Route::post('/update-fecha-salida', [FormerEmpleadoController::class, 'updateFechaSalida']);
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
    Route::post('/notificaciones/guardarec', [NotificacionController::class, 'guardarRecordatorios']);

    Route::get('/notificaciones/consultar/{id_portal}/{id_cliente}/{status}', [NotificacionController::class, 'consultar']);

    Route::get('/notificaciones/consultarrecordatorios/{id_portal}/{id_cliente}/{status}', [NotificacionController::class, 'consultarRecordatorio']);

    Route::get('/notificaciones/consultarex/{id_portal}/{id_cliente}/{status}', [NotificacionController::class, 'consultarExempleo']);

    //***************  Inicio Checador  ****************/
    Route::get('/checador/mappings', [ChecadorController::class, 'indexMappings']);
    Route::post('/checador/mappings', [ChecadorController::class, 'storeMapping']);
    Route::post('/checador/import', [ChecadorController::class, 'import']);
    Route::get('/checador/ultimo-dia', [ChecadasController::class, 'ultimoDiaChecadas']);

    Route::prefix('checador')->group(function () {
        // crudas (lista simple, ordenadas por fecha/hora)
        Route::get('/checadas', [ChecadasController::class, 'listChecadas']);

        // agrupadas por día (y empleado)
        Route::get('/checadas/rango', [ChecadasController::class, 'checadasPorRango']);

        // un día con navegación (prev/next)
        Route::get('/checadas/dia', [ChecadasController::class, 'checadasPorDia']);
    });
    //***************  Fin  Checador  ****************/

    //***************  Inicio Recordatorios ****************/
    Route::prefix('comunicacion')->group(function () {
        Route::get('recordatorios', [RecordatorioController::class, 'index']);
        Route::delete('recordatorios/{id}', [RecordatorioController::class, 'destroy']);
        Route::get('recordatorios/{id}/evidencias', [RecordatorioController::class, 'evidenciasIndex']);
        Route::post('recordatorios/{id}/evidencias', [RecordatorioController::class, 'evidenciasStore']);
        Route::delete('recordatorios/evidencias/{docId}', [RecordatorioController::class, 'evidenciasDestroy']);
        Route::patch('recordatorios/{id}/estado', [RecordatorioController::class, 'toggle']);
        Route::get('recordatorios/evidencias/{docId}/ver', [RecordatorioController::class, 'evidenciasShow']);
        Route::get('recordatorios/evidencias/{docId}/descargar', [RecordatorioController::class, 'evidenciasDownload']);
    });

    // Rutas especiales para guardar con portal/cliente en el path (como pediste)
    Route::prefix('_recordadorios')->group(function () {
        Route::post('{idPortal}/{idCliente}', [RecordatorioController::class, 'storeForPortalCliente']);
        Route::put('{idPortal}/{idCliente}/{id}', [RecordatorioController::class, 'updateForPortalCliente']);
    });

    //***************  Fin Recordatorios ****************/

    //***************  Fin Permision ****************/

    Route::get('/auth/permissions/effective', [PermissionController::class, 'effective']);

    //***************  Fin Permision ****************/

    Route::post('/send-notification', [WhatsAppController::class, 'sendMessage_notificacion_talentsafe']);
    Route::post('/send-notification-ex', [WhatsAppController::class, 'sendMessage_notificacion_exempleados']);

    Route::post('/send-notification-recordatorio', [WhatsAppController::class, 'sendMessage_recordatorio_portal']);

    //***************  Inicio Informacion Interna ****************/

    Route::prefix('internos')
        ->middleware([
            'auth:sanctum',
            'admin.session',
        ])
        ->group(function () {
            // Información interna: directorios
            Route::get(
                'informacion',
                [ClienteInformacionInternaController::class, 'index']
            )->middleware(
                'admin.permission:empleados.expediente.informacion_interna.ver'
            );

            Route::post(
                'informacion',
                [ClienteInformacionInternaController::class, 'store']
            )->middleware(
                'admin.permission:empleados.expediente.informacion_interna.crear_directorio'
            );

            Route::put(
                'informacion/{informacion}',
                [ClienteInformacionInternaController::class, 'update']
            )->middleware(
                'admin.permission:empleados.expediente.informacion_interna.editar_directorio'
            );

            Route::delete(
                'informacion/{informacion}',
                [ClienteInformacionInternaController::class, 'destroy']
            )->middleware(
                'admin.permission:empleados.expediente.informacion_interna.eliminar_directorio'
            );

            // Información interna: documentos
            Route::post(
                'informacion/{informacion}/documentos',
                [DocumentoInternoController::class, 'store']
            )->middleware(
                'admin.permission:empleados.expediente.informacion_interna.subir_documento'
            );

            Route::get(
                'documentos/{documento}/download',
                [DocumentoInternoController::class, 'download']
            )->middleware(
                'admin.permission:empleados.expediente.informacion_interna.descargar_documento'
            );

            Route::put(
                'documentos/{documento}/comparticion',
                [DocumentoInternoController::class, 'updateSharing']
            )->middleware(
                'admin.permission:empleados.expediente.informacion_interna.compartir_documento'
            );

            Route::delete(
                'documentos/{documento}',
                [DocumentoInternoController::class, 'destroy']
            )->middleware(
                'admin.permission:empleados.expediente.informacion_interna.eliminar_documento'
            );
        });
    //***************  Fin Informacion Interna ****************/
    //**********************Ruta para  fotos de perfil **************************** */
    // routes/api.php
    Route::get(
        'employees/photo/{filename?}',
        [EmployeePhotoController::class, 'show']
    );
    //**********************Fin Ruta para  fotos de perfil **************************** */

});

//********************** Inicio Rutas MiPortal Empleados ****************************//

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

Route::prefix('empleado/auth')->group(function () {

    // Login público
    Route::post('/login', [AuthController::class,
        'login',
    ]);

    // Logout
    Route::middleware('auth:empleado')->post('/logout', [AuthController::class,
        'logout',
    ]);

    // Cambio de contraseña
    Route::middleware('auth:empleado')->post('/change-password', [AuthController::class,
        'changePassword',
    ]);

});

/*
|--------------------------------------------------------------------------
| PORTAL EMPLEADO (requiere autenticación)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:empleado')->get(
    '/empleado/profile',
    [ProfileController::class, 'profile']
);
Route::middleware('auth:empleado')->get(
    '/empleado/approvers',
    [EmpleadoApproversController::class, 'index']
);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/empleado/tareas', [EmpleadoTareasController::class, 'index']);
    Route::post('/empleado/tareas/{id}/toggle', [EmpleadoTareasController::class, 'toggle']);
    Route::post('/empleado/tareas/{id}/comentarios', [EmpleadoTareasController::class, 'storeComentario']
    );
    Route::post('/empleado/tareas/{id}/evidencia', [
        EmpleadoTareasController::class,
        'uploadEvidencia',
    ]);

    Route::post('/empleado/tareas/{id}/validar-ubicacion-evidencia', [
        EmpleadoTareasController::class,
        'validarUbicacion',
    ]);
    Route::get('/empleado/tareas/{id}/evidencia/{evidenciaId}/ver', [
        EmpleadoTareasController::class,
        'verEvidencia',
    ]);
    Route::delete('/empleado/tareas/{id}/evidencia/{evidenciaId}', [
        EmpleadoTareasController::class,
        'deleteEvidencia',
    ]);

});
Route::middleware('auth:empleado')->post('/empleado/incidencias', [EmpleadoIncidenciasController::class, 'store']);
Route::middleware('auth:empleado')->get('empleado/incidencias', [EmpleadoIncidenciasController::class, 'index']);
Route::middleware('auth:empleado')->get('/empleado/incidencias/contexto', [EmpleadoIncidenciasController::class, 'contexto']);
Route::middleware('auth:empleado')->get('/empleado/aprobaciones/pendientes', [EmpleadoAprobacionesController::class, 'pendientes']);
Route::middleware('auth:empleado')->post('/empleado/aprobaciones/{id}/aprobar', [EmpleadoAprobacionesController::class, 'aprobar']);
Route::middleware('auth:empleado')->post('/empleado/aprobaciones/{id}/rechazar', [EmpleadoAprobacionesController::class, 'rechazar']);
Route::middleware('auth:empleado')->get('/empleado/aprobaciones/historial', [EmpleadoAprobacionesController::class, 'historial']);
Route::middleware('auth:empleado')->get('/empleado/aprobaciones/resumen', [EmpleadoAprobacionesController::class, 'resumen']);
Route::middleware('auth:empleado')->get(
    '/empleado/aprobaciones/mis-solicitudes',
    [EmpleadoAprobacionesController::class, 'misSolicitudes']
);
Route::middleware(['auth:empleado'])
    ->prefix('empleado')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | PERFIL
        |--------------------------------------------------------------------------
        */

        Route::get('/me', function (Request $request) {
            return response()->json($request->user());
        });

        Route::get('/profile-photo', [
            ApiEmpleadoController::class,
            'getMyProfilePicture',
        ]);

        /*
        |--------------------------------------------------------------------------
        | DASHBOARD
        |--------------------------------------------------------------------------
        */

        Route::get('/dashboard', [
            EmpleadoDashboardController::class,
            'dashboard',
        ]);
        /*
        |--------------------------------------------------------------------------
        | CHECADOR / ASISTENCIA
        |--------------------------------------------------------------------------
        */

        Route::get('/checador/contexto', [EmpleadoChecadorController::class, 'contexto']);
        Route::get('/checador/historial-hoy', [EmpleadoChecadorController::class, 'historialHoy']);
        Route::post('/checador/registrar', [EmpleadoChecadorController::class, 'registrar']);
        Route::post('/checador/regularizar-salida-pendiente/preview', [EmpleadoChecadorController::class, 'previewRegularizacionSalidaPendiente']);

        Route::post('/checador/regularizar-salida-pendiente/confirmar', [EmpleadoChecadorController::class, 'confirmarRegularizacionSalidaPendiente']); /*
        |--------------------------------------------------------------------------
        | CHECADOR / ASISTENCIA
        |--------------------------------------------------------------------------
        */
        Route::get('/compliance/{tipo}/{id}/ver', [
            EmpleadoDashboardController::class,
            'verCompliance',
        ]);
        /*
        |--------------------------------------------------------------------------
        | EVENTOS / CONFIRMACIONES EMPLEADO
        |--------------------------------------------------------------------------
        */

        Route::get('/eventos/confirmaciones-pendientes', [
            EmpleadoEventoConfirmacionesController::class,
            'pendientes',
        ]);

        Route::post('/eventos/{id}/confirmar', [
            EmpleadoEventoConfirmacionesController::class,
            'confirmar',
        ]);

        Route::post('/eventos/{id}/rechazar-confirmacion', [
            EmpleadoEventoConfirmacionesController::class,
            'rechazar',
        ]);
        Route::get('/eventos/horas-extra/colaboradores', [
            EmpleadoHorasExtraController::class,
            'colaboradores',
        ]);
        Route::post('/eventos/horas-extra', [
            EmpleadoHorasExtraController::class,
            'store',
        ]);
        Route::get('/horario-semanal', [EmpleadoChecadorController::class, 'horarioSemanal']);

    });

/*
|--------------------------------------------------------------------------
| PORTAL EMPLEADO - REQUIERE PASSWORD ACTUALIZADA
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:empleado', 'force.password.change'])
    ->prefix('empleado')
    ->group(function () {

        // futuras rutas protegidas
        // Route::get('/recibos', ...);
        // Route::get('/documentos', ...);
        // Route::get('/solicitudes', ...);

    });

//********************** Fin Rutas MiPortal Empleados ****************************//

//********************** INICIO Rutas Comunicacion 360  ****************************//

Route::prefix('comunicacion360')
    ->middleware([
        'auth:sanctum',
        'admin.session',
        'admin.permission:module.comunicacion360.ver',
    ])
    ->group(function () {
        Route::get(
            '/accesos',
            [AccesosController::class, 'index']
        )->middleware('admin.permission:comunicacion360.accesos.ver');

        Route::post(
            '/accesos/generar',
            [AccesosController::class, 'generar']
        )->middleware('admin.permission:comunicacion360.accesos.generar');

        Route::post(
            '/accesos/actualizar',
            [AccesosController::class, 'actualizar']
        )->middleware('admin.permission:comunicacion360.accesos.actualizar');

        Route::post(
            '/accesos/generar-individual',
            [AccesosController::class, 'generarIndividual']
        )->middleware('admin.permission:comunicacion360.accesos.generar');

        Route::post(
            '/accesos/actualizar-individual',
            [AccesosController::class, 'actualizarIndividual']
        )->middleware('admin.permission:comunicacion360.accesos.actualizar');

        Route::post(
            '/accesos/{id}/cerrar-sesion',
            [AccesosChecadorController::class, 'cerrarSesion']
        )->middleware('admin.permission:comunicacion360.accesos.cerrar_sesion');

        Route::get(
            '/accesos/empleados/{id}/gestion-checadas/contexto',
            [AccesosChecadorGestionController::class, 'contextoDia']
        )->middleware(
            'admin.permission:comunicacion360.accesos.checadas.gestionar'
        );

        Route::post(
            '/accesos/empleados/{id}/gestion-checadas/ejecutar',
            [
                AccesosChecadorGestionController::class,
                'ejecutarAccionAdministrativa',
            ]
        )->middleware(
            'admin.permission:comunicacion360.accesos.checadas.gestionar'
        );

        Route::post(
            '/accesos/empleados/{id}/eliminar-acceso',
            [AccesosController::class, 'eliminarAcceso']
        )->middleware('admin.permission:comunicacion360.accesos.eliminar');

        Route::prefix('accesos/empleados/{id}/ips')
            ->group(function () {
                Route::get(
                    '/',
                    [AccesosIpController::class, 'index']
                )->middleware('admin.permission:comunicacion360.accesos.ips.ver');

                Route::post(
                    '/',
                    [AccesosIpController::class, 'guardarIp']
                )->middleware('admin.permission:comunicacion360.accesos.ips.crear');

                Route::put(
                    '/{ipId}',
                    [AccesosIpController::class, 'actualizarIp']
                )->middleware('admin.permission:comunicacion360.accesos.ips.editar');

                Route::delete(
                    '/{ipId}',
                    [AccesosIpController::class, 'eliminarIp']
                )->middleware('admin.permission:comunicacion360.accesos.ips.eliminar');
            });
        Route::get(
            '/accesos/empleados/{id}/checadas-dia',
            [AccesosChecadorController::class, 'checadasDia']
        )->middleware('admin.permission:comunicacion360.accesos.checadas.ver');

        Route::get(
            '/accesos/empleados/{id}/metricas-dia',
            [AccesosChecadorController::class, 'metricasDia']
        )->middleware('admin.permission:comunicacion360.accesos.checadas.ver');

        Route::get(
            '/accesos/empleados/{id}/metricas-operativas',
            [AccesosChecadorController::class, 'metricasOperativas']
        )->middleware('admin.permission:comunicacion360.accesos.checadas.ver');

        Route::get(
            '/accesos/empleados/{id}/checadas-historial',
            [AccesosChecadorController::class, 'historialChecadas']
        )->middleware('admin.permission:comunicacion360.accesos.checadas.ver');

        Route::get(
            '/accesos/empleados/{id}/checadas/{idChecada}/evidencia',
            [AccesosChecadorController::class, 'evidenciaChecada']
        )->middleware('admin.permission:comunicacion360.accesos.checadas.ver');

        Route::get(
            '/accesos/empleados/{id}/tareas-historial',
            [AccesosTareasController::class, 'historialTareas']
        )->middleware('admin.permission:comunicacion360.accesos.tareas.ver');

        Route::get(
            '/accesos/empleados/{id}/tareas-dia',
            [AccesosTareasController::class, 'tareasDia']
        )->middleware('admin.permission:comunicacion360.accesos.tareas.ver');

        Route::get(
            '/accesos/empleados/{id}/tareas/{idTarea}/evidencias/{idEvidencia}',
            [AccesosTareasController::class, 'evidenciaTarea']
        )->middleware('admin.permission:comunicacion360.accesos.tareas.ver');
        Route::get(
            '/accesos/empleados/{id}/analisis-operativo',
            [EmployeeProfileAnalysisController::class, 'show']
        )->middleware('admin.permission:comunicacion360.accesos.perfil.ver');
        Route::post(
            '/accesos/empleados/{id}/eventos/horas-extra',
            [ChecadorEventosController::class, 'registrarHorasExtra']
        )->middleware(
            'admin.permission:comunicacion360.accesos.eventos.registrar_horas_extra'
        );

        Route::post(
            '/accesos/eventos/horas-extra',
            [ChecadorEventosController::class, 'registrarHorasExtraMasivo']
        )->middleware(
            'admin.permission:comunicacion360.accesos.eventos.registrar_horas_extra'
        );

        Route::get(
            '/accesos/empleados/{id}/eventos',
            [ChecadorEventosController::class, 'eventosEmpleado']
        )->middleware(
            'admin.permission:comunicacion360.accesos.eventos.ver'
        );

        Route::get(
            '/accesos/empleados/{id}/reportes/checadas/vista-previa',
            [AccesosChecadorReportesController::class, 'vistaPrevia']
        )->middleware(
            'admin.permission:comunicacion360.accesos.reportes.ver'
        );
        Route::prefix('incidencias')
            ->group(function () {
                Route::get(
                    '/calendario',
                    [IncidenciasCalendarioController::class, 'index']
                )->middleware(
                    'admin.permission:comunicacion360.incidencias.ver'
                );

                Route::get(
                    '/{id}/evidencia',
                    [IncidenciasCalendarioController::class, 'evidencia']
                )->middleware(
                    'admin.permission:comunicacion360.incidencias.ver_evidencia'
                );
            });

    });

Route::prefix('checador')->group(function () {
    //CRUD  Cat Ubicaciones
    Route::middleware(['auth:sanctum', 'admin.session'])
        ->group(function () {
            Route::get('/ubicaciones', [ChecadorUbicacionesController::class, 'index']);
            Route::post('/ubicaciones', [ChecadorUbicacionesController::class, 'store']);
            Route::put('/ubicaciones/{id}', [ChecadorUbicacionesController::class, 'update']);
            Route::delete('/ubicaciones/{id}', [ChecadorUbicacionesController::class, 'destroy']);

            Route::post('/qr/generar', [ChecadorQrController::class, 'generar']);
            Route::get(
                '/qr/fijo/{ubicacionId}',
                [ChecadorQrController::class, 'mostrarFijo']
            );
        });

    //validate Ubications
    Route::post('/validar-ubicacion', [ChecadorValidacionController::class, 'validarUbicacion']);

    // QR routes
    Route::post('/qr/validar', [ChecadorQrController::class, 'validar']);

    // Plantillas  para  el checador

    // endoints  de horarios
    Route::prefix('horarios')
        ->middleware(['auth:sanctum', 'admin.session'])
        ->group(function () {
            Route::get(
                '/',
                [ChecadorHorarioPlantillaController::class, 'index']
            );

            Route::post(
                '/',
                [ChecadorHorarioPlantillaController::class, 'store']
            );

            Route::put(
                '/{id}',
                [ChecadorHorarioPlantillaController::class, 'update']
            );

            Route::post(
                '/{id}/estado',
                [ChecadorHorarioPlantillaController::class, 'cambiarEstado']
            );
        });

    // Importaciones / Exportaciones STC
    Route::prefix('/importaciones')->group(function () {
        Route::get('/horarios/exportar', [ChecadorImportExportController::class, 'exportarHorarios']);
        Route::post('/horarios/importar', [ChecadorImportExportController::class, 'importarHorarios']);

    });

    // Checadas masivas administrativas
    Route::prefix('checadas-masivas')
        ->middleware(['auth:sanctum', 'admin.session'])
        ->group(function () {
            Route::post(
                '/exportar-plantilla',
                [ChecadorChecadasMasivasController::class, 'exportarPlantilla']
            );

            Route::post(
                '/importar-preview',
                [ChecadorChecadasMasivasController::class, 'importarPreview']
            );

            Route::post(
                '/importar-confirmar',
                [ChecadorChecadasMasivasController::class, 'importarConfirmar']
            );
        });

// Incidencias masivas administrativas
    Route::prefix('incidencias-masivas')
        ->middleware(['auth:sanctum', 'admin.session'])
        ->group(function () {
            Route::post(
                '/exportar-plantilla',
                [ChecadorIncidenciasMasivasController::class, 'exportarPlantilla']
            );

            Route::post(
                '/importar-preview',
                [ChecadorIncidenciasMasivasController::class, 'importarPreview']
            );

            Route::post(
                '/importar-confirmar',
                [ChecadorIncidenciasMasivasController::class, 'importarConfirmar']
            );
        });

    Route::get('/empleados/{id}/plantilla', [
        ChecadorAsignacionController::class,
        'plantillaEmpleado',
    ]);

    Route::post('/empleados/{id}/plantilla', [
        ChecadorAsignacionController::class,
        'guardarPlantillaEmpleado',
    ]);

// Métodos
    Route::get('/metodos', [
        ChecadorMetodoController::class,
        'index',
    ]);

// Plantillas para el checador
    Route::get('/plantillas-checada', [
        ChecadorChecadaPlantillaController::class,
        'index',
    ]);

    Route::post('/plantillas-checada', [
        ChecadorChecadaPlantillaController::class,
        'store',
    ]);

    Route::post('/plantillas-checada/{id}/metodos', [
        ChecadorChecadaPlantillaController::class,
        'guardarMetodos',
    ]);

    Route::put('/plantillas-checada/{id}', [
        ChecadorChecadaPlantillaController::class,
        'update',
    ]);

    Route::post('/plantillas-checada/{id}/estado', [
        ChecadorChecadaPlantillaController::class,
        'cambiarEstado',
    ]);

// Aprobadores
    Route::get('/aprobadores-disponibles', [
        ChecadorAsignacionController::class,
        'aprobadoresDisponibles',
    ]);

// Asignaciones de plantillas a empleados
    Route::get('/plantillas-checada/{id}/asignaciones', [
        ChecadorAsignacionController::class,
        'index',
    ]);

    Route::post('/plantillas-checada/{id}/asignaciones', [
        ChecadorAsignacionController::class,
        'store',
    ]);

    Route::get('/empleados-acceso', [
        ChecadorAsignacionController::class,
        'empleadosConAcceso',
    ]);

});
Route::prefix('comunicacion360/tasks')
    ->middleware([
        'auth:sanctum',
        'admin.session',
        'admin.permission:module.comunicacion360.ver',
    ])
    ->group(function () {
        Route::get(
            '/',
            [App\Http\Controllers\Api\Comunicacion360\TasksController::class, 'index']
        )->middleware(
            'admin.permission:comunicacion360.tareas.ver,comunicacion360.plantillas.ver'
        );
        Route::post(
            '/',
            [App\Http\Controllers\Api\Comunicacion360\TasksController::class, 'store']
        )->middleware('admin.permission:comunicacion360.tareas.crear');

        Route::get(
            '/empleado/{id}',
            [App\Http\Controllers\Api\Comunicacion360\TasksController::class, 'empleado']
        )->middleware('admin.permission:comunicacion360.accesos.tareas.ver');

        Route::post(
            '/empleado-tarea/{id}/comentarios',
            [App\Http\Controllers\Api\Comunicacion360\TasksController::class, 'storeComentarioEmpleado']
        )->middleware('admin.permission:comunicacion360.accesos.tareas.comentar');

        Route::post(
            '/empleado-tarea/{id}/reabrir',
            [App\Http\Controllers\Api\Comunicacion360\TasksController::class, 'reabrirTareaEmpleado']
        )->middleware('admin.permission:comunicacion360.accesos.tareas.reabrir');

        Route::delete(
            '/comentarios/{id}',
            [App\Http\Controllers\Api\Comunicacion360\TasksController::class, 'deleteComentarioEmpleado']
        )->middleware('admin.permission:comunicacion360.accesos.tareas.eliminar_comentario');

        Route::get(
            '/{id}',
            [App\Http\Controllers\Api\Comunicacion360\TasksController::class, 'show']
        )->middleware('admin.permission:comunicacion360.tareas.ver');

        Route::put(
            '/{id}',
            [App\Http\Controllers\Api\Comunicacion360\TasksController::class, 'update']
        )->middleware('admin.permission:comunicacion360.tareas.editar');

        Route::delete(
            '/{id}',
            [App\Http\Controllers\Api\Comunicacion360\TasksController::class, 'destroy']
        )->middleware('admin.permission:comunicacion360.tareas.eliminar');
    });

Route::prefix('comunicacion360/plantillas')
    ->middleware([
        'auth:sanctum',
        'admin.session',
        'admin.permission:module.comunicacion360.ver',
    ])
    ->group(function () {
        Route::get(
            '/',
            [PlantillasController::class, 'index']
        )->middleware('admin.permission:comunicacion360.plantillas.ver');

        Route::post(
            '/',
            [PlantillasController::class, 'store']
        )->middleware('admin.permission:comunicacion360.plantillas.crear');

        Route::put(
            '/{id}',
            [PlantillasController::class, 'update']
        )->middleware('admin.permission:comunicacion360.plantillas.editar');

        Route::delete(
            '/{id}',
            [PlantillasController::class, 'destroy']
        )->middleware('admin.permission:comunicacion360.plantillas.eliminar');

        Route::post(
            '/{id}/asignar',
            [PlantillasController::class, 'asignar']
        )->middleware('admin.permission:comunicacion360.plantillas.asignar');

        Route::get(
            '/empleados/{id}/plantillas',
            [PlantillasController::class, 'empleadoPlantillas']
        )->middleware('admin.permission:comunicacion360.plantillas.ver');

        Route::post(
            '/{id}/desasignar',
            [PlantillasController::class, 'desasignarEmpleado']
        )->middleware('admin.permission:comunicacion360.plantillas.desasignar');
    });

Route::post(
    '/checador/hikvision/eventos/{token}',
    [HikvisionEventController::class, 'store']
)
    ->where('token', '[A-Fa-f0-9]{64}')
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:hikvision-device');

Route::post(
    '/checador/dispositivo/registrar',
    [ChecadaDispositivoController::class, 'store']
);
//********************** Fin Rutas Comunicacion 360 ****************************//

/*notificaciones  via  whatsapp modulo empleados*/

/*Este  endpoint  es para   mostrar  avances  de los  candidatos  en pre empleo  */
//Route::get('/check-avances', [AvanceController::class, 'checkAvances']);
Route::post('/preempleados/proceso-candidato', [PreEmpleadoController::class, 'verProcesoCandidato'])->name('preempleados.procesoCandidato');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
