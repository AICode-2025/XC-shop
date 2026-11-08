<?php

/**
 * zwxc-custom: 新建 GeoNames 二级行政区与城市表（独立表，避免改动原生 states 的语义）。
 *
 * - geo_admin2：admin2 级别行政区（不进入地址下拉，仅做归属与时区补充）；
 * - geo_cities：城市候选，供地址表单输入建议使用（原生 addresses.city 仍是文本，不受影响）。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_admin2', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('geoname_id')->unique('geo_admin2_geoname_unique')->comment('GeoNames ID');
            $table->unsignedInteger('country_id')->default(0)->comment('countries.id');
            $table->string('country_code', 2)->comment('ISO alpha-2');
            $table->unsignedInteger('state_id')->nullable()->comment('states.id (admin1)');
            $table->string('admin1_code', 16)->default('')->comment('GeoNames admin1 code');
            $table->string('code', 16)->default('')->comment('GeoNames admin2 code');
            $table->string('name', 191)->comment('ASCII/English name');
            $table->string('name_local', 191)->nullable()->comment('Local name');
            $table->string('timezone', 64)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->bigInteger('population')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['country_code', 'admin1_code', 'code'], 'geo_admin2_lookup_index');
        });

        Schema::create('geo_cities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('geoname_id')->unique('geo_cities_geoname_unique')->comment('GeoNames ID');
            $table->unsignedInteger('country_id')->default(0)->comment('countries.id');
            $table->string('country_code', 2)->comment('ISO alpha-2');
            $table->unsignedInteger('state_id')->nullable()->comment('states.id (admin1)');
            $table->string('admin1_code', 16)->default('')->comment('GeoNames admin1 code');
            $table->string('admin2_code', 16)->default('')->comment('GeoNames admin2 code');
            $table->string('name', 191)->comment('ASCII/English name');
            $table->string('name_local', 191)->nullable()->comment('Local name');
            $table->string('timezone', 64)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->bigInteger('population')->default(0);
            $table->string('feature_code', 10)->default('')->comment('GeoNames feature code');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['country_code', 'admin1_code'], 'geo_cities_country_admin1_index');
            $table->index(['country_code', 'name'], 'geo_cities_country_name_index');
            $table->index('population', 'geo_cities_population_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_cities');
        Schema::dropIfExists('geo_admin2');
    }
};
