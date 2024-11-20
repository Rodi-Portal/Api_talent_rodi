<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CursosExport implements FromCollection, WithHeadings, WithStyles
{
    private $data;
    private $empleados;
    private $cursos;

    public function __construct($data)
    {
        $this->data = $data;
        $this->empleados = collect($data)->pluck('empleado')->unique();
        $this->cursos = collect($data)->pluck('curso')->unique();
    }

    public function collection()
    {
        $rows = [];

        foreach ($this->empleados as $empleado) {
            $row = [$empleado];
            foreach ($this->cursos as $curso) {
                // Encuentra la entrada correspondiente al empleado y curso
                $entry = collect($this->data)->first(function ($d) use ($empleado, $curso) {
                    return $d['empleado'] === $empleado && $d['curso'] === $curso;
                });
                $row[] = $entry['fecha_expiracion'] ?? 'Sin fecha';
            }
            $rows[] = $row;
        }

        return collect($rows);
    }

    public function headings(): array
    {
        // Genera las cabeceras dinámicamente
        return array_merge(['Empleado'], $this->cursos->toArray());
    }

    public function styles(Worksheet $sheet)
    {
        // Aplica estilos al encabezado
        $sheet->getStyle('1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => 'solid',
                'color' => ['argb' => 'ADD8E6'], // Azul claro
            ],
        ]);

        // Aplica estilos adicionales según tus necesidades (opcional)
        return [];
    }
}
