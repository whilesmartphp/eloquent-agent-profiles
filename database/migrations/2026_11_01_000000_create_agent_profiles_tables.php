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
        $states = config('agent-profiles.tables.version_states', 'agent_profile_version_states');

        Schema::create($profiles, function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('owner_type');
            $table->string('owner_id', 64);
            $table->string('scope_key', 100)->default('default');
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('draft_revision')->default(1);
            $table->json('draft_definition');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id', 'scope_key']);
        });

        Schema::create($versions, function (Blueprint $table) use ($profiles) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('agent_profile_id')->constrained($profiles)->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedInteger('source_revision');
            $table->json('definition');
            $table->json('skills');
            $table->json('manifest');
            $table->string('manifest_hash', 71);
            $table->longText('snapshots')->nullable();
            $table->string('published_by_type')->nullable();
            $table->string('published_by_id', 64)->nullable();
            $table->timestamp('published_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['agent_profile_id', 'number']);
        });

        Schema::create($states, function (Blueprint $table) use ($profiles, $versions) {
            $table->id();
            $table->foreignId('agent_profile_version_id')->unique()->constrained($versions)->cascadeOnDelete();
            $table->foreignId('agent_profile_id')->constrained($profiles)->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->string('changed_by_type')->nullable();
            $table->string('changed_by_id', 64)->nullable();
            $table->timestamps();

            $table->index(['agent_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('agent-profiles.tables.version_states', 'agent_profile_version_states'));
        Schema::dropIfExists(config('agent-profiles.tables.versions', 'agent_profile_versions'));
        Schema::dropIfExists(config('agent-profiles.tables.profiles', 'agent_profiles'));
    }
};
