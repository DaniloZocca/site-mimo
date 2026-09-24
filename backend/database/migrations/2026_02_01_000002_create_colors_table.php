<?php

declare(strict_types=1);

use App\Enums\MaterialType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('hex_code', 7);
            $table->string('material_type', 30)->default(MaterialType::PLA->value)->index();
            $table->boolean('in_stock')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colors');
    }
};
