<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum MaterialType: string implements HasLabel, HasColor, HasDescription
{
    case PLA = 'pla';
    case PETG = 'petg';
    case ABS = 'abs';
    case TPU = 'tpu';

    public function label(): string
    {
        return match ($this) {
            self::PLA => 'PLA Premium (Atóxico e Biodegradável)',
            self::PETG => 'PETG Resistente (Uso Externo & Térmico)',
            self::ABS => 'ABS Industrial (Alta Resistência Mecânica)',
            self::TPU => 'TPU Flexível (Emborrachado & Maleável)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::PLA => 'PLA',
            self::PETG => 'PETG',
            self::ABS => 'ABS',
            self::TPU => 'TPU Flexível',
        };
    }

    public function technicalDescription(): string
    {
        return match ($this) {
            self::PLA => 'Polímero de origem vegetal (amido de milho). Ideal para decoração, vasos e action figures.',
            self::PETG => 'Alta resistência ao impacto e raios UV. Ideal para peças funcionais, suportes e uso automotivo.',
            self::ABS => 'Resiste a temperaturas de até 90°C. Acabamento rígido e excelente durabilidade mecânica.',
            self::TPU => 'Filamento elastômero flexível. Resistente a torção, impactos e absorção de choque.',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getDescription(): string
    {
        return $this->technicalDescription();
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PLA => 'success',
            self::PETG => 'info',
            self::ABS => 'warning',
            self::TPU => 'primary',
        };
    }
}
