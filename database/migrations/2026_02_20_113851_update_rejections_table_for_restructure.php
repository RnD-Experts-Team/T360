<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateRejectionsTableForRestructure extends Migration
{
    public function up()
    {
        // Update the 'rejections' table to remove columns and add new ones
        Schema::table('rejections', function (Blueprint $table) {
            // Drop the 'rejection_type' column
            $table->dropColumn('rejection_type');

            // Remove 'driver_name' and 'rejection_category' columns
            $table->dropColumn(['driver_name', 'rejection_category']);
            $table->dropColumn('disputed');
            // Update 'disputed' to be a nullable enum
            $table->enum('disputed', ['none', 'pending', 'won', 'lost'])->nullable()->default('none')->after('penalty');

            // Add new columns: 'carrier_controllable', 'driver_controllable', and 'rejection_reason'
            $table->boolean('carrier_controllable')->default(true)->after('disputed')->comment('Indicates if the rejection is carrier controllable');
            $table->dropColumn('driver_controllable');
            $table->dropForeign('rejections_reason_code_id_foreign');

            $table->dropCOlumn('reason_code_id');
            $table->boolean('driver_controllable')->default(true)->after('carrier_controllable')->comment('Indicates if the rejection is driver controllable');
            $table->string('rejection_reason')->nullable()->after('driver_controllable')->comment('Reason for rejection');
        });

        Schema::dropIfExists('rejection_reason_codes');
    }

    public function down()
    {
        // Rollback changes in the 'rejections' table
        Schema::table('rejections', function (Blueprint $table) {
            // Revert 'disputed' to a boolean
            $table->boolean('disputed')->nullable()->default(false)->after('penalty')->comment('Indicates if the rejection is disputed');

            // Revert 'driver_name', 'rejection_category', and 'rejection_type' back
            $table->string('driver_name', 75)->nullable()->after('tenant_id')->comment('Driver name');
            $table->enum('rejection_category', ['more_than_6', 'within_6', 'after_start'])->after('rejection_type')->comment('Rejection time category');
            $table->enum('rejection_type', ['block', 'load', 'advanced_block'])->after('tenant_id')->comment('Type of rejection');

            // Drop newly added columns
            $table->dropColumn(['carrier_controllable', 'driver_controllable', 'rejection_reason']);
        });

        Schema::create('rejection_reason_codes', function (Blueprint $table) {
            $table->id();
            $table->string('reason_code', 75)->unique()->comment('Unique rejection reason code');
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
