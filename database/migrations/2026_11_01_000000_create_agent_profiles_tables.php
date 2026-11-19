<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $profiles = config('agent-profiles.tables.profiles', 'agent_profiles');
        $versions = config('agent-profiles.tables.versions', 'agent_profile_versions');

        Schema::create($profiles, function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 220)->unique();
            $table->string('owner_type');
            $table->string('owner_id', 64);
            $table->string('scope_key', 100)->default('default');
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('active_version_id')->nullable()->index();
            $table->json('definition');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id', 'scope_key']);
        });

        Schema::create($versions, function (Blueprint $table) use ($profiles) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('agent_profile_id')->constrained($profiles)->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedInteger('source_revision');
            $table->json('content');
            $table->string('content_hash', 71);
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->string('revoked_by_type')->nullable();
            $table->string('revoked_by_id', 64)->nullable();
            $table->string('published_by_type')->nullable();
            $table->string('published_by_id', 64)->nullable();
            $table->timestamp('published_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['agent_profile_id', 'number']);
            $table->unique(['agent_profile_id', 'content_hash']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists(config('agent-profiles.tables.versions', 'agent_profile_versions'));
        Schema::dropIfExists(config('agent-profiles.tables.profiles', 'agent_profiles'));
    }
};
