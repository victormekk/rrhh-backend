<?php

namespace App\Http\Controllers;

use App\Models\LogSistema;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class LogSistemaController extends Controller
{
    private function filtrar(Request $request)
    {
        return LogSistema::with('usuario:id,name')
            ->when($request->modulo,      fn($q, $m) => $q->where('objeto_actualizado', $m))
            ->when($request->accion,      fn($q, $a) => $q->where('accion', $a))
            ->when($request->search,      fn($q, $s) => $q->where('descripcion', 'like', "%$s%"))
            ->when($request->fecha_desde, fn($q, $f) => $q->whereDate('created_at', '>=', $f))
            ->when($request->fecha_hasta, fn($q, $f) => $q->whereDate('created_at', '<=', $f))
            ->orderByDesc('created_at');
    }

    public function index(Request $request)
    {
        return response()->json($this->filtrar($request)->paginate(25));
    }

    public function exportPdf(Request $request)
    {
        $logs = $this->filtrar($request)->get();

        $filtros = [
            'modulo'       => $request->modulo,
            'accion'       => $request->accion,
            'search'       => $request->search,
            'fecha_desde'  => $request->fecha_desde,
            'fecha_hasta'  => $request->fecha_hasta,
        ];

        $pdf = Pdf::loadView('log-sistema.pdf', compact('logs', 'filtros'))
            ->setPaper('letter', 'landscape');

        return $pdf->download(now()->format('dmY') . '-log-sistema.pdf');
    }
}
