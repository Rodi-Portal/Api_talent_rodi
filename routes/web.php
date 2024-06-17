<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiCandidatoSinEseController;
use App\Http\Controllers\ApiCandidatoConProyectoPrevioController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::post('/candidatossinese', [ApiCandidatoSinEseController::class, 'store']);
Route::post('/candidatoconprevio', [ApiCandidatoConProyectoPrevioController::class, 'store']);


Route::get('/', function () {
    return view('welcome');
});
