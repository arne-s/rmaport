<?php

use App\Models\MicrosoftMailToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_sender_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('microsoft_mail_token_id')
                ->nullable()
                ->constrained('microsoft_mail_tokens')
                ->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->foreignId('mail_sender_profile_id')
                ->nullable()
                ->constrained('mail_sender_profiles')
                ->nullOnDelete()
                ->after('audience');
        });

        // Auto-map: create one profile per distinct token currently used in templates,
        // then link those templates to the new profile.
        $this->migrateExistingTokens();

        // Drop the old direct FK column.
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropForeign(['from_microsoft_mail_token_id']);
            $table->dropColumn('from_microsoft_mail_token_id');
        });

        // Create a default "Standaard" profile seeded from the is_default token (if any).
        $this->seedDefaultProfile();
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->foreignId('from_microsoft_mail_token_id')
                ->nullable()
                ->constrained('microsoft_mail_tokens')
                ->nullOnDelete()
                ->after('audience');
        });

        // Restore token IDs from profiles.
        DB::table('email_templates')
            ->whereNotNull('mail_sender_profile_id')
            ->each(function (object $row): void {
                $profile = DB::table('mail_sender_profiles')->where('id', $row->mail_sender_profile_id)->first();
                if ($profile) {
                    DB::table('email_templates')
                        ->where('id', $row->id)
                        ->update(['from_microsoft_mail_token_id' => $profile->microsoft_mail_token_id]);
                }
            });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropForeign(['mail_sender_profile_id']);
            $table->dropColumn('mail_sender_profile_id');
        });

        Schema::dropIfExists('mail_sender_profiles');
    }

    private function migrateExistingTokens(): void
    {
        $tokenIds = DB::table('email_templates')
            ->whereNotNull('from_microsoft_mail_token_id')
            ->distinct()
            ->pluck('from_microsoft_mail_token_id');

        foreach ($tokenIds as $tokenId) {
            $token = DB::table('microsoft_mail_tokens')->where('id', $tokenId)->first();
            $name = $token?->microsoft_email ?? ('Account ' . $tokenId);

            $profileId = DB::table('mail_sender_profiles')->insertGetId([
                'name'                    => $name,
                'microsoft_mail_token_id' => $tokenId,
                'is_default'              => false,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);

            DB::table('email_templates')
                ->where('from_microsoft_mail_token_id', $tokenId)
                ->update(['mail_sender_profile_id' => $profileId]);
        }
    }

    private function seedDefaultProfile(): void
    {
        // Only create if a default token exists and no default profile exists yet.
        $defaultToken = DB::table('microsoft_mail_tokens')->where('is_default', true)->first();

        if (! $defaultToken) {
            return;
        }

        $alreadyExists = DB::table('mail_sender_profiles')
            ->where('microsoft_mail_token_id', $defaultToken->id)
            ->exists();

        if ($alreadyExists) {
            // Promote the existing profile to default instead of creating a duplicate.
            DB::table('mail_sender_profiles')
                ->where('microsoft_mail_token_id', $defaultToken->id)
                ->limit(1)
                ->update(['is_default' => true, 'name' => 'Standaard']);

            return;
        }

        DB::table('mail_sender_profiles')->insert([
            'name'                    => 'Standaard',
            'microsoft_mail_token_id' => $defaultToken->id,
            'is_default'              => true,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);
    }
};
