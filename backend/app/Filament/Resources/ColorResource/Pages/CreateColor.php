<?php

declare(strict_types=1);

namespace App\Filament\Resources\ColorResource\Pages;

use App\Filament\Resources\ColorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateColor extends CreateRecord
{
    protected static string $resource = ColorResource::class;
}
