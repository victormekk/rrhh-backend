<?php

namespace App\Http\Controllers;

use App\Calculos\Fechas;
use App\Models\Empleado;
use App\Traits\EncabezadoExcel;
use App\Traits\LogsActividad;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CumpleanosController extends Controller
{
    use EncabezadoExcel, LogsActividad;

    private const MESES = [
        1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
    ];

    public function index(Request $request)
    {
        return response()->json($this->cumpleaneros($this->mes($request)));
    }

    // Excel con la misma lista de la pantalla: empleados activos del mes, por día de cumpleaños.
    public function excel(Request $request)
    {
        $mes   = $this->mes($request);
        $lista = $this->cumpleaneros($mes);
        $nombreMes = self::MESES[$mes];

        $columnas  = ['A' => 'Nombre completo', 'B' => 'Departamento', 'C' => 'Cumpleaños', 'D' => 'Edad que cumple'];
        $ultimaCol = 'D';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cumpleaños ' . $nombreMes);

        $this->encabezadoExcel($sheet, $ultimaCol, [
            ['INVERSIONES Y SERVICIOS S.A - HOTEL PALMA REAL', 14],
            ['CUMPLEAÑOS DE ' . mb_strtoupper($nombreMes), 12],
            [sprintf('Generado: %s   |   Empleados: %d', now()->format('d/m/Y'), $lista->count()), null],
        ]);

        $row = 5;
        foreach ($columnas as $col => $titulo) {
            $sheet->setCellValue("{$col}{$row}", $titulo);
        }
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3B2B16');
        $row++;

        foreach ($lista as $emp) {
            $sheet->setCellValue("A{$row}", trim("{$emp['nombres']} {$emp['apellidos']}"));
            $sheet->setCellValue("B{$row}", $emp['departamento']);
            $sheet->setCellValue("C{$row}", "{$emp['dia']} de {$nombreMes}");
            $sheet->setCellValue("D{$row}", "{$emp['edad_cumple']} años");
            $row++;
        }

        if ($lista->isEmpty()) {
            $sheet->setCellValue("A{$row}", "Ningún empleado activo cumple años en {$nombreMes}.");
            $sheet->mergeCells("A{$row}:{$ultimaCol}{$row}");
            $row++;
        }

        $sheet->getStyle("A5:{$ultimaCol}" . ($row - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D6D3D1');
        $sheet->getStyle("C6:D" . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        foreach (array_keys($columnas) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'cumpleanos') . '.xlsx';
        $this->anchoColumnaLogo($sheet);
        (new Xlsx($spreadsheet))->save($tempFile);

        $this->logActividad('generado', 'Empleados', "Exportó los cumpleaños de {$nombreMes} a Excel ({$lista->count()} empleado(s)).");

        return response()->download($tempFile, "Cumpleanos_{$nombreMes}_" . now()->format('dmY') . '.xlsx')
            ->deleteFileAfterSend(true);
    }

    private function mes(Request $request): int
    {
        $mes = (int) ($request->mes ?? Carbon::now()->month);

        return $mes >= 1 && $mes <= 12 ? $mes : Carbon::now()->month;
    }

    private function cumpleaneros(int $mes): Collection
    {
        $hoy = Carbon::now();

        return Empleado::with(['departamento:id,nombre'])
            ->whereNotNull('fecha_nacimiento')
            ->whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))
            ->whereRaw('MONTH(fecha_nacimiento) = ?', [$mes])
            ->orderByRaw('DAY(fecha_nacimiento)')
            ->get()
            ->map(function ($emp) use ($hoy) {
                $nac = $emp->fecha_nacimiento;
                // Quien nació un 29 de febrero celebra el 28 en años no bisiestos.
                $cumpleEsteAnio = Fechas::aniversarioEn($nac, $hoy->year);
                $esHoy = $cumpleEsteAnio->isSameDay($hoy);
                $edad  = $hoy->year - $nac->year;

                $proxCumple = $cumpleEsteAnio->copy();
                if ($proxCumple->lt($hoy->copy()->startOfDay()) && !$esHoy) {
                    $proxCumple = Fechas::aniversarioEn($nac, $hoy->year + 1);
                }
                $diasPara = $esHoy ? 0 : (int) $hoy->copy()->startOfDay()->diffInDays($proxCumple);

                return [
                    'id'               => $emp->id,
                    'nombres'          => $emp->nombres,
                    'apellidos'        => $emp->apellidos,
                    'genero'           => $emp->genero,
                    'foto_url'         => $emp->foto_url,
                    'departamento'     => $emp->departamento?->nombre ?? '—',
                    'fecha_nacimiento' => $nac->format('Y-m-d'),
                    'fecha_celebracion' => $cumpleEsteAnio->format('Y-m-d'),
                    'dia'              => $nac->day,
                    'edad_cumple'      => $edad,
                    'es_hoy'           => $esHoy,
                    'dias_para'        => $diasPara,
                ];
            });
    }
}
