<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();
            $table->timestamps();
        });

        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->char('queue', 1);
            $table->unsignedSmallInteger('number');
            $table->string('ticket_code', 4);
            $table->unsignedTinyInteger('size');
            $table->string('name', 30);
            $table->string('phone', 10);
            $table->string('channel', 10);
            $table->uuid('token')->unique();
            $table->string('status', 12)->default('waiting');
            $table->double('position_key');
            $table->unsignedTinyInteger('skip_count')->default(0);
            $table->unsignedTinyInteger('no_show_count')->default(0);
            $table->unsignedTinyInteger('requeue_count')->default(0);
            $table->dateTime('issued_at');
            $table->dateTime('called_at')->nullable();
            $table->dateTime('seated_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedSmallInteger('est_low_at_issue')->nullable();
            $table->unsignedSmallInteger('est_high_at_issue')->nullable();
            $table->unsignedSmallInteger('est_low')->nullable();
            $table->unsignedSmallInteger('est_high')->nullable();
            $table->timestamps();

            $table->unique(['business_date', 'ticket_code']);
            $table->index(['business_date', 'status']);
        });

        // 同一支手機在同一營業日只能有一筆有效（等候中、已叫號）的號碼，由資料庫保證
        DB::statement("CREATE UNIQUE INDEX parties_active_phone_unique ON parties (business_date, phone) WHERE status IN ('waiting', 'called')");

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->unsignedTinyInteger('capacity');
            $table->string('area', 10);
            $table->string('status', 10)->default('available');
            $table->foreignId('held_for_party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('hold_reason', 10)->nullable();
            $table->dateTime('status_changed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('table_adjacencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_a_id')->constrained('dining_tables')->cascadeOnDelete();
            $table->foreignId('table_b_id')->constrained('dining_tables')->cascadeOnDelete();
            $table->unique(['table_a_id', 'table_b_id']);
        });

        Schema::create('ticket_counters', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');
            $table->char('queue', 1);
            $table->unsignedSmallInteger('last_number')->default(0);
            $table->unique(['business_date', 'queue']);
        });

        Schema::create('seatings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('business_date')->index();
            $table->dateTime('seated_at');
            $table->dateTime('left_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('seating_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seating_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('dining_tables')->cascadeOnDelete();
            $table->dateTime('released_at')->nullable();
            $table->index('table_id');
        });

        // 同一張桌子同時只能屬於一筆進行中的入座，由資料庫保證
        DB::statement('CREATE UNIQUE INDEX seating_tables_active_unique ON seating_tables (table_id) WHERE released_at IS NULL');

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('message');
            $table->dateTime('created_at');
            $table->unique(['party_id', 'type']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at')->index();
            $table->string('actor_type', 10);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30)->index();
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12)->nullable();
            $table->json('meta')->nullable();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach (['activity_logs', 'notification_logs', 'seating_tables', 'seatings', 'ticket_counters', 'table_adjacencies', 'dining_tables', 'parties', 'devices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
