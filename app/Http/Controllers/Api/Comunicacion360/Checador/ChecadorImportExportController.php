<?php
namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Checador\ChecadorHorarioPlantilla;
use App\Services\Checador\HorariosImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ChecadorImportExportController extends Controller
{
    public function exportarHorarios(Request $request)
    {
        $data = $request->validate([
            'id_portal'  => ['required', 'integer'],
            'id_cliente' => ['required', 'integer'],
        ]);
        $lang     = $request->input('lang', 'es');
        $horarios = ChecadorHorarioPlantilla::with('detalles')
            ->where('id_portal', $data['id_portal'])
            ->where('id_cliente', $data['id_cliente'])
            ->orderBy('nombre')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Horarios');

        $headers = $this->headersHorarios($lang);

        $sheet->fromArray($headers, null, 'A1');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:AC1');

        $sheet->getStyle('A1:AC1')->applyFromArray([
            'font'      => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill'      => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E3A8A'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
            'borders'   => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'CBD5E1'],
                ],
            ],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(34);
        $this->configurarAnchosColumnas($sheet);
        $sheet->getColumnDimension('A')->setVisible(false);
        $row = 2;

        foreach ($horarios as $horario) {
            $detalles = $horario->detalles->keyBy('dia_semana');

            $fila = [
                $horario->codigo,
                $horario->nombre,
                $horario->descripcion,
                $this->labelTimezone($horario->timezone, $lang),
                $horario->tolerancia_entrada_min,
                $horario->tolerancia_salida_min,
                $this->valorBooleano($horario->permite_descanso, $lang),
                $this->valorBooleano($horario->activo, $lang),
                ...$this->detalleDia($detalles, 1, $lang),
                ...$this->detalleDia($detalles, 2, $lang),
                ...$this->detalleDia($detalles, 3, $lang),
                ...$this->detalleDia($detalles, 4, $lang),
                ...$this->detalleDia($detalles, 5, $lang),
                ...$this->detalleDia($detalles, 6, $lang),
                ...$this->detalleDia($detalles, 0, $lang),
            ];

            $sheet->fromArray($fila, null, 'A' . $row);
            $row++;
        }

        $lastRow           = max($row - 1, 2);
        $validationLastRow = $lastRow + 100;
        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(35);
        $sheet->getColumnDimension('D')->setWidth(26);

        $sheet->getStyle("A1:AC{$lastRow}")->applyFromArray([
            'borders'   => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'E5E7EB'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle("A2:A{$lastRow}")->applyFromArray([
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EEF2FF'],
            ],
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => '1E3A8A'],
            ],
        ]);

        $booleanOptions = $this->opcionesBooleanas($lang);
      
        $this->aplicarValidacionLista($sheet, "G2:G{$validationLastRow}", $booleanOptions);
        $this->aplicarValidacionLista($sheet, "H2:H{$validationLastRow}", $booleanOptions);

        foreach (['I', 'L', 'O', 'R', 'U', 'X', 'AA'] as $columnaLabora) {
            $this->aplicarValidacionLista(
                $sheet,
                "{$columnaLabora}2:{$columnaLabora}{$validationLastRow}",
                $booleanOptions
            );
        }

        $timezones = $this->obtenerTimezones();

        $this->crearHojaCatalogos($spreadsheet, $timezones);

        $this->aplicarValidacionRango(
            $sheet,
            "D2:D{$validationLastRow}",
            "'Catalogos'!\$A\$1:\$A\$" . count($timezones)
        );

        $filename = 'catalogo_horarios_' . now()->format('Ymd_His') . '.xlsx';

        $tempPath = storage_path('app/temp/' . $filename);

        if (! is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0775, true);
        }
        // Todo editable
        $sheet->getStyle('A:AC')
            ->getProtection()
            ->setLocked(Protection::PROTECTION_UNPROTECTED);

// Solo código protegido
        $sheet->getStyle('A:A')
            ->getProtection()
            ->setLocked(Protection::PROTECTION_PROTECTED);

// Activar protección
        $sheet->getProtection()->setSheet(true);

// Permisos para el usuario
        $sheet->getProtection()->setSort(true);
        $sheet->getProtection()->setAutoFilter(true);
        $sheet->getProtection()->setInsertRows(true);
        $sheet->getProtection()->setFormatCells(true);
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return response()
            ->download($tempPath, $filename)
            ->deleteFileAfterSend(true);
    }

    private function detalleDia($detalles, int $diaSemana, string $lang): array
    {
        $detalle = $detalles->get($diaSemana);

        if (! $detalle) {
            return [$this->valorBooleano(false, $lang), null, null];
        }

        return [
            $this->valorBooleano($detalle->labora, $lang),
            $detalle->hora_entrada ? substr($detalle->hora_entrada, 0, 5) : null,
            $detalle->hora_salida ? substr($detalle->hora_salida, 0, 5) : null,
        ];
    }
    private function aplicarValidacionLista($sheet, string $range, array $values): void
    {
        foreach ($sheet->rangeToArray($range, null, true, true, true) as $rowIndex => $columns) {
            foreach ($columns as $column => $value) {
                $cell = "{$column}{$rowIndex}";

                $validation = $sheet->getCell($cell)->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_STOP);
                $validation->setAllowBlank(true);
                $validation->setShowInputMessage(true);
                $validation->setShowErrorMessage(true);
                $validation->setShowDropDown(true);
                $validation->setFormula1('"' . implode(',', $values) . '"');
            }
        }
    }
    private function aplicarValidacionRango($sheet, string $range, string $formulaRange): void
    {
        foreach ($sheet->rangeToArray($range, null, true, true, true) as $rowIndex => $columns) {
            foreach ($columns as $column => $value) {
                $cell = "{$column}{$rowIndex}";

                $validation = $sheet->getCell($cell)->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_STOP);
                $validation->setAllowBlank(false);
                $validation->setShowInputMessage(true);
                $validation->setShowErrorMessage(true);
                $validation->setShowDropDown(true);
                $validation->setFormula1($formulaRange);
            }
        }
    }
    private function headersHorarios(string $lang): array
    {
        $lang = strtolower($lang);

        if ($lang === 'en') {
            return [
                'Code',
                'Name',
                'Description',
                'Time zone',
                'Entry tolerance (min)',
                'Exit tolerance (min)',
                'Allows break',
                'Active',

                'Monday works',
                'Monday entry',
                'Monday exit',

                'Tuesday works',
                'Tuesday entry',
                'Tuesday exit',

                'Wednesday works',
                'Wednesday entry',
                'Wednesday exit',

                'Thursday works',
                'Thursday entry',
                'Thursday exit',

                'Friday works',
                'Friday entry',
                'Friday exit',

                'Saturday works',
                'Saturday entry',
                'Saturday exit',

                'Sunday works',
                'Sunday entry',
                'Sunday exit',
            ];
        }

        return [
            'Código',
            'Nombre',
            'Descripción',
            'Zona horaria',
            'Tolerancia entrada (min)',
            'Tolerancia salida (min)',
            'Permite descanso',
            'Activo',

            'Lunes labora',
            'Lunes entrada',
            'Lunes salida',

            'Martes labora',
            'Martes entrada',
            'Martes salida',

            'Miércoles labora',
            'Miércoles entrada',
            'Miércoles salida',

            'Jueves labora',
            'Jueves entrada',
            'Jueves salida',

            'Viernes labora',
            'Viernes entrada',
            'Viernes salida',

            'Sábado labora',
            'Sábado entrada',
            'Sábado salida',

            'Domingo labora',
            'Domingo entrada',
            'Domingo salida',
        ];
    }
    private function obtenerTimezones(): array
    {
        $lang = request()->input('lang', 'es');

        $campo = strtolower($lang) === 'en'
            ? 'label_en'
            : 'label_es';

        return DB::connection('portal_main')
            ->table('checador_timezones')
            ->where('activo', 1)
            ->orderBy('orden')
            ->pluck($campo)
            ->toArray();
    }
    private function configurarAnchosColumnas($sheet): void
    {
        $widths = [

                        // Información general
            'A'  => 18, // Código
            'B'  => 30, // Nombre
            'C'  => 40, // Descripción
            'D'  => 38, // Zona horaria
            'E'  => 18, // Tol. Entrada
            'F'  => 18, // Tol. Salida
            'G'  => 18, // Descanso
            'H'  => 12, // Activo

            // Lunes
            'I'  => 11,
            'J'  => 11,
            'K'  => 11,

            // Martes
            'L'  => 11,
            'M'  => 11,
            'N'  => 11,

            // Miércoles
            'O'  => 11,
            'P'  => 11,
            'Q'  => 11,

            // Jueves
            'R'  => 11,
            'S'  => 11,
            'T'  => 11,

            // Viernes
            'U'  => 11,
            'V'  => 11,
            'W'  => 11,

            // Sábado
            'X'  => 11,
            'Y'  => 11,
            'Z'  => 11,

            // Domingo
            'AA' => 11,
            'AB' => 11,
            'AC' => 11,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }
    private function valorBooleano($value, string $lang): string
    {
        $activo = (bool) $value;
        $lang   = strtolower($lang);

        if ($lang === 'en') {
            return $activo ? 'Yes' : 'No';
        }

        return $activo ? 'Sí' : 'No';
    }
    private function opcionesBooleanas(string $lang): array
    {
        return strtolower($lang) === 'en'
            ? ['Yes', 'No']
            : ['Sí', 'No'];
    }
    private function labelTimezone(string $codigo, string $lang): string
    {
        $registro = DB::connection('portal_main')
            ->table('checador_timezones')
            ->where('codigo', $codigo)
            ->first();

        if (! $registro) {
            return $codigo;
        }

        return strtolower($lang) === 'en'
            ? $registro->label_en
            : $registro->label_es;
    }
    private function crearHojaCatalogos(Spreadsheet $spreadsheet, array $timezones): void
    {
        $catalogSheet = $spreadsheet->createSheet();
        $catalogSheet->setTitle('Catalogos');

        $row = 1;

        foreach ($timezones as $timezone) {
            $catalogSheet->setCellValue("A{$row}", $timezone);
            $row++;
        }

        $catalogSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
    }

    public function importarHorarios(Request $request)
    {
        $service = new HorariosImportService();

        return response()->json(
            $service->importar($request)
        );
    }
}
