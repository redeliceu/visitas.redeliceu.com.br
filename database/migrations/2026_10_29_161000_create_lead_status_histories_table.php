<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('lead_status')->restrictOnDelete();
            $table->foreignId('to_status_id')->nullable()->constrained('lead_status')->restrictOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('changed_at');
            $table->index(['lead_id', 'changed_at']);
        });

        $baselineAt = now();

        DB::table('leads')
            ->whereNotNull('status_id')
            ->orderBy('id')
            ->chunk(500, function ($leads) use ($baselineAt): void {
                $histories = $leads->map(fn (object $lead): array => [
                    'lead_id' => $lead->id,
                    'from_status_id' => null,
                    'to_status_id' => $lead->status_id,
                    'changed_by' => null,
                    'changed_at' => $baselineAt,
                ])->all();

                DB::table('lead_status_histories')->insert($histories);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_status_histories');
    }
};
