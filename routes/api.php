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



Route::get('/medico/{id}', [ApiGetMedicoDetalles::class, 'getDatosMedico']);
Route::get('/test', [TestController::class, 'testPost']);


Route::get('file/{path}', [ImageController::class, 'getFile'])->where('path', '.*');
Route::post('/upload', [DocumentController::class, 'upload']);


Route::post('/candidatoconprevio', [ApiCandidatoConProyectoPrevioController::class, 'store']);
Route::post('/candidatos', [ApiCandidatoSinEseController::class, 'store']);
Route::post('/existe-cliente', [ApiClientesController::class, 'VerificarCliente']);
Route::get('candidato-sync/{id_cliente_talent}', [ApiGetCandidatosByCliente::class, 'getByClienteTalent']);
Route::get('doping/{id}', [ApiGetDopingDetalles::class, 'getDatosDoping']);
Route::get('doping-detalles/{id}', [ApiGetDopingDetalles::class, 'getDopingDetalles']);

Route::get('area/{nombre}', [ApiGetArea::class, 'getArea']);

// reportes

Route::get('/report/{id_candidato}', [ReportController::class, 'getReport']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});