<?php

/**
 * zwxc-custom: 扩展原生 countries / states 表，补充 GeoNames 地理字段。
 *
 * 约束：只新增可空字段，不删除、不重命名原生字段，原生代码无需改动即可继续运行。
 * 回滚：down() 仅删除本迁移新增的列与索引。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->unsignedBigInteger('geoname_id')->nullable()->comment('GeoNames ID');
            $table->char('iso_code_3', 3)->nullable()->comment('ISO 3166-1 alpha-3');
            $table->string('name_local', 128)->nullable()->comment('Local name');
            $table->string('currency_code', 3)->nullable()->comment('ISO 4217 currency');
            $table->string('phone_code', 16)->nullable()->comment('Phone code');
            $table->string('languages', 128)->nullable()->comment('Official languages from GeoNames');
            $table->string('timezone', 64)->nullable()->comment('Primary timezone');
            $table->decimal('latitude', 10, 7)->nullable()->comment('Centroid latitude');
            $table->decimal('longitude', 10, 7)->nullable()->comment('Centroid longitude');
            $table->bigInteger('population')->nullable()->comment('Population');
            $table->string('geo_source', 16)->nullable()->comment('Data source, e.g. geonames');
            $table->timestamp('geo_updated_at')->nullable()->comment('Geo data updated at');

            $table->index('geoname_id', 'countries_geoname_id_index');
            $table->index('iso_code_3', 'countries_iso3_index');
        });

        Schema::table('states', function (Blueprint $table) {
            $table->unsignedBigInteger('geoname_id')->nullable()->comment('GeoNames ID');
            $table->string('name_local', 128)->nullable()->comment('Local name');
            $table->string('admin1_code', 16)->nullable()->comment('GeoNames admin1 code');
            $table->tinyInteger('level')->default(1)->comment('1=admin1 division');
            $table->string('timezone', 64)->nullable()->comment('Timezone');
            $table->decimal('latitude', 10, 7)->nullable()->comment('Latitude');
            $table->decimal('longitude', 10, 7)->nullable()->comment('Longitude');
            $table->string('geo_source', 16)->nullable()->comment('Data source, e.g. geonames');
            $table->timestamp('geo_updated_at')->nullable()->comment('Geo data updated at');

            $table->index('geoname_id', 'states_geoname_id_index');
            $table->index(['country_code', 'code'], 'states_country_code_code_index');
        });
    }

    public function down(): void
    {
        Schema::table('states', function (Blueprint $table) {
            $table->dropIndex('states_geoname_id_index');
            $table->dropIndex('states_country_code_code_index');
            $table->dropColumn([
                'geoname_id', 'name_local', 'admin1_code', 'level', 'timezone',
                'latitude', 'longitude', 'geo_source', 'geo_updated_at',
            ]);
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->dropIndex('countries_geoname_id_index');
            $table->dropIndex('countries_iso3_index');
            $table->dropColumn([
                'geoname_id', 'iso_code_3', 'name_local', 'currency_code', 'phone_code',
                'languages', 'timezone', 'latitude', 'longitude', 'population',
                'geo_source', 'geo_updated_at',
            ]);
        });
    }
};
