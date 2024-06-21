<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TestController extends Controller
{
    public function testPost(Request $request)
    {
        return response()->json([
            'message' => 'This is a test POST request',
            'data' => $request->all(),
        ]);
    }
}
