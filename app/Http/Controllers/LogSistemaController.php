<?php

namespace App\Http\Controllers;

use App\Models\LogSistema;
use Illuminate\Http\Request;

class LogSistemaController extends Controller
{
    public function index(Request $request)
    {
        $logs = LogSistema::with('usuario:id,name')
            ->when($request->modulo,      fn($q, $m) => $q->where('objeto_actualizado', $m))
            ->when($request->accion,      fn($q, $a) => $q->where('accion', $a))
            ->when($request->search,      fn($q, $s) => $q->where('descripcion', 'like', "%$s%"))
            ->when($request->fecha_desde, fn($q, $f) => $q->whereDate('created_at', '>=', $f))
            ->when($request->fecha_hasta, fn($q, $f) => $q->whereDate('created_at', '<=', $f))
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json($logs);
    }
}
