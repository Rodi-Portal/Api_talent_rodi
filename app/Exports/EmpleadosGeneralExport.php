<?php
namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class EmpleadosGeneralExport implements FromCollection, WithHeadings, WithStyles, WithEvents
{
    protected array $camposExtra = [];

    public function __construct(
        protected $empleados,
        protected array $deps,
        protected array $puestos
    ) {
        // Unificar nombres únicos de "campos extra"
        $uni = [];
        foreach ($empleados as $e) {
            foreach ($e->camposExtra ?? [] as $cx) {
                $nombre = trim((string)($cx->nombre ?? ''));
                if ($nombre !== '') $uni[$nombre] = true;
            }
        }
        $this->camposExtra = array_keys($uni);
        sort($this->camposExtra, SORT_NATURAL | SORT_FLAG_CASE);

        // Sanea catálogos y antepone "Otro"
        $this->deps    = $this->ensureOtroFirst($this->sanitizeList($this->deps));
        $this->puestos = $this->ensureOtroFirst($this->sanitizeList($this->puestos));
    }

    public function headings(): array
    {
        $fixed = [
            // Contexto (se ocultarán)
            'id_portal',
            'id_cliente',

            // Identificación
            'ID', 'ID Empleado',
            'Nombre', 'Paterno', 'Materno', 'Teléfono', 'Correo', 'RFC', 'CURP', 'NSS',

            // Catálogos: Selección + Otro (texto)
            'Departamento (Selección)',
            'Departamento (Otro)',

            'Puesto (Selección)',
            'Puesto (Otro)',

            // Fechas
            'Fecha Nacimiento', 'Fecha Ingreso',

            // Domicilio
            'Pais', 'Estado', 'Ciudad', 'Colonia', 'Calle', 'Num Int', 'Num Ext', 'CP',
        ];

        return array_merge($fixed, $this->camposExtra);
    }

    public function collection()
    {
        $rows = [];
        foreach ($this->empleados as $e) {
            $depNombre    = $e->depto->nombre     ?? ($e->departamento ?? '');
            $puestoNombre = $e->puestoRel->nombre ?? ($e->puesto ?? '');

            // Si coincide → Selección; si no → "Otro" + texto
            [$depSelect, $depOtro] = $this->splitSelectOtro($depNombre, $this->deps);
            [$ptoSelect, $ptoOtro] = $this->splitSelectOtro($puestoNombre, $this->puestos);

            $row = [
                (int)($e->id_portal  ?? 0),
                (int)($e->id_cliente ?? 0),

                $e->id,
                $e->id_empleado,
                $e->nombre,
                $e->paterno,
                $e->materno,
                $e->telefono,
                $e->correo,
                $e->rfc,
                $e->curp,
                $e->nss,

                $depSelect,
                $depOtro,

                $ptoSelect,
                $ptoOtro,

                $this->asDate($e->fecha_nacimiento),
                $this->asDate($e->fecha_ingreso),

                $e->domicilioEmpleado->pais    ?? '',
                $e->domicilioEmpleado->estado  ?? '',
                $e->domicilioEmpleado->ciudad  ?? '',
                $e->domicilioEmpleado->colonia ?? '',
                $e->domicilioEmpleado->calle   ?? '',
                $e->domicilioEmpleado->num_int ?? '',
                $e->domicilioEmpleado->num_ext ?? '',
                $e->domicilioEmpleado->cp      ?? '',
            ];

            // Extras dinámicos
            $map = [];
            foreach ($e->camposExtra ?? [] as $cx) {
                $n = trim((string)($cx->nombre ?? ''));
                if ($n !== '') $map[$n] = $cx->valor ?? '';
            }
            foreach ($this->camposExtra as $n) $row[] = $map[$n] ?? '';

            $rows[] = $row;
        }

        return new Collection($rows);
    }

    private function splitSelectOtro(?string $valor, array $catalogo): array
    {
        $v = trim((string)($valor ?? ''));
        if ($v === '' || !in_array($v, $catalogo, true)) {
            return ['Otro', $v];
        }
        return [$v, ''];
    }

    private function asDate($v): string
    {
        if (empty($v)) return '';
        if ($v instanceof \Carbon\CarbonInterface) return $v->toDateString();
        try { return Carbon::parse($v)->toDateString(); }
        catch (\Throwable) { return substr((string)$v, 0, 10); }
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 12],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0070C0']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                    'wrapText'   => true,
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Autosize
                $maxColIdx = Coordinate::columnIndexFromString($sheet->getHighestColumn());
                for ($c = 1; $c <= $maxColIdx; $c++) {
                    $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
                }

                // Cabeceras y helper
                $headers = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1')[0];
                $colByHeader = function (string $name) use ($headers) {
                    foreach ($headers as $idx => $h) {
                        if (trim((string)$h) === $name) {
                            return Coordinate::stringFromColumnIndex($idx + 1);
                        }
                    }
                    return null;
                };

                // Oculta columnas de contexto
                foreach ([
                    $colByHeader('id_portal'),
                    $colByHeader('id_cliente'),
                    $colByHeader('ID'),
                ] as $col) {
                    if ($col) $sheet->getColumnDimension($col)->setVisible(false);
                }

                // Congelar hasta DESPUÉS de “Materno” (fallback: después de “ID Empleado”)
                $colMaterno = $colByHeader('Materno');
                if ($colMaterno) {
                    $idxMaterno = Coordinate::columnIndexFromString($colMaterno);
                    $sheet->freezePane(Coordinate::stringFromColumnIndex($idxMaterno + 1) . '2');
                } else {
                    $colIdEmp = $colByHeader('ID Empleado');
                    if ($colIdEmp) {
                        $idx = Coordinate::columnIndexFromString($colIdEmp);
                        $sheet->freezePane(Coordinate::stringFromColumnIndex($idx + 1) . '2');
                    } else {
                        $sheet->freezePane('E2');
                    }
                }

                // === 1) Esquinas ocultas para listas (misma hoja) ===
                // (Escribe primero, luego calcula los rangos)
                $currentMaxColIdx = Coordinate::columnIndexFromString($sheet->getHighestColumn());
                $depColHiddenIdx  = $currentMaxColIdx + 1;
                $ptoColHiddenIdx  = $currentMaxColIdx + 2;
                $depColHiddenLet  = Coordinate::stringFromColumnIndex($depColHiddenIdx);
                $ptoColHiddenLet  = Coordinate::stringFromColumnIndex($ptoColHiddenIdx);

                $sheet->setCellValue("{$depColHiddenLet}1", '_deps_');
                $sheet->setCellValue("{$ptoColHiddenLet}1", '_puestos_');

                // Escribir listas saneadas como TEXTO
                $depStartRow = 2; $depEndRow = 1;
                $r = 2;
                foreach ($this->deps as $d) {
                    $sheet->setCellValueExplicit("{$depColHiddenLet}{$r}", $d, DataType::TYPE_STRING);
                    $depEndRow = $r;
                    $r++;
                }

                $ptoStartRow = 2; $ptoEndRow = 1;
                $r = 2;
                foreach ($this->puestos as $p) {
                    $sheet->setCellValueExplicit("{$ptoColHiddenLet}{$r}", $p, DataType::TYPE_STRING);
                    $ptoEndRow = $r;
                    $r++;
                }

                // Oculta columnas de catálogos
                $sheet->getColumnDimension($depColHiddenLet)->setVisible(false);
                $sheet->getColumnDimension($ptoColHiddenLet)->setVisible(false);

                // === 2) Validación con RANGO ABSOLUTO + NOMBRE DE HOJA ===
                $sheetTitle = $sheet->getTitle();
                // escapado simple por si hay comillas simples en el título
                $sheetTitleEsc = str_replace("'", "''", $sheetTitle);

                $depHasData = $depEndRow >= $depStartRow;
                $ptoHasData = $ptoEndRow >= $ptoStartRow;

                $depFormula = $depHasData
                    ? "='{$sheetTitleEsc}'!\${$depColHiddenLet}\${$depStartRow}:\${$depColHiddenLet}\${$depEndRow}"
                    : null;

                $ptoFormula = $ptoHasData
                    ? "='{$sheetTitleEsc}'!\${$ptoColHiddenLet}\${$ptoStartRow}:\${$ptoColHiddenLet}\${$ptoEndRow}"
                    : null;

                // Columnas visibles de selección
                $depSelCol = $colByHeader('Departamento (Selección)');
                $ptoSelCol = $colByHeader('Puesto (Selección)');
                $highestRow = $sheet->getHighestRow();

                if ($depSelCol && $depFormula) {
                    $this->applyDropdown($sheet, $depSelCol, 2, $highestRow, $depFormula);
                }
                if ($ptoSelCol && $ptoFormula) {
                    $this->applyDropdown($sheet, $ptoSelCol, 2, $highestRow, $ptoFormula);
                }

                // RFC/CURP/CP como texto
                foreach (['RFC', 'CURP', 'CP'] as $hdr) {
                    $col = $colByHeader($hdr);
                    if ($col) {
                        $sheet->getStyle($col . '2:' . $col . $highestRow)
                              ->getNumberFormat()
                              ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
                    }
                }
            },
        ];
    }

    // ========= Helpers =========

    private function applyDropdown(Worksheet $sheet, string $colLetter, int $startRow, int $endRow, string $listFormula): void
    {
        for ($row = $startRow; $row <= $endRow; $row++) {
            $cell = "{$colLetter}{$row}";
            $v = new DataValidation();
            $v->setType(DataValidation::TYPE_LIST);
            $v->setErrorStyle(DataValidation::STYLE_STOP);
            $v->setAllowBlank(true);
            $v->setShowInputMessage(true);
            $v->setShowErrorMessage(true);
            $v->setShowDropDown(true);
            $v->setErrorTitle('Valor inválido');
            $v->setError('Seleccione un valor de la lista');
            $v->setPromptTitle('Seleccione');
            $v->setPrompt('Elija un valor del listado o “Otro”.');
            $v->setFormula1($listFormula);
            $sheet->getCell($cell)->setDataValidation($v);
        }
    }

    private function sanitizeList(array $items): array
    {
        $out = [];
        foreach ($items as $v) {
            if (!is_string($v) && !is_numeric($v)) continue;
            $s = trim((string)$v);
            if ($s === '' || $s === '--' || strtoupper($s) === 'N/A') continue;
            $out[] = $s;
        }
        // Unicidad case-insensitive
        $seen = [];
        $final = [];
        foreach ($out as $s) {
            $key = mb_strtolower($s, 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $final[] = $s;
        }
        sort($final, SORT_NATURAL | SORT_FLAG_CASE);
        return $final;
    }

    private function ensureOtroFirst(array $items): array
    {
        $hasOtro = false;
        foreach ($items as $it) {
            if (mb_strtolower($it, 'UTF-8') === 'otro') { $hasOtro = true; break; }
        }
        if (!$hasOtro) array_unshift($items, 'Otro');
        return $items;
    }
}
