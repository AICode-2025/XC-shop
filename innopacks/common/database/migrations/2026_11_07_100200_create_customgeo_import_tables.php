<?php

/**
 * zwxc-custom: 导入运行记录与错误日志表（支撑断点续导、进度查询与失败排查）。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_import_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source', 32)->default('allCountries')->comment('Data set name');
            $table->string('mode', 16)->default('slim')->comment('full / slim');
            $table->string('status', 16)->default('running')->comment('running / completed / failed');
            $table->unsignedBigInteger('checkpoint_line')->default(0)->comment('Resume checkpoint (1-based line no)');
            $table->unsignedBigInteger('admin1_count')->default(0);
            $table->unsignedBigInteger('admin2_count')->default(0);
            $table->unsignedBigInteger('city_count')->default(0);
            $table->unsignedBigInteger('skipped_count')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);
            $table->text('error_message')->nullable();
            $table->text('options')->nullable()->comment('JSON options');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'mode', 'status'], 'geo_import_runs_resume_index');
        });

        Schema::create('geo_import_errors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('run_id')->index('geo_import_errors_run_index');
            $table->unsignedBigInteger('line_no')->default(0);
            $table->unsignedBigInteger('geoname_id')->nullable();
            $table->string('message', 500)->comment('Error message');
            $table->text('payload')->nullable()->comment('Raw line (truncated)');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_import_errors');
        Schema::dropIfExists('geo_import_runs');
    }
};
