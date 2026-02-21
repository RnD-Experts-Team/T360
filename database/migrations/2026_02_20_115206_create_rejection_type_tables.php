<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRejectionTypeTables extends Migration
{
    public function up()
    {
        // Create the table for Advanced Block Rejections
        Schema::create('advanced_rejected_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rejection_id')->constrained('rejections')->onDelete('cascade');
            $table->string('advance_block_rejection_id')->unique()->comment('Unique identifier for the advanced block rejection');
            $table->dateTime('week_start');
            $table->dateTime('week_end');
            $table->integer('impacted_blocks');
            $table->integer('expected_blocks');
            $table->timestamps();
        });

        // Create the table for Rejected Blocks
        Schema::create('rejected_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rejection_id')->constrained('rejections')->onDelete('cascade');
            $table->string('block_id')->comment('Unique identifier for the rejected block');
            $table->string('driver_name')->nullable();
            $table->dateTime('block_start');
            $table->dateTime('block_end');
            $table->dateTime('rejection_datetime')->nullable();
            $table->enum('rejection_bucket', ['less_than_24', 'more_than_24'])->nullable();
            $table->timestamps();
        });

        // Create the table for Rejected Loads
        Schema::create('rejected_loads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rejection_id')->constrained('rejections')->onDelete('cascade');
            $table->string('load_id')->comment('Unique identifier for the rejected load');
            $table->string('driver_name')->nullable();
            $table->dateTime('origin_yard_arrival');
            $table->enum('rejection_bucket', ['rejected_after_start_time', 'rejected_0_6_hours_before_start_time', 'rejected_6_plus_hours_before_start_time'])->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        // Drop the specific rejection type tables
        Schema::dropIfExists('advanced_rejected_blocks');
        Schema::dropIfExists('rejected_blocks');
        Schema::dropIfExists('rejected_loads');
    }
}
