<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\HoldService;
class HoldController extends Controller
{
    public function store(Request $request, HoldService $service)
    {
        $data = $request->validate([
            'product_id' => 'required|integer',
            'qty' => 'required|integer|min:1',
        ]);

        $hold = $service->createHold($data['product_id'], $data['qty']);

        return response()->json([
            'hold_id' => $hold->id,
            'expires_at' => $hold->expires_at->toDateTimeString(),
        ], 201);
    }
}
