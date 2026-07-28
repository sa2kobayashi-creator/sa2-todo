<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('visa_type', 32)->default('13A');
            $table->date('rp_expires_on')->nullable();
            $table->unsignedTinyInteger('rp_duration_months')->default(6);
            $table->unsignedSmallInteger('annual_report_done_year')->nullable();
            $table->unsignedInteger('budget_max_jpy')->default(60000);
            $table->string('preferred_currency', 3)->default('PHP');
            $table->string('home_airport', 8)->default('FUK');
            $table->string('ph_airport', 8)->default('MNL');
            $table->string('airline_code', 8)->default('5J');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('travel_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 32)->default('other'); // annual_report | rp_renewal | other
            $table->string('label', 120)->nullable();
            $table->date('depart_on'); // FUK -> MNL 側（日本発）
            $table->date('return_on')->nullable(); // MNL -> FUK
            $table->string('origin', 8)->default('FUK');
            $table->string('destination', 8)->default('MNL');
            $table->string('airline_code', 8)->default('5J');
            $table->string('status', 16)->default('planned'); // planned | booked | done | cancelled
            $table->string('prefer_currency', 3)->default('PHP');
            $table->string('booked_as', 16)->nullable(); // rt | ow_pair
            $table->unsignedInteger('rt_price_php')->nullable();
            $table->unsignedInteger('ow_out_price_php')->nullable();
            $table->unsignedInteger('ow_back_price_php')->nullable();
            $table->unsignedInteger('rt_price_jpy')->nullable();
            $table->unsignedInteger('ow_out_price_jpy')->nullable();
            $table->unsignedInteger('ow_back_price_jpy')->nullable();
            $table->boolean('out_booked_in_php')->default(false);
            $table->boolean('back_booked_in_php')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'depart_on']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_trips');
        Schema::dropIfExists('travel_profiles');
    }
};
