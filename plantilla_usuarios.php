<?php

declare(strict_types=1);

require_once __DIR__ . '/config/rol.php';

exigirRol('admin');

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$excel = new Spreadsheet();

$excel->getProperties()
    ->setCreator('AulaIA')
    ->setTitle('Plantilla de registro de usuarios');

$hojas = [
    'Estudiantes' => [
        'RUT',
        'Nombre',
        'Apellido',
        'Usuario',
        'Contraseña',
        'Curso'
    ],

    'Apoderados' => [
        'RUT',
        'Nombre',
        'Apellido',
        'Usuario',
        'Contraseña',
        'RUT estudiante'
    ],

    'Profesores' => [
        'RUT',
        'Nombre',
        'Apellido',
        'Usuario',
        'Contraseña',
        'Curso',
        'Asignatura'
    ]
];

foreach ($hojas as $nombre => $columnas) {

    $hoja = $nombre === 'Estudiantes'
        ? $excel->getActiveSheet()
        : $excel->createSheet();

    $hoja->setTitle($nombre);

    $hoja->fromArray($columnas, null, 'A1');

    $ultimaColumna = Coordinate::stringFromColumnIndex(
        count($columnas)
    );

    $rango = 'A1:' . $ultimaColumna . '1';

    $hoja->getStyle($rango)->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
            'size' => 11
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '0B192E']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ]);

    $hoja->getRowDimension(1)->setRowHeight(30);

    $hoja->freezePane('A2');

    $hoja->setAutoFilter($rango);

    $hoja->getSheetView()->setZoomScale(95);

    $hoja->getTabColor()->setRGB('2563EB');

    foreach ($columnas as $indice => $columna) {

        $letra = Coordinate::stringFromColumnIndex(
            $indice + 1
        );

        $ancho = match ($columna) {
            'RUT', 'RUT estudiante' => 22,
            'Contraseña' => 24,
            'Asignatura' => 30,
            'Curso' => 24,
            default => 23
        };

        $hoja->getColumnDimension($letra)
            ->setWidth($ancho);
    }

    // Evitar conversiones automáticas de Excel.

    $hoja->getStyle('A2:' . $ultimaColumna . '1001')
        ->getNumberFormat()
        ->setFormatCode('@');
}

$excel->setActiveSheetIndex(0);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

header('Content-Disposition: attachment; filename="AulaIA_Plantilla_Usuarios.xlsx"');

header('Cache-Control: no-store, private');

$writer = new Xlsx($excel);

$writer->save('php://output');

$excel->disconnectWorksheets();

exit;
