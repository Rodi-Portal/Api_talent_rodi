<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;

class TestController extends Controller
{
    public function testPost()
    {
        return response()->json([
            'message' => 'This is a test POST request',
           
        ]);
    }
}
