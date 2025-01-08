<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CargaMasivaPlantillaExport implements FromArray, WithHeadings, WithStyles
{
    private $encabezados;

    public function __construct($encabezados)
    {
        $this->encabezados = $encabezados;
    }

    public function array(): array
    {
        // Puedes dejar las filas vacías, o agregar datos de ejemplo si lo prefieres
        return [
            ['', '', ''],  // Fila vacía para ejemplo
        ];
    }

    public function headings(): array
    {
        return $this->encabezados;
    }

    public function styles(Worksheet $sheet)
    {
        // Estilo para el encabezado
        $sheet->getStyle('A1:R1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 14,
                'color' => ['argb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => 'solid',
                'color' => ['argb' => '4F81BD'], // Azul
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['argb' => '000000'],
                ],
            ],
        ]);

        // Ajustar el ancho de las columnas
        foreach (range('A', 'R') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return [];
    }
}
