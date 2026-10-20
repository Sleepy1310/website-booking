<?php

function aircraft_seat_row_presets(): array
{
    return [
        'Airbus A320' => ['economy_rows' => 10, 'business_rows' => 2],
        'Airbus A321' => ['economy_rows' => 10, 'business_rows' => 2],
        'Boeing 787-9' => ['economy_rows' => 14, 'business_rows' => 3],
    ];
}

function aircraft_seat_row_preset(string $aircraft): array
{
    foreach (aircraft_seat_row_presets() as $model => $preset) {
        if (strcasecmp(trim($aircraft), $model) === 0) {
            return $preset;
        }
    }

    return ['economy_rows' => 10, 'business_rows' => 2];
}
